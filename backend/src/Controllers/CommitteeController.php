<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Settings;

/**
 * Questions waiting for the committee. Each says what the system does meanwhile (the rule it is
 * tied to, read live from Setup), and once decided, what was decided, at which meeting, and the
 * value that was applied. Everyone in the office can see it; the super admin keeps it.
 */
final class CommitteeController
{
    /** GET /api/committee (admin) */
    public function index(Request $request): never
    {
        $rows = Database::tenant()->query(
            "SELECT q.*, d.full_name AS decided_by_name FROM committee_questions q LEFT JOIN users d ON d.id = q.decided_by
             ORDER BY q.status = 'decided', q.decided_on DESC, q.id"
        )->fetchAll();
        Response::ok(['questions' => array_map(fn ($q) => self::present($q), $rows)]);
    }

    /** POST /api/committee { topic, question, setting_key? } (super admin) */
    public function store(Request $request): never
    {
        $data = self::validated($request);
        $db = Database::tenant();
        $db->prepare('INSERT INTO committee_questions (topic, question, setting_key, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$data['topic'], $data['question'], $data['setting_key'], Auth::id()]);
        $id = (int) $db->lastInsertId();
        Audit::log('committee.added', 'committee_question', $id, ['topic' => $data['topic']]);
        Response::ok(['question' => self::present(self::find($id))], 201);
    }

    /** PATCH /api/committee/{id} { topic, question, setting_key? } (super admin) */
    public function update(Request $request): never
    {
        $q = self::find((int) $request->param('id'));
        $data = self::validated($request);
        Database::tenant()->prepare('UPDATE committee_questions SET topic = ?, question = ?, setting_key = ? WHERE id = ?')
            ->execute([$data['topic'], $data['question'], $data['setting_key'], $q['id']]);
        Audit::log('committee.changed', 'committee_question', (int) $q['id'], ['topic' => $data['topic']]);
        Response::ok(['question' => self::present(self::find((int) $q['id']))]);
    }

    /**
     * POST /api/committee/{id}/decide { decision, meeting?, decided_on?, value? } (super admin)
     * With a value, the tied rule on Setup changes to it at the same time.
     */
    public function decide(Request $request): never
    {
        $q = self::find((int) $request->param('id'));
        $decision = trim($request->string('decision'));
        $meeting = mb_substr(trim($request->string('meeting')), 0, 160) ?: null;
        $on = $request->string('decided_on') ?: date('Y-m-d');
        $value = trim((string) $request->input('value', ''));
        $errors = [];
        if ($decision === '') {
            $errors['decision'] = 'Write what the committee decided.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > date('Y-m-d')) {
            $errors['decided_on'] = 'Not a valid date.';
        }
        if ($errors) {
            Response::error('Please fix the highlighted fields.', 422, $errors);
        }
        $before = $q['setting_key'] ? Settings::get($q['setting_key']) : null;
        if ($q['setting_key'] && $value !== '') {
            if ($bad = SettingsController::apply([$q['setting_key'] => $value])) {
                Response::error('That value isn\'t valid for this rule.', 422, ['value' => reset($bad)]);
            }
        }
        $applied = $q['setting_key'] && $value !== '' ? Settings::get($q['setting_key']) : null;
        Database::tenant()->prepare(
            "UPDATE committee_questions SET status = 'decided', decision = ?, decided_value = ?, meeting = ?, decided_on = ?, decided_by = ? WHERE id = ?"
        )->execute([$decision, $applied, $meeting, $on, Auth::id(), $q['id']]);
        // The office hears about it through the activity message (Audit), with the decision and the rule
        $rule = $applied !== null ? SettingsController::label($q['setting_key']) . ' is now ' . self::words($q['setting_key'], $applied)
            . ($before !== $applied ? ' (was ' . self::words($q['setting_key'], (string) $before) . ')' : ' (unchanged)') : null;
        Audit::log('committee.decided', 'committee_question', (int) $q['id'], ['topic' => $q['topic'], 'decision' => $decision, 'meeting' => $meeting, 'rule' => $rule]);
        Response::ok(['question' => self::present(self::find((int) $q['id']))]);
    }

    /** POST /api/committee/{id}/reopen (super admin) - back on the list; the rule stays as it is */
    public function reopen(Request $request): never
    {
        $q = self::find((int) $request->param('id'));
        Database::tenant()->prepare("UPDATE committee_questions SET status = 'open', decision = NULL, decided_value = NULL, meeting = NULL, decided_on = NULL, decided_by = NULL WHERE id = ?")
            ->execute([$q['id']]);
        Audit::log('committee.reopened', 'committee_question', (int) $q['id'], ['topic' => $q['topic']]);
        Response::ok(['question' => self::present(self::find((int) $q['id']))]);
    }

    /** DELETE /api/committee/{id} (super admin; open questions only) */
    public function destroy(Request $request): never
    {
        $q = self::find((int) $request->param('id'));
        if ($q['status'] === 'decided') {
            Response::error('A decided question stays on record. Reopen it first if it was a mistake.', 422);
        }
        Database::tenant()->prepare('DELETE FROM committee_questions WHERE id = ?')->execute([$q['id']]);
        Audit::log('committee.deleted', 'committee_question', (int) $q['id'], ['topic' => $q['topic']]);
        Response::ok();
    }

    private static function present(array $q): array
    {
        $key = $q['setting_key'];
        return [
            'id' => (int) $q['id'],
            'topic' => $q['topic'],
            'question' => $q['question'],
            'status' => $q['status'],
            'setting_key' => $key,
            // What the system does while the committee decides, and its choices if it has some
            'setting_label' => $key ? SettingsController::label($key) : null,
            'current_value' => $key ? Settings::get($key) : null,
            'current_words' => $key ? self::words($key, (string) Settings::get($key)) : null,
            'options' => $key ? SettingsController::options($key) : null,
            'decision' => $q['decision'],
            'decided_value' => $q['decided_value'],
            'decided_words' => $key && $q['decided_value'] !== null ? self::words($key, $q['decided_value']) : null,
            'meeting' => $q['meeting'],
            'decided_on' => $q['decided_on'],
            'decided_by' => $q['decided_by_name'] ?? null,
            'created_at' => $q['created_at'],
        ];
    }

    /** A rule's value as people say it: a choice's words, or the number */
    private static function words(string $key, string $value): string
    {
        return SettingsController::options($key)[$value] ?? (is_numeric($value) ? number_format((float) $value, str_contains($value, '.') ? 2 : 0) : $value);
    }

    private static function validated(Request $request): array
    {
        $topic = mb_substr(trim($request->string('topic')), 0, 120);
        $question = trim($request->string('question'));
        $key = trim($request->string('setting_key')) ?: null;
        $errors = [];
        if ($topic === '') {
            $errors['topic'] = 'Give it a short name.';
        }
        if ($question === '') {
            $errors['question'] = 'Write the question for the committee.';
        }
        if ($key !== null && !SettingsController::has($key)) {
            $errors['setting_key'] = 'Not one of the SACCO\'s rules.';
        }
        if ($errors) {
            Response::error('Please fix the highlighted fields.', 422, $errors);
        }
        return ['topic' => $topic, 'question' => $question, 'setting_key' => $key];
    }

    private static function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT q.*, d.full_name AS decided_by_name FROM committee_questions q LEFT JOIN users d ON d.id = q.decided_by WHERE q.id = ?');
        $stmt->execute([$id]);
        $q = $stmt->fetch();
        if (!$q) {
            Response::error('That question doesn\'t exist.', 404);
        }
        return $q;
    }
}
