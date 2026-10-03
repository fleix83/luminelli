<?php
declare(strict_types=1);

/**
 * File-based job store: storage/projektor/jobs/<id>/job.json + uploads/.
 *
 * States: running → done | failed. Status tokens are stored as SHA-256 hashes only.
 */

function pj_jobs_dir(): string
{
    return PJ_DIR . '/jobs';
}

function pj_job_dir(string $id): string
{
    return pj_jobs_dir() . '/' . $id;
}

function pj_valid_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{20}$/', $id);
}

function pj_job_create(array $data): array
{
    $id = bin2hex(random_bytes(10));
    $dir = pj_job_dir($id);
    if (!mkdir($dir . '/uploads', 0750, true)) {
        throw new RuntimeException('Could not create job directory');
    }
    $job = $data + [
        'id' => $id,
        'state' => 'new',
        'created_at' => pj_now()->format(DATE_ATOM),
        'status_hashes' => [],
        'session_id' => null,
        'draft_slug' => null,
        'report' => null,
        'error' => null,
    ];
    pj_job_save($job);
    return $job;
}

function pj_job_load(string $id): ?array
{
    if (!pj_valid_id($id)) {
        return null;
    }
    $file = pj_job_dir($id) . '/job.json';
    if (!is_file($file)) {
        return null;
    }
    $job = json_decode((string) file_get_contents($file), true);
    return is_array($job) ? $job : null;
}

function pj_job_save(array $job): void
{
    $job['updated_at'] = pj_now()->format(DATE_ATOM);
    $file = pj_job_dir($job['id']) . '/job.json';
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $file);
}

/** Status token for the live view (issued when the session starts; max 5 kept). */
function pj_job_issue_status_token(array &$job): string
{
    $token = bin2hex(random_bytes(16));
    $job['status_hashes'] = array_slice(array_merge($job['status_hashes'] ?? [], [hash('sha256', $token)]), -5);
    return $token;
}

function pj_job_status_ok(array $job, string $token): bool
{
    $hash = hash('sha256', $token);
    foreach ($job['status_hashes'] ?? [] as $h) {
        if (hash_equals($h, $hash)) {
            return true;
        }
    }
    return false;
}

/**
 * Exclusive, non-blocking lock per job (prevents two pollers finalizing the
 * same job). Returns the handle, or null if someone else holds it.
 *
 * @return resource|null
 */
function pj_job_lock(string $id)
{
    $fh = fopen(pj_job_dir($id) . '/.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
        return null;
    }
    return $fh;
}

/** @param resource|null $fh */
function pj_job_unlock($fh): void
{
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** @return iterable<array> */
function pj_jobs_all(): iterable
{
    foreach (glob(pj_jobs_dir() . '/*/job.json') ?: [] as $file) {
        $job = json_decode((string) file_get_contents($file), true);
        if (is_array($job)) {
            yield $job;
        }
    }
}

function pj_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
