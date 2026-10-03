<?php
declare(strict_types=1);

/**
 * Polls running jobs and delivers finished drafts:
 * build.zip from the session outputs is unpacked (whitelisted file types only)
 * into drafts_dir/<random slug>/, the customer and Luminelli get a mail, and
 * the remote session plus uploaded inputs are deleted.
 */

const PJ_DRAFT_EXTENSIONS = ['html', 'css', 'js', 'json', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'woff2', 'woff', 'txt', 'csv', 'md', 'ico'];
const PJ_DRAFT_MAX_FILES = 400;
const PJ_DRAFT_MAX_BYTES = 15 * 1024 * 1024;
const PJ_POLL_INTERVAL = 8; // seconds between remote checks per job

/**
 * Bring a running job up to date. Cheap to call often: talks to the API at
 * most every PJ_POLL_INTERVAL seconds and never twice in parallel.
 */
function pj_poll_job(array $pj, array $job, bool $withActivity = false, bool $force = false): array
{
    if ($job['state'] !== 'running' || empty($job['session_id'])) {
        return $job;
    }
    if (!$force && time() - (int) ($job['last_poll'] ?? 0) < PJ_POLL_INTERVAL) {
        return $job;
    }
    $lock = pj_job_lock($job['id']);
    if (!$lock) {
        return $job;
    }
    try {
        $job = pj_job_load($job['id']) ?? $job; // reload under lock
        if ($job['state'] !== 'running') {
            return $job;
        }
        $job['last_poll'] = time();
        $state = pj_session_state($pj, $job['session_id']);
        $job['cost_cents'] = $state['cost_cents'] ?? ($job['cost_cents'] ?? null);

        $finished = $state['status'] === 'terminated'
            || ($state['status'] === 'idle' && $state['stop'] !== null);

        if ($finished) {
            $job = pj_finalize($pj, $job, $state['stop'] ?? $state['status']);
        } else {
            $runtime = time() - strtotime((string) $job['started_at']);
            if ($runtime > 60 * (int) ($pj['max_runtime_minutes'] ?? 45) && empty($job['interrupted_at'])) {
                pj_interrupt($pj, $job['session_id']);
                $job['interrupted_at'] = pj_now()->format(DATE_ATOM);
            }
            if ($withActivity) {
                $job['activity'] = pj_session_activity($pj, $job['session_id']);
            }
        }
        pj_job_save($job);
    } catch (Throwable $e) {
        app_log('[projektor] poll ' . $job['id'] . ': ' . $e->getMessage());
    } finally {
        pj_job_unlock($lock);
    }
    return $job;
}

function pj_finalize(array $pj, array $job, string $stop): array
{
    $job['stop_reason'] = $stop;
    $outputs = pj_session_outputs($pj, $job['session_id']);

    if (!isset($outputs['build.zip'])) {
        // Outputs appear 1-3 s after the session goes idle: retry on the next polls.
        $job['finalize_attempts'] = (int) ($job['finalize_attempts'] ?? 0) + 1;
        if ($job['finalize_attempts'] < 4) {
            return $job;
        }
        $report = isset($outputs['report.json']) ? pj_parse_report(pj_download($pj, $outputs['report.json'])) : null;
        return pj_fail($pj, $job, 'Kein build.zip in den Session-Outputs (Stop: ' . $stop . ')', $report);
    }

    try {
        $report = isset($outputs['report.json']) ? pj_parse_report(pj_download($pj, $outputs['report.json'])) : null;
        $zip = pj_download($pj, $outputs['build.zip']);
        $slug = bin2hex(random_bytes(8));
        pj_extract_draft($pj, $zip, $slug, $report['entry_file'] ?? 'index.html');
    } catch (Throwable $e) {
        return pj_fail($pj, $job, 'Auslieferung fehlgeschlagen: ' . $e->getMessage(), $report ?? null);
    }

    $job['state'] = 'done';
    $job['error'] = null;
    $job['draft_slug'] = $slug;
    $job['report'] = $report;
    $job['finished_at'] = pj_now()->format(DATE_ATOM);
    $job['activity'] = [];

    pj_mail_done($pj, $job);
    pj_cleanup_remote($pj, $job);
    pj_rmdir(pj_job_dir($job['id']) . '/uploads');
    return $job;
}

function pj_fail(array $pj, array $job, string $reason, ?array $report = null): array
{
    $job['state'] = 'failed';
    $job['error'] = $reason;
    $job['report'] = $report;
    $job['finished_at'] = pj_now()->format(DATE_ATOM);
    pj_mail_failed($pj, $job);
    // Keep the session and its outputs for inspection; cron.php cleans up after 3 days.
    return $job;
}

/** Parse and clamp the agent's report.json (untrusted input). */
function pj_parse_report(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    $str = fn($v, int $max) => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    $list = fn($v) => array_values(array_map(fn($x) => mb_substr(trim((string) $x), 0, 300), array_filter(is_array($v) ? array_slice($v, 0, 12) : [], 'is_scalar')));
    $entry = $str($data['entry_file'] ?? 'index.html', 100);
    return [
        'type' => in_array($data['type'] ?? '', ['website', 'webapp', 'website_with_tool'], true) ? $data['type'] : 'website',
        'title' => $str($data['title'] ?? '', 80),
        'summary_for_customer' => $str($data['summary_for_customer'] ?? '', 800),
        'entry_file' => preg_match('/^[A-Za-z0-9_\-\/]+\.html$/', $entry) && !str_contains($entry, '..') ? $entry : 'index.html',
        'assumptions' => $list($data['assumptions'] ?? []),
        'placeholders' => $list($data['placeholders'] ?? []),
        'suggested_next_steps' => $list($data['suggested_next_steps'] ?? []),
        'notes_for_luminelli' => $str($data['notes_for_luminelli'] ?? '', 3000),
    ];
}

/** Unpack the build zip into drafts_dir/<slug>/ with strict checks. */
function pj_extract_draft(array $pj, string $zipData, string $slug, string $entryFile): void
{
    // Own temp folder: the system temp dir is often not writable for the web user.
    $tmpBase = PJ_DIR . '/tmp';
    if (!is_dir($tmpBase)) {
        mkdir($tmpBase, 0750, true);
    }
    $tmpZip = $tmpBase . '/' . $slug . '.zip';
    if (file_put_contents($tmpZip, $zipData) === false) {
        throw new RuntimeException('Cannot write temp zip');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        unlink($tmpZip);
        throw new RuntimeException('build.zip is not a valid zip');
    }

    $base = rtrim((string) $pj['drafts_dir'], '/');
    if (!is_dir($base) && !mkdir($base, 0755, true)) {
        throw new RuntimeException('drafts_dir not writable');
    }
    $tmpDir = $base . '/.tmp-' . $slug;
    mkdir($tmpDir, 0755);

    try {
        $count = 0;
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) $stat['name']);
            if (str_ends_with($name, '/')) {
                continue; // directory entry
            }
            $name = ltrim(preg_replace('#^\./#', '', $name), '/');
            $parts = explode('/', $name);
            $bad = in_array('..', $parts, true) || in_array('', $parts, true) || str_contains($name, ':');
            $hidden = (bool) array_filter($parts, fn($p) => str_starts_with($p, '.'));
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($bad || $hidden || $name === '_brief.md' || !in_array($ext, PJ_DRAFT_EXTENSIONS, true)) {
                continue;
            }
            if (++$count > PJ_DRAFT_MAX_FILES || ($total += (int) $stat['size']) > PJ_DRAFT_MAX_BYTES) {
                throw new RuntimeException('Draft too large');
            }
            $target = $tmpDir . '/' . $name;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            $in = $zip->getStream($stat['name']);
            $out = fopen($target, 'wb');
            $written = stream_copy_to_stream($in, $out, PJ_DRAFT_MAX_BYTES);
            fclose($in);
            fclose($out);
            if ($written > (int) $stat['size']) {
                throw new RuntimeException('Zip entry size mismatch');
            }
        }
        if (!is_file($tmpDir . '/' . $entryFile)) {
            throw new RuntimeException("Entry file $entryFile missing");
        }
        if ($entryFile !== 'index.html' && !is_file($tmpDir . '/index.html')) {
            // Make the folder URL work.
            file_put_contents($tmpDir . '/index.html', '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0; url=' . htmlspecialchars($entryFile) . '"><title>Entwurf</title>');
        }
        rename($tmpDir, $base . '/' . $slug);
    } catch (Throwable $e) {
        pj_rmdir($tmpDir);
        throw $e;
    } finally {
        $zip->close();
        unlink($tmpZip);
    }
}

/* ---------- Mails ---------- */

function pj_mail_done(array $pj, array $job): void
{
    $root = $pj['_root'];
    $r = $job['report'] ?? [];
    $title = ($r['title'] ?? '') !== '' ? $r['title'] : $job['project'];
    $url = pj_draft_url($pj, $job);

    if ($job['email'] !== '') send_plain_mail([
        'to' => $job['email'],
        'reply_to' => (string) $root['to'],
        'subject' => 'Ihr Entwurf ist fertig: ' . $title,
        'tag' => 'projektor',
        'body' => implode("\n", array_filter([
            'Guten Tag',
            '',
            'Der Projektor hat Ihren Entwurf fertig gebaut:',
            $url,
            '',
            $r['summary_for_customer'] ?? null,
            ($r['summary_for_customer'] ?? '') !== '' ? '' : null,
            'Der Entwurf wurde mit KI erstellt und ist ein erster Eindruck, kein fertiges Produkt. Inhalte in eckigen Klammern sind Platzhalter.',
            'Er bleibt ' . (int) ($pj['keep_drafts_days'] ?? 60) . ' Tage online.',
            '',
            'Gefällt Ihnen die Richtung? Antworten Sie einfach auf diese E-Mail, dann besprechen wir, wie daraus Ihre echte Lösung wird.',
            '',
            'Freundliche Grüsse',
            'Studio Luminelli',
            'Luftgässlein 3, 4051 Basel',
            '+41 76 757 60 52',
        ], fn($l) => $l !== null)),
    ], $root);

    send_plain_mail([
        'to' => (string) $pj['notify_to'],
        'reply_to' => $job['email'] !== '' ? $job['email'] : null,
        'subject' => 'Projektor: Entwurf fertig – ' . $title,
        'tag' => 'projektor',
        'body' => pj_internal_summary($pj, $job, $url),
    ], $root);
}

function pj_mail_failed(array $pj, array $job): void
{
    $root = $pj['_root'];
    if ($job['email'] !== '') send_plain_mail([
        'to' => $job['email'],
        'reply_to' => (string) $root['to'],
        'subject' => 'Ihr Projektor-Entwurf: leider nicht geklappt',
        'tag' => 'projektor',
        'body' => implode("\n", [
            'Guten Tag',
            '',
            'Beim Bauen Ihres Entwurfs ist leider etwas schiefgelaufen. Das liegt nicht an Ihnen.',
            'Wir schauen uns das an und melden uns persönlich bei Ihnen.',
            '',
            'Freundliche Grüsse',
            'Studio Luminelli',
            '+41 76 757 60 52 · service@luminelli.ch',
        ]),
    ], $root);

    send_plain_mail([
        'to' => (string) $pj['notify_to'],
        'reply_to' => $job['email'] !== '' ? $job['email'] : null,
        'subject' => 'Projektor: FEHLGESCHLAGEN – ' . mb_substr($job['project'], 0, 60),
        'tag' => 'projektor',
        'body' => 'Fehler: ' . ($job['error'] ?? '?') . "\n\n" . pj_internal_summary($pj, $job, null),
    ], $root);
}

function pj_internal_summary(array $pj, array $job, ?string $url): string
{
    $r = $job['report'] ?? [];
    $cost = isset($job['cost_cents']) ? sprintf('$%.2f', $job['cost_cents'] / 100) : 'unbekannt';
    $list = fn(string $label, array $items) => $items ? $label . ":\n- " . implode("\n- ", $items) . "\n" : '';
    $trace = $job['session_id']
        ? 'https://platform.claude.com/workspaces/' . rawurlencode((string) ($pj['anthropic_workspace'] ?? 'default')) . '/sessions/' . $job['session_id'] . ' (Session wurde nach der Auslieferung gelöscht)'
        : '-';
    return implode("\n", [
        'Entwurf:       ' . ($url ?? '-'),
        'Kunde:         ' . ($job['email'] !== '' ? $job['email'] : '(keine E-Mail angegeben)'),
        'Typ:           ' . ($r['type'] ?? '-'),
        'Kosten:        ' . $cost . ' (Limit $' . number_format(((int) $pj['budget_cents']) / 100, 2) . ')',
        'Stop:          ' . ($job['stop_reason'] ?? '-'),
        'Gestartet:     ' . ($job['started_at'] ?? '-'),
        'Fertig:        ' . ($job['finished_at'] ?? '-'),
        'Job:           ' . $job['id'],
        'Session:       ' . $trace,
        '',
        'Projekt:       ' . $job['project'],
        'Referenz:      ' . ($job['reference_url'] ?: '-'),
        'Schrift/Farbe: ' . PJ_FONTS[$job['font']]['family'] . ' / ' . $job['color'],
        'Dateien:       ' . (count($job['files']) ? implode(', ', array_column($job['files'], 'name')) : '-'),
        '',
        'Beschreibung:',
        $job['description'],
        '',
        'Hinweise des Agenten:',
        ($r['notes_for_luminelli'] ?? '') !== '' ? $r['notes_for_luminelli'] : '-',
        '',
        $list('Annahmen', $r['assumptions'] ?? []) . $list('Platzhalter', $r['placeholders'] ?? []) . $list('Nächste Schritte', $r['suggested_next_steps'] ?? []),
    ]);
}
