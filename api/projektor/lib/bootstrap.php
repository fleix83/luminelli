<?php
declare(strict_types=1);

/**
 * Projektor: shared setup for the endpoints (submit, status, webhook) and the
 * CLI scripts (setup, cron).
 *
 * Flow: submit.php checks the daily caps (per IP, global), stores the job and
 * starts a Managed Agents session right away → webhook.php (called by
 * Anthropic when the session is idle) or status.php (while the visitor
 * watches) let Finalizer download the build into the drafts folder and mail
 * the link (if an e-mail was given). Housekeeping piggybacks on these
 * requests (no cron).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/common.php';
require_once __DIR__ . '/Jobs.php';
require_once __DIR__ . '/Limits.php';
require_once __DIR__ . '/Agent.php';
require_once __DIR__ . '/Finalizer.php';
require_once __DIR__ . '/Housekeeping.php';

const PJ_DIR = STORAGE_DIR . '/projektor';
const PJ_SETUP_FILE = PJ_DIR . '/setup.json';

/** Fonts offered in the configurator. Files live in assets/fonts/projektor/. */
const PJ_FONTS = [
    'inter'            => ['family' => 'Inter',            'fallback' => 'system-ui, sans-serif', 'weights' => '100 900'],
    'dm-sans'          => ['family' => 'DM Sans',          'fallback' => 'system-ui, sans-serif', 'weights' => '100 1000'],
    'space-grotesk'    => ['family' => 'Space Grotesk',    'fallback' => 'system-ui, sans-serif', 'weights' => '300 700'],
    'quicksand'        => ['family' => 'Quicksand',        'fallback' => 'system-ui, sans-serif', 'weights' => '300 700'],
    'nunito'           => ['family' => 'Nunito',           'fallback' => 'system-ui, sans-serif', 'weights' => '200 1000'],
    'lora'             => ['family' => 'Lora',             'fallback' => 'Georgia, serif',        'weights' => '400 700'],
    'playfair-display' => ['family' => 'Playfair Display', 'fallback' => 'Georgia, serif',        'weights' => '400 900'],
    'fraunces'         => ['family' => 'Fraunces',         'fallback' => 'Georgia, serif',        'weights' => '100 900'],
];
const PJ_FONT_DIR = __DIR__ . '/../../../assets/fonts/projektor';

/** Accepted uploads: MIME type (detected with finfo) => extension. */
const PJ_UPLOAD_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'image/svg+xml' => 'svg',
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/csv' => 'csv',
    'application/json' => 'json',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
];
const PJ_MAX_FILES = 8;
const PJ_MAX_FILE_BYTES = 8 * 1024 * 1024;
const PJ_MAX_TOTAL_BYTES = 25 * 1024 * 1024;

function pj_config(): array
{
    $config = load_config();
    $pj = $config['projektor'] ?? [];
    $pj['_root'] = $config;
    return $pj;
}

function pj_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich'));
}

function pj_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pj_require_cli(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
}

function pj_setup(): array
{
    $data = is_file(PJ_SETUP_FILE) ? json_decode((string) file_get_contents(PJ_SETUP_FILE), true) : null;
    return is_array($data) ? $data : [];
}

function pj_save_setup(array $data): void
{
    if (!is_dir(PJ_DIR)) {
        mkdir(PJ_DIR, 0750, true);
    }
    file_put_contents(PJ_SETUP_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Is the Projektor ready to accept requests? Returns an error text or null. */
function pj_unavailable_reason(array $pj): ?string
{
    if (empty($pj['enabled'])) {
        return 'Der Projektor ist zurzeit nicht verfügbar.';
    }
    $setup = pj_setup();
    if (empty($pj['anthropic_api_key']) || empty($setup['agent_id']) || empty($setup['environment_id'])) {
        return 'Der Projektor ist noch nicht fertig eingerichtet.';
    }
    return null;
}

function pj_status_url(array $pj, string $jobId, string $statusToken): string
{
    return rtrim((string) $pj['site_url'], '/') . '/projektor.html?job=' . rawurlencode($jobId) . '&t=' . rawurlencode($statusToken);
}

function pj_draft_url(array $pj, array $job): string
{
    return rtrim((string) $pj['drafts_url'], '/') . '/' . rawurlencode((string) $job['draft_slug']) . '/';
}

/**
 * Run housekeeping after the response has been sent (no cron job on the host).
 * With PHP-FPM the client gets its answer first; with mod_php it waits a
 * moment, at most once every few minutes.
 */
function pj_defer_housekeeping(array $pj): void
{
    register_shutdown_function(static function () use ($pj): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        pj_maybe_housekeeping($pj);
    });
}

/** Session ID → job ID index, so the webhook finds the job without scanning. */
function pj_session_index_file(string $sessionId): string
{
    return PJ_DIR . '/sessions/' . preg_replace('/[^A-Za-z0-9_]/', '', $sessionId);
}
