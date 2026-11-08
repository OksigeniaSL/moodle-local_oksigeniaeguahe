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
 * What the entries of the panel show, whatever they look like: by how many doors families arrive and which brought most
 * this month; the forms of the centre with the places they are shared from; the bridges (Google, Meta, the mailbox,
 * another tool) and what is missing to start the ones not used yet; and a new entry.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_entries {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact, int $now = 0): array {
        $now = $now ?: time();
        $c = self::C;
        $forms = [];
        $bridges = [];
        $list = entries::for_centre((int) $centre->id);
        foreach ($list as $entry) {
            $sites = [];
            foreach ($entry->kind === 'form' ? forms::origins($entry) : [] as $origin) {
                $sites[] = is_array($origin) ? (string) ($origin['name'] ?? '') : (string) $origin;
            }
            $row = [
                'name' => format_string($entry->name),
                'kind' => get_string('entry_kind_' . $entry->kind, $c),
                'state' => get_string(!empty($entry->active) ? 'panel_open' : 'panel_closed', $c),
                'brought' => get_string(
                    (int) $entry->received === 1 ? 'panel_brought_one' : 'panel_brought',
                    $c,
                    (int) $entry->received
                ),
                'last' => $entry->lastreceived ? get_string('panel_last_one', $c, panel::when_words(
                    (int) $entry->lastreceived,
                    $now
                )) : '',
                'missing' => !$entry->received && $entry->kind !== 'form' ? get_string('panel_missing_' . $entry->kind, $c) : '',
                'sites' => array_values(array_filter($sites)),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id]))->out(false),
            ];
            if ($entry->kind === 'form') {
                $forms[] = $row;
            } else {
                $bridges[] = $row;
            }
        }
        $kinds = [];
        foreach (array_keys(entries::KINDS) as $i => $kind) {
            $kinds[] = ['value' => $kind, 'name' => get_string('entry_kind_' . $kind, $c),
                'help' => get_string('entry_kind_' . $kind . '_help', $c), 'checked' => $i === 0];
        }
        $best = self::best((int) $centre->id, $now);
        return [
            'head' => panel::head([
                'title' => $list ? get_string(count($list) === 1 ? 'panel_entries_one' : 'panel_entries', $c, count($list)) :
                    get_string('panel_entries_none', $c),
                'sub' => $best,
            ]),
            'forms' => $forms,
            'hasforms' => (bool) $forms,
            'bridges' => $bridges,
            'hasbridges' => (bool) $bridges,
            'kinds' => $kinds,
            'canact' => $canact,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The entry that brought most families this month, with the place it was shared from: «La que más ha traído este
     * mes, «Pidan información» por Instagram».
     *
     * @param int $centreid
     * @param int $now
     * @return string Empty when none brought any this month.
     */
    protected static function best(int $centreid, int $now): string {
        global $DB;
        $month = make_timestamp((int) userdate($now, '%Y'), (int) userdate($now, '%m'), 1);
        $rows = $DB->get_recordset_sql("SELECT c.entryid, COALESCE(c.detail, '') AS detail, COUNT(DISTINCT c.householdid) AS n
                                        FROM {" . households::CONTACTS . "} c
                                       WHERE c.centreid = :centreid AND c.entryid > 0 AND c.direction = 'in'
                                             AND c.timecreated >= :month
                                    GROUP BY c.entryid, COALESCE(c.detail, '')
                                    ORDER BY n DESC", ['centreid' => $centreid, 'month' => $month], 0, 1);
        $row = $rows->valid() ? $rows->current() : null;
        $rows->close();
        if (!$row) {
            return '';
        }
        $name = format_string((string) $DB->get_field(entries::T, 'name', ['id' => $row->entryid]));
        return get_string($row->detail !== '' ? 'panel_entries_best_site' : 'panel_entries_best', self::C, (object) [
            'name' => $name, 'site' => s($row->detail)]);
    }
}
