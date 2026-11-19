<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Notify;

/**
 * Members write to the SACCO office and the super admins answer: one conversation per member.
 * Members see the office's answers as from "SACCO office" (not who wrote them); inside the office
 * the inbox shows who answered. The super admins hear of each new message, the member of each answer.
 */
final class OfficeChatController
{
    private const MAX = 2000;

    /** GET /api/me/office-chat (member) - their conversation with the office; the office's answers are now read */
    public function mine(Request $request): never
    {
        $me = $this->member();
        $db = Database::tenant();
        $db->prepare('UPDATE office_messages SET read_at = NOW() WHERE member_id = ? AND from_office = 1 AND read_at IS NULL')->execute([$me['id']]);
        Response::ok(['messages' => $this->thread((int) $me['id'], true)]);
    }

    /** POST /api/me/office-chat { body } (member) */
    public function send(Request $request): never
    {
        $me = $this->member();
        $body = $this->body($request);
        $db = Database::tenant();
        $recent = $db->prepare('SELECT COUNT(*) FROM office_messages WHERE member_id = ? AND from_office = 0 AND created_at > NOW() - INTERVAL 1 HOUR');
        $recent->execute([$me['id']]);
        if ((int) $recent->fetchColumn() >= 20) {
            Response::error('You have sent many messages in the last hour. The office will answer; please wait a little.', 429);
        }
        $db->prepare('INSERT INTO office_messages (member_id, sender_id, from_office, body) VALUES (?, ?, 0, ?)')->execute([$me['id'], $me['id'], $body]);
        // Every active super admin hears of it (not the writer, if they are one through office access)
        $supers = $db->prepare("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active' AND COALESCE(linked_member_id, 0) <> ?");
        $supers->execute([$me['id']]);
        foreach ($supers->fetchAll(\PDO::FETCH_COLUMN) as $sid) {
            Notify::member((int) $sid, 'member_message', 'Message from ' . $me['full_name'], mb_strimwidth($body, 0, 160, '…'), '/admin/inbox?member=' . $me['id']);
        }
        Response::ok(['messages' => $this->thread((int) $me['id'], true)], 201);
    }

    /** GET /api/office-chat (super admin) - every conversation, newest first, with what is unread */
    public function inbox(Request $request): never
    {
        $db = Database::tenant();
        $rows = $db->query(
            "SELECT m.member_id, u.full_name, u.account_no, u.photo,
                    MAX(m.id) AS last_id, MAX(m.created_at) AS last_at, COUNT(*) AS total,
                    SUM(m.from_office = 0 AND m.read_at IS NULL) AS unread
             FROM office_messages m JOIN users u ON u.id = m.member_id
             GROUP BY m.member_id, u.full_name, u.account_no, u.photo ORDER BY last_id DESC"
        )->fetchAll();
        $last = $db->prepare('SELECT body, from_office FROM office_messages WHERE id = ?');
        $out = [];
        foreach ($rows as $r) {
            // A super admin's own conversation (as a member) is answered by the others
            if (Auth::isMe((int) $r['member_id'])) {
                continue;
            }
            $last->execute([$r['last_id']]);
            $l = $last->fetch();
            $out[] = [
                'member_id' => (int) $r['member_id'], 'full_name' => $r['full_name'], 'account_no' => $r['account_no'],
                'photo_url' => $r['photo'] ? "api/users/{$r['member_id']}/photo?v={$r['photo']}" : null,
                'last' => mb_strimwidth((string) $l['body'], 0, 120, '…'), 'last_from_office' => (bool) $l['from_office'],
                'last_at' => $r['last_at'], 'total' => (int) $r['total'], 'unread' => (int) $r['unread'],
            ];
        }
        Response::ok(['conversations' => $out]);
    }

    /** GET /api/office-chat/{member} (super admin) - one conversation; the member's messages are now read */
    public function show(Request $request): never
    {
        $member = $this->findMember((int) $request->param('member'));
        Database::tenant()->prepare('UPDATE office_messages SET read_at = NOW() WHERE member_id = ? AND from_office = 0 AND read_at IS NULL')->execute([$member['id']]);
        Response::ok(['member' => ['id' => (int) $member['id'], 'full_name' => $member['full_name'], 'account_no' => $member['account_no'], 'phone' => $member['phone']],
            'messages' => $this->thread((int) $member['id'], false)]);
    }

    /** POST /api/office-chat/{member} { body } (super admin) - an answer; the member is told */
    public function reply(Request $request): never
    {
        $member = $this->findMember((int) $request->param('member'));
        if (Auth::isMe((int) $member['id'])) {
            Response::error('This is your own conversation: another super admin answers it.', 403);
        }
        $body = $this->body($request);
        Database::tenant()->prepare('INSERT INTO office_messages (member_id, sender_id, from_office, body) VALUES (?, ?, 1, ?)')->execute([$member['id'], Auth::id(), $body]);
        Notify::member((int) $member['id'], 'office_reply', 'The SACCO office answered you', mb_strimwidth($body, 0, 160, '…'), '/office-chat');
        Response::ok(['messages' => $this->thread((int) $member['id'], false)], 201);
    }

    /** The conversation, oldest first; for the member, the office's side is just "SACCO office" */
    private function thread(int $memberId, bool $forMember): array
    {
        $s = Database::tenant()->prepare(
            'SELECT m.id, m.from_office, m.body, m.created_at, m.read_at, ' . Roles::shownNameSql('u') . ' AS sender
             FROM office_messages m JOIN users u ON u.id = m.sender_id WHERE m.member_id = ? ORDER BY m.id DESC LIMIT 300'
        );
        $s->execute([$memberId]);
        return array_reverse(array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'from_office' => (bool) $r['from_office'],
            'body' => $r['body'],
            'at' => $r['created_at'],
            'read' => $r['read_at'] !== null,
            'sender' => $r['from_office'] ? ($forMember ? 'SACCO office' : $r['sender']) : $r['sender'],
        ], $s->fetchAll()));
    }

    private function body(Request $request): string
    {
        $body = trim(str_replace("\r\n", "\n", (string) $request->input('body', '')));
        if ($body === '') {
            Response::error('Write a message first.', 422, ['body' => 'Required']);
        }
        if (mb_strlen($body) > self::MAX) {
            Response::error('That message is too long (at most ' . self::MAX . ' characters).', 422, ['body' => 'Too long']);
        }
        return $body;
    }

    private function member(): array
    {
        $me = Auth::user();
        if ($me['role'] !== Roles::MEMBER) {
            Response::error('Only members write to the office from here.', 403);
        }
        return $me;
    }

    private function findMember(int $id): array
    {
        $s = Database::tenant()->prepare("SELECT id, full_name, account_no, phone FROM users WHERE id = ? AND role = 'member'");
        $s->execute([$id]);
        return $s->fetch() ?: Response::error('Member not found.', 404);
    }
}
