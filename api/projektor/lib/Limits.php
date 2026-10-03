<?php
declare(strict_types=1);

/**
 * Daily generation caps (global, per e-mail, per IP), counted per calendar
 * day in Europe/Zurich. Only hashes of e-mail and IP are stored. The IP hash
 * is the one recorded at submit, so confirming on another device counts too.
 */

function pj_limits_file(): string
{
    return PJ_DIR . '/limits-' . pj_now()->format('Y-m-d') . '.json';
}

/**
 * Check the caps and, if $consume is true and all pass, count this generation.
 * Returns null when allowed, otherwise a German message for the customer.
 */
function pj_limits_check(array $pj, string $email, string $ipHash, bool $consume): ?string
{
    if (!is_dir(PJ_DIR)) {
        mkdir(PJ_DIR, 0750, true);
    }
    $root = $pj['_root'];
    $emailKey = hash_id('mail:' . mb_strtolower(trim($email)), $root);
    $ipKey = $ipHash;

    $fh = fopen(pj_limits_file(), 'c+');
    if ($fh === false) {
        return 'Der Projektor ist gerade nicht erreichbar. Bitte versuchen Sie es später erneut.';
    }
    flock($fh, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fh), true);
    $data = is_array($data) ? $data : ['total' => 0, 'email' => [], 'ip' => []];

    $error = null;
    if ($data['total'] >= (int) ($pj['daily_global_cap'] ?? 10)) {
        $error = 'Für heute sind alle Projektor-Plätze vergeben. Morgen geht es weiter – oder schreiben Sie uns direkt.';
    } elseif (($data['email'][$emailKey] ?? 0) >= (int) ($pj['per_email_per_day'] ?? 1)
        || ($data['ip'][$ipKey] ?? 0) >= (int) ($pj['per_ip_per_day'] ?? 1)) {
        $error = 'Pro Tag ist ein Entwurf möglich. Morgen können Sie den Projektor wieder nutzen.';
    }

    if ($error === null && $consume) {
        $data['total']++;
        $data['email'][$emailKey] = ($data['email'][$emailKey] ?? 0) + 1;
        $data['ip'][$ipKey] = ($data['ip'][$ipKey] ?? 0) + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $error;
}

/** Give a consumed slot back (the session could not be started on our side). */
function pj_limits_release(array $pj, string $email, string $ipHash): void
{
    $emailKey = hash_id('mail:' . mb_strtolower(trim($email)), $pj['_root']);
    $fh = fopen(pj_limits_file(), 'c+');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fh), true);
    if (is_array($data)) {
        $data['total'] = max(0, ($data['total'] ?? 0) - 1);
        foreach (['email' => $emailKey, 'ip' => $ipHash] as $kind => $key) {
            if (isset($data[$kind][$key])) {
                $data[$kind][$key] = max(0, $data[$kind][$key] - 1);
            }
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}
