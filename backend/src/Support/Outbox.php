<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;
use Sacco\Tenant;

/**
 * The email copies of members' messages. Notify queues them; they go out a few at a time in the
 * background: after the reply when the server allows it, on the bell's next check, and from the
 * daily run or bin/send-mail.php. A failed email is tried again (three tries in all).
 */
final class Outbox
{
    private const TRIES = 3;
    private static bool $scheduled = false;

    /** Sends the queue once this request's reply has gone, where the server allows that */
    public static function afterReply(): void
    {
        if (self::$scheduled || !function_exists('fastcgi_finish_request')) {
            return;
        }
        self::$scheduled = true;
        $sacco = Tenant::current();
        register_shutdown_function(function () use ($sacco) {
            fastcgi_finish_request();
            if ($sacco) {
                Tenant::actAs($sacco, fn (PDO $db) => self::flush($db));
            }
        });
    }

    /** Sends up to $limit queued emails; returns how many went */
    public static function flush(PDO $db, int $limit = 15): int
    {
        if (!Mailer::enabled()) {
            return 0;
        }
        // Emails stuck "sending" (a request that died half way) go back in the queue
        $db->exec("UPDATE notifications SET email_status = 'queued', email_claim = NULL WHERE email_status = 'sending' AND emailed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $claim = bin2hex(random_bytes(8));
        $take = $db->prepare(
            "UPDATE notifications SET email_status = 'sending', email_claim = ?, emailed_at = NOW()
             WHERE email_status = 'queued' OR (email_status = 'failed' AND email_tries < " . self::TRIES . " AND emailed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
             ORDER BY id LIMIT " . max(1, $limit)
        );
        $take->execute([$claim]);
        if ($take->rowCount() === 0) {
            return 0;
        }
        $rows = $db->prepare('SELECT n.id, n.title, n.body, n.link, u.email, u.full_name FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.email_claim = ?');
        $rows->execute([$claim]);
        $sent = 0;
        foreach ($rows->fetchAll() as $n) {
            try {
                [$html, $text] = self::render($n['title'], $n['body'], $n['link'], $n['full_name']);
                Mailer::send((string) $n['email'], $n['full_name'], $n['title'], $html, $text);
                $db->prepare("UPDATE notifications SET email_status = 'sent', email_claim = NULL, email_tries = email_tries + 1, emailed_at = NOW(), email_error = NULL WHERE id = ?")->execute([$n['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $db->prepare("UPDATE notifications SET email_status = 'failed', email_claim = NULL, email_tries = email_tries + 1, email_error = ? WHERE id = ?")
                    ->execute([mb_substr($e->getMessage(), 0, 255), $n['id']]);
                error_log('[sacco] email ' . $n['id'] . ' failed: ' . $e->getMessage());
            }
        }
        return $sent;
    }

    /**
     * One message as an email in the SACCO's colours: [html, plain text]
     *
     * @param string|null $link a page in the app (/loans/3) or a full address
     */
    public static function render(string $title, string $body, ?string $link, string $name, string $button = 'Open in the app'): array
    {
        $sacco = Tenant::current() ?? [];
        $saccoName = $sacco['name'] ?? 'SACCO';
        $colour = preg_match('/^#[0-9a-f]{6}$/i', $sacco['brand_color'] ?? '') ? $sacco['brand_color'] : '#2b35af';
        $url = $link ? (preg_match('#^https?://#', $link) ? $link : Mailer::appUrl() . $link) : Mailer::appUrl();
        $first = explode(' ', trim($name))[0] ?: $name;
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $paragraphs = implode('', array_map(fn ($p) => '<p style="margin:0 0 14px;font-size:15px;line-height:1.55;color:#33363f">' . nl2br($e($p)) . '</p>', preg_split('/\n{2,}/', trim($body))));

        $html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$e($title)}</title></head>
<body style="margin:0;padding:0;background:#f4f4f6;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f6;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6e6ea">
<tr><td style="background:{$colour};padding:18px 24px;color:#ffffff;font-size:17px;font-weight:600">{$e($saccoName)}</td></tr>
<tr><td style="height:3px;background:#eea90f;font-size:0;line-height:0">&nbsp;</td></tr>
<tr><td style="padding:26px 24px 8px">
<p style="margin:0 0 6px;font-size:14px;color:#6b6e78">Dear {$e($first)},</p>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#15181f">{$e($title)}</h1>
{$paragraphs}
<p style="margin:22px 0 8px"><a href="{$e($url)}" style="display:inline-block;background:{$colour};color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 20px;border-radius:9px">{$e($button)}</a></p>
</td></tr>
<tr><td style="padding:18px 24px 22px;border-top:1px solid #eeeef1;font-size:12px;line-height:1.5;color:#8a8d96">
This message is also in your Messages in the {$e($saccoName)} app. You get these emails because your email is on your SACCO account; you can turn them off in My profile.
</td></tr>
</table></td></tr></table>
</body></html>
HTML;
        $text = "$saccoName\n\nDear $first,\n\n$title\n\n" . trim($body) . "\n\n$button: $url\n\n--\nThis message is also in your Messages in the $saccoName app. You can turn these emails off in My profile.\n";
        return [$html, $text];
    }
}
