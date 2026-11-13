<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Reminders;
use Sacco\Support\Mailer;
use Sacco\Support\Outbox;

/**
 * A member's messages from the SACCO: the bell, and the Messages page that shows them like text
 * messages on a phone. Each can also go to their email.
 */
final class NotificationController
{
    /** GET /api/me/notifications?before=&limit= - newest first; "before" pages back */
    public function index(Request $request): never
    {
        // The day's reminders go out on the first look at the bell each day, and emails waiting
        // in the queue go out a few at a time
        try {
            Reminders::runIfDue();
            // On the first look of a new month: last month's statement to every member (when turned on)
            \Sacco\Loans\Statements::runIfDue(Database::tenant());
            Outbox::flush(Database::tenant(), 10);
        } catch (\Throwable $e) {
            error_log('Daily reminders or emails failed: ' . $e->getMessage());
        }
        $db = Database::tenant();
        $limit = max(1, min(100, (int) $request->query('limit', 50)));
        $before = (int) $request->query('before', 0);
        $stmt = $db->prepare(
            'SELECT id, title, body, link, kind, read_at, created_at, email_status, emailed_at FROM notifications
             WHERE user_id = ?' . ($before > 0 ? ' AND id < ?' : '') . " ORDER BY id DESC LIMIT $limit"
        );
        $stmt->execute($before > 0 ? [Auth::id(), $before] : [Auth::id()]);
        $rows = $stmt->fetchAll();
        $unread = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $unread->execute([Auth::id()]);
        Response::ok(['notifications' => $rows, 'unread' => (int) $unread->fetchColumn(), 'more' => count($rows) === $limit]);
    }

    /** POST /api/me/notifications/read { ids? } - marks the given ones, or all, as read */
    public function read(Request $request): never
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $db = Database::tenant();
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN ($in)")->execute([Auth::id(), ...$ids]);
        } else {
            $db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([Auth::id()]);
        }
        Response::ok();
    }

    /** GET /api/me/message-settings - whether copies go to their email */
    public function settings(Request $request): never
    {
        $user = Auth::user();
        Response::ok([
            'email' => $user['email'],
            'email_alerts' => (bool) $user['email_alerts'],
            'email_working' => Mailer::enabled(),
        ]);
    }

    /** PATCH /api/me/message-settings { email_alerts } */
    public function saveSettings(Request $request): never
    {
        $on = filter_var($request->input('email_alerts'), FILTER_VALIDATE_BOOLEAN);
        Database::tenant()->prepare('UPDATE users SET email_alerts = ? WHERE id = ?')->execute([$on ? 1 : 0, Auth::id()]);
        Auth::refresh();
        $this->settings($request);
    }
}
