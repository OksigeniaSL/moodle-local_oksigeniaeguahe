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
 * What ties a child to the centre before it asks for a place, as a person of the team knows it: it is already in its
 * nursery, it is already a student, it has brothers or sisters there, its family works there, or someone of the family
 * studied there (alumni). Several at once. The lists of the centre say part of it by themselves; who knows the families
 * says the rest, so that the numbers are fair: a child that goes on is not one the centre has attracted, whatever the open
 * day its family came to have a look. A fact of the team, never shown to the family nor used as a grade.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ties {
    /** @var string What the team marked, one row for each thing. */
    const T = 'local_oksigeniaeguahe_tie';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] Of a child: it is already at the centre, so it goes on. */
    const CHILD = ['nursery', 'student'];

    /** @var string[] Of the whole family: the same for each of its children. */
    const FAMILY = ['sibling', 'staff'];

    /** @var string[] As they are offered; the last one is kept with the former students (alumni). */
    const KINDS = ['nursery', 'student', 'sibling', 'staff', 'alumni'];

    /** @var string[] What each one makes a family, for the numbers (who). */
    const WHO = ['nursery' => 'nursery', 'student' => 'centre', 'sibling' => 'centre', 'staff' => 'staff'];

    /**
     * What the team marked of a family.
     *
     * @param int $householdid
     * @return array child id (0, the family) => kind => true
     */
    public static function of(int $householdid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(self::T, ['householdid' => $householdid], 'id ASC', 'id, childid, kind') as $row) {
            $out[(int) $row->childid][(string) $row->kind] = true;
        }
        return $out;
    }

    /**
     * What the team marked of the families of a centre.
     *
     * @param int $centreid
     * @return array household id => kind => true
     */
    public static function families(int $centreid): array {
        global $DB;
        $out = [];
        $rows = $DB->get_recordset_select(self::T, 'centreid = ?', [$centreid], '', 'id, householdid, kind');
        foreach ($rows as $row) {
            $out[(int) $row->householdid][(string) $row->kind] = true;
        }
        $rows->close();
        return $out;
    }

    /**
     * The children of a centre that are already there, as the team marked them.
     *
     * @param int $centreid
     * @return array child id => true
     */
    public static function inside(int $centreid): array {
        global $DB;
        return array_fill_keys($DB->get_fieldset_select(
            self::T,
            'DISTINCT childid',
            "centreid = ? AND childid > 0 AND kind IN ('nursery', 'student')",
            [$centreid]
        ), true);
    }

    /**
     * Marks or unmarks one thing. Any of them but the former students makes it a family of the centre, out of the numbers
     * of who is attracted; taking out the last one leaves it as it was, unless a list of the centre says so too.
     *
     * @param \stdClass $household
     * @param int $childid 0 for what is of the family.
     * @param string $kind One of KINDS.
     * @param bool $on
     * @param int $userid Who of the team says it.
     * @return bool Whether something changed.
     */
    public static function set(\stdClass $household, int $childid, string $kind, bool $on, int $userid): bool {
        global $DB;
        $child = $childid ? $DB->get_record(households::CHILDREN, ['id' => $childid, 'householdid' => $household->id]) : null;
        if (!in_array($kind, self::KINDS, true) || (in_array($kind, self::CHILD, true) && !$child)) {
            return false;
        }
        if ($kind === 'alumni') {
            $changed = alumni::set($household, 0, $on ? alumni::YES : null, 'team', $userid);
            who::reset();
            return $changed;
        }
        $of = in_array($kind, self::CHILD, true) ? $childid : 0;
        $where = ['householdid' => (int) $household->id, 'childid' => $of, 'kind' => $kind];
        $row = $DB->get_record(self::T, $where, '*', IGNORE_MULTIPLE);
        if ((bool) $row === $on) {
            return false;
        }
        $now = time();
        // Already at the centre before this mark, or no longer after it: its place is kept, or goes back to where it was.
        $was = $of ? continuing::kind($of) !== '' : false;
        if ($on) {
            $made = (int) $DB->get_field(households::T, 'incentre', ['id' => $household->id]) ? 0 : 1;
            $DB->insert_record(self::T, (object) ($where + ['centreid' => (int) $household->centreid, 'made' => $made,
                'userid' => $userid, 'timecreated' => $now]));
            if ($made) {
                $household->incentre = 1;
                $DB->set_field(households::T, 'incentre', 1, ['id' => $household->id]);
            }
        } else {
            $DB->delete_records(self::T, $where);
            if ((int) $row->made) {
                self::unmade($household);
            }
        }
        who::reset();
        if ($of && in_array($kind, self::CHILD, true)) {
            $is = continuing::kind($of) !== '';
            if ($is && !$was) {
                continuing::reserve($of);
            } else if ($was && !$is) {
                continuing::release($of);
            }
        }
        $centre = centres::get((int) $household->centreid);
        $lang = (string) ($centre->lang ?? '') ?: current_language();
        $strings = get_string_manager();
        $line = $strings->get_string('tie_noted_' . ($on ? 'on' : 'off'), self::C, (object) [
            'what' => $strings->get_string('tie_' . $kind, self::C, null, $lang),
            'name' => $of ? panel::called($child) : '',
        ], $lang);
        // Several in a row are one line of the history: what the family said stays in sight.
        $last = $DB->get_records(households::CONTACTS, ['householdid' => $household->id], 'timecreated DESC, id DESC', '*', 0, 1);
        $last = reset($last);
        $marks = trim(preg_replace('/\{[^}]*\}.*$/s', '', (string) $strings->get_string('tie_noted_on', self::C, null, $lang)));
        if (
            $last && $last->direction === 'note' && (int) $last->userid === $userid && (int) $last->timecreated > $now - 10 * MINSECS &&
                $marks !== '' && strpos((string) $last->body, $marks) === 0
        ) {
            $DB->update_record(households::CONTACTS, (object) ['id' => $last->id, 'body' => $last->body . "\n" . $line, 'timecreated' => $now]);
        } else {
            households::note($household, $userid, $line);
        }
        return true;
    }

    /**
     * The mark that made it a family of the centre is out: another one keeps it so, or a list does, or it is not any more.
     *
     * @param \stdClass $household
     */
    protected static function unmade(\stdClass $household): void {
        global $DB;
        $other = $DB->get_records(self::T, ['householdid' => $household->id], 'id ASC', 'id', 0, 1);
        if ($other) {
            $DB->set_field(self::T, 'made', 1, ['id' => key($other)]);
            return;
        }
        if ($DB->record_exists(listmatch::T, ['householdid' => $household->id, 'kind' => 'incentre'])) {
            // The list says so too: from now on it is for it that it is one of the centre, and it stops with it.
            listmatch::reckon($household);
            return;
        }
        if ((int) ($household->roster ?? 0)) {
            return;
        }
        $household->incentre = 0;
        $DB->set_field(households::T, 'incentre', 0, ['id' => $household->id]);
    }

    /**
     * What the list had made a family of the centre for went, and a person marked it too: it is for that mark now.
     *
     * @param int $householdid
     */
    public static function answers_for(int $householdid): void {
        global $DB;
        $rows = $DB->get_records(self::T, ['householdid' => $householdid], 'made DESC, id ASC', 'id, made', 0, 1);
        $first = reset($rows);
        if ($first && !(int) $first->made) {
            $DB->set_field(self::T, 'made', 1, ['id' => $first->id]);
        }
    }

    /**
     * Whether a person of the team marked something that makes a family one of the centre.
     *
     * @param int $householdid
     * @return bool
     */
    public static function any(int $householdid): bool {
        global $DB;
        return $DB->record_exists(self::T, ['householdid' => $householdid]);
    }

    /**
     * What a family can download of it.
     *
     * @param int $householdid
     * @return array
     */
    public static function export(int $householdid): array {
        global $DB;
        $names = $DB->get_records_menu(households::CHILDREN, ['householdid' => $householdid], '', 'id, firstname');
        $out = [];
        foreach (self::of($householdid) as $childid => $kinds) {
            foreach (array_keys($kinds) as $kind) {
                $out[] = array_filter(['what' => get_string('tie_' . $kind, self::C), 'child' => (string) ($names[$childid] ?? '')]);
            }
        }
        return $out;
    }

    /**
     * Whether a centre has a nursery: a course of its age in its offer.
     *
     * @param \stdClass|null $centre
     * @param array $offers
     * @return bool
     */
    public static function has_nursery(?\stdClass $centre, array $offers): bool {
        foreach ($offers as $offer) {
            $age = grades::age((string) $offer->name, $centre);
            if ($age !== null && $age <= who::NURSERY_AGE) {
                return true;
            }
        }
        return false;
    }

    /**
     * What the file shows under a child: each thing, marked or not, to change with one touch; and what the lists of the
     * centre say by themselves.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @param array $marked As of() gives it.
     * @param bool $nursery Whether the centre has a nursery.
     * @param bool $alumni Whether someone of the family studied at the centre.
     * @param bool $byadult Whether it is said of one of its adults, which is changed beside them.
     * @param string $list What the lists of the centre say, in words; empty, nothing.
     * @return array
     */
    public static function context(
        \stdClass $household,
        \stdClass $child,
        array $marked,
        bool $nursery,
        bool $alumni,
        bool $byadult,
        string $list
    ): array {
        $rows = [];
        foreach (self::KINDS as $kind) {
            $on = $kind === 'alumni' ? $alumni :
                isset($marked[in_array($kind, self::CHILD, true) ? (int) $child->id : 0][$kind]);
            if ($kind === 'nursery' && !$nursery && !$on) {
                continue;
            }
            // Of whom it is said: of this child, or of its family.
            $tag = panel::tag($child);
            $name = in_array($kind, self::CHILD, true) && $tag !== '' ? get_string('tie_' . $kind . '_of', self::C, $tag) :
                get_string('tie_' . $kind, self::C);
            $rows[] = ['kind' => $kind, 'name' => $name, 'on' => $on, 'next' => $on ? 0 : 1,
                // Said of one adult: it is changed there, where it says who.
                'fixed' => $kind === 'alumni' && $on && $byadult];
        }
        return ['id' => (int) $child->id, 'rows' => $rows, 'list' => $list,
            'said' => array_values(array_filter($rows, fn($r) => $r['on'])),
            'hassaid' => (bool) array_filter($rows, fn($r) => $r['on']) || $list !== ''];
    }

    /**
     * What the lists of the centre say of a family, in words.
     *
     * @param int $householdid
     * @param \stdClass|null $centre
     * @return string
     */
    public static function from_list(int $householdid, ?\stdClass $centre): string {
        global $DB;
        $grades = $DB->get_fieldset_select(listmatch::T, 'grade', "householdid = ? AND kind = 'incentre'", [$householdid]);
        if ($grades) {
            $nursery = !array_filter($grades, function ($grade) use ($centre) {
                $age = grades::age((string) $grade, $centre);
                return $age === null || $age > who::NURSERY_AGE;
            });
            return get_string('tie_list', self::C, get_string($nursery ? 'tie_list_nursery' : 'tie_list_centre', self::C));
        }
        return $DB->record_exists(listmatch::T, ['householdid' => $householdid, 'kind' => 'former']) ?
            get_string('tie_list', self::C, get_string('tie_list_former', self::C)) : '';
    }
}
