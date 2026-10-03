<?php
declare(strict_types=1);

/**
 * GET ?id=…&t=… – progress of one job for the status view. Polling this also
 * drives the job forward (webhook.php does the same when nobody watches).
 */

require __DIR__ . '/lib/bootstrap.php';

$pj = pj_config();
pj_defer_housekeeping($pj);
$job = pj_job_load((string) ($_GET['id'] ?? ''));
if (!$job || !pj_job_status_ok($job, (string) ($_GET['t'] ?? ''))) {
    pj_json(['ok' => false, 'error' => 'Dieser Link ist ungültig.'], 404);
}

if ($job['state'] === 'running') {
    $job = pj_poll_job($pj, $job, true);
}

$r = $job['report'] ?? [];
$out = [
    'ok' => true,
    'state' => $job['state'],
    'project' => $job['project'],
    'started_at' => $job['started_at'] ?? null,
    'finished_at' => $job['finished_at'] ?? null,
    'activity' => $job['state'] === 'running' ? ($job['activity'] ?? []) : [],
];
if ($job['state'] === 'done') {
    $out['draft_url'] = pj_draft_url($pj, $job);
    $out['title'] = ($r['title'] ?? '') !== '' ? $r['title'] : $job['project'];
    $out['summary'] = $r['summary_for_customer'] ?? '';
    $out['placeholders'] = $r['placeholders'] ?? [];
}
pj_json($out);
