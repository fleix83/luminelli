<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (STARTTLS / implicit TLS, AUTH LOGIN) for sending
 * a single plain-text message. Deliberately small; replace with PHPMailer or
 * the real backend's mailer when the project grows.
 */
final class SmtpMailer
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(private array $cfg)
    {
    }

    /**
     * @param string $from     envelope sender
     * @param string $to       envelope recipient
     * @param string $message  full message incl. headers, CRLF line endings
     */
    public function send(string $from, string $to, string $message): void
    {
        $host = (string) $this->cfg['host'];
        $port = (int) ($this->cfg['port'] ?? 587);
        $enc = (string) ($this->cfg['encryption'] ?? 'tls');
        $timeout = (int) ($this->cfg['timeout'] ?? 15);

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($socket === false) {
            throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect(220);
            $ehloHost = gethostname() ?: 'localhost';
            $this->command("EHLO $ehloHost", 250);

            if ($enc === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS failed');
                }
                $this->command("EHLO $ehloHost", 250);
            }

            if (($this->cfg['username'] ?? '') !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode((string) $this->cfg['username']), 334);
                $this->command(base64_encode((string) $this->cfg['password']), 235);
            }

            $this->command("MAIL FROM:<$from>", 250);
            $this->command("RCPT TO:<$to>", [250, 251]);
            $this->command('DATA', 354);

            // Dot-stuffing (RFC 5321 4.5.2)
            $data = preg_replace('/^\./m', '..', $message);
            $this->write($data . "\r\n.\r\n");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    private function write(string $data): void
    {
        if (fwrite($this->socket, $data) === false) {
            throw new RuntimeException('SMTP write failed');
        }
    }

    /** @param int|int[] $codes */
    private function command(string $line, int|array $codes): string
    {
        $this->write($line . "\r\n");
        return $this->expect($codes);
    }

    /** @param int|int[] $codes */
    private function expect(int|array $codes): string
    {
        $codes = (array) $codes;
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-", the last line "250 ".
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP unexpected reply: ' . trim($response));
        }
        return $response;
    }
}
