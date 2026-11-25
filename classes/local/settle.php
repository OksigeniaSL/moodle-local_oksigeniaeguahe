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
 * Families that wait for an answer and may already have it: they came to the centre after writing, or have an appointment
 * given after it, and have not written since. A person says so of several at once, with why, and can take it back.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settle {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string */
    const T = 'local_oksigeniaeguahe_settle';

    /** @var string[] Why. */
    const REASONS = ['came', 'booked'];

    /** @var string A person said that it still waits: it is not asked again until something else happens. */
    const KEEP = 'keep';

    /**
     * The groups of families, each with why.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array[] reason (came or booked), eventid (0 for the visits), what (the act), day and families (household id => when
     *     they wrote)
     */
    public static function candidates(\stdClass $centre, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $waiting = $DB->get_records_select(
            households::T,
            'centreid = ? AND waitingsince > 0',
            [$centre->id],
            'waitingsince ASC',
            'id, waitingsince'
        );
        if (!$waiting) {
            return [];
        }
        // The last thing each one wrote: nobody who wrote after what would answer it is here.
        $wrote = $DB->get_records_sql_menu(
            "SELECT c.householdid, MAX(c.timecreated)
               FROM {" . households::CONTACTS . "} c
               JOIN {" . households::T . "} h ON h.id = c.householdid
              WHERE h.centreid = ? AND h.waitingsince > 0 AND c.direction = 'in' AND c.userid = 0
           GROUP BY c.householdid",
            [$centre->id]
        );
        $last = [];
        foreach ($waiting as $household) {
            $last[(int) $household->id] = max((int) ($wrote[$household->id] ?? 0), (int) $household->waitingsince);
        }
        // Those a person left waiting, knowing what there was then.
        $kept = $DB->get_records_sql_menu(
            "SELECT householdid, MAX(timecreated) FROM {" . self::T . "} WHERE centreid = ? AND reason = ? GROUP BY householdid",
            [$centre->id, self::KEEP]
        );
        $groups = [];
        // Each family once, for the first that would answer it.
        $placed = [];
        $talks = [];
        foreach (appointments::agenda($centre, min($last) - DAYSECS, $now + YEARSECS, $now) as $one) {
            $id = $one['householdid'];
            if (!isset($last[$id]) || isset($placed[$id])) {
                continue;
            }
            // The day of a note without hour counts whole.
            $end = $one['dayonly'] ? $one['start'] + DAYSECS : $one['start'];
            $given = $one['state'] === 'coming' && $one['kind'] === 'visit' ? self::given($one) : 0;
            if ($one['kind'] === 'act' && ($talks[$one['eventid']] ?? null) === null) {
                $talks[$one['eventid']] = (string) $DB->get_field(events::T, 'kind', ['id' => (int) $one['eventid']]) === 'other';
            }
            if ($one['kind'] === 'act' && $talks[$one['eventid']]) {
                // Coming to a talk or a party answers nothing they asked.
                continue;
            }
            if ($one['state'] === 'came' && $end > $last[$id]) {
                $reason = 'came';
            } else if ($given > $last[$id]) {
                $reason = 'booked';
            } else {
                continue;
            }
            if (($kept[$id] ?? 0) >= ($given ?: $one['start'])) {
                continue;
            }
            $key = $reason . '-' . ($one['kind'] === 'act' ? $one['eventid'] : 0);
            $groups[$key] = $groups[$key] ?? ['key' => $key, 'reason' => $reason, 'eventid' => $one['kind'] === 'act' ? $one['eventid'] : 0,
                'what' => $one['kind'] === 'act' ? $one['title'] : '', 'day' => $one['kind'] === 'act' ? $one['start'] : 0, 'families' => []];
            $groups[$key]['families'][$id] = $last[$id];
            $placed[$id] = true;
        }
        return array_values($groups);
    }

    /**
     * When a visit was given.
     *
     * @param array $one As appointments::agenda() gives it.
     * @return int
     */
    protected static function given(array $one): int {
        global $DB;
        return (int) $DB->get_field(events::SIGNUPS, 'timecreated', ['id' => (int) substr($one['id'], 1)]);
    }

    /**
     * A person says of several that they are already answered: they no longer wait, with a line in their history that says
     * why. Only those that are in that group now. The others it had in sight still wait, and are not asked again until
     * something else happens.
     *
     * @param \stdClass $centre
     * @param int[] $ids
     * @param string $group The key of its group.
     * @param int $userid
     * @param int $now
     * @param int[] $seen Those it had in sight.
     * @return string The key of this time, for undo(); empty when none.
     */
    public static function many(
        \stdClass $centre,
        array $ids,
        string $group,
        int $userid,
        int $now = 0,
        array $seen = []
    ): string {
        global $DB;
        $now = $now ?: time();
        $found = array_column(self::candidates($centre, $now), null, 'key')[$group] ?? null;
        if (!$found) {
            return '';
        }
        $batch = random_string(12);
        $any = false;
        foreach (array_intersect(array_keys($found['families']), array_map('intval', $ids)) as $id) {
            $household = households::get($id, (int) $centre->id);
            if (!$household || !(int) $household->waitingsince) {
                continue;
            }
            $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'householdid' => $id, 'batch' => $batch, 'reason' => $found['reason'],
                'waitingsince' => $household->waitingsince, 'waitingdayonly' => $household->waitingdayonly, 'userid' => $userid,
                'timecreated' => $now]);
            $DB->update_record(households::T, (object) ['id' => $id, 'waitingsince' => 0, 'waitingdayonly' => 0, 'timemodified' => $now]);
            households::note($household, $userid, get_string('settle_note_' . $found['reason'], self::C, $found['what'] !== '' ?
                panel::quote($found['what']) : ''));
            $any = true;
        }
        foreach (array_diff(array_intersect(array_keys($found['families']), array_map('intval', $seen)), array_map('intval', $ids)) as $id) {
            $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'householdid' => $id, 'batch' => $batch, 'reason' => self::KEEP,
                'waitingsince' => 0, 'waitingdayonly' => 0, 'userid' => $userid, 'timecreated' => $now]);
            $any = true;
        }
        return $any ? $batch : '';
    }

    /**
     * The way of the children of a family was closed by a person: it no longer waits, which is not an answer. Only when none
     * goes on.
     *
     * @param \stdClass $household Changed in place.
     * @param int $userid
     * @return bool
     */
    public static function closed(\stdClass $household, int $userid): bool {
        global $DB;
        $open = $DB->record_exists_select(
            households::CHILDREN,
            "householdid = ? AND stage <> 'closed' AND NOT (stage = 'enrolled' AND COALESCE(lefttime, 0) > 0)",
            [$household->id]
        );
        if ($open || !(int) $DB->get_field(households::T, 'waitingsince', ['id' => $household->id])) {
            return false;
        }
        $DB->update_record(households::T, (object) ['id' => $household->id, 'waitingsince' => 0, 'waitingdayonly' => 0, 'timemodified' => time()]);
        $household->waitingsince = 0;
        $household->waitingdayonly = 0;
        households::note($household, $userid, get_string('settle_note_closed', self::C));
        return true;
    }

    /**
     * Takes it back: they wait again, since when they did, unless something happened since.
     *
     * @param \stdClass $centre
     * @param string $batch
     * @param int $userid
     * @param int $now
     * @return int How many.
     */
    public static function undo(\stdClass $centre, string $batch, int $userid, int $now = 0): int {
        global $DB;
        $now = $now ?: time();
        $n = 0;
        foreach ($DB->get_records(self::T, ['centreid' => $centre->id, 'batch' => $batch]) as $row) {
            if ($row->reason === self::KEEP) {
                $DB->delete_records(self::T, ['id' => $row->id]);
                continue;
            }
            $household = households::get((int) $row->householdid, (int) $centre->id);
            $answered = $household && $DB->record_exists_select(
                households::CONTACTS,
                "householdid = ? AND direction = 'out' AND aim IS NULL AND timecreated >= ?",
                [$row->householdid, $row->timecreated]
            );
            if ($household && !(int) $household->waitingsince && !$answered) {
                $DB->update_record(households::T, (object) ['id' => $household->id, 'waitingsince' => $row->waitingsince,
                    'waitingdayonly' => $row->waitingdayonly, 'timemodified' => $now]);
                households::note($household, $userid, get_string('settle_note_undone', self::C));
                $n++;
            }
            $DB->delete_records(self::T, ['id' => $row->id]);
        }
        return $n;
    }
}
