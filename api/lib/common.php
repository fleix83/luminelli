<?php
declare(strict_types=1);

/**
 * Shared helpers for the PHP endpoints: config, client IP, file-based rate
 * limits and plain-text mail (DEV log, SMTP or mail()).
 */

const STORAGE_DIR = __DIR__ . '/../storage';

require_once __DIR__ . '/SmtpMailer.php';

// Record fatal errors (blank 500s) in app.log as well.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        app_log('[fatal] ' . $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line']);
    }
});

/**
 * Log to PHP's error_log and to api/storage/app.log (readable in the Plesk
 * file manager when the hosting's error log isn't accessible). Never log secrets.
 */
function app_log(string $message): void
{
    error_log($message);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message) . "\n";
    @file_put_contents(STORAGE_DIR . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function load_config(): array
{
    static $config = null;
    if ($config === null) {
        $dir = dirname(__DIR__);
        $config = require (is_file($dir . '/config.php') ? $dir . '/config.php' : $dir . '/config.example.php');
    }
    return $config;
}

/** Remove CR/LF and other control characters (header injection protection). */
function header_safe(string $value): string
{
    return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
}

function client_ip(): string
{
    // REMOTE_ADDR only: forwarded headers can be spoofed.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function hash_id(string $value, array $config): string
{
    return hash('sha256', $value . ($config['ip_salt'] ?? ''));
}

/**
 * Simple file-based sliding-window rate limit. Stores hashed keys only.
 * Returns false if the limit is exceeded (and records the hit otherwise).
 */
function rate_limit_allows(string $key, array $config, string $bucket = 'ratelimit', ?int $max = null, ?int $window = null): bool
{
    $dir = STORAGE_DIR . '/' . $bucket;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return true; // don't block users if storage is broken
    }
    $window ??= (int) ($config['rate_limit_window'] ?? 3600);
    $max ??= (int) ($config['rate_limit_max'] ?? 5);
    $file = $dir . '/' . hash_id($key, $config) . '.json';

    $fh = fopen($file, 'c+');
    if ($fh === false) {
        return true;
    }
    flock($fh, LOCK_EX);
    $now = time();
    $hits = json_decode((string) stream_get_contents($fh), true);
    $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $window));
    $allowed = count($hits) < $max;
    if ($allowed) {
        $hits[] = $now;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    flock($fh, LOCK_UN);
    fclose($fh);

    // Occasional cleanup of stale files
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < $now - $window) {
                @unlink($f);
            }
        }
    }
    return $allowed;
}

function encode_header(string $text): string
{
    return preg_match('/[^\x20-\x7E]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

/**
 * Build a plain-text UTF-8 message (headers + body) and the headers for mail().
 *
 * @param array{to: string, subject: string, body: string, reply_to?: string, tag?: string} $msg
 */
function compose_mail(array $msg, array $config): array
{
    $from = header_safe((string) $config['from']);
    $fromName = header_safe((string) ($config['from_name'] ?? 'Website'));
    $to = header_safe($msg['to']);
    $subject = header_safe($msg['subject']);
    $body = str_replace(["\r\n", "\r", "\n"], "\r\n", $msg['body']);

    $date = new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich'));
    $domain = substr(strrchr($from, '@') ?: '@luminelli.ch', 1);
    $headers = ['From: ' . encode_header($fromName) . " <$from>"];
    if (!empty($msg['reply_to'])) {
        $headers[] = 'Reply-To: <' . header_safe($msg['reply_to']) . '>';
    }
    array_push(
        $headers,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: luminelli.ch ' . header_safe($msg['tag'] ?? 'website'),
    );
    $full = array_merge([
        'Date: ' . $date->format(DATE_RFC2822),
        "To: <$to>",
        'Subject: ' . encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
    ], $headers);

    $encodedBody = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));

    return [
        'to' => $to,
        'from' => $from,
        'subject' => $subject,
        'headers' => $headers,
        'body' => $body,
        'encoded_body' => $encodedBody,
        'raw' => implode("\r\n", $full) . "\r\n\r\n" . $encodedBody,
    ];
}

function send_mail(array $mail, array $config): bool
{
    if (!empty($config['dev_mode'])) {
        app_log('[mail] dev_mode is on: mail written to mail.log, not sent');
        $entry = str_repeat('=', 72) . "\n"
            . 'To: ' . $mail['to'] . "\n"
            . 'Subject: ' . $mail['subject'] . "\n"
            . implode("\n", $mail['headers']) . "\n\n"
            . str_replace("\r\n", "\n", $mail['body']) . "\n";
        return file_put_contents(STORAGE_DIR . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    if (($config['transport'] ?? 'mail') === 'smtp') {
        (new SmtpMailer($config['smtp'] ?? []))->send($mail['from'], $mail['to'], $mail['raw']);
        return true;
    }

    $ok = mail(
        $mail['to'],
        encode_header($mail['subject']),
        $mail['encoded_body'],
        implode("\r\n", $mail['headers']),
        '-f' . $mail['from']
    );
    if (!$ok) {
        $err = error_get_last();
        app_log('[mail] mail() returned false' . ($err ? ': ' . $err['message'] : ''));
    }
    return $ok;
}

/** Convenience: compose + send, never throws. */
function send_plain_mail(array $msg, array $config): bool
{
    try {
        return send_mail(compose_mail($msg, $config), $config);
    } catch (Throwable $e) {
        app_log('[mail] ' . get_class($e) . ': ' . $e->getMessage());
        return false;
    }
}
