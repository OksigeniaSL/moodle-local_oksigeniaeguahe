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
 * The page of the families that wait and may already be answered: by why, each with what it wrote, to say so of several.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_settle {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The page.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param string $key Of those just said, to take it back.
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact, string $key = '', int $now = 0): array {
        $now = $now ?: time();
        global $DB;
        $groups = [];
        $n = 0;
        $said = $key !== '' ? $DB->get_records_menu(settle::T, ['centreid' => $centre->id, 'batch' => $key], '', 'id, reason') : [];
        $kept = count(array_keys($said, settle::KEEP, true));
        $done = count($said) - $kept;
        $undotext = $done ? get_string($done === 1 ? 'settle_done_one' : 'settle_done', self::C, $done) :
            ($kept ? get_string($kept === 1 ? 'settle_kept_one' : 'settle_kept', self::C, $kept) : '');
        foreach (settle::candidates($centre, $now) as $group) {
            $people = households::some((int) $centre->id, array_keys($group['families']));
            $wrote = self::said(array_keys($group['families']));
            $families = [];
            foreach ($group['families'] as $id => $when) {
                if (!isset($people[$id])) {
                    continue;
                }
                $tags = [];
                foreach ($people[$id]->children as $child) {
                    if ($child->stage !== 'closed' && panel::tag($child) !== '') {
                        $tags[] = ['tag' => panel::tag($child)];
                    }
                }
                $families[] = ['id' => $id, 'adults' => panel::adults_line($people[$id]->adults) ?:
                    get_string('chat_family_noname', self::C), 'children' => $tags,
                    'wrote' => get_string('settle_wrote', self::C, panel::when_words($when, $now, !empty($people[$id]->waitingdayonly))),
                    'said' => ($wrote[$id] ?? '') !== '' ? panel::quote(shorten_text($wrote[$id], 180)) : '',
                    'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $id]))->out(false)];
            }
            if (!$families) {
                continue;
            }
            $n += count($families);
            $name = $group['reason'] === 'came' && $group['what'] !== '' ?
                get_string('settle_group_act', self::C, panel::quote($group['what'])) :
                get_string('settle_group_' . $group['reason'], self::C);
            $groups[] = ['key' => $group['key'], 'reason' => $group['reason'], 'name' => $name,
                'day' => $group['day'] ? panel_agenda::day($group['day']) : '', 'families' => $families, 'n' => count($families),
                'button' => get_string(count($families) === 1 ? 'settle_do_one' : 'settle_do', self::C, count($families))];
        }
        return ['head' => panel::head(['title' => get_string('settle_title', self::C), 'sub' => $n ?
            get_string($n === 1 ? 'settle_sub_one' : 'settle_sub', self::C, $n) : get_string('settle_none', self::C),
            'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]))->out(false),
                'name' => get_string('tab_inbox', self::C)]]),
            'groups' => $groups, 'none' => !$groups, 'canact' => $canact, 'undo' => $said ? $key : '',
            'undotext' => $undotext, 'sesskey' => sesskey(),
            'markurl' => (new \moodle_url('/local/oksigeniaeguahe/contestadas.php', ['c' => $centre->id]))->out(false)];
    }

    /**
     * The last thing each family wrote.
     *
     * @param int[] $ids
     * @return string[] By household.
     */
    protected static function said(array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $out = [];
        $rows = $DB->get_recordset_select(
            households::CONTACTS,
            "householdid $in AND direction = 'in' AND userid = 0",
            $params,
            'timecreated ASC, id ASC',
            'id, householdid, body'
        );
        foreach ($rows as $row) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $row->body));
            if ($text !== '') {
                $out[(int) $row->householdid] = $text;
            }
        }
        $rows->close();
        return $out;
    }
}
