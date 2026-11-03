<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;

/** Notices from the office (AGM dates, new rules, holidays), shown on every member's dashboard */
final class NoticeController
{
    /** GET /api/notices/current - what members see now */
    public function current(Request $request): never
    {
        $rows = Database::tenant()->query(
            'SELECT id, title, body, starts_on, ends_on FROM notices
             WHERE starts_on <= CURDATE() AND (ends_on IS NULL OR ends_on >= CURDATE()) ORDER BY starts_on DESC, id DESC LIMIT 5'
        )->fetchAll();
        Response::ok(['notices' => $rows]);
    }

    /** GET /api/notices (admin) - all of them */
    public function index(Request $request): never
    {
        $rows = Database::tenant()->query(
            'SELECT n.*, u.full_name AS created_by_name FROM notices n LEFT JOIN users u ON u.id = n.created_by ORDER BY n.starts_on DESC, n.id DESC LIMIT 100'
        )->fetchAll();
        Response::ok(['notices' => $rows]);
    }

    /** POST /api/notices { title, body, starts_on, ends_on? } (admin) */
    public function store(Request $request): never
    {
        $title = mb_substr($request->string('title'), 0, 120);
        $body = mb_substr($request->string('body'), 0, 5000);
        if ($title === '' || $body === '') {
            Response::error('Give the notice a title and some text.', 422);
        }
        $starts = $request->string('starts_on') ?: date('Y-m-d');
        $ends = $request->string('ends_on') ?: null;
        foreach (array_filter([$starts, $ends]) as $d) {
            $p = \DateTime::createFromFormat('!Y-m-d', $d);
            if (!$p || $p->format('Y-m-d') !== $d) {
                Response::error('Enter valid dates.', 422);
            }
        }
        if ($ends && $ends < $starts) {
            Response::error('The notice can\'t end before it starts.', 422);
        }
        $db = Database::tenant();
        $db->prepare('INSERT INTO notices (title, body, starts_on, ends_on, created_by) VALUES (?, ?, ?, ?, ?)')->execute([$title, $body, $starts, $ends, Auth::id()]);
        $id = (int) $db->lastInsertId();
        Audit::log('notice.posted', 'notice', $id, ['title' => $title]);
        Response::ok(['id' => $id], 201);
    }

    /** DELETE /api/notices/{id} (admin) - take a notice down early */
    public function end(Request $request): never
    {
        Database::tenant()->prepare('UPDATE notices SET ends_on = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?')->execute([(int) $request->param('id')]);
        Audit::log('notice.ended', 'notice', (int) $request->param('id'));
        Response::ok();
    }
}
