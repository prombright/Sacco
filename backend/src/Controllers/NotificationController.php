<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;

/** A member's messages from the SACCO (the bell) */
final class NotificationController
{
    /** GET /api/me/notifications */
    public function index(Request $request): never
    {
        $db = Database::tenant();
        $stmt = $db->prepare('SELECT id, title, body, link, kind, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50');
        $stmt->execute([Auth::id()]);
        $unread = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $unread->execute([Auth::id()]);
        Response::ok(['notifications' => $stmt->fetchAll(), 'unread' => (int) $unread->fetchColumn()]);
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
}
