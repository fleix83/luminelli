<?php
declare(strict_types=1);

/**
 * Housekeeping without a cron job: pj_maybe_housekeeping() is called from the
 * Projektor endpoints (submit, status, webhook) and runs at most every
 * PJ_HOUSEKEEPING_INTERVAL seconds. cron.php calls pj_housekeeping() directly
 * if a real cron job is ever available.
 *
 * - drives running jobs forward (safety net if a webhook was lost)
 * - removes requests that never started (uploads) after 24 h
 * - cleans up failed jobs' remote sessions after 3 days
 * - deletes drafts after keep_drafts_days and job records after 90 days
 */

const PJ_HOUSEKEEPING_INTERVAL = 600;

function pj_maybe_housekeeping(array $pj): void
{
    $stamp = PJ_DIR . '/.housekeeping';
    if (is_file($stamp) && time() - (int) filemtime($stamp) < PJ_HOUSEKEEPING_INTERVAL) {
        return;
    }
    $fh = @fopen($stamp, 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
        return; // someone else is on it
    }
    touch($stamp);
    try {
        pj_housekeeping($pj);
    } catch (Throwable $e) {
        app_log('[projektor] housekeeping: ' . $e->getMessage());
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function pj_housekeeping(array $pj, ?callable $log = null): void
{
    $log ??= static fn(string $msg) => null;
    $now = time();

    foreach (pj_jobs_all() as $job) {
        $id = $job['id'];
        $age = $now - strtotime((string) $job['created_at']);

        if ($job['state'] === 'running') {
            $job = pj_poll_job($pj, $job);
            if ($job['state'] !== 'running') {
                $log("$id → {$job['state']}");
            }
            continue;
        }

        if (in_array($job['state'], ['new', 'pending'], true) && $age > 24 * 3600) {
            $job['state'] = 'expired';
            pj_job_save($job);
            pj_rmdir(pj_job_dir($id) . '/uploads');
            $log("$id expired");
            continue;
        }

        if (in_array($job['state'], ['expired', 'rejected'], true)) {
            pj_rmdir(pj_job_dir($id) . '/uploads');
        }

        $finished = strtotime((string) ($job['finished_at'] ?? $job['created_at']));

        // Failed jobs keep their session for inspection; clean up after 3 days.
        if ($job['state'] === 'failed' && empty($job['remote_cleaned']) && $now - $finished > 3 * 86400) {
            pj_cleanup_remote($pj, $job);
            pj_rmdir(pj_job_dir($id) . '/uploads');
            $job['remote_cleaned'] = true;
            pj_job_save($job);
            $log("$id remote cleanup (failed job)");
        }

        if ($job['state'] === 'done' && !empty($job['draft_slug']) && empty($job['draft_deleted'])
            && $now - $finished > 86400 * (int) ($pj['keep_drafts_days'] ?? 60)) {
            pj_rmdir(rtrim((string) $pj['drafts_dir'], '/') . '/' . $job['draft_slug']);
            $job['draft_deleted'] = true;
            pj_job_save($job);
            $log("$id draft deleted");
        }

        if ($age > 90 * 86400 && $job['state'] !== 'done') {
            pj_rmdir(pj_job_dir($id));
            $log("$id record deleted");
        }
    }

    foreach (glob(PJ_DIR . '/limits-*.json') ?: [] as $f) {
        if (filemtime($f) < $now - 7 * 86400) {
            unlink($f);
        }
    }
    foreach (glob(PJ_DIR . '/webhook-seen/*') ?: [] as $f) {
        if (filemtime($f) < $now - 7 * 86400) {
            unlink($f);
        }
    }
}
