<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Config;

/**
 * Sends email, with no extra library. MAIL_DRIVER in backend/.env picks how:
 *
 *  - smtp: through a mailbox's server (cPanel -> Email Accounts -> Connect devices shows the
 *          server and port); MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION
 *  - mail: PHP's mail(), which most cPanel hosts accept without settings
 *  - log:  writes each email to backend/storage/mail/ instead of sending (the default locally)
 *  - off:  no email at all
 */
final class Mailer
{
    public static function driver(): string
    {
        $driver = strtolower((string) Config::get('MAIL_DRIVER', ''));
        if (in_array($driver, ['smtp', 'mail', 'log', 'off'], true)) {
            return $driver;
        }
        return Config::get('MAIL_HOST') ? 'smtp' : (Config::isProduction() ? 'mail' : 'log');
    }

    public static function enabled(): bool
    {
        return self::driver() !== 'off';
    }

    /** @throws \RuntimeException when the email can't be sent */
    public static function send(string $to, string $name, string $subject, string $html, string $text): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("Not an email address: $to");
        }
        $from = (string) Config::get('MAIL_FROM_ADDRESS', 'no-reply@' . (parse_url(self::appUrl(), PHP_URL_HOST) ?: 'localhost'));
        $fromName = (string) Config::get('MAIL_FROM_NAME', \Sacco\Tenant::current()['name'] ?? 'SACCO');
        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::address($from, $fromName),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@') ?: '@sacco', 1) . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";
        $encodedSubject = self::encode($subject);

        switch (self::driver()) {
            case 'off':
                return;
            case 'log':
                $dir = __DIR__ . '/../../storage/mail';
                if (!is_dir($dir)) {
                    mkdir($dir, 0700, true);
                }
                $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
                file_put_contents($file, implode("\r\n", [...$headers, 'To: ' . self::address($to, $name), "Subject: $encodedSubject"]) . "\r\n\r\n" . $body);
                return;
            case 'mail':
                if (!mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $from)) {
                    throw new \RuntimeException('The server\'s mail() refused the email.');
                }
                return;
            default:
                self::smtp($from, $to, implode("\r\n", [...$headers, 'To: ' . self::address($to, $name), "Subject: $encodedSubject"]) . "\r\n\r\n" . $body);
        }
    }

    /** The app's address for links in emails, e.g. https://sacco.stmark.sc.ug */
    public static function appUrl(): string
    {
        return rtrim((string) Config::get('APP_URL', 'http://localhost/sacco'), '/');
    }

    private static function smtp(string $from, string $to, string $message): void
    {
        $host = (string) Config::get('MAIL_HOST');
        $port = (int) Config::get('MAIL_PORT', 587);
        $encryption = strtolower((string) Config::get('MAIL_ENCRYPTION', $port === 465 ? 'ssl' : 'tls'));
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new \RuntimeException("Can't reach the mail server $host:$port ($error).");
        }
        stream_set_timeout($socket, 20);
        $talk = function (?string $line, array $expect) use ($socket): string {
            if ($line !== null) {
                fwrite($socket, $line . "\r\n");
            }
            $reply = '';
            while (($row = fgets($socket, 1024)) !== false) {
                $reply .= $row;
                if (strlen($row) < 4 || $row[3] === ' ') {
                    break;
                }
            }
            if (!in_array((int) substr($reply, 0, 3), $expect, true)) {
                // Never put the password in the error
                $said = $line !== null && str_starts_with($line, 'AUTH') ? 'AUTH' : ($line ?? 'connect');
                throw new \RuntimeException('Mail server said: ' . trim($reply ?: 'nothing') . " (after $said)");
            }
            return $reply;
        };
        try {
            $me = parse_url(self::appUrl(), PHP_URL_HOST) ?: 'localhost';
            $talk(null, [220]);
            $talk("EHLO $me", [250]);
            if ($encryption === 'tls') {
                $talk('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Couldn\'t start a secure connection to the mail server.');
                }
                $talk("EHLO $me", [250]);
            }
            if ($user = (string) Config::get('MAIL_USERNAME', '')) {
                $talk('AUTH LOGIN', [334]);
                $talk(base64_encode($user), [334]);
                $talk(base64_encode((string) Config::get('MAIL_PASSWORD', '')), [235]);
            }
            $talk("MAIL FROM:<$from>", [250]);
            $talk("RCPT TO:<$to>", [250, 251]);
            $talk('DATA', [354]);
            // A line starting with a dot gets a second one, so it isn't read as the end
            $talk(preg_replace('/^\./m', '..', $message) . "\r\n.", [250]);
            $talk('QUIT', [221, 250]);
        } finally {
            fclose($socket);
        }
    }

    private static function address(string $email, string $name): string
    {
        $name = trim(str_replace(["\r", "\n", '"'], '', $name));
        return $name === '' ? "<$email>" : self::encode($name) . " <$email>";
    }

    private static function encode(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }
}

