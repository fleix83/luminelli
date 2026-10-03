<?php
declare(strict_types=1);

/**
 * GET link from the confirmation mail. Consumes a daily slot, starts the
 * Managed Agents session and redirects to the status view on projektor.html.
 * Idempotent: a second click (or a mail scanner) just opens the status view.
 */

require __DIR__ . '/lib/bootstrap.php';

$pj = pj_config();
$site = rtrim((string) ($pj['site_url'] ?? '..'), '/');
$fail = function (string $code) use ($site): never {
    header('Location: ' . $site . '/projektor.html?fehler=' . $code, true, 303);
    exit;
};

$id = (string) ($_GET['id'] ?? '');
$token = (string) ($_GET['t'] ?? '');
$job = pj_job_load($id);
if (!$job || !pj_job_confirm_ok($job, $token)) {
    $fail('link');
}

// Wait up to ~5 s for a parallel click or poll to finish. (exit() in $fail
// skips `finally`, but PHP releases the lock when the process ends.)
$lock = null;
for ($i = 0; $i < 20; $i++) {
    if ($lock = pj_job_lock($id)) {
        break;
    }
    usleep(250000);
}
if (!$lock) {
    $fail('busy');
}

try {
    $job = pj_job_load($id);

    if ($job['state'] === 'pending') {
        if (strtotime($job['created_at']) < time() - 24 * 3600) {
            $job['state'] = 'expired';
            pj_job_save($job);
            $fail('abgelaufen');
        }
        if ($reason = pj_unavailable_reason($pj)) {
            $fail('pause');
        }
        if (pj_limits_check($pj, $job['email'], (string) $job['ip_hash'], true) !== null) {
            $job['state'] = 'rejected';
            pj_job_save($job);
            $fail('limit');
        }

        $job['confirmed_at'] = pj_now()->format(DATE_ATOM);
        try {
            pj_start_session($pj, $job);
            @mkdir(PJ_DIR . '/sessions', 0750, true);
            file_put_contents(pj_session_index_file($job['session_id']), $job['id']);
            $job['state'] = 'running';
            $job['started_at'] = pj_now()->format(DATE_ATOM);
            $job['last_poll'] = time();
        } catch (Throwable $e) {
            app_log('[projektor] start ' . $id . ': ' . $e->getMessage());
            pj_limits_release($pj, $job['email'], (string) $job['ip_hash']);
            $job = pj_fail($pj, $job, 'Start fehlgeschlagen: ' . $e->getMessage());
        }
    } elseif (!in_array($job['state'], ['running', 'done', 'failed'], true)) {
        $fail($job['state'] === 'rejected' ? 'limit' : 'abgelaufen');
    }

    $statusToken = pj_job_issue_status_token($job);
    pj_job_save($job);
} finally {
    pj_job_unlock($lock);
}

header('Location: ' . pj_status_url($pj, $job['id'], $statusToken), true, 303);
