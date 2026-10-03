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

require __DIR__ . '/lib/common.php';

/** Build the notification mail for one validated inquiry. */
function build_mail(array $inquiry, array $config): array
{
    $topic = $inquiry['topic'] !== '' ? $inquiry['topic'] : 'Allgemein';
    $date = new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich'));
    return compose_mail([
        'to' => (string) $config['to'],
        'reply_to' => $inquiry['email'],
        'subject' => 'Neue Anfrage über luminelli.ch: ' . $topic,
        'tag' => 'inquiry',
        'body' => implode("\n", [
            'Thema:          ' . $topic,
            'E-Mail:         ' . $inquiry['email'],
            'Datum/Uhrzeit:  ' . $date->format('d.m.Y H:i') . ' (Europe/Zurich)',
            'Herkunftsseite: ' . ($inquiry['page'] !== '' ? $inquiry['page'] : 'unbekannt'),
            '',
            'Nachricht:',
            '----------',
            $inquiry['message'],
            '',
        ]),
    ], $config);
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
        app_log('[inquiry] ' . get_class($e) . ': ' . $e->getMessage());
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
