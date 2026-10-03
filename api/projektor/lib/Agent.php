<?php
declare(strict_types=1);

use Anthropic\Client;
use Anthropic\Core\FileParam;

/**
 * Thin wrapper around the Managed Agents calls the Projektor needs.
 * The agent and environment are created once by setup.php (IDs in setup.json).
 */

const PJ_BETA = 'managed-agents-2026-04-01';
const PJ_INPUT_DIR = '/workspace/input';

function pj_client(array $pj): Client
{
    return new Client(apiKey: (string) $pj['anthropic_api_key']);
}

/** Upload a local file to the Files API (auto-expires after 3 days). Returns the file ID. */
function pj_upload(Client $client, string $path, string $filename, string $mime): string
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Cannot open $path");
    }
    try {
        $meta = $client->beta->files->upload(
            file: FileParam::fromResource($fh, $filename, $mime),
            expiresInSeconds: 3 * 24 * 3600,
        );
    } finally {
        if (is_resource($fh)) {
            fclose($fh);
        }
    }
    return $meta->id;
}

/**
 * Upload the job's files, create the session with its budget and send the
 * request as the first message. Updates $job (session_id, input_file_ids).
 */
function pj_start_session(array $pj, array &$job): void
{
    $setup = pj_setup();
    $client = pj_client($pj);
    $resources = [];
    $assets = [];
    $job['input_file_ids'] = [];

    $font = PJ_FONTS[$job['font']];
    $fontFileId = $setup['font_files'][$job['font']] ?? null;
    if (!$fontFileId) {
        throw new RuntimeException('Font not uploaded, run setup.php');
    }
    $fontPath = PJ_INPUT_DIR . '/fonts/' . $job['font'] . '.woff2';
    $resources[] = ['type' => 'file', 'file_id' => $fontFileId, 'mount_path' => $fontPath];

    $screenshotPath = null;
    foreach ($job['files'] as $f) {
        $local = pj_job_dir($job['id']) . '/uploads/' . $f['stored'];
        $fileId = pj_upload($client, $local, $f['name'], $f['mime']);
        $job['input_file_ids'][] = $fileId;
        if ($f['role'] === 'screenshot') {
            $mount = PJ_INPUT_DIR . '/reference/' . $f['name'];
            $screenshotPath = $mount;
        } else {
            $mount = PJ_INPUT_DIR . '/assets/' . $f['name'];
            $assets[] = ['path' => $mount, 'filename' => $f['name'], 'mime_type' => $f['mime'], 'size_bytes' => $f['size']];
        }
        $resources[] = ['type' => 'file', 'file_id' => $fileId, 'mount_path' => $mount];
    }

    $request = [
        'request_id' => $job['id'],
        'reference_url' => $job['reference_url'] ?: null,
        'reference_screenshot' => $screenshotPath,
        'project' => $job['project'],
        'description' => $job['description'],
        'font' => [
            'family' => $font['family'],
            'weights' => $font['weights'] . ' (variable font)',
            'fallback_stack' => $font['fallback'],
            'files' => [$fontPath],
        ],
        'primary_color' => $job['color'],
        'assets' => $assets,
    ];
    $text = "<request>\n" . json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</request>";
    if ($job['reference_url']) {
        // web_fetch may only fetch URLs that appear in the conversation.
        $text .= "\n\nReference URL: " . $job['reference_url'];
    }

    $session = $client->beta->sessions->create(
        agent: ['type' => 'agent', 'id' => $setup['agent_id'], 'version' => (int) $setup['agent_version']],
        environmentID: $setup['environment_id'],
        budget: ['type' => 'limit', 'max_list_cost' => ['amount' => (string) (int) $pj['budget_cents'], 'currency' => 'USD']],
        initialEvents: [
            ['type' => 'user.message', 'content' => [['type' => 'text', 'text' => $text]]],
        ],
        metadata: ['job' => $job['id']],
        resources: $resources,
        title: 'Projektor ' . $job['id'] . ': ' . mb_substr($job['project'], 0, 60),
    );
    $job['session_id'] = $session->id;
}

/**
 * Current session state.
 * @return array{status: string, stop: ?string, cost_cents: ?int}
 */
function pj_session_state(array $pj, string $sessionId): array
{
    $client = pj_client($pj);
    $session = $client->beta->sessions->retrieve($sessionId);
    $stop = null;
    if ($session->status === 'idle') {
        $events = $client->beta->sessions->events->list($sessionId, limit: 1, order: 'desc', types: ['session.status_idle']);
        $last = $events->getItems()[0] ?? null;
        $stop = $last?->stopReason?->type ?? null;
    }
    $cost = $session->usage->listCost ?? null;
    return [
        'status' => $session->status,
        'stop' => $stop,
        'cost_cents' => $cost ? (int) $cost->amount : null,
    ];
}

/**
 * Recent agent activity as short German lines for the status page.
 * @return string[]
 */
function pj_session_activity(array $pj, string $sessionId): array
{
    $client = pj_client($pj);
    $events = $client->beta->sessions->events->list($sessionId, limit: 12, order: 'desc', types: ['agent.tool_use']);
    $lines = [];
    foreach ($events->getItems() as $ev) {
        $input = is_array($ev->input ?? null) ? $ev->input : [];
        $path = (string) ($input['file_path'] ?? $input['path'] ?? '');
        $file = $path !== '' ? basename($path) : '';
        $line = match ($ev->name ?? '') {
            'write' => $file === '_brief.md' ? 'Hält das Konzept fest' : ($file ? "Schreibt $file" : 'Schreibt eine Datei'),
            'edit' => $file ? "Überarbeitet $file" : 'Überarbeitet eine Datei',
            'read' => str_contains($path, '/input/') ? ($file ? "Sieht sich $file an" : 'Sieht sich Ihre Dateien an') : 'Prüft den Entwurf',
            'web_fetch' => 'Schaut sich die Referenz-Website an',
            'bash' => str_contains((string) ($input['command'] ?? ''), 'zipfile') ? 'Verpackt den Entwurf' : 'Arbeitet im Terminal',
            'glob', 'grep' => 'Sucht in den Dateien',
            default => 'Arbeitet am Entwurf',
        };
        if (($lines[count($lines) - 1] ?? null) !== $line) {
            $lines[] = $line;
        }
    }
    return array_slice($lines, 0, 6);
}

/**
 * Output files of the session (newest wins per filename).
 * @return array<string, string> filename => file ID
 */
function pj_session_outputs(array $pj, string $sessionId): array
{
    $client = pj_client($pj);
    $out = [];
    $seen = [];
    $page = $client->beta->files->list(scopeID: $sessionId, limit: 100, betas: [PJ_BETA]);
    foreach ($page->getItems() as $f) {
        $ts = $f->createdAt->getTimestamp();
        if (!isset($seen[$f->filename]) || $ts > $seen[$f->filename]) {
            $seen[$f->filename] = $ts;
            $out[$f->filename] = $f->id;
        }
    }
    return $out;
}

function pj_download(array $pj, string $fileId): string
{
    // files->download() parses JSON files into arrays and then fails on its
    // string return type (SDK 0.54); the raw response gives the exact bytes.
    return (string) pj_client($pj)->beta->files->raw->download($fileId, params: [])->getBody();
}

/** Interrupt a running session (used when it runs too long). */
function pj_interrupt(array $pj, string $sessionId): void
{
    pj_client($pj)->beta->sessions->events->send($sessionId, events: [['type' => 'user.interrupt']]);
}

/**
 * Delete the customer's uploaded inputs and the session (container, history,
 * outputs). Best effort. Called after a successful delivery, and by cron for
 * failed jobs after a few days (so they can be inspected in the Console first).
 */
function pj_cleanup_remote(array $pj, array $job): void
{
    $client = pj_client($pj);
    foreach ($job['input_file_ids'] ?? [] as $fileId) {
        try {
            $client->beta->files->delete($fileId);
        } catch (Throwable $e) {
            app_log("[projektor] delete file $fileId: " . $e->getMessage());
        }
    }
    if (!empty($job['session_id'])) {
        try {
            $client->beta->sessions->delete($job['session_id']);
        } catch (Throwable $e) {
            app_log("[projektor] delete session {$job['session_id']}: " . $e->getMessage());
        }
    }
}
