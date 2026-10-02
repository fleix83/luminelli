<?php
declare(strict_types=1);

/**
 * Contact endpoint for the inquiry form on index.html.
 *
 * - fetch requests (Accept: application/json) get JSON: {ok: true} / {ok: false, error: "..."}
 * - plain form posts (no JavaScript) are redirected back to the page
 *
 * The actual logic lives in handle_inquiry() so it can later be moved into a
 * real backend (or extended to store inquiries in a database).
 */

const INQUIRY_TOPICS = ['', 'Webapps', 'Mobile Apps', 'Webhosting', 'Support'];
const STORAGE_DIR = __DIR__ . '/storage';

require __DIR__ . '/lib/SmtpMailer.php';

function load_config(): array
{
    $file = is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/config.example.php';
    return require $file;
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

/**
 * Simple file-based rate limit. Stores hashed IPs only.
 * Returns false if the limit is exceeded (and records the hit otherwise).
 */
function rate_limit_allows(string $ip, array $config): bool
{
    $dir = STORAGE_DIR . '/ratelimit';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return true; // don't block users if storage is broken
    }
    $window = (int) ($config['rate_limit_window'] ?? 3600);
    $max = (int) ($config['rate_limit_max'] ?? 5);
    $file = $dir . '/' . hash('sha256', $ip . ($config['ip_salt'] ?? '')) . '.json';

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

/** Build the full RFC 5322 message (headers + body) and the headers for mail(). */
function build_mail(array $inquiry, array $config): array
{
    $from = header_safe((string) $config['from']);
    $fromName = header_safe((string) ($config['from_name'] ?? 'Website'));
    $to = header_safe((string) $config['to']);
    $replyTo = header_safe($inquiry['email']);
    $subject = 'Neue Anfrage über luminelli.ch: ' . ($inquiry['topic'] !== '' ? $inquiry['topic'] : 'Allgemein');

    $date = new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich'));
    $body = implode("\n", [
        'Thema:          ' . ($inquiry['topic'] !== '' ? $inquiry['topic'] : 'Allgemein'),
        'E-Mail:         ' . $inquiry['email'],
        'Datum/Uhrzeit:  ' . $date->format('d.m.Y H:i') . ' (Europe/Zurich)',
        'Herkunftsseite: ' . ($inquiry['page'] !== '' ? $inquiry['page'] : 'unbekannt'),
        '',
        'Nachricht:',
        '----------',
        $inquiry['message'],
        '',
    ]);
    $body = str_replace(["\r\n", "\r", "\n"], "\r\n", $body);

    $domain = substr(strrchr($from, '@') ?: '@luminelli.ch', 1);
    $headers = [
        'From: ' . encode_header($fromName) . " <$from>",
        "Reply-To: <$replyTo>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: luminelli.ch inquiry',
    ];
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

    return mail(
        $mail['to'],
        encode_header($mail['subject']),
        $mail['encoded_body'],
        implode("\r\n", $mail['headers']),
        '-f' . $mail['from']
    );
}

/**
 * Validate, filter and send one inquiry.
 *
 * @param array $data    raw input (email, message, topic, website, ts, page)
 * @param array $context ['ip' => string, 'referer' => string, 'config' => array]
 * @return array{ok: bool, error?: string, status: int, spam?: bool}
 */
function handle_inquiry(array $data, array $context): array
{
    $config = $context['config'];

    // Honeypot: pretend success so bots learn nothing.
    if (trim((string) ($data['website'] ?? '')) !== '') {
        return ['ok' => true, 'status' => 200, 'spam' => true];
    }

    // Time trap (timestamp in ms set by JS when the dialog opens).
    $ts = (string) ($data['ts'] ?? '');
    if ($ts !== '') {
        $elapsed = (microtime(true) * 1000 - (float) $ts) / 1000;
        if (!ctype_digit($ts) || $elapsed < (int) ($config['min_seconds'] ?? 3)) {
            return ['ok' => false, 'status' => 400, 'error' => 'Das ging etwas zu schnell. Bitte warten Sie einen Moment und senden Sie die Anfrage erneut.'];
        }
    } elseif (!empty($context['is_fetch'])) {
        return ['ok' => false, 'status' => 400, 'error' => 'Ungültige Anfrage. Bitte laden Sie die Seite neu.'];
    }

    $email = trim((string) ($data['email'] ?? ''));
    $message = trim(str_replace("\r\n", "\n", (string) ($data['message'] ?? '')));
    $topic = trim((string) ($data['topic'] ?? ''));
    $page = trim((string) ($data['page'] ?? ''));

    if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email)) {
        return ['ok' => false, 'status' => 422, 'error' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.'];
    }
    $len = mb_strlen($message, 'UTF-8');
    if ($len < 10 || $len > 5000 || !mb_check_encoding($message, 'UTF-8')) {
        return ['ok' => false, 'status' => 422, 'error' => 'Die Nachricht muss zwischen 10 und 5000 Zeichen lang sein.'];
    }
    if (!in_array($topic, INQUIRY_TOPICS, true)) {
        return ['ok' => false, 'status' => 422, 'error' => 'Ungültiges Thema.'];
    }
    if ($page === '' || !preg_match('#^https?://#i', $page)) {
        $page = (string) ($context['referer'] ?? '');
    }
    $page = mb_substr(header_safe($page), 0, 300, 'UTF-8');
    if ($page !== '' && filter_var($page, FILTER_VALIDATE_URL) === false) {
        $page = '';
    }

    if (!rate_limit_allows((string) $context['ip'], $config)) {
        return ['ok' => false, 'status' => 429, 'error' => 'Sie haben in kurzer Zeit sehr viele Anfragen gesendet. Bitte versuchen Sie es später erneut oder schreiben Sie an service@luminelli.ch.'];
    }

    $inquiry = ['email' => $email, 'message' => $message, 'topic' => $topic, 'page' => $page];
    // Future: store $inquiry in a database here.

    try {
        $sent = send_mail(build_mail($inquiry, $config), $config);
    } catch (Throwable $e) {
        error_log('[inquiry] ' . $e->getMessage());
        $sent = false;
    }
    if (!$sent) {
        return ['ok' => false, 'status' => 500, 'error' => 'Ihre Anfrage konnte leider nicht gesendet werden. Bitte versuchen Sie es später erneut oder schreiben Sie direkt an service@luminelli.ch.'];
    }

    return ['ok' => true, 'status' => 200];
}

/* ---------- HTTP entry point ---------- */

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $isFetch = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $result = handle_inquiry($_POST, [
        'ip' => client_ip(),
        'referer' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
        'config' => load_config(),
        'is_fetch' => $isFetch,
    ]);

    if ($isFetch) {
        http_response_code($result['status']);
        header('Content-Type: application/json; charset=utf-8');
        $out = ['ok' => $result['ok']];
        if (!$result['ok']) {
            $out['error'] = $result['error'];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // No-JS fallback: redirect back to the page (relative, works in a subfolder and at the root).
    $target = $result['ok'] ? '../?anfrage=gesendet#anfrage-gesendet' : '../?anfrage=fehler#anfrage-fehler';
    header('Location: ' . $target, true, 303);
    exit;
}
