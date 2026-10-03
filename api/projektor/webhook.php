<?php
declare(strict_types=1);

/**
 * Anthropic → us: session state webhooks (replaces a cron job).
 * Register in the Console → Manage → Webhooks:
 *   URL:    https://luminelli.ch/api/projektor/webhook.php   (exact, no redirect!)
 *   Events: session.status_idled, session.status_terminated
 * Put the whsec_… signing secret into projektor.webhook_secret.
 *
 * Answers 204 right away, then delivers the draft (the payload is thin; the
 * real state is fetched from the API).
 */

require __DIR__ . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$pj = pj_config();
$body = (string) file_get_contents('php://input');
$headers = [];
foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $h) {
    $headers[$h] = (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? '');
}

if (empty($pj['webhook_secret']) || empty($pj['anthropic_api_key'])) {
    http_response_code(503);
    exit;
}
try {
    $event = pj_client($pj)->beta->webhooks->unwrap($body, $headers, (string) $pj['webhook_secret']);
} catch (Throwable $e) {
    http_response_code(400);
    exit;
}

// Dedupe retries (the event ID is the same on every attempt).
$seenDir = PJ_DIR . '/webhook-seen';
@mkdir($seenDir, 0750, true);
$seenFile = $seenDir . '/' . preg_replace('/[^A-Za-z0-9_]/', '', $event->id);
if (is_file($seenFile)) {
    http_response_code(204);
    exit;
}
touch($seenFile);

$type = $event->data->type ?? '';
$sessionId = (string) ($event->data->id ?? '');

// Acknowledge first; delivery can take a few seconds.
http_response_code(204);
header('Content-Length: 0');
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
ignore_user_abort(true);
set_time_limit(120);

if (in_array($type, ['session.status_idled', 'session.status_terminated'], true) && $sessionId !== '') {
    $index = pj_session_index_file($sessionId);
    $job = is_file($index) ? pj_job_load(trim((string) file_get_contents($index))) : null;
    // Outputs show up 1–3 s after the session goes idle: a few quick retries.
    for ($i = 0; $job && $job['state'] === 'running' && $i < 5; $i++) {
        if ($i > 0) {
            sleep(3);
        }
        $job = pj_poll_job($pj, $job, false, true);
    }
}

pj_maybe_housekeeping($pj);
