<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_oksigeniaeguahe\local;

/**
 * A bridge with what the centre already uses, in the panel, whatever it looks like: the script of Google for a form and
 * for a sheet; the file of Meta, with each of its columns before anything comes in; the mailbox, with where to forward
 * and the last mails; any other tool, with its door, its key and an example. In all of them, the questions that came
 * with an example of what they bring and what Eguahe reads in each. Also what each button posts.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_bridge {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int How many of the last mails are shown. */
    const LASTMAILS = 3;

    /**
     * The file of contacts given and not brought yet, in the draft area of whoever gave it.
     *
     * @param int $draft
     * @return \stored_file|null
     */
    public static function file(int $draft): ?\stored_file {
        global $USER;
        if (!$draft) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draft,
            'id DESC',
            false
        );
        return reset($files) ?: null;
    }

    /**
     * The context of the page.
     *
     * @param \stdClass $entry
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $draft A file of Meta given and not brought yet.
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $entry, \stdClass $centre, bool $canact, int $draft = 0, int $now = 0): array {
        $c = self::C;
        $now = $now ?: time();
        $kind = (string) $entry->kind;
        $action = (new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id]))->out(false);
        $out = [
            'head' => panel::head([
                'title' => format_string($entry->name),
                'ante' => get_string('panel_bridge_ante', $c, (object) ['kind' => get_string('entry_kind_' . $kind, $c),
                    'state' => get_string(!empty($entry->active) ? 'panel_bridge_open' : 'panel_bridge_closed', $c)]),
                'sub' => get_string('panel_bridge_sub_' . $kind, $c),
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]))->out(false),
                    'name' => get_string('tab_entries', $c)],
            ]),
            'action' => $action,
            'sesskey' => sesskey(),
            'canact' => $canact,
            'active' => !empty($entry->active),
            'isgoogle' => $kind === 'google',
            'ismeta' => $kind === 'meta',
            'isemail' => $kind === 'email',
            'ishook' => $kind === 'hook',
            'settings' => ['name' => (string) $entry->name, 'active' => !empty($entry->active),
                'acknowledge' => !empty($entry->acknowledge)],
            'deletequestion' => get_string('panel_erase_bridge', $c, format_string($entry->name)),
        ];
        if ($kind === 'google') {
            $out['places'] = [];
            foreach (['form', 'sheet'] as $i => $where) {
                $out['places'][] = ['key' => $where, 'title' => get_string('entry_google_' . $where, $c),
                    'steps' => array_values(array_filter(array_map('trim', explode(
                        "\n",
                        get_string('panel_google_steps_' . $where, $c)
                    )))),
                    'script' => $canact ? entries::apps_script($entry, $where) : '', 'open' => $i === 0];
            }
            $out['keynote'] = get_string('panel_google_key', $c);
        }
        if ($kind === 'meta') {
            $out['uploadhelp'] = get_string('entry_meta_help', $c);
            $out['preview'] = self::preview($entry, $draft);
        }
        if ($kind === 'email') {
            $out['address'] = (string) $entry->title;
            $out['forward'] = (string) ($centre->email ?: get_string('panel_mailbox_of_centre', $c));
            $out['steps'] = array_values(array_filter(array_map('trim', explode("\n", get_string(
                'panel_mail_steps',
                $c,
                (string) $entry->title
            )))));
            $out['last'] = self::last_mails($entry, $now);
            $out['haslast'] = (bool) $out['last'];
        }
        if ($kind === 'hook') {
            $out['url'] = entries::hook_url($entry)->out(false);
            $out['key'] = $canact ? (string) $entry->secret : '';
            $out['signhelp'] = get_string('entry_hook_help', $c);
            $out['example'] = json_encode(
                ['id' => 'respuesta-123', 'time' => date('Y-m-d\TH:i:s\Z', $now),
                'answers' => [get_string('panel_example_q_name', $c) => 'Marta Ruiz',
                    get_string('panel_example_q_phone', $c) => '+34 600 000 000',
                    get_string('panel_example_q_course', $c) => get_string('panel_example_a_course', $c)]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        $out['seen'] = self::seen($entry);
        $out['hasseen'] = (bool) $out['seen'];
        return $out;
    }

    /**
     * The questions that came, each with an example of what it brought and what Eguahe reads in it, to change.
     *
     * @param \stdClass $entry
     * @return array
     */
    public static function seen(\stdClass $entry): array {
        $c = self::C;
        $questions = entries::questions($entry);
        $roles = entries::roles($entry, $questions);
        $said = json_decode((string) $entry->roles, true) ?: [];
        $examples = entries::examples($entry);
        $out = [];
        foreach ($questions as $i => $question) {
            $role = $roles[$question] ?? null;
            $chosen = $said[recognise::plain((string) $question)] ?? '';
            $options = [['value' => '', 'name' => get_string('entry_role_auto', $c), 'selected' => $chosen === '']];
            foreach (entries::ROLES as $each) {
                $options[] = ['value' => $each, 'name' => get_string('entry_role_' . $each, $c), 'selected' => $chosen === $each];
            }
            // What says who someone is or how to reach them is never kept: its kind stands for it.
            $personal = in_array($role, entries::PERSONAL, true);
            $example = $personal ? get_string('panel_brings', $c, get_string('panel_example_' . $role, $c)) :
                (string) ($examples[$question] ?? '');
            $out[] = ['i' => $i, 'question' => $question, 'example' => $example, 'hasexample' => $example !== '',
                'literal' => !$personal,
                'now' => $role && in_array($role, entries::ROLES, true) ? get_string('entry_role_' . $role, $c) :
                    get_string(in_array($role, entries::DROPPED, true) ? 'entry_role_dropped' : 'entry_role_none', $c),
                'unknown' => !$role, 'options' => $options];
        }
        return $out;
    }

    /**
     * The file of Meta before bringing it: how many rows, and each column with the first thing it brings and what it is.
     *
     * @param \stdClass $entry
     * @param int $draft
     * @return array|null
     */
    protected static function preview(\stdClass $entry, int $draft): ?array {
        $file = self::file($draft);
        if (!$file) {
            return null;
        }
        $c = self::C;
        $path = $file->copy_content_to_temp('local_oksigeniaeguahe');
        try {
            [$rows, $headers] = entries::rows_of(sheets::read($path, $file->get_filename()));
        } finally {
            @unlink($path);
        }
        $roles = entries::roles($entry, $headers);
        $columns = [];
        foreach ($headers as $header) {
            $role = $roles[$header] ?? null;
            $first = '';
            foreach ($rows as $row) {
                if (trim((string) ($row[$header] ?? '')) !== '') {
                    $first = \core_text::substr(trim((string) $row[$header]), 0, 60);
                    break;
                }
            }
            $dropped = in_array($role, entries::DROPPED, true);
            $columns[] = ['header' => $header, 'example' => $first, 'dropped' => $dropped,
                'role' => $role && in_array($role, entries::ROLES, true) ? get_string('entry_role_' . $role, $c) :
                    get_string($dropped ? 'entry_role_dropped' : 'entry_role_none', $c)];
        }
        return ['filename' => $file->get_filename(), 'rows' => get_string('entry_meta_rows', $c, count($rows)),
            'columns' => $columns, 'draft' => $draft, 'hasrows' => (bool) $rows];
    }

    /**
     * The last mails that came into the mailbox: who wrote, the beginning of what they said and when.
     *
     * @param \stdClass $entry
     * @param int $now
     * @return array
     */
    protected static function last_mails(\stdClass $entry, int $now): array {
        global $DB;
        $rows = $DB->get_records_sql('SELECT c.id, c.householdid, c.body, c.timecreated
                                        FROM {' . households::CONTACTS . '} c
                                       WHERE c.entryid = ? AND c.direction = ?
                                    ORDER BY c.timecreated DESC, c.id DESC', [$entry->id, 'in'], 0, self::LASTMAILS);
        $out = [];
        foreach ($rows as $row) {
            $household = households::get((int) $row->householdid, (int) $entry->centreid);
            $from = $household ? panel::adults_line(households::full($household)->adults) : '';
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $row->body))));
            $said = $lines[1] ?? ($lines[0] ?? '');
            $out[] = ['from' => $from !== '' ? $from : get_string('panel_a_family', self::C),
                'subject' => \core_text::substr($said, 0, 70) . (\core_text::strlen($said) > 70 ? '…' : ''),
                'when' => panel::when_words((int) $row->timecreated, $now)];
        }
        return $out;
    }

    /**
     * Does what a button of the bridge posted, and says where to go after it.
     *
     * @param \stdClass $entry
     * @param \stdClass $centre
     * @return array [where to go, a message, its kind]
     */
    public static function act(\stdClass $entry, \stdClass $centre): array {
        $c = self::C;
        $url = new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id]);
        $draft = optional_param('draft', 0, PARAM_INT);
        $ok = \core\output\notification::NOTIFY_SUCCESS;
        switch (optional_param('action', '', PARAM_ALPHA)) {
            case 'roles':
                $questions = entries::questions($entry);
                $roles = [];
                foreach (optional_param_array('role', [], PARAM_ALPHA) as $i => $role) {
                    if (isset($questions[(int) $i])) {
                        $roles[$questions[(int) $i]] = $role;
                    }
                }
                entries::set_roles($entry, $roles);
                return [$url, get_string('changessaved'), $ok];
            case 'renew':
                entries::renew_secret($entry);
                return [$url, get_string('entry_renewed', $c), \core\output\notification::NOTIFY_WARNING];
            case 'upload':
                $draft = self::keep_upload();
                return $draft ? [new \moodle_url($url, ['draft' => $draft]), '', $ok] :
                    [$url, get_string('panel_file_not_taken', $c), \core\output\notification::NOTIFY_WARNING];
            case 'bring':
                $file = self::file($draft);
                if ($file && $entry->kind === 'meta') {
                    $path = $file->copy_content_to_temp('local_oksigeniaeguahe');
                    try {
                        [$rows] = entries::rows_of(sheets::read($path, $file->get_filename()));
                    } finally {
                        @unlink($path);
                    }
                    $counts = entries::from_rows($entry, $rows);
                    self::forget($draft);
                    return [$url, get_string('entry_brought', $c, (object) $counts), $ok];
                }
                return [$url, '', $ok];
            case 'cancel':
                self::forget($draft);
                return [$url, '', $ok];
            case 'settings':
                entries::update($entry, (object) ['name' => optional_param('name', $entry->name, PARAM_TEXT),
                    'acknowledge' => optional_param('acknowledge', 0, PARAM_BOOL),
                    'active' => optional_param('active', 0, PARAM_BOOL)]);
                return [$url, get_string('changessaved'), $ok];
            case 'delete':
                entries::delete($entry);
                return [new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]),
                    get_string('entry_deleted', $c), $ok];
        }
        return [$url, '', $ok];
    }

    /**
     * Keeps the file sent with the form in a draft area of whoever sent it, until it is brought or left.
     *
     * @return int The draft, or 0 if no file of contacts came.
     */
    protected static function keep_upload(): int {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $sent = $_FILES['file'] ?? null;
        if (!$sent || (int) ($sent['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($sent['tmp_name'])) {
            return 0;
        }
        $filename = clean_param((string) $sent['name'], PARAM_FILE);
        if (!preg_match('/\.(csv|xlsx|xls|tsv|txt)$/i', $filename)) {
            return 0;
        }
        $draft = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname(
            ['contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/', 'filename' => $filename],
            $sent['tmp_name']
        );
        return $draft;
    }

    /**
     * Forgets a file of contacts given.
     *
     * @param int $draft
     */
    protected static function forget(int $draft): void {
        global $USER;
        if ($draft) {
            get_file_storage()->delete_area_files(\context_user::instance($USER->id)->id, 'user', 'draft', $draft);
        }
    }
}
