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
 * The same family written down twice: the mother asks for a visit on one side and the father on the other, each with
 * their own email and phone, so nothing joins them. A child with the same name and the same birthday in two households
 * of a centre, or the same name asking for the same course, says they may be one. A person decides: «They are the same
 * family» puts them together in the older one; «No, they are different» is kept and never asked again. Never joined on
 * its own.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lookalike {
    /** @var string Two households a person said are not the same family. */
    const T = 'local_oksigeniaeguahe_notsame';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string What came from the other household when two were put together, to separate them again. */
    const LOG = 'local_oksigeniaeguahe_mergelog';

    /** @var string[] What a household has that goes with it as a whole, by its householdid, when two are put together. */
    const MOVED = [households::CONSENTS, households::CONTACTS, households::LINKS, visits::REQUESTS, forms::ANSWERS,
        attend::PROMISES, surveys::T, seen::T, hobbies::T, siblings::T, invites::T, entries::ARRIVALS, roster::T, events::T,
        renewal::T, listmatch::T, teamnotes::T, alumni::T, access::T, appointments::T,
        notices::T, settle::T, ties::T, self::TWICE];

    /** @var string Two lines of one household that a person said are the same child (joined, with what undoes it) or two. */
    const TWICE = 'local_oksigeniaeguahe_samechild';

    /** @var int How long a child put together with its other line can be separated again from its file. */
    const TWICE_UNDO = 30 * DAYSECS;

    /** @var int Two families asking for the same course look alike only if they came within this time of each other. */
    const NEAR = 180 * DAYSECS;

    /**
     * The households of its centre that may be this same family, each with the child that says so.
     *
     * @param \stdClass $household
     * @return array householdid => [child (its name), why (birthdate or course), birthdate]
     */
    public static function of(\stdClass $household): array {
        global $DB;
        // The same email or the same phone in two households: a family that wrote twice, or one that wrote the phone of
        // another (a form anyone can fill never joins them by a phone alone; a person says).
        $apart = self::apart((int) $household->centreid, (int) $household->id);
        $out = [];
        foreach (['email', 'phone'] as $field) {
            $rows = $DB->get_recordset_sql(
                "SELECT DISTINCT b.householdid, a.$field AS said
                   FROM {" . households::ADULTS . "} a
                   JOIN {" . households::ADULTS . "} b ON b.centreid = a.centreid AND b.$field = a.$field
                        AND b.householdid <> a.householdid
                  WHERE a.householdid = :me AND a.$field IS NOT NULL AND a.$field <> ''
               ORDER BY b.householdid ASC",
                ['me' => $household->id]
            );
            foreach ($rows as $row) {
                if (!isset($apart[(int) $row->householdid]) && !isset($out[(int) $row->householdid])) {
                    $out[(int) $row->householdid] = ['child' => '', 'why' => $field, 'birthdate' => '', $field => (string) $row->said];
                }
            }
            $rows->close();
        }
        $mine = $DB->get_records(
            households::CHILDREN,
            ['householdid' => $household->id],
            'id ASC',
            'id, firstname, birthdate, offerid, stage'
        );
        foreach ($mine as $child) {
            $child->roster = (int) ($household->roster ?? 0);
        }
        $names = [];
        foreach ($mine as $child) {
            if (self::name_key((string) $child->firstname) !== '') {
                $names[] = $child;
            }
        }
        if (!$names) {
            return $out;
        }
        $births = array_values(array_filter(array_unique(array_map(fn($k) => trim((string) $k->birthdate), $mine))));
        $offers = array_values(array_filter(array_unique(array_map(fn($k) => (int) $k->offerid, $mine))));
        $where = [];
        $params = ['centreid' => $household->centreid, 'me' => $household->id];
        if ($births) {
            [$in, $p] = $DB->get_in_or_equal($births, SQL_PARAMS_NAMED, 'b');
            $where[] = "c.birthdate $in";
            $params += $p;
        }
        if ($offers) {
            [$in, $p] = $DB->get_in_or_equal($offers, SQL_PARAMS_NAMED, 'o');
            $where[] = "c.offerid $in";
            $params += $p;
        }
        if (!$where) {
            return $out;
        }
        $others = $DB->get_records_sql(
            'SELECT c.id, c.householdid, c.firstname, c.birthdate, c.offerid, c.stage, h.roster, h.timecreated
               FROM {' . households::CHILDREN . '} c
               JOIN {' . households::T . '} h ON h.id = c.householdid
              WHERE c.centreid = :centreid AND c.householdid <> :me AND (' . implode(' OR ', $where) . ')
           ORDER BY h.timecreated ASC, c.id ASC',
            $params
        );
        $sur = self::surnames((int) $household->centreid, array_merge(
            [(int) $household->id],
            array_map(fn($o) => (int) $o->householdid, $others)
        ));
        foreach ($others as $other) {
            $id = (int) $other->householdid;
            if (isset($out[$id]) || isset($apart[$id])) {
                continue;
            }
            $shared = (bool) array_intersect_key($sur[(int) $household->id] ?? [], $sur[$id] ?? []);
            foreach ($names as $child) {
                if (!self::alike($child, $other)) {
                    continue;
                }
                $why = self::why($child, $other, abs((int) $household->timecreated - (int) $other->timecreated), $shared);
                if ($why !== '') {
                    $out[$id] = ['child' => (string) $child->firstname, 'why' => $why,
                        'birthdate' => trim((string) $child->birthdate)];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Whether the names of two children may be of the same one: the same words, whatever their order; or, with the same
     * birthday, one with all the words of the other («José Manuel Hernández» and «José Manuel Hernández Gil»).
     *
     * @param \stdClass $one
     * @param \stdClass $other
     * @return bool
     */
    protected static function alike(\stdClass $one, \stdClass $other): bool {
        $a = self::name_key((string) $one->firstname);
        $b = self::name_key((string) $other->firstname);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $born = trim((string) $one->birthdate);
        if ($born === '' || $born !== trim((string) $other->birthdate)) {
            return false;
        }
        $one = explode(' ', $a);
        $two = explode(' ', $b);
        [$short, $long] = count($one) <= count($two) ? [$one, $two] : [$two, $one];
        return !array_diff($short, $long);
    }

    /**
     * Why two children of the same name may be the same child: the same birthday, or the same course asked for by two
     * families that came near in time. Two different birthdays are two children, and two children already at the
     * centre (enrolled, or brought from its roll) with the same name in the same course are classmates.
     *
     * @param \stdClass $one
     * @param \stdClass $other
     * @param int $apart How far apart in time their households came.
     * @param bool $samename Whether the adults of the two households share a surname.
     * @return string birthdate, course or '' when they are not.
     */
    public static function why(\stdClass $one, \stdClass $other, int $apart, bool $samename = true): string {
        $a = trim((string) $one->birthdate);
        $b = trim((string) $other->birthdate);
        if ($a !== '' && $a === $b) {
            return 'birthdate';
        }
        if ($a !== '' && $b !== '') {
            return '';
        }
        $settled = fn($k) => ($k->stage ?? '') === 'enrolled' || !empty($k->roster);
        if ($settled($one) || $settled($other)) {
            return '';
        }
        // The same first name and course is not enough on its own (two Hugos for the same year are common): a surname of
        // the adults in common too, or the name written with its surnames.
        return (int) $one->offerid && (int) $one->offerid === (int) $other->offerid && $apart <= self::NEAR &&
            ($samename || self::whole_name((string) $one->firstname)) ? 'course' : '';
    }

    /**
     * The name of a child to compare it: its words without accents, whatever their order («Ruiz Gil Leo» is «Leo Ruiz Gil»).
     *
     * @param string $name
     * @return string
     */
    protected static function name_key(string $name): string {
        $words = explode(' ', grades::plain($name));
        sort($words);
        return implode(' ', $words);
    }

    /**
     * Whether what is written as the name of a child is its name with its surnames: three words or more, without counting
     * «de», «del» and the like.
     *
     * @param string $name
     * @return bool
     */
    protected static function whole_name(string $name): bool {
        $words = array_diff(explode(' ', grades::plain($name)), ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'i', 'da', 'do', 'das',
            'dos', 'san', 'santa']);
        return count(array_filter($words, fn($w) => \core_text::strlen($w) > 1)) >= 3;
    }

    /**
     * The words of the surnames of the adults of some households, without accents: to tell whether two share one.
     *
     * @param int $centreid
     * @param int[] $ids
     * @return array householdid => [word => true]
     */
    protected static function surnames(int $centreid, array $ids): array {
        global $DB;
        $out = [];
        if (!$ids) {
            return $out;
        }
        [$in, $params] = $DB->get_in_or_equal(array_values(array_unique($ids)), SQL_PARAMS_NAMED);
        foreach (
            $DB->get_recordset_select(
                households::ADULTS,
                "centreid = :centreid AND householdid $in",
                ['centreid' => $centreid] + $params,
                '',
                'id, householdid, lastname'
            ) as $adult
        ) {
            foreach (preg_split('/\s+/', grades::plain((string) $adult->lastname)) ?: [] as $word) {
                if (\core_text::strlen($word) > 2) {
                    $out[(int) $adult->householdid][$word] = true;
                }
            }
        }
        return $out;
    }

    /**
     * The households a person said are not the same family as this one.
     *
     * @param int $centreid
     * @param int $householdid
     * @return array householdid => true
     */
    protected static function apart(int $centreid, int $householdid): array {
        global $DB;
        $out = [];
        $rows = $DB->get_records_select(
            self::T,
            'centreid = :centreid AND (householda = :a OR householdb = :b)',
            ['centreid' => $centreid, 'a' => $householdid, 'b' => $householdid],
            '',
            'id, householda, householdb'
        );
        foreach ($rows as $row) {
            $out[(int) $row->householda === $householdid ? (int) $row->householdb : (int) $row->householda] = true;
        }
        return $out;
    }

    /**
     * A person says two households are different families: it is not asked again.
     *
     * @param int $centreid
     * @param int $one
     * @param int $other
     * @param int $userid
     */
    public static function distinct(int $centreid, int $one, int $other, int $userid): void {
        global $DB;
        [$a, $b] = [min($one, $other), max($one, $other)];
        if ($a === $b || $DB->record_exists(self::T, ['centreid' => $centreid, 'householda' => $a, 'householdb' => $b])) {
            return;
        }
        $DB->insert_record(self::T, (object) ['centreid' => $centreid, 'householda' => $a, 'householdb' => $b,
            'userid' => $userid, 'timecreated' => time()]);
        self::forget($centreid);
    }

    /**
     * The lines of a household that may be the same child written down twice: both with a name, the same words or one name
     * starting the other («Dafne» and «Dafne Cano»), the same birthday when both have one, never one already at the centre and
     * the other asking for a later year, and nobody said they are two. A shorter name that could be either of two is not
     * proposed until one of them is settled.
     *
     * @param \stdClass $household
     * @return \stdClass[][] Pairs [older line, newer line], the older first.
     */
    public static function twice(\stdClass $household): array {
        global $DB;
        $children = array_values($DB->get_records(households::CHILDREN, ['householdid' => $household->id], 'id ASC'));
        if (count($children) < 2) {
            return [];
        }
        $apart = [];
        foreach ($DB->get_records(self::TWICE, ['householdid' => $household->id, 'same' => 0], '', 'id, childa, childb') as $row) {
            $apart[min((int) $row->childa, (int) $row->childb) . ':' . max((int) $row->childa, (int) $row->childb)] = true;
        }
        $years = $DB->get_records_menu(centres::OFFERS, ['centreid' => $household->centreid], '', 'id, schoolyear');
        $year = fn($k) => (string) ($years[(int) $k->offerid] ?? '');
        $settled = fn($k) => $k->stage === 'enrolled' && !(int) ($k->lefttime ?? 0);
        $open = fn($k) => !in_array($k->stage, ['enrolled', 'closed'], true);
        $exact = [];
        $close = [];
        foreach ($children as $i => $a) {
            foreach (array_slice($children, $i + 1) as $b) {
                if (trim((string) $a->firstname) === '' || trim((string) $b->firstname) === '' || isset($apart[$a->id . ':' . $b->id])) {
                    continue;
                }
                $da = trim((string) $a->birthdate);
                $db = trim((string) $b->birthdate);
                if (
                    ($da !== '' && $db !== '' && $da !== $db) ||
                    ($settled($a) && $open($b) && $year($b) > $year($a) && $year($a) !== '') ||
                    ($settled($b) && $open($a) && $year($a) > $year($b) && $year($b) !== '')
                ) {
                    continue;
                }
                if (self::name_key((string) $a->firstname) === self::name_key((string) $b->firstname)) {
                    $exact[] = [$a, $b];
                } else if (households::same_name((string) $a->firstname, (string) $b->firstname)) {
                    $close[] = [$a, $b];
                }
            }
        }
        // «Dafne» with «Dafne Cano» and «Dafne López» could be either: not said until one of them is.
        $count = [];
        foreach ($close as [$a, $b]) {
            $count[$a->id] = ($count[$a->id] ?? 0) + 1;
            $count[$b->id] = ($count[$b->id] ?? 0) + 1;
        }
        $close = array_filter($close, fn($p) => $count[$p[0]->id] === 1 && $count[$p[1]->id] === 1);
        return array_merge($exact, array_values($close));
    }

    /**
     * Two lines of one household are the same child: they become the older one, with what only the other knew, the way that
     * went furthest, and everything that named the other (sign-ups, marks, ties, the lists, the answer about next year, the
     * drawing). The fuller of two names that start the same stays, and the shorter one is how it is called at home. A line in
     * its history says it, and it can be undone for a month.
     *
     * @param \stdClass $household
     * @param int $one
     * @param int $other
     * @param int $userid
     * @return bool Whether they were put together.
     */
    public static function join_twice(\stdClass $household, int $one, int $other, int $userid): bool {
        global $DB;
        if ($one === $other) {
            return false;
        }
        $transaction = $DB->start_delegated_transaction();
        $keep = $DB->get_record(households::CHILDREN, ['id' => min($one, $other), 'householdid' => $household->id]);
        $gone = $DB->get_record(households::CHILDREN, ['id' => max($one, $other), 'householdid' => $household->id]);
        if (!$keep || !$gone) {
            $transaction->allow_commit();
            return false;
        }
        $before = [panel::tag($keep) ?: '—', panel::tag($gone) ?: '—'];
        $years = $DB->get_records_menu(centres::OFFERS, ['centreid' => $household->centreid], '', 'id, schoolyear');
        $log = ['v' => 1, 'keep' => (int) $keep->id, 'samechildren' => [], 'changed' => [], 'deleted' => [],
            'drawing' => drawings::file((int) $keep->id) ? 0 : (drawings::file((int) $gone->id) ? 1 : 0)];
        $told = [];
        $short = trim((string) $keep->firstname);
        $long = trim((string) $gone->firstname);
        self::one_child($keep, $gone, $years, $log, $told);
        if (\core_text::strlen($long) > \core_text::strlen($short) && households::same_name($short, $long)) {
            self::change($log, households::CHILDREN, $keep, ['firstname' => $long] +
                (trim((string) $keep->tagname) === '' ? ['tagname' => $short] : []));
        }
        $map = [(int) $gone->id => (int) $keep->id];
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $household->id]) as $row) {
            if ((string) $row->wasstages !== '') {
                self::change($log, households::SIGNUPS, $row, ['wasstages' => self::renumber($row->wasstages, $map)]);
            }
        }
        foreach ($DB->get_records(appointments::T, ['householdid' => $household->id]) as $row) {
            if ((string) $row->stages !== '') {
                self::change($log, appointments::T, $row, ['stages' => self::renumber($row->stages, $map)]);
            }
        }
        $kinds = [];
        foreach ($DB->get_records(ties::T, ['householdid' => $household->id], 'id ASC') as $row) {
            if ((int) $row->childid === (int) $gone->id) {
                if (isset($kinds[$row->kind])) {
                    self::drop($log, ties::T, $row);
                    continue;
                }
                self::change($log, ties::T, $row, ['childid' => (int) $keep->id]);
            }
            if ((int) $row->childid === (int) $keep->id) {
                $kinds[$row->kind] = true;
            }
        }
        foreach ($DB->get_records(listmatch::T, ['householdid' => $household->id, 'childid' => $gone->id]) as $row) {
            self::change($log, listmatch::T, $row, ['childid' => (int) $keep->id]);
        }
        $DB->insert_record(self::TWICE, (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
            'childa' => (int) $keep->id, 'childb' => (int) $gone->id, 'same' => 1,
            'data' => json_encode($log, JSON_UNESCAPED_UNICODE), 'userid' => $userid, 'timecreated' => time()]);
        households::note($household, $userid, trim(get_string('twice_note', self::C, (object) ['one' => $before[0],
            'other' => $before[1], 'way' => self::way_words($DB->get_record(households::CHILDREN, ['id' => $keep->id]))]) .
            ($told ? "\n" . implode("\n", $told) : '')));
        self::forget((int) $household->centreid);
        who::reset();
        $transaction->allow_commit();
        return true;
    }

    /**
     * Two lines of one household that a person said are two children: never proposed again.
     *
     * @param \stdClass $household
     * @param int $one
     * @param int $other
     * @param int $userid
     */
    public static function not_twice(\stdClass $household, int $one, int $other, int $userid): void {
        global $DB;
        $ids = $DB->get_fieldset_select(
            households::CHILDREN,
            'id',
            'householdid = ? AND id IN (?, ?)',
            [$household->id, $one, $other]
        );
        if (count($ids) !== 2) {
            return;
        }
        $DB->insert_record(self::TWICE, (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
            'childa' => min($one, $other), 'childb' => max($one, $other), 'same' => 0, 'userid' => $userid,
            'timecreated' => time()]);
    }

    /**
     * Separates again a child put together with its other line: the other comes back as it was, with what was its own, and
     * what changed in the one that stayed goes back, unless it was changed again since.
     *
     * @param \stdClass $household
     * @param int $id The row of the joining.
     * @param int $userid
     * @return bool
     */
    public static function undo_twice(\stdClass $household, int $id, int $userid): bool {
        global $DB;
        $row = $DB->get_record(self::TWICE, ['id' => $id, 'householdid' => $household->id, 'same' => 1]);
        $log = $row ? json_decode((string) $row->data, true) : null;
        if (!$row || !is_array($log) || (int) $row->timecreated < time() - self::TWICE_UNDO) {
            return false;
        }
        $transaction = $DB->start_delegated_transaction();
        foreach ((array) ($log['samechildren'] ?? []) as $child) {
            unset($child['into']);
            $child['householdid'] = (int) $household->id;
            self::again(households::CHILDREN, $child);
        }
        foreach ((array) ($log['changed'] ?? []) as $table => $rows) {
            foreach ((array) $rows as $rowid => $change) {
                $now = $DB->get_record($table, ['id' => (int) $rowid]);
                if (!$now) {
                    continue;
                }
                $back = [];
                foreach ((array) ($change['now'] ?? []) as $field => $value) {
                    // Only what is still as the joining left it.
                    if ((string) ($now->$field ?? '') === (string) $value) {
                        $back[$field] = $change['was'][$field] ?? null;
                    }
                }
                if ($back) {
                    $DB->update_record($table, (object) (['id' => (int) $rowid] + $back));
                }
            }
        }
        foreach ((array) ($log['deleted'] ?? []) as $table => $rows) {
            foreach ((array) $rows as $deleted) {
                self::again($table, (array) $deleted);
            }
        }
        if (!empty($log['drawing'])) {
            // The drawing it took from the other: the other has it still.
            drawings::delete((int) $row->childa);
        }
        $DB->delete_records(self::TWICE, ['id' => $row->id]);
        $names = array_map(
            fn($k) => panel::tag($k) ?: '—',
            $DB->get_records_list(households::CHILDREN, 'id', [(int) $row->childa, (int) $row->childb], 'id ASC')
        );
        households::note($household, $userid, get_string('twice_undone_note', self::C, implode(' · ', $names)));
        // Said once by a person: not proposed again.
        self::not_twice($household, (int) $row->childa, (int) $row->childb, $userid);
        self::forget((int) $household->centreid);
        who::reset();
        $transaction->allow_commit();
        return true;
    }

    /**
     * What the file of a household shows of a child written down twice: each pair that may be one, and any two to put together
     * by hand; and the last ones put together, to separate them again.
     *
     * @param \stdClass $household
     * @param bool $canact
     * @return array|false
     */
    public static function twice_context(\stdClass $household, bool $canact) {
        global $DB;
        if (!$canact) {
            return false;
        }
        $c = self::C;
        $action = (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id], 'mismo-nino'))->out(false);
        $line = function (\stdClass $a, \stdClass $b) use ($c): array {
            return ['one' => (int) $a->id, 'other' => (int) $b->id, 'names' => get_string('twice_pair', $c, (object) [
                'one' => panel::tag($a) ?: get_string('chat_child_noname', $c), 'onewas' => self::way_words($a),
                'other' => panel::tag($b) ?: get_string('chat_child_noname', $c), 'otherwas' => self::way_words($b)])];
        };
        $proposed = array_map(fn($p) => $line($p[0], $p[1]), self::twice($household));
        $said = [];
        foreach ($proposed as $p) {
            $said[$p['one'] . ':' . $p['other']] = true;
        }
        $apart = [];
        foreach ($DB->get_records(self::TWICE, ['householdid' => $household->id, 'same' => 0], '', 'id, childa, childb') as $row) {
            $apart[min((int) $row->childa, (int) $row->childb) . ':' . max((int) $row->childa, (int) $row->childb)] = true;
        }
        $children = array_values($DB->get_records(households::CHILDREN, ['householdid' => $household->id], 'id ASC'));
        $hand = [];
        foreach ($children as $i => $a) {
            foreach (array_slice($children, $i + 1) as $b) {
                if (!isset($said[$a->id . ':' . $b->id]) && !isset($apart[$a->id . ':' . $b->id])) {
                    $hand[] = $line($a, $b);
                }
            }
        }
        $joined = [];
        foreach (
            $DB->get_records_select(
                self::TWICE,
                'householdid = ? AND same = 1 AND timecreated >= ?',
                [$household->id, time() - self::TWICE_UNDO],
                'timecreated DESC, id DESC'
            ) as $row
        ) {
            $joined[] = ['id' => (int) $row->id, 'text' => get_string('twice_joined', $c, userdate(
                (int) $row->timecreated,
                get_string('strftimedatetimeshort', 'langconfig')
            ))];
        }
        if (!$proposed && !$hand && !$joined) {
            return false;
        }
        return ['proposed' => $proposed, 'hasproposed' => (bool) $proposed, 'hand' => $hand, 'hashand' => (bool) $hand,
            'joined' => $joined, 'hasjoined' => (bool) $joined, 'action' => $action, 'sesskey' => sesskey()];
    }

    /**
     * The households that may be written down twice, for the inbox: pairs where at least one came since a time.
     *
     * @param int $centreid
     * @param int $since
     * @return array[] [newer householdid, older householdid], the newest first.
     */
    public static function pairs(int $centreid, int $since): array {
        $cache = \cache::make('local_oksigeniaeguahe', 'lookalikes');
        $kept = $cache->get($centreid);
        // A few minutes old is fine for «may be the same family»; the window moves by the day.
        if (is_array($kept) && ($kept['since'] ?? 0) === intdiv($since, DAYSECS)) {
            return $kept['pairs'];
        }
        return self::refresh($centreid, $since);
    }

    /**
     * Compares the households of a centre now and keeps the pairs for the inbox: the task does it every ten minutes, so a
     * large centre never makes the person who opens the inbox wait for it.
     *
     * @param int $centreid
     * @param int $since The oldest household compared.
     * @return array[] [newer householdid, older householdid], the newest first.
     */
    public static function refresh(int $centreid, int $since): array {
        $pairs = self::compare($centreid, $since);
        \cache::make('local_oksigeniaeguahe', 'lookalikes')->set(
            $centreid,
            ['since' => intdiv($since, DAYSECS), 'pairs' => $pairs]
        );
        return $pairs;
    }

    /**
     * Where a household that was put together with another is now.
     *
     * @param int $id
     * @return int 0, nowhere.
     */
    public static function now_in(int $id): int {
        global $DB;
        $rows = $DB->get_records(self::LOG, ['goneid' => $id], 'timecreated DESC, id DESC', 'id, householdid', 0, 1);
        $row = $id ? reset($rows) : false;
        return $row && $DB->record_exists(households::T, ['id' => $row->householdid]) ? (int) $row->householdid : 0;
    }

    /**
     * The drawings kept of the children that were the same one, for when a family erases its data.
     *
     * @param int $householdid
     */
    public static function forget_drawings(int $householdid): void {
        global $DB;
        $logs = array_merge(
            $DB->get_fieldset_select(self::LOG, 'data', 'householdid = ?', [$householdid]),
            $DB->get_fieldset_select(self::TWICE, 'data', 'householdid = ? AND same = 1', [$householdid])
        );
        foreach ($logs as $data) {
            foreach ((array) (json_decode((string) $data, true)['samechildren'] ?? []) as $row) {
                if (!empty($row['id']) && !$DB->record_exists(households::CHILDREN, ['id' => (int) $row['id']])) {
                    drawings::delete((int) $row['id']);
                }
            }
        }
    }

    /**
     * Forgets the pairs kept for the inbox of a centre: two households were joined, separated or said to be different.
     *
     * @param int $centreid
     */
    public static function forget(int $centreid): void {
        \cache::make('local_oksigeniaeguahe', 'lookalikes')->delete($centreid);
    }

    /**
     * The households that may be written down twice, compared now.
     *
     * @param int $centreid
     * @param int $since
     * @return array[] [newer householdid, older householdid], the newest first.
     */
    protected static function compare(int $centreid, int $since): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT c.id, c.householdid, c.firstname, c.birthdate, c.offerid, c.stage, h.roster, h.timecreated
               FROM {' . households::CHILDREN . '} c
               JOIN {' . households::T . '} h ON h.id = c.householdid
              WHERE c.centreid = :centreid
           ORDER BY h.timecreated DESC, h.id DESC, c.id ASC',
            ['centreid' => $centreid]
        );
        $byname = [];
        // The words of each name, once: a big centre has thousands of children to set one against another.
        $words = [];
        $born = [];
        $whole = [];
        foreach ($rows as $row) {
            $born[(int) $row->id] = trim((string) $row->birthdate);
            $plain = self::name_key((string) $row->firstname);
            if ($plain !== '') {
                if ((int) $row->offerid) {
                    $byname[$plain . '|' . (int) $row->offerid][] = $row;
                }
                $words[(int) $row->id] = explode(' ', $plain);
                $whole[(int) $row->id] = self::whole_name((string) $row->firstname);
            }
        }
        $apart = [];
        foreach ($DB->get_records(self::T, ['centreid' => $centreid], '', 'id, householda, householdb') as $row) {
            $apart[$row->householda . ':' . $row->householdb] = true;
        }
        $pairs = [];
        $sur = self::surnames($centreid, array_map(fn($r) => (int) $r->householdid, $rows));
        // And those born the same day, one of whose names has all the words of the other.
        $byday = [];
        foreach ($rows as $row) {
            if (trim((string) $row->birthdate) !== '' && isset($words[(int) $row->id])) {
                $byday[trim((string) $row->birthdate)][] = $row;
            }
        }
        foreach (array_merge(array_values($byname), array_values($byday)) as $children) {
            $count = count($children);
            foreach ($children as $i => $one) {
                if ((int) $one->timecreated < $since) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    $other = $children[$j];
                    [$a, $b] = [(int) $one->householdid, (int) $other->householdid];
                    [$mine, $theirs] = [$born[(int) $one->id], $born[(int) $other->id]];
                    $sameday = $mine !== '' && $mine === $theirs;
                    $far = abs((int) $one->timecreated - (int) $other->timecreated);
                    // Of the same day, or of the same course and time: anything else is never one (why()).
                    if (
                        $a === $b || (!$sameday && (($mine !== '' && $theirs !== '') || !(int) $one->offerid ||
                            (int) $one->offerid !== (int) $other->offerid || $far > self::NEAR))
                    ) {
                        continue;
                    }
                    if ($sameday) {
                        [$short, $long] = [$words[(int) $one->id], $words[(int) $other->id]];
                        [$short, $long] = count($short) <= count($long) ? [$short, $long] : [$long, $short];
                        if (array_diff($short, $long)) {
                            continue;
                        }
                    }
                    $key = min($a, $b) . ':' . max($a, $b);
                    if (isset($apart[$key]) || isset($pairs[$key])) {
                        continue;
                    }
                    $shared = $sameday || $whole[(int) $one->id] || array_intersect_key($sur[$a] ?? [], $sur[$b] ?? []);
                    if ($shared && self::why($one, $other, $far, true) !== '') {
                        $pairs[$key] = [$a, $b];
                    }
                }
            }
        }
        // The same email or the same phone in two households, one of them come since.
        $same = ['email' => [], 'phone' => []];
        $came = [];
        $adults = $DB->get_recordset_sql(
            'SELECT a.id, a.householdid, a.email, a.phone, h.timecreated
               FROM {' . households::ADULTS . '} a
               JOIN {' . households::T . '} h ON h.id = a.householdid
              WHERE a.centreid = :centreid
           ORDER BY h.timecreated DESC, h.id DESC, a.id ASC',
            ['centreid' => $centreid]
        );
        foreach ($adults as $adult) {
            $came[(int) $adult->householdid] = (int) $adult->timecreated;
            foreach (['email', 'phone'] as $field) {
                $value = \core_text::strtolower(trim((string) $adult->$field));
                if ($value !== '') {
                    $same[$field][$value][(int) $adult->householdid] = true;
                }
            }
        }
        $adults->close();
        foreach (array_merge(array_values($same['email']), array_values($same['phone'])) as $homes) {
            $homes = array_keys($homes);
            foreach (count($homes) > 1 ? $homes : [] as $k => $a) {
                foreach ($came[$a] >= $since ? array_slice($homes, $k + 1) : [] as $b) {
                    $key = min($a, $b) . ':' . max($a, $b);
                    if (!isset($apart[$key]) && !isset($pairs[$key])) {
                        $pairs[$key] = [$a, $b];
                    }
                }
            }
        }
        return array_values($pairs);
    }

    /**
     * Puts two households of the same family together in the older one: its adults, its children (the same child
     * once), what was said, the permissions, what it answered, what it was promised and one sign-up for each act,
     * the largest. The links of both keep opening its page. The other is gone, and its history says so, with what could not
     * be in one file twice. Everything is kept in a log, to separate them again as they were.
     *
     * @param \stdClass $keep
     * @param \stdClass $gone
     * @param int $userid
     * @return \stdClass The household that stays.
     */
    public static function merge(\stdClass $keep, \stdClass $gone, int $userid): \stdClass {
        global $DB;
        if ((int) $keep->centreid !== (int) $gone->centreid || (int) $keep->id === (int) $gone->id) {
            throw new \coding_exception('Only two households of the same centre can be put together.');
        }
        if ((int) $gone->timecreated < (int) $keep->timecreated) {
            [$keep, $gone] = [$gone, $keep];
        }
        $k = (int) $keep->id;
        $g = (int) $gone->id;
        $transaction = $DB->start_delegated_transaction();
        // As they are now in the database, whatever the page had read.
        $keep = $DB->get_record(households::T, ['id' => $k], '*', MUST_EXIST);
        $gone = $DB->get_record(households::T, ['id' => $g], '*', MUST_EXIST);
        $before = households::full($gone);
        $log = ['v' => 2, 'gone' => $g, 'household' => array_diff_key((array) $gone, ['id' => 1]), 'keepwas' => (array) $keep,
            'tables' => [], 'adults' => [], 'children' => [], 'sameadults' => [], 'samechildren' => [], 'signups' => [],
            'joinedsignups' => [], 'changed' => [], 'deleted' => []];
        // What could not be in one file twice, said in its history.
        $told = [];
        $bythemselves = self::said_of_centre($keep) || self::said_of_centre($gone);
        $adults = self::merge_adults($k, $g, $log, $told);
        $children = self::merge_children($k, $g, $log, $told);
        self::merge_signups($k, $g, $children, $log);
        $log['adults'] = array_values(array_diff(array_map('intval', array_keys($before->adults)), array_keys($adults)));
        $log['children'] = array_values(array_diff(array_map('intval', array_keys($before->children)), array_keys($children)));
        self::merge_surveys($keep, $gone, $log, $told);
        self::merge_invites($k, $g, $log);
        self::merge_siblings($k, $g, $log);
        self::merge_requests($k, $g, $log);
        self::merge_list($k, $g, $children, $log);
        foreach (self::MOVED as $table) {
            $log['tables'][$table] = array_map('intval', $DB->get_fieldset_select($table, 'id', 'householdid = ?', [$g]));
            $DB->set_field($table, 'householdid', $k, ['householdid' => $g]);
        }
        self::merge_people($k, $adults, $log);
        self::merge_ties($k, $children, $bythemselves, $log);
        foreach ($DB->get_records_list(appointments::T, 'id', $log['tables'][appointments::T]) as $row) {
            $stages = self::renumber($row->stages, $children);
            if ((string) $stages !== (string) $row->stages) {
                self::change($log, appointments::T, $row, ['stages' => $stages]);
            }
        }
        // What the household says of itself: the older one keeps what it has, and takes what only the other knew.
        $changes = ['lastcontact' => max((int) $keep->lastcontact, (int) $gone->lastcontact)];
        $waiting = array_filter([(int) $keep->waitingsince, (int) $gone->waitingsince]);
        $changes['waitingsince'] = $waiting ? min($waiting) : 0;
        $waits = $changes['waitingsince'] && $changes['waitingsince'] === (int) $keep->waitingsince ? $keep : $gone;
        $changes['waitingdayonly'] = $changes['waitingsince'] ? (int) ($waits->waitingdayonly ?? 0) : 0;
        foreach (
            ['municipality', 'municipalityname', 'heardfrom', 'heardwho', 'heardnet', 'invitesign', 'whyother',
                'ownerid'] as $field
        ) {
            if (empty($keep->$field) && !empty($gone->$field)) {
                $changes[$field] = $gone->$field;
                if ($field === 'whyother') {
                    $changes['whyothertime'] = $gone->whyothertime;
                }
            }
        }
        // The family of the common record: the one a person confirmed for either; else the one Eguahe made for the other.
        // Never «brought from the roll»: one that came to the admissions is there, whatever the other was.
        $changes['familylink'] = family::merged($keep, $gone);
        // The arrival of the newer one, only when the older had none and it was not an ad: an ad is where a family came
        // from only when it was new.
        if (arrival::of($keep)[0] === '' && arrival::of($gone)[0] !== '' && !arrival::paid($gone)) {
            foreach (['arrivalsource', 'arrivalmedium', 'arrivalcampaign', 'arrivalref'] as $field) {
                $changes[$field] = $gone->$field;
            }
        }
        foreach (['incentre', 'moving', 'self', 'volunteer'] as $field) {
            if (empty($keep->$field) && !empty($gone->$field)) {
                $changes[$field] = $gone->$field;
            }
        }
        // Only a guest of an act when both were.
        $changes['guest'] = min((int) $keep->guest, (int) $gone->guest);
        // One of the two goes on, so it goes on: the other was the same family said to have gone. Both gone, as the last said.
        if (outs::is_out($keep) && (!outs::is_out($gone) || (int) $gone->outtime > (int) $keep->outtime)) {
            foreach (['outreason', 'outtime', 'outuserid', 'outnote', 'outstep', 'outstops', 'outwas'] as $field) {
                $changes[$field] = outs::is_out($gone) && $field !== 'outwas' ? $gone->$field : (in_array(
                    $field,
                    ['outtime', 'outuserid', 'outstep'],
                    true
                ) ? 0 : null);
            }
        }
        foreach (['thanked', 'applysent'] as $field) {
            $changes[$field] = max((int) $keep->$field, (int) $gone->$field);
        }
        // How they are written to: the most careful of the two (no messages, or the ways both allow), never lost. With
        // nothing in common, the ways of both: neither of them said that it wants nothing.
        $wk = writeby::chosen($keep);
        $wg = writeby::chosen($gone);
        if ($wk !== null || $wg !== null) {
            $ways = $wk === null ? $wg : ($wg === null ? $wk : array_values(array_intersect($wk, $wg)));
            if (!$ways && $wk && $wg) {
                $ways = array_merge($wk, $wg);
                $told[] = get_string('lookalike_told_ways', self::C);
            }
            $changes['writeby'] = writeby::raw($ways);
            $latest = (int) $gone->writebytime > (int) $keep->writebytime ? $gone : $keep;
            $changes['writebytime'] = max((int) $keep->writebytime, (int) $gone->writebytime);
            $changes['writebyfrom'] = $latest->writebyfrom;
        }
        $changes['writebynone'] = max((int) $keep->writebynone, (int) $gone->writebynone);
        $changes['noinvites'] = max((int) $keep->noinvites, (int) $gone->noinvites);
        // The summary of the AI said one half of the family: written again when someone asks.
        $changes['aisummary'] = null;
        $changes['aisummarytime'] = 0;
        $DB->update_record(households::T, (object) (['id' => $k, 'timemodified' => time()] + $changes));
        foreach (self::also($keep, $gone) as $line) {
            $told[] = $line;
        }
        // Said of one of them, «they are not the same family» as a third one: of the one that stays too.
        $log['notsame'] = [];
        $log['notsamenew'] = [];
        $mine = self::apart((int) $keep->centreid, $k);
        foreach (self::apart((int) $keep->centreid, $g) as $other => $true) {
            $log['notsame'][] = $other;
            if ($other !== $k && !isset($mine[$other])) {
                $log['notsamenew'][] = $other;
                $DB->insert_record(self::T, (object) ['centreid' => $keep->centreid, 'householda' => min($k, $other),
                    'householdb' => max($k, $other), 'userid' => $userid, 'timecreated' => time()]);
            }
        }
        $DB->delete_records_select(self::T, 'householda = :a OR householdb = :b', ['a' => $g, 'b' => $g]);
        // Its letters on their way go to the one that stays.
        invites::renumber($g, $k);
        self::join_phones($k, $g, $log);
        // What was put together in the other before comes with it.
        $log['logs'] = array_map('intval', $DB->get_fieldset_select(self::LOG, 'id', 'householdid = ?', [$g]));
        $DB->set_field(self::LOG, 'householdid', $k, ['householdid' => $g]);
        $DB->delete_records(households::T, ['id' => $g]);
        self::forget((int) $keep->centreid);
        who::reset();
        $log['keepnow'] = (array) $DB->get_record(households::T, ['id' => $k]);
        $DB->insert_record(self::LOG, (object) ['centreid' => $keep->centreid, 'householdid' => $k, 'goneid' => $g,
            'data' => json_encode($log, JSON_UNESCAPED_UNICODE), 'userid' => $userid, 'timecreated' => time()]);
        $kept = households::get($k, (int) $keep->centreid);
        households::note($kept, $userid, trim(get_string('lookalike_merged_note', self::C, (object) [
            'adults' => panel::adults_line($before->adults) ?: '—',
            'date' => userdate((int) $gone->timecreated, get_string('strftimedate', 'langconfig')),
        ]) . ($told ? "\n" . implode("\n", $told) : '')));
        $transaction->allow_commit();
        return households::get($k, (int) $keep->centreid);
    }

    /**
     * Changes a row and keeps in the log how it was and how it was left, to take it back.
     *
     * @param array $log
     * @param string $table
     * @param \stdClass $row
     * @param array $changes
     */
    protected static function change(array &$log, string $table, \stdClass $row, array $changes): void {
        global $DB;
        $was = [];
        $now = [];
        foreach ($changes as $field => $value) {
            if ((string) ($row->$field ?? '') !== (string) $value) {
                $was[$field] = $row->$field;
                $now[$field] = $value;
            }
        }
        if (!$now) {
            return;
        }
        $DB->update_record($table, (object) (['id' => $row->id] + $now));
        foreach ($now as $field => $value) {
            $row->$field = $value;
        }
        $id = (int) $row->id;
        $log['changed'][$table][$id]['was'] = ($log['changed'][$table][$id]['was'] ?? []) + $was;
        $log['changed'][$table][$id]['now'] = $now + ($log['changed'][$table][$id]['now'] ?? []);
    }

    /**
     * Takes out a row that cannot be there twice, and keeps it whole in the log.
     *
     * @param array $log
     * @param string $table
     * @param \stdClass $row
     */
    protected static function drop(array &$log, string $table, \stdClass $row): void {
        global $DB;
        $log['deleted'][$table][] = (array) $row;
        $DB->delete_records($table, ['id' => $row->id]);
    }

    /**
     * Puts back a row that was taken out, with the number it had when it is free.
     *
     * @param string $table
     * @param array $row
     * @return int
     */
    protected static function again(string $table, array $row): int {
        global $DB;
        $row = array_intersect_key($row, $DB->get_columns($table));
        $id = (int) ($row['id'] ?? 0);
        if ($id && !$DB->record_exists($table, ['id' => $id])) {
            $DB->insert_record_raw($table, $row, false, false, true);
            return $id;
        }
        unset($row['id']);
        return (int) $DB->insert_record($table, (object) $row);
    }

    /**
     * Whether a household is «of the centre» because the family or the team said it of it, not by a mark or a list.
     *
     * @param \stdClass $household
     * @return bool
     */
    protected static function said_of_centre(\stdClass $household): bool {
        global $DB;
        return (int) $household->incentre && !$DB->record_exists(ties::T, ['householdid' => $household->id, 'made' => 1]);
    }

    /**
     * The phones joined with the other household, on WhatsApp or Telegram, are of the one that stays; where Marandu cannot
     * move them, they join again from its page.
     *
     * @param int $keep
     * @param int $gone
     * @param array $log
     */
    protected static function join_phones(int $keep, int $gone, array &$log): void {
        global $DB;
        $api = '\\local_oksigeniamarandu\\api';
        if (class_exists($api) && method_exists($api, 'relink')) {
            try {
                $api::relink(self::C, $gone, $keep);
                return;
            } catch (\Throwable $e) {
                debugging('Eguahe: Marandu did not move household ' . $gone . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        // Who wrote on a phone was joined to one of the two: from now on an answer goes to the household, not to them.
        $DB->set_field_select(households::CONTACTS, 'address', null, 'householdid = ?', [$keep]);
        self::unlink_phones($gone);
    }

    /**
     * The phones joined with a household join again from its page.
     *
     * @param int $householdid
     */
    protected static function unlink_phones(int $householdid): void {
        if (class_exists('\\local_oksigeniamarandu\\api') && method_exists('\\local_oksigeniamarandu\\api', 'unlink')) {
            try {
                \local_oksigeniamarandu\api::unlink(self::C, $householdid);
            } catch (\Throwable $e) {
                debugging('Eguahe: Marandu did not unlink household ' . $householdid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * What the other household said of itself that this one says otherwise, in lines for its history.
     *
     * @param \stdClass $keep
     * @param \stdClass $gone
     * @return string[]
     */
    protected static function also(\stdClass $keep, \stdClass $gone): array {
        $out = [];
        if (!empty($keep->municipalityname) && !empty($gone->municipalityname) && $keep->municipalityname !== $gone->municipalityname) {
            $out[] = get_string('lookalike_told_lives', self::C, format_string($gone->municipalityname));
        }
        $heard = fn($h) => trim(get_string('heard_' . $h->heardfrom, self::C) . (trim((string) ($h->heardwho ?: $h->heardnet)) !== '' ?
            ': ' . format_string((string) ($h->heardwho ?: $h->heardnet)) : ''));
        if (
            !empty($keep->heardfrom) && in_array($gone->heardfrom, lists::HEARD_FROM, true) && ($gone->heardfrom !== $keep->heardfrom ||
                (trim((string) ($gone->heardwho . $gone->heardnet)) !== '' && $gone->heardwho . $gone->heardnet !== $keep->heardwho . $keep->heardnet))
        ) {
            $out[] = get_string('lookalike_told_heard', self::C, $heard($gone));
        }
        if (!empty($keep->whyother) && !empty($gone->whyother) && $keep->whyother !== $gone->whyother) {
            $out[] = get_string('lookalike_told_whyother', self::C, $gone->whyother);
        }
        if (outs::is_out($gone) && trim((string) $gone->outnote) !== '') {
            $out[] = get_string('lookalike_told_out', self::C, (object) ['reason' => get_string('out_' . $gone->outreason, self::C),
                'note' => $gone->outnote]);
        }
        return $out;
    }

    /**
     * The adults of the other household come to this one; the same person (the same email, or the same phone and name: a
     * phone of the home is of two people) only once. The permissions of a person go with them, but a «yes» given for an
     * address or a phone is never of another one.
     *
     * @param int $keep
     * @param int $gone
     * @param array $log
     * @param string[] $told What could not be there twice, in lines.
     * @return array The adults that were the same: their id in the other household => their id in this one.
     */
    protected static function merge_adults(int $keep, int $gone, array &$log, array &$told): array {
        global $DB;
        $here = $DB->get_records(households::ADULTS, ['householdid' => $keep], 'id ASC');
        $same = [];
        foreach ($DB->get_records(households::ADULTS, ['householdid' => $gone], 'id ASC') as $adult) {
            $match = null;
            foreach ($here as $mine) {
                $samemail = !empty($adult->email) && \core_text::strtolower($adult->email) === \core_text::strtolower((string) $mine->email);
                $samephone = !empty($adult->phone) && $adult->phone === $mine->phone;
                if ($samemail || ($samephone && self::same_person($adult, $mine))) {
                    $match = $mine;
                    break;
                }
            }
            if (!$match) {
                $DB->set_field(households::ADULTS, 'householdid', $keep, ['id' => $adult->id]);
                continue;
            }
            $fill = [];
            foreach (['email', 'phone', 'firstname', 'lastname'] as $field) {
                if (trim((string) $match->$field) === '' && trim((string) $adult->$field) !== '') {
                    $fill[$field] = $adult->$field;
                    if ($field === 'email') {
                        $fill['emailpage'] = $adult->emailpage;
                    }
                }
            }
            self::change($log, households::ADULTS, $match, $fill);
            $others = [];
            $name = fn(\stdClass $a) => trim($a->firstname . ' ' . $a->lastname);
            foreach (['email', 'phone'] as $field) {
                if (!empty($adult->$field) && \core_text::strtolower($adult->$field) !== \core_text::strtolower((string) $match->$field)) {
                    $others[] = $field === 'phone' ? phone::display($adult->phone) : $adult->email;
                }
            }
            if ($name($adult) !== '' && grades::plain($name($adult)) !== grades::plain($name($match))) {
                array_unshift($others, $name($adult));
            }
            if ($others) {
                $told[] = get_string('lookalike_told_adult', self::C, (object) ['who' => $name($match) ?: '—',
                    'others' => implode(' · ', $others)]);
            }
            $entry = ['into' => (int) $match->id, 'row' => (array) $adult, 'consents' => [], 'dropped' => [], 'hobbies' => [], 'alumni' => []];
            foreach ($DB->get_records(households::CONSENTS, ['adultid' => $adult->id], 'timecreated ASC, id ASC') as $consent) {
                $field = $consent->channel === 'email' ? 'email' : 'phone';
                if ((int) $consent->granted && (string) $adult->$field !== '' && $adult->$field !== $match->$field) {
                    // A «yes» to be written to at an address this person no longer has here: never of the other address.
                    $entry['dropped'][] = (array) $consent;
                    $DB->delete_records(households::CONSENTS, ['id' => $consent->id]);
                    continue;
                }
                $entry['consents'][] = (int) $consent->id;
                $DB->set_field(households::CONSENTS, 'adultid', $match->id, ['id' => $consent->id]);
            }
            $same[(int) $adult->id] = (int) $match->id;
            $log['sameadults'][] = $entry;
            $DB->delete_records(households::ADULTS, ['id' => $adult->id]);
        }
        return $same;
    }

    /**
     * Whether two adults with the same phone are the same person: by their names, or because one of them has none (and each
     * does not write from its own address).
     *
     * @param \stdClass $a
     * @param \stdClass $b
     * @return bool
     */
    protected static function same_person(\stdClass $a, \stdClass $b): bool {
        $alike = households::named_alike($a, $b);
        return $alike ?? (empty($a->email) || empty($b->email) || \core_text::strtolower($a->email) === \core_text::strtolower($b->email));
    }

    /**
     * What each adult likes and whether they studied at the centre, of the same adult once.
     *
     * @param int $keep
     * @param array $adults From merge_adults().
     * @param array $log
     */
    protected static function merge_people(int $keep, array $adults, array &$log): void {
        global $DB;
        foreach ($adults as $from => $to) {
            foreach ($log['sameadults'] as $i => $entry) {
                if ((int) $entry['row']['id'] === $from) {
                    foreach (['hobbies' => hobbies::T, 'alumni' => alumni::T] as $key => $table) {
                        $log['sameadults'][$i][$key] = array_map('intval', $DB->get_fieldset_select(
                            $table,
                            'id',
                            'householdid = ? AND adultid = ?',
                            [$keep, $from]
                        ));
                    }
                }
            }
            $DB->set_field(hobbies::T, 'adultid', $to, ['householdid' => $keep, 'adultid' => $from]);
            $DB->set_field(alumni::T, 'adultid', $to, ['householdid' => $keep, 'adultid' => $from]);
        }
        $seen = [];
        foreach ($DB->get_records(hobbies::T, ['householdid' => $keep], 'id ASC') as $row) {
            $key = $row->adultid . ':' . $row->hobby . ':' . grades::plain((string) $row->other);
            if (isset($seen[$key])) {
                self::drop($log, hobbies::T, $row);
            }
            $seen[$key] = true;
        }
        $said = [];
        // What a person said is over what a list or a sheet says; of two, the last one.
        $weight = fn(\stdClass $row) => [in_array($row->source, alumni::PEOPLE, true) ? 1 : 0, (int) $row->said === alumni::YES ? 1 : 0,
            (int) $row->timecreated, (int) $row->id];
        foreach ($DB->get_records(alumni::T, ['householdid' => $keep], 'id ASC') as $row) {
            $mine = $said[(int) $row->adultid] ?? null;
            if (!$mine) {
                $said[(int) $row->adultid] = $row;
                continue;
            }
            [$stays, $goes] = $weight($row) > $weight($mine) ? [$row, $mine] : [$mine, $row];
            if (trim((string) $stays->year) === '' && trim((string) $goes->year) !== '') {
                self::change($log, alumni::T, $stays, ['year' => $goes->year]);
            }
            self::drop($log, alumni::T, $goes);
            $said[(int) $row->adultid] = $stays;
        }
    }

    /**
     * The children of the other household come to this one; the same child (the same name, and the same birthday when
     * both have one; one without a name asking for the same) only once, with what only the other knew of it and one way:
     * the course and its step go together. A child already enrolled and the same one asking for a later year stay two.
     *
     * @param int $keep
     * @param int $gone
     * @param array $log
     * @param string[] $told What could not be there twice, in lines.
     * @return array The children that were the same: their id in the other household => their id in this one.
     */
    protected static function merge_children(int $keep, int $gone, array &$log, array &$told): array {
        global $DB;
        $here = $DB->get_records(households::CHILDREN, ['householdid' => $keep], 'id ASC');
        $theirs = $DB->get_records(households::CHILDREN, ['householdid' => $gone], 'id ASC');
        $first = reset($here) ?: reset($theirs);
        $years = $first ? $DB->get_records_menu(centres::OFFERS, ['centreid' => $first->centreid], '', 'id, schoolyear') : [];
        $pairs = self::same_children($here, $theirs, $years);
        $same = [];
        foreach ($theirs as $child) {
            $match = $here[$pairs[(int) $child->id] ?? 0] ?? null;
            if (!$match) {
                $DB->set_field(households::CHILDREN, 'householdid', $keep, ['id' => $child->id]);
                continue;
            }
            self::one_child($match, $child, $years, $log, $told);
            $same[(int) $child->id] = (int) $match->id;
        }
        return $same;
    }

    /**
     * Two lines of the same child become the first one, with what only the other knew of it and one way: the course and its
     * step go together. The other goes, kept whole in the log.
     *
     * @param \stdClass $match The one that stays.
     * @param \stdClass $child The one that goes.
     * @param array $years Offer id => its school year.
     * @param array $log
     * @param string[] $told What could not be there twice, in lines.
     */
    protected static function one_child(\stdClass $match, \stdClass $child, array $years, array &$log, array &$told): void {
        global $DB;
        $fill = ['timemodified' => time()];
        foreach (['firstname', 'birthdate', 'tagname', 'latinname', 'familychild', 'startyear'] as $field) {
            if (trim((string) $match->$field) === '' || (string) $match->$field === '0') {
                if (trim((string) $child->$field) !== '' && (string) $child->$field !== '0') {
                    $fill[$field] = $child->$field;
                }
            }
        }
        $winner = self::way($match, $child, $years);
        $loser = $winner === $child ? $match : $child;
        if ($winner === $child) {
            foreach (
                ['offerid', 'stage', 'stagetime', 'closedreason', 'closedstage', 'started', 'lefttime', 'leftreason', 'leftnote',
                    'familygone', 'roster'] as $field
            ) {
                $fill[$field] = $child->$field;
            }
        }
        if (!(int) $winner->offerid && (int) $loser->offerid) {
            $fill['offerid'] = $loser->offerid;
        }
        if (
            ((int) $loser->offerid && (int) $loser->offerid !== (int) ($fill['offerid'] ?? $match->offerid)) ||
                $loser->stage !== $winner->stage || (int) $loser->lefttime !== (int) $winner->lefttime
        ) {
            $told[] = get_string('lookalike_told_child', self::C, (object) ['name' => panel::tag($match) ?: panel::tag($child) ?: '—',
                'way' => self::way_words($loser)]);
        }
        $log['samechildren'][] = ['into' => (int) $match->id, 'id' => (int) $child->id] + (array) $child;
        self::change($log, households::CHILDREN, $match, $fill);
        // Its answer about next year: the last of the two for the same year.
        foreach ($DB->get_records(renewal::T, ['childid' => $child->id]) as $row) {
            $mine = $DB->get_record(renewal::T, ['childid' => $match->id, 'schoolyear' => $row->schoolyear]);
            if ($mine && (int) $mine->timemodified >= (int) $row->timemodified) {
                self::drop($log, renewal::T, $row);
                continue;
            }
            if ($mine) {
                self::drop($log, renewal::T, $mine);
            }
            self::change($log, renewal::T, $row, ['childid' => $match->id]);
        }
        // Its drawing, unless this one already has one; the other one's stays kept with it, to have it back.
        self::copy_drawing((int) $child->id, (int) $match->id);
        $DB->delete_records(households::CHILDREN, ['id' => $child->id]);
    }

    /**
     * Which children of two households are the same one.
     *
     * @param \stdClass[] $here
     * @param \stdClass[] $theirs
     * @param array $years Offer id => its school year.
     * @return array id of theirs => id here
     */
    protected static function same_children(array $here, array $theirs, array $years): array {
        $pairs = [];
        $birth = fn($a, $b) => trim((string) $a->birthdate) === '' || trim((string) $b->birthdate) === '' ||
            trim((string) $a->birthdate) === trim((string) $b->birthdate);
        $year = fn($k) => (string) ($years[(int) $k->offerid] ?? '');
        $settled = fn($k) => $k->stage === 'enrolled' && !(int) ($k->lefttime ?? 0);
        $open = fn($k) => !in_array($k->stage, ['enrolled', 'closed'], true);
        // Already at the centre, and asking for a later year: both are true at once, and one line cannot say both.
        $both = fn($a, $b) => ($settled($a) && $open($b) && $year($b) > $year($a) && $year($a) !== '') ||
            ($settled($b) && $open($a) && $year($a) > $year($b) && $year($b) !== '');
        $try = function (callable $fits) use (&$pairs, $here, $theirs, $birth, $both): void {
            foreach ($theirs as $child) {
                if (isset($pairs[(int) $child->id])) {
                    continue;
                }
                foreach ($here as $mine) {
                    if (!in_array((int) $mine->id, $pairs, true) && $birth($mine, $child) && !$both($mine, $child) && $fits($mine, $child)) {
                        $pairs[(int) $child->id] = (int) $mine->id;
                        break;
                    }
                }
            }
        };
        $named = fn($k) => trim((string) $k->firstname) !== '';
        $try(fn($a, $b) => $named($a) && $named($b) && self::name_key((string) $a->firstname) === self::name_key((string) $b->firstname));
        // «Martín» and «Martín Pérez»: the same one, when no other could be.
        $short = fn($list, $name) => array_filter($list, fn($k) => $named($k) && households::same_name((string) $k->firstname, $name));
        $try(fn($a, $b) => $named($a) && $named($b) && households::same_name((string) $a->firstname, (string) $b->firstname) &&
            count($short($here, (string) $b->firstname)) === 1 && count($short($theirs, (string) $a->firstname)) === 1);
        // One without a name (a contact of an ad) and one that asks for the same, or where one says no course yet.
        $course = fn($a, $b) => (int) $a->offerid === (int) $b->offerid || !(int) $a->offerid || !(int) $b->offerid;
        $try(fn($a, $b) => (!$named($a) || !$named($b)) && $course($a, $b) && !$settled($a) && !$settled($b));
        return $pairs;
    }

    /**
     * Of two lines of the same child, the one whose course and step stay.
     *
     * @param \stdClass $mine
     * @param \stdClass $other
     * @param array $years Offer id => its school year.
     * @return \stdClass One of the two.
     */
    protected static function way(\stdClass $mine, \stdClass $other, array $years): \stdClass {
        $left = fn($k) => $k->stage === 'enrolled' && (int) ($k->lefttime ?? 0);
        $settled = fn($k) => $k->stage === 'enrolled' && !(int) ($k->lefttime ?? 0);
        $open = fn($k) => !in_array($k->stage, ['enrolled', 'closed'], true);
        $year = fn($k) => (string) ($years[(int) $k->offerid] ?? '');
        $since = fn($k) => max((int) $k->stagetime, (int) $k->timecreated);
        if ($settled($mine) && $settled($other)) {
            // Enrolled in both: the later year; one that came by the admissions before one brought from the roll.
            return [$year($other), (int) empty($other->roster)] > [$year($mine), (int) empty($mine->roster)] ? $other : $mine;
        }
        if ($settled($mine) || $settled($other)) {
            return $settled($mine) ? $mine : $other;
        }
        foreach ([[$mine, $other], [$other, $mine]] as [$a, $b]) {
            if ($left($a) && $open($b)) {
                // It left, and it is asked for again since; or it is an old line of before it was there.
                return $since($b) > (int) $a->lefttime || ($year($b) > $year($a) && $year($a) !== '') ? $b : $a;
            }
        }
        foreach ([[$mine, $other], [$other, $mine]] as [$a, $b]) {
            if (($open($a) && !$open($b)) || ($left($a) && $b->stage === 'closed')) {
                return $a;
            }
        }
        if (!$open($mine)) {
            return $since($other) > $since($mine) ? $other : $mine;
        }
        if ($year($mine) !== $year($other) && $year($mine) !== '' && $year($other) !== '') {
            return $year($other) > $year($mine) ? $other : $mine;
        }
        $step = fn($k) => lists::STAGE_STEP[$k->stage] ?? 1;
        if ($step($mine) !== $step($other)) {
            return $step($other) > $step($mine) ? $other : $mine;
        }
        if ((int) $mine->offerid === (int) $other->offerid || !(int) $other->offerid) {
            return $mine;
        }
        return !(int) $mine->offerid || $since($other) > $since($mine) ? $other : $mine;
    }

    /**
     * What a child asked for and how far it had come, in words.
     *
     * @param \stdClass $child
     * @return string
     */
    protected static function way_words(\stdClass $child): string {
        $offer = (int) $child->offerid ? (centres::offers((int) $child->centreid)[(int) $child->offerid] ?? null) : null;
        $step = get_string('stage_' . $child->stage, self::C);
        if ($child->stage === 'closed' && trim((string) $child->closedreason) !== '') {
            $step .= ' (' . get_string('closed_' . $child->closedreason, self::C) . ')';
        }
        if ((int) ($child->lefttime ?? 0)) {
            $step .= ' (' . get_string('left_note', self::C, get_string('left_' . ($child->leftreason ?: 'unknown'), self::C)) . ')';
        }
        return ($offer ? centres::offer_label($offer) : get_string('offer_unknown', self::C)) . ' · ' . $step;
    }

    /**
     * The steps of some children kept with a sign-up or a mark, with the numbers they have now.
     *
     * @param string|null $was childid:stage, separated by commas.
     * @param array $children Child of the other household => the same child in this one.
     * @return string|null
     */
    protected static function renumber(?string $was, array $children): ?string {
        if ($was === null || $was === '') {
            return $was;
        }
        $pairs = [];
        foreach (explode(',', $was) as $pair) {
            [$id, $stage] = array_pad(explode(':', $pair, 2), 2, '');
            $id = $children[(int) $id] ?? (int) $id;
            $pairs[$id] = $pairs[$id] ?? $id . ':' . $stage;
        }
        return implode(',', $pairs);
    }

    /**
     * One sign-up for each act: the one already there, with the most people of the two, and coming if either came.
     *
     * @param int $keep
     * @param int $gone
     * @param array $children The children that were the same, from merge_children().
     * @param array $log
     */
    protected static function merge_signups(int $keep, int $gone, array $children, array &$log): void {
        global $DB;
        $here = [];
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $keep]) as $row) {
            $here[(int) $row->eventid] = $row;
        }
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $gone], 'id ASC') as $row) {
            $mine = $here[(int) $row->eventid] ?? null;
            if (!$mine) {
                $log['signups'][] = (int) $row->id;
                self::change($log, households::SIGNUPS, $row, ['wasstages' => self::renumber($row->wasstages, $children)]);
                $DB->set_field(households::SIGNUPS, 'householdid', $keep, ['id' => $row->id]);
                continue;
            }
            $log['joinedsignups'][] = (array) $row;
            $came = max((int) $mine->attended, (int) $row->attended);
            $was = implode(',', array_filter([(string) $mine->wasstages, (string) self::renumber($row->wasstages, $children)]));
            self::change($log, households::SIGNUPS, $mine, [
                'people' => max((int) $mine->people, (int) $row->people),
                'attended' => $came,
                // They came if either did: never that and that they did not.
                'missed' => $came ? 0 : max((int) $mine->missed, (int) $row->missed),
                'thanked' => max((int) $mine->thanked, (int) $row->thanked),
                'reminded' => max((int) $mine->reminded, (int) $row->reminded),
                'slotid' => (int) $mine->slotid ?: (int) $row->slotid,
                'wasstages' => $was === '' ? null : self::renumber($was, [])]);
            $DB->delete_records(households::SIGNUPS, ['id' => $row->id]);
            // What was said of it, of the one that stays.
            foreach ($DB->get_records(appointments::T, ['householdid' => $gone, 'item' => 's' . $row->id]) as $mark) {
                self::change($log, appointments::T, $mark, ['item' => 's' . $mine->id]);
            }
        }
    }

    /**
     * The answers of a survey: one each. The last one stays; the other, in a line of the history.
     *
     * @param \stdClass $keep
     * @param \stdClass $gone
     * @param array $log
     * @param string[] $told
     */
    protected static function merge_surveys(\stdClass $keep, \stdClass $gone, array &$log, array &$told): void {
        global $DB;
        $have = [];
        foreach ($DB->get_records(surveys::T, ['householdid' => $keep->id]) as $row) {
            $have[$row->eventid . ':' . $row->fieldid] = $row;
        }
        $label = function (\stdClass $row): string {
            global $DB;
            $field = (int) $row->fieldid ? $DB->get_record(forms::FIELDS, ['id' => $row->fieldid]) : null;
            $words = $field ? forms::texts($field, current_language(), 'es')['label'] : '';
            return $words !== '' ? format_string($words) : get_string('lookalike_told_survey', self::C);
        };
        foreach ($DB->get_records(surveys::T, ['householdid' => $gone->id], 'id ASC') as $row) {
            $mine = $have[$row->eventid . ':' . $row->fieldid] ?? null;
            if (!$mine) {
                continue;
            }
            [$stays, $goes] = (int) $row->timemodified > (int) $mine->timemodified ? [$row, $mine] : [$mine, $row];
            if (trim((string) $goes->value) !== '' && trim((string) $goes->value) !== trim((string) $stays->value)) {
                $told[] = get_string('lookalike_told_answer', self::C, (object) ['question' => $label($goes), 'answer' => $goes->value]);
            }
            self::drop($log, surveys::T, $goes);
        }
    }

    /**
     * One letter for each act both were invited to: what they said last, and that they come when they are signed up.
     *
     * @param int $keep
     * @param int $gone
     * @param array $log
     */
    protected static function merge_invites(int $keep, int $gone, array &$log): void {
        global $DB;
        $here = [];
        foreach ($DB->get_records(invites::T, ['householdid' => $keep], 'id ASC') as $row) {
            $here[(int) $row->eventid] = $row;
        }
        foreach ($DB->get_records(invites::T, ['householdid' => $gone], 'id ASC') as $row) {
            $mine = $here[(int) $row->eventid] ?? null;
            if (!$mine) {
                continue;
            }
            $last = (int) $row->replied > (int) $mine->replied ? $row : $mine;
            $comes = $DB->record_exists(households::SIGNUPS, ['householdid' => $keep, 'eventid' => $row->eventid]);
            $ways = fn($r) => (string) $r->channel ?: 'email';
            self::change($log, invites::T, $mine, ['reply' => $comes && (string) $last->reply !== '' ? 'yes' : $last->reply,
                'replied' => $last->replied, 'opened' => min(array_filter([(int) $mine->opened, (int) $row->opened]) ?: [0]),
                'channel' => $ways($mine) === $ways($row) ? $mine->channel : 'both',
                'bulkid' => (int) $mine->bulkid ?: (int) $row->bulkid]);
            self::drop($log, invites::T, $row);
        }
    }

    /**
     * Each brother or sister for later, once.
     *
     * @param int $keep
     * @param int $gone
     * @param array $log
     */
    protected static function merge_siblings(int $keep, int $gone, array &$log): void {
        global $DB;
        $here = $DB->get_records(siblings::T, ['householdid' => $keep], 'id ASC');
        foreach ($DB->get_records(siblings::T, ['householdid' => $gone], 'id ASC') as $row) {
            foreach ($here as $mine) {
                if ((int) $mine->birthyear === (int) $row->birthyear && self::name_key($mine->firstname) === self::name_key($row->firstname)) {
                    self::change($log, siblings::T, $mine, ['notify' => max((int) $mine->notify, (int) $row->notify),
                        'proposed' => max((int) $mine->proposed, (int) $row->proposed),
                        'confirmed' => max((int) $mine->confirmed, (int) $row->confirmed)]);
                    self::drop($log, siblings::T, $row);
                    break;
                }
            }
        }
    }

    /**
     * Of two that asked for a day, the last one is what they are waiting for.
     *
     * @param int $keep
     * @param int $gone
     * @param array $log
     */
    protected static function merge_requests(int $keep, int $gone, array &$log): void {
        global $DB;
        $pending = $DB->get_records_select(
            visits::REQUESTS,
            "householdid IN (?, ?) AND status = 'pending'",
            [$keep, $gone],
            'timemodified DESC, id DESC'
        );
        array_shift($pending);
        foreach ($pending as $row) {
            self::change($log, visits::REQUESTS, $row, ['status' => 'withdrawn']);
        }
    }

    /**
     * What the lists of the centre say, once, and of the child that stays.
     *
     * @param int $keep
     * @param int $gone
     * @param array $children Child of the other household => the same child in this one.
     * @param array $log
     */
    protected static function merge_list(int $keep, int $gone, array $children, array &$log): void {
        global $DB;
        $rows = $DB->get_records(listmatch::T, ['householdid' => $keep], 'id ASC');
        foreach ($DB->get_records(listmatch::T, ['householdid' => $gone], 'id ASC') as $row) {
            if ((int) $row->childid && isset($children[(int) $row->childid])) {
                self::change($log, listmatch::T, $row, ['childid' => $children[(int) $row->childid]]);
            }
            $rows[$row->id] = $row;
        }
        // Of each student and year, what a person said, or the one that says most.
        $rank = array_flip(listmatch::RANK);
        uasort($rows, fn($a, $b) => [(int) $b->said, $rank[$a->kind] ?? 9, (int) $a->id] <=>
            [(int) $a->said, $rank[$b->kind] ?? 9, (int) $b->id]);
        $seen = [];
        foreach ($rows as $row) {
            $key = $row->student . ':' . $row->schoolyear;
            if (!isset($seen[$key])) {
                $seen[$key] = $row;
                continue;
            }
            if ((int) $row->marked === listmatch::MADE && $row->kind === 'incentre' && $seen[$key]->kind === 'incentre') {
                self::change($log, listmatch::T, $seen[$key], ['marked' => listmatch::MADE]);
            }
            self::drop($log, listmatch::T, $row);
        }
    }

    /**
     * What the team marked of both, once, and of the child that stays. When one of them was of the centre because the family
     * or the team said so, no mark made it so: taking one out changes nothing of it.
     *
     * @param int $keep
     * @param array $children Child of the other household => the same child in this one.
     * @param bool $bythemselves
     * @param array $log
     */
    protected static function merge_ties(int $keep, array $children, bool $bythemselves, array &$log): void {
        global $DB;
        $seen = [];
        foreach ($DB->get_records(ties::T, ['householdid' => $keep], 'id ASC') as $row) {
            $changes = ['childid' => $children[(int) $row->childid] ?? (int) $row->childid] + ($bythemselves ? ['made' => 0] : []);
            self::change($log, ties::T, $row, $changes);
            $key = $changes['childid'] . ':' . $row->kind;
            if (!isset($seen[$key])) {
                $seen[$key] = $row;
                continue;
            }
            if ((int) $row->made && !$bythemselves) {
                self::change($log, ties::T, $seen[$key], ['made' => 1]);
            }
            self::drop($log, ties::T, $row);
        }
    }

    /**
     * What the file of a household shows: the families that may be this one, with what says so and the two answers.
     *
     * @param \stdClass $household
     * @param bool $canact
     * @return array|false
     */
    public static function context(\stdClass $household, bool $canact) {
        $found = self::of($household);
        if (!$found) {
            return false;
        }
        $c = self::C;
        $action = (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false);
        $out = [];
        foreach ($found as $id => $why) {
            $other = households::get((int) $id, (int) $household->centreid);
            if (!$other) {
                continue;
            }
            $full = households::full($other);
            $who = panel::adults_line($full->adults) ?: get_string('household_noname', $c);
            $reason = in_array($why['why'], ['email', 'phone'], true) ? get_string('lookalike_why_' . $why['why'], $c, $why['why'] === 'phone' ?
                phone::display((string) $why['phone']) : $why['email']) : ($why['why'] === 'birthdate' ?
                get_string('lookalike_why_birthdate', $c, (object) ['child' => format_string($why['child']),
                    'date' => userdate((int) strtotime($why['birthdate'] . ' 12:00'), get_string('strftimedate', 'langconfig'))]) :
                get_string('lookalike_why_course', $c, format_string($why['child'])));
            $older = (int) $other->timecreated <= (int) $household->timecreated;
            $out[] = [
                'id' => (int) $id,
                'line' => get_string('lookalike_line', $c, (object) ['who' => $who, 'since' => userdate(
                    (int) $other->timecreated,
                    get_string('strftimedateshort', 'langconfig')
                )]),
                'reason' => $reason,
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $id]))->out(false),
                'what' => get_string($older ? 'lookalike_merge_into_other' : 'lookalike_merge_into_this', $c, $who),
                'notes' => $canact ? array_map(fn($line) => ['text' => $line], self::preview($household, $other)) : [],
            ];
        }
        return $out ? ['others' => $out, 'canact' => $canact, 'action' => $action, 'sesskey' => sesskey()] : false;
    }

    /**
     * Another file of the centre to put this one together with, found by a name, an email or a phone: those Eguahe did not
     * recognise.
     *
     * @param \stdClass $household
     * @param string $query
     * @return array open, id, query, searchurl, none and found (id, line, url, what, notes)
     */
    public static function by_hand(\stdClass $household, string $query): array {
        $c = self::C;
        $query = trim($query);
        $found = [];
        if ($query !== '') {
            // A phone as anyone writes it, too.
            $centre = centres::get((int) $household->centreid);
            $phone = preg_match('/^[0-9+() .-]{6,}$/', $query) ? phone::normalise($query, $centre->country ?? null) : null;
            [$list] = households::search((int) $household->centreid, $phone ?? $query, false, 8);
            foreach ($list as $other) {
                if ((int) $other->id === (int) $household->id) {
                    continue;
                }
                $full = households::full($other);
                $who = panel::adults_line($full->adults) ?: get_string('household_noname', $c);
                $tags = array_filter(array_map(fn($k) => panel::tag($k), $full->children));
                $older = (int) $other->timecreated <= (int) $household->timecreated;
                $found[] = ['id' => (int) $other->id, 'line' => get_string('lookalike_line', $c, (object) ['who' => $who .
                    ($tags ? ' (' . implode(', ', $tags) . ')' : ''), 'since' => userdate((int) $other->timecreated, get_string(
                        'strftimedateshort',
                        'langconfig'
                    ))]), 'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $other->id]))->out(false),
                    'what' => get_string($older ? 'lookalike_merge_into_other' : 'lookalike_merge_into_this', $c, $who),
                    'notes' => array_map(fn($line) => ['text' => $line], self::preview($household, $other))];
            }
        }
        return ['open' => $query !== '', 'id' => (int) $household->id, 'query' => $query, 'searchurl' => (new \moodle_url(
            '/local/oksigeniaeguahe/household.php'
        ))->out(false), 'none' => $query !== '' && !$found ? get_string('panel_search_nothing', $c, $query) : '', 'found' => $found,
            'sesskey' => sesskey(), 'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false)];
    }

    /**
     * What putting two households together does, before a person says it: who is the same person, the course and the step of
     * each child written twice, two appointments, and what either asked not to get.
     *
     * @param \stdClass $household
     * @param \stdClass $other
     * @return string[]
     */
    public static function preview(\stdClass $household, \stdClass $other): array {
        $c = self::C;
        [$keep, $gone] = (int) $other->timecreated < (int) $household->timecreated ? [$other, $household] : [$household, $other];
        $mine = households::full($keep);
        $theirs = households::full($gone);
        $out = [];
        foreach ($theirs->adults as $adult) {
            foreach ($mine->adults as $one) {
                $samemail = !empty($adult->email) && \core_text::strtolower($adult->email) === \core_text::strtolower((string) $one->email);
                if ($samemail || (!empty($adult->phone) && $adult->phone === $one->phone && self::same_person($adult, $one))) {
                    $out[] = get_string('lookalike_pre_adult', $c, trim($one->firstname . ' ' . $one->lastname) ?: (string) $one->email);
                    break;
                }
            }
        }
        $first = reset($mine->children) ?: reset($theirs->children);
        $years = [];
        foreach ($first ? centres::offers((int) $keep->centreid) : [] as $id => $offer) {
            $years[(int) $id] = (string) $offer->schoolyear;
        }
        foreach (self::same_children($mine->children, $theirs->children, $years) as $from => $to) {
            $one = $mine->children[$to];
            $two = $theirs->children[$from];
            $winner = self::way($one, $two, $years);
            $loser = $winner === $one ? $two : $one;
            $a = (object) ['name' => panel::tag($one) ?: panel::tag($two) ?: '—', 'way' => self::way_words($winner), 'other' => self::way_words($loser)];
            $out[] = get_string($a->way === $a->other ? 'lookalike_pre_child' : 'lookalike_pre_child_ways', $c, $a);
        }
        $coming = fn(\stdClass $full) => array_values(array_filter(
            appointments::of($full),
            fn($o) => $o['kind'] !== 'act' && in_array($o['state'], ['coming', 'asked'], true)
        ));
        if ($coming($mine) && $coming($theirs)) {
            $out[] = get_string('lookalike_pre_visits', $c);
        }
        if (writeby::silent($keep) !== writeby::silent($gone)) {
            $out[] = get_string('lookalike_pre_silent', $c);
        }
        if ((bool) (int) $keep->noinvites !== (bool) (int) $gone->noinvites) {
            $out[] = get_string('lookalike_pre_noinvites', $c);
        }
        return $out;
    }

    /**
     * Separates a household in two, when it was two families: the adults chosen go to a new one with their permissions
     * and what they like; each child stays, goes or is in both; each sign-up to an act stays, goes or is in both (a visit of
     * its own, only one of them); the lines of the conversation, what it asked for and what it was promised chosen go too.
     * Coming from two households put together (its log), each is as it was, but for what was said since: the new one with
     * the number it had. Without a log, the links sent since the first adult that goes arrived stop working: both families
     * may have them.
     *
     * @param \stdClass $household
     * @param array $choice adults (ids that go), both (ids of those that were the same person in the two put together),
     *     children (id => go or both), signups (event id => go or both), contacts (ids that go), things (r and the id of
     *     something asked for, or p and the id of a promise => go) and log (the id of the log of a merge, or 0).
     * @param int $userid
     * @return \stdClass The new household.
     */
    public static function split(\stdClass $household, array $choice, int $userid): \stdClass {
        global $DB;
        $h = (int) $household->id;
        $household = $DB->get_record(households::T, ['id' => $h], '*', MUST_EXIST);
        $full = households::full($household);
        $centreid = (int) $household->centreid;
        $going = array_values(array_intersect(array_map('intval', $choice['adults'] ?? []), array_keys($full->adults)));
        $logrow = !empty($choice['log']) ? $DB->get_record(self::LOG, ['id' => (int) $choice['log'], 'householdid' => $h]) : null;
        $log = $logrow ? (array) json_decode((string) $logrow->data, true) : [];
        $twice = array_map(fn($e) => (int) $e['into'], (array) ($log['sameadults'] ?? []));
        $both = array_values(array_diff(array_intersect(array_map('intval', $choice['both'] ?? []), array_keys($full->adults), $twice), $going));
        if (!$going && !$both || count($going) >= count($full->adults)) {
            throw new \moodle_exception('lookalike_split_adults_error', self::C);
        }
        if (!$both && !array_intersect($going, array_map('intval', (array) ($log['adults'] ?? [])))) {
            // None of the ones that came: it is not taking back that time, it is another thing.
            $log = [];
            $logrow = null;
        }
        $exact = (int) ($log['v'] ?? 1) >= 2;
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $family = self::family_of($household);
        $first = min(array_map(fn($id) => (int) $full->adults[$id]->timecreated ?: $now, $going ?: $both));
        $was = (array) ($log['household'] ?? []);
        $id = self::other_half($household, $log, $was, $first);
        if ($exact) {
            self::as_it_was($household, (array) $log['keepwas'], (array) $log['keepnow']);
        }
        // The link with the common record of families: back as it was when it comes from undoing a merge; else nobody
        // knows which half it belongs to, so neither keeps it and the file asks again.
        if (!$log || (!$exact && (int) ($was['familylink'] ?? 0) === (int) $household->familylink)) {
            $DB->set_field(households::T, 'familylink', 0, ['id' => $h]);
        }
        // The phones joined with the family join again from the page of each half; who wrote is forgotten in both.
        $DB->set_field_select(households::CONTACTS, 'address', null, 'householdid = ?', [$h]);
        self::unlink_phones($h);
        self::forget($centreid);
        // The adults, with their permissions and what they like.
        if ($going) {
            [$in, $params] = $DB->get_in_or_equal($going);
            $DB->set_field_select(households::ADULTS, 'householdid', $id, "id $in", $params);
            $DB->set_field_select(households::CONSENTS, 'householdid', $id, "adultid $in", $params);
            $DB->set_field_select(hobbies::T, 'householdid', $id, "adultid $in", $params);
            $DB->set_field_select(alumni::T, 'householdid', $id, "adultid $in", $params);
        }
        self::adults_again($both, $log, $id);

        // The children: each stays, goes, or is in both families (a child written down twice, again in each).
        $same = [];
        foreach ((array) ($log['samechildren'] ?? []) as $row) {
            $same[(int) $row['into']] = $row;
        }
        $children = 0;
        // The numbers they had, with the ones they have now.
        $again = [];
        $moved = [];
        foreach ((array) ($choice['children'] ?? []) as $childid => $how) {
            $child = $full->children[(int) $childid] ?? null;
            if (!$child || !in_array($how, ['go', 'both'], true)) {
                continue;
            }
            $children++;
            if ($how === 'go') {
                foreach ([households::CHILDREN => 'id', renewal::T => 'childid', ties::T => 'childid', listmatch::T => 'childid'] as $table => $field) {
                    $DB->set_field($table, 'householdid', $id, [$field => $child->id, 'householdid' => $h]);
                }
                $moved[] = (int) $child->id;
                continue;
            }
            $copy = isset($same[(int) $child->id]) ? array_diff_key($same[(int) $child->id], ['into' => 1]) :
                ['familychild' => 0] + array_diff_key((array) $child, ['id' => 1]);
            $newchild = self::again(households::CHILDREN, ['householdid' => $id, 'centreid' => $centreid, 'timemodified' => $now] + $copy);
            if (isset($same[(int) $child->id])) {
                $again[(int) ($same[(int) $child->id]['id'] ?? 0)] = $newchild;
            } else {
                foreach ($DB->get_records(ties::T, ['householdid' => $h, 'childid' => $child->id], 'id ASC') as $tie) {
                    $DB->insert_record(ties::T, (object) (['householdid' => $id, 'childid' => $newchild] + array_diff_key((array) $tie, ['id' => 1])));
                }
            }
            self::copy_drawing((int) $child->id, $newchild);
        }

        // The sign-ups: each stays, goes, or is in both, with the people of each family.
        $joined = [];
        foreach ((array) ($log['joinedsignups'] ?? []) as $row) {
            $joined[(int) $row['eventid']] = $row;
        }
        $people = max(1, count($going) + count($both) + $children);
        $signups = [];
        $backsignups = [];
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $h], 'id ASC') as $signup) {
            $eventid = (int) $signup->eventid;
            $how = $choice['signups'][$eventid] ?? '';
            $own = $DB->record_exists(events::T, ['id' => $eventid, 'householdid' => $h]);
            if ($how === 'go' || ($how === 'both' && $own)) {
                $DB->set_field(households::SIGNUPS, 'householdid', $id, ['id' => $signup->id]);
                foreach ([appointments::T => ['item' => 's' . $signup->id], notices::T => ['eventid' => $eventid]] as $table => $where) {
                    $DB->set_field($table, 'householdid', $id, $where + ['householdid' => $h]);
                }
                if ($own) {
                    // A visit of its own is of the family that has it, with what asked for it.
                    $DB->set_field(events::T, 'householdid', $id, ['id' => $eventid]);
                    $DB->set_field(visits::REQUESTS, 'householdid', $id, ['visitid' => $eventid, 'householdid' => $h]);
                }
            } else if ($how === 'both') {
                $copy = isset($joined[$eventid]) ? $joined[$eventid] : ['wasstages' => null, 'people' => $people] +
                    array_diff_key((array) $signup, ['id' => 1]);
                $newsignup = self::again(households::SIGNUPS, ['householdid' => $id] + $copy);
                if (isset($joined[$eventid])) {
                    $signups[(int) ($joined[$eventid]['id'] ?? 0)] = $newsignup;
                    $backsignups[] = (int) $signup->id;
                } else {
                    $DB->set_field(households::SIGNUPS, 'people', max(1, (int) $signup->people - $people), ['id' => $signup->id]);
                }
            }
        }

        // The lines of the conversation chosen, and the visits a sheet gave in them.
        $lines = array_map('intval', (array) ($choice['contacts'] ?? []));
        if ($lines) {
            [$in, $params] = $DB->get_in_or_equal($lines, SQL_PARAMS_NAMED);
            $DB->set_field_select(households::CONTACTS, 'householdid', $id, "householdid = :h AND id $in", ['h' => $h] + $params);
            foreach ($DB->get_records_select(teamnotes::T, "householdid = :h AND contactid $in", ['h' => $h] + $params) as $note) {
                $DB->set_field(teamnotes::T, 'householdid', $id, ['id' => $note->id]);
                $DB->set_field(appointments::T, 'householdid', $id, ['householdid' => $h, 'item' => 'n' . $note->id]);
            }
        }
        // What it asked for and has no day, and what it was promised.
        $things = isset($choice['things']) ? (array) $choice['things'] : null;
        foreach ($things ?? [] as $thing => $how) {
            $table = ['r' => visits::REQUESTS, 'p' => attend::PROMISES][substr((string) $thing, 0, 1)] ?? null;
            if ($table && $how === 'go') {
                $DB->set_field($table, 'householdid', $id, ['id' => (int) substr((string) $thing, 1), 'householdid' => $h]);
            }
        }

        if ($log) {
            self::tables_again($log, $h, $id, $things !== null);
            self::rows_again($log, $h, $id, $again, $both, $signups, $backsignups, $same);
            if (!empty($log['logs'])) {
                $DB->set_field_select(self::LOG, 'householdid', $id, 'id IN (' . implode(',', array_map('intval', $log['logs'])) . ')');
            }
            foreach ((array) ($log['notsame'] ?? []) as $other) {
                if ((int) $other !== $h && $DB->record_exists(households::T, ['id' => (int) $other])) {
                    self::distinct($centreid, $id, (int) $other, $userid);
                }
            }
            foreach ((array) ($log['notsamenew'] ?? []) as $other) {
                $DB->delete_records(self::T, ['centreid' => $centreid, 'householda' => min($h, (int) $other), 'householdb' => max($h, (int) $other)]);
            }
            $DB->delete_records(self::LOG, ['id' => $logrow->id]);
        } else {
            // Both families may have the links sent since the first that goes arrived: they stop working.
            $DB->delete_records_select(households::LINKS, 'householdid = :h AND timecreated >= :t', ['h' => $h, 't' => $first]);
            // What was marked of the whole family is of both.
            foreach ($DB->get_records(ties::T, ['householdid' => $h, 'childid' => 0], 'id ASC') as $tie) {
                $DB->insert_record(ties::T, (object) (['householdid' => $id] + array_diff_key((array) $tie, ['id' => 1])));
            }
        }
        self::reckon($household, $id, $log);
        self::still_of_centre($household, $id, (bool) $log);
        self::family_apart($family, $h, $id, $going, $moved);
        self::distinct($centreid, $h, $id, $userid);
        who::reset();
        $new = households::get($id, $centreid);
        $stayed = households::get($h, $centreid);
        // Without the names of the other family: if one of them erases its data, nothing of it stays here.
        households::note($stayed, $userid, get_string('lookalike_split_note', self::C, $id));
        households::note($new, $userid, get_string('lookalike_split_note_new', self::C, $h));
        $transaction->allow_commit();
        return households::get($id, $centreid);
    }

    /**
     * The half that goes: the household it was, with its number, or a new one with what both said of themselves. Never less
     * careful than the other: whoever asked not to be written to, or not to be invited, is asked it in both.
     *
     * @param \stdClass $household
     * @param array $log
     * @param array $was
     * @param int $first When the first of those that go came.
     * @return int
     */
    protected static function other_half(\stdClass $household, array $log, array $was, int $first): int {
        global $DB;
        $now = time();
        if ($was) {
            $id = self::again(households::T, ['id' => (int) ($log['gone'] ?? 0), 'timemodified' => $now] + $was);
        } else {
            $id = households::create((int) $household->centreid, (object) ['lang' => $household->lang,
                'firstchannel' => $household->firstchannel, 'guest' => $household->guest]);
            $fields = ['id' => $id, 'timecreated' => $first];
            foreach (
                ['incentre', 'moving', 'self', 'outreason', 'outtime', 'outuserid', 'outnote', 'outstep', 'outstops', 'outwas', 'outasked',
                    'ownerid'] as $field
            ) {
                $fields[$field] = $household->$field;
            }
            $DB->update_record(households::T, (object) $fields);
        }
        // What was said of it since they were together, and everything when they were never apart.
        $since = fn(string $field) => !isset($log['keepnow']) || (string) ($log['keepnow'][$field] ?? '') !== (string) ($household->$field ?? '');
        $new = $DB->get_record(households::T, ['id' => $id]);
        $fields = ['id' => $id];
        if ($since('writeby') || $since('writebytime')) {
            $mine = writeby::chosen($household);
            $theirs = writeby::chosen($new);
            if ($mine !== null && $theirs !== [] && ($mine === [] || $theirs === null || (int) $household->writebytime >= (int) $new->writebytime)) {
                foreach (['writeby', 'writebytime', 'writebyfrom'] as $field) {
                    $fields[$field] = $household->$field;
                }
            }
        }
        foreach (['writebynone', 'noinvites'] as $field) {
            if ($since($field)) {
                $fields[$field] = max((int) $household->$field, (int) $new->$field);
            }
        }
        if (count($fields) > 1) {
            $DB->update_record(households::T, (object) $fields);
        }
        return $id;
    }

    /**
     * The one that stays goes back to what it said before they were put together, but for what changed since.
     *
     * @param \stdClass $household As it is now.
     * @param array $was
     * @param array $left How it was left when they were put together.
     */
    protected static function as_it_was(\stdClass $household, array $was, array $left): void {
        global $DB;
        $fields = [];
        foreach (array_diff_key($was, array_flip(['id', 'timemodified', 'lastcontact', 'waitingsince', 'waitingdayonly', 'linkhash'])) as $field => $value) {
            if (
                property_exists($household, $field) && (string) $value !== (string) ($left[$field] ?? '') &&
                    (string) $household->$field === (string) ($left[$field] ?? '')
            ) {
                $fields[$field] = $value;
            }
        }
        if ($fields) {
            $DB->update_record(households::T, (object) (['id' => $household->id] + $fields));
        }
    }

    /**
     * The adults that were the same person in both, in each again, with what each one had said.
     *
     * @param int[] $both
     * @param array $log
     * @param int $id The household they go back to.
     */
    protected static function adults_again(array $both, array $log, int $id): void {
        global $DB;
        foreach ((array) ($log['sameadults'] ?? []) as $entry) {
            if (!in_array((int) $entry['into'], $both, true)) {
                continue;
            }
            $adult = self::again(households::ADULTS, ['householdid' => $id] + $entry['row']);
            foreach ((array) $entry['dropped'] as $row) {
                self::again(households::CONSENTS, ['householdid' => $id, 'adultid' => $adult] + $row);
            }
            foreach ([households::CONSENTS => $entry['consents'], hobbies::T => $entry['hobbies'], alumni::T => $entry['alumni']] as $table => $ids) {
                if ($ids) {
                    [$in, $params] = $DB->get_in_or_equal(array_map('intval', $ids));
                    $DB->set_field_select($table, 'householdid', $id, "id $in", $params);
                    $DB->set_field_select($table, 'adultid', $adult, "id $in", $params);
                }
            }
        }
    }

    /**
     * What the other household had, from the log: its links, requests, answers, promises and the rest.
     *
     * @param array $log
     * @param int $h
     * @param int $id
     * @param bool $chosen Whether a person already said which things go.
     */
    protected static function tables_again(array $log, int $h, int $id, bool $chosen): void {
        global $DB;
        $apart = [households::CONSENTS, hobbies::T, households::CONTACTS, renewal::T, alumni::T];
        foreach ((array) ($log['tables'] ?? []) as $table => $ids) {
            if (!in_array($table, self::MOVED, true) || !$ids || in_array($table, $apart, true)) {
                continue;
            }
            if ($table === events::T) {
                // A visit of its own went with its sign-up; one called off has none.
                $ids = array_filter($ids, fn($one) => !$DB->record_exists(households::SIGNUPS, ['eventid' => (int) $one]));
                if (!$ids) {
                    continue;
                }
            }
            if ($chosen && in_array($table, [visits::REQUESTS, attend::PROMISES], true)) {
                continue;
            }
            [$in, $params] = $DB->get_in_or_equal(array_map('intval', $ids), SQL_PARAMS_NAMED);
            $where = "householdid = :h AND id $in";
            $params += ['h' => $h];
            if (in_array($table, [ties::T, listmatch::T], true)) {
                // What is said of a child is with the child.
                $where .= ' AND childid = 0';
            }
            $DB->set_field_select($table, 'householdid', $id, $where, $params);
        }
    }

    /**
     * What was changed or could not be there twice, as it was, unless something else was said of it since.
     *
     * @param array $log
     * @param int $h
     * @param int $id
     * @param array $again Child of the other household => the number it has now.
     * @param int[] $both The adults that are two again.
     * @param array $signups Sign-up of the other household => the number it has now.
     * @param int[] $backsignups The sign-ups that are two again.
     * @param array $same Child here => the row of the same one in the other.
     */
    protected static function rows_again(
        array $log,
        int $h,
        int $id,
        array $again,
        array $both,
        array $signups,
        array $backsignups,
        array $same
    ): void {
        global $DB;
        $gone = (int) ($log['gone'] ?? 0);
        $owner = fn(string $table, array $row) => (int) $row['householdid'] === $gone ||
            in_array((int) $row['id'], array_map('intval', (array) ($log['tables'][$table] ?? [])), true) ? $id : $h;
        // The children here whose line is two again.
        $backchildren = [];
        foreach ($same as $into => $row) {
            if (isset($again[(int) ($row['id'] ?? 0)])) {
                $backchildren[] = (int) $into;
            }
        }
        $only = [households::CHILDREN => $backchildren, households::ADULTS => $both, households::SIGNUPS => array_merge(
            $backsignups,
            array_map('intval', (array) ($log['signups'] ?? []))
        )];
        foreach ((array) ($log['changed'] ?? []) as $table => $rows) {
            foreach ($rows as $rowid => $one) {
                $row = $DB->get_record($table, ['id' => (int) $rowid]);
                if (!$row || (isset($only[$table]) && !in_array((int) $rowid, $only[$table], true))) {
                    continue;
                }
                $fields = [];
                foreach ($one['was'] as $field => $value) {
                    if ((string) $row->$field !== (string) $one['now'][$field]) {
                        continue;
                    }
                    if ($field === 'childid') {
                        $value = $again[(int) $value] ?? 0;
                        if (!$value) {
                            continue;
                        }
                    } else if ($field === 'item') {
                        $value = isset($signups[(int) substr((string) $value, 1)]) ? 's' . $signups[(int) substr((string) $value, 1)] : $row->item;
                    } else if (in_array($field, ['wasstages', 'stages'], true) && !self::all_there((string) $value)) {
                        continue;
                    }
                    $fields[$field] = $value;
                }
                if (isset($fields['childid'])) {
                    // With its child.
                    $fields['householdid'] = $id;
                }
                if ($fields) {
                    $DB->update_record($table, (object) (['id' => (int) $rowid] + $fields));
                }
            }
        }
        $adults = [];
        foreach ((array) ($log['sameadults'] ?? []) as $entry) {
            foreach (['hobbies' => hobbies::T, 'alumni' => alumni::T] as $key => $table) {
                foreach ((array) ($entry[$key] ?? []) as $rowid) {
                    $adults[$table][(int) $rowid] = in_array((int) $entry['into'], $both, true) ? (int) $entry['row']['id'] : 0;
                }
            }
        }
        foreach ((array) ($log['deleted'] ?? []) as $table => $rows) {
            foreach ($rows as $row) {
                $row = (array) ($log['changed'][$table][$row['id']]['was'] ?? []) + $row;
                $row['householdid'] = $owner($table, $row);
                if (!empty($row['childid']) && !$DB->record_exists(households::CHILDREN, ['id' => (int) $row['childid']])) {
                    $row['childid'] = $again[(int) $row['childid']] ?? 0;
                    if (!$row['childid']) {
                        continue;
                    }
                }
                if (isset($adults[$table][(int) $row['id']])) {
                    if (!$adults[$table][(int) $row['id']]) {
                        continue;
                    }
                    $row['adultid'] = $adults[$table][(int) $row['id']];
                }
                if (!empty($row['adultid']) && !$DB->record_exists(households::ADULTS, ['id' => $row['adultid'], 'householdid' => $row['householdid']])) {
                    continue;
                }
                $once = [surveys::T => ['eventid', 'householdid', 'fieldid'], renewal::T => ['childid', 'schoolyear'],
                    invites::T => ['eventid', 'householdid']][$table] ?? null;
                if ($once && $DB->record_exists($table, array_intersect_key($row, array_flip($once)))) {
                    continue;
                }
                self::again($table, $row);
            }
        }
    }

    /**
     * Whether every child of some steps kept (childid:stage, by commas) is still there.
     *
     * @param string $was
     * @return bool
     */
    protected static function all_there(string $was): bool {
        global $DB;
        foreach (array_filter(explode(',', $was)) as $pair) {
            if (!$DB->record_exists(households::CHILDREN, ['id' => (int) $pair])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Who waits for an answer after separating two, and when each was last in touch: whoever waited goes on waiting since
     * when it did, in the half that has the line it waits for; the other half, for what it asked after that.
     *
     * @param \stdClass $household As it was before.
     * @param int $other The half that went.
     * @param array $log Of the time they were put together, if that is what is taken back.
     */
    protected static function reckon(\stdClass $household, int $other, array $log): void {
        global $DB;
        $since = (int) $household->waitingsince;
        $h = (int) $household->id;
        $waits = [$h => [0, 0], $other => [0, 0]];
        $lines = [];
        foreach ([$h, $other] as $id) {
            $lines[$id] = $DB->get_records_select(
                households::CONTACTS,
                "householdid = ? AND direction <> 'note'",
                [$id],
                'timecreated ASC, id ASC'
            );
        }
        $here = fn(int $id) => array_filter($lines[$id], fn($l) => $l->direction === 'in' && (int) $l->timecreated === $since);
        if ($since) {
            $holder = $here($other) && !$here($h) ? $other : $h;
            if (isset($log['keepnow']) && (int) $log['keepnow']['waitingsince'] === $since && !$here($h) && !$here($other)) {
                // Nothing written says it: whose it was.
                $holder = (int) ($log['household']['waitingsince'] ?? 0) === $since ? $other : $h;
            }
            $waits[$holder] = [$since, (int) $household->waitingdayonly];
            $rest = $holder === $h ? $other : $h;
            // The other waited too before they were put together, and nobody has answered since.
            $before = !isset($log['keepnow']) || (int) $log['keepnow']['waitingsince'] !== $since ? null :
                ($rest === $h ? (array) $log['keepwas'] : (array) $log['household']);
            if ($before) {
                $waits[$rest] = [(int) $before['waitingsince'], (int) $before['waitingdayonly']];
            }
            // What it asked after that, still with no answer: not a sign-up to an act, nor what the team wrote down.
            foreach ($waits[$rest][0] ? [] : $lines[$rest] as $line) {
                $asks = $line->direction === 'in' && (int) $line->timecreated > $since && !(int) $line->userid &&
                    !in_array($line->channel, ['event', 'openday', 'visit'], true) && trim((string) $line->body) !== '' &&
                    ($line->channel !== lists::PAGE || in_array((string) $line->detail, [settled::ASKED, settled::GONEASKED,
                        settled::FIRSTDAYASKED], true));
                if ($asks) {
                    $waits[$rest] = [(int) $line->timecreated, (int) $line->dayonly];
                    break;
                }
            }
        }
        foreach ([$h, $other] as $id) {
            $changes = ['id' => $id, 'waitingsince' => $waits[$id][0], 'waitingdayonly' => $waits[$id][1]];
            // Never less than it was: its data are kept as long as any other family.
            $since = isset($log['keepnow']) && (int) $log['keepnow']['lastcontact'] === (int) $household->lastcontact ?
                ($id === $h ? (int) $log['keepwas']['lastcontact'] : (int) $log['household']['lastcontact']) : (int) $household->lastcontact;
            $changes['lastcontact'] = max(array_merge([$since], array_map(fn($l) => (int) $l->timecreated, array_values($lines[$id]))));
            $DB->update_record(households::T, (object) $changes);
        }
    }

    /**
     * Whether each half is «of the centre» after separating them: by a mark of the team, a list or the roll; what the family
     * said of itself stays in both.
     *
     * @param \stdClass $household As it was before.
     * @param int $other
     * @param bool $back Whether it took back a time they were put together, and each has already what it had.
     */
    protected static function still_of_centre(\stdClass $household, int $other, bool $back): void {
        global $DB;
        $marks = fn(int $id) => $DB->record_exists(ties::T, ['householdid' => $id]) ||
            $DB->record_exists(listmatch::T, ['householdid' => $id, 'kind' => 'incentre']);
        // Made so by a mark or a list, and not said by anyone: only the half that has them is.
        $made = $DB->record_exists_select(ties::T, 'householdid IN (?, ?) AND made = 1', [$household->id, $other]) ||
            $DB->record_exists_select(
                listmatch::T,
                "householdid IN (?, ?) AND kind = 'incentre' AND marked = ?",
                [$household->id, $other, listmatch::MADE]
            );
        foreach ([(int) $household->id, $other] as $id) {
            $row = $DB->get_record(households::T, ['id' => $id], 'id, incentre, roster');
            $is = $marks($id) || (int) $row->roster ? 1 : (!$back && $made ? 0 : (int) $row->incentre);
            if ($is !== (int) $row->incentre) {
                $DB->set_field(households::T, 'incentre', $is, ['id' => $id]);
            }
            // Of the marks of each, the first one made it so when none says it.
            if ($is && $made && !$back && !$DB->record_exists(ties::T, ['householdid' => $id, 'made' => 1])) {
                $first = $DB->get_records(ties::T, ['householdid' => $id], 'id ASC', 'id', 0, 1);
                if ($first) {
                    $DB->set_field(ties::T, 'made', 1, ['id' => key($first)]);
                }
            }
        }
    }

    /**
     * The family Eguahe made in the common record for a household.
     *
     * @param \stdClass $household
     * @return int 0, none.
     */
    protected static function family_of(\stdClass $household): int {
        if (!family::available()) {
            return 0;
        }
        try {
            $api = family::API;
            return (int) $api::family_by_key(family::SOURCE, family::key('h', (int) $household->id));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * In the common record too: whoever goes no longer sees the family that stays in the app, and its children enrolled go to
     * theirs. Only what Eguahe put there.
     *
     * @param int $familyid The one Eguahe made for the household; 0, none.
     * @param int $h
     * @param int $id
     * @param int[] $adults Those that go.
     * @param int[] $children Those that go.
     */
    protected static function family_apart(int $familyid, int $h, int $id, array $adults, array $children): void {
        global $DB;
        if (!$familyid) {
            return;
        }
        $api = family::API;
        try {
            foreach ($adults as $adultid) {
                $theirs = $DB->get_field(
                    'local_oksigeniafamily_adult',
                    'id',
                    ['familyid' => $familyid, 'sourcekey' => family::SOURCE . ':a' . $adultid],
                    IGNORE_MULTIPLE
                );
                if ($theirs) {
                    $api::remove_adult((int) $theirs, 'manual');
                }
            }
            $new = households::get($id, 0);
            foreach ($children as $childid) {
                $child = $DB->get_record(households::CHILDREN, ['id' => $childid]);
                if ($child && $child->stage === 'enrolled' && !(int) $child->lefttime && (int) $child->familychild) {
                    family::leave($child);
                    family::enrol($new, $child);
                }
            }
        } catch (\Throwable $e) {
            debugging('Eguahe: Family did not separate household ' . $h . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The drawing of a child that is now in two families, in both; one that already has its own keeps it.
     *
     * @param int $from
     * @param int $to
     */
    protected static function copy_drawing(int $from, int $to): void {
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        if (drawings::file($to)) {
            return;
        }
        foreach ([drawings::AREA, drawings::STROKES] as $area) {
            foreach ($fs->get_area_files($contextid, self::C, $area, $from, 'id', false) as $file) {
                $fs->create_file_from_storedfile(['itemid' => $to], $file);
            }
        }
    }

    /**
     * The value chosen among some segments.
     *
     * @param array[] $options Each with value and selected.
     * @return string
     */
    protected static function picked(array $options): string {
        foreach ($options as $option) {
            if (!empty($option['selected'])) {
                return (string) $option['value'];
            }
        }
        return 'stay';
    }

    /**
     * What the file of a household shows to separate it: its adults, children, sign-ups and the last lines of its
     * conversation, marked as they came when it comes from two households put together.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param bool $canact
     * @return array|false
     */
    public static function split_context(\stdClass $household, \stdClass $full, bool $canact) {
        global $DB;
        $c = self::C;
        $logs = $canact ? $DB->get_records(self::LOG, ['householdid' => $household->id], 'timecreated DESC, id DESC', '*', 0, 1) : [];
        $logrow = reset($logs) ?: null;
        $log = $logrow ? (array) json_decode((string) $logrow->data, true) : [];
        $bothadults = array_values(array_intersect(
            array_map(fn($e) => (int) $e['into'], (array) ($log['sameadults'] ?? [])),
            array_map('intval', array_keys($full->adults))
        ));
        // Two adults, one for each; or the same person, when it was in both before they were put together.
        if (!$canact || (count($full->adults) < 2 && !$bothadults)) {
            return false;
        }
        $goadults = array_map('intval', (array) ($log['adults'] ?? []));
        $gochildren = array_map('intval', (array) ($log['children'] ?? []));
        $bothchildren = array_map(fn($r) => (int) $r['into'], (array) ($log['samechildren'] ?? []));
        $gosignups = array_map('intval', (array) ($log['signups'] ?? []));
        $bothevents = array_map(fn($r) => (int) $r['eventid'], (array) ($log['joinedsignups'] ?? []));
        $golines = array_map('intval', (array) ($log['tables'][households::CONTACTS] ?? []));
        $options = function (string $now, array $values = ['stay', 'go', 'both']) use ($c): array {
            return array_map(fn($v) => ['value' => $v, 'label' => get_string('lookalike_split_' . $v, $c),
                'selected' => $v === $now], $values);
        };
        $adults = [];
        foreach ($full->adults as $adult) {
            $id = (int) $adult->id;
            $now = in_array($id, $bothadults, true) ? 'both' : (in_array($id, $goadults, true) ? 'go' : 'stay');
            // An adult stays or goes, with the same segments as a child; in both, only one that was in both.
            $adults[] = ['id' => $id, 'name' => trim($adult->firstname . ' ' . $adult->lastname),
                'contact' => (string) ($adult->email ?: $adult->phone), 'checked' => $now !== 'stay', 'options' => $options(
                    $now,
                    in_array($id, $bothadults, true) ? ['stay', 'go', 'both'] : ['stay', 'go']
                )];
        }
        $children = [];
        foreach ($full->children as $child) {
            $now = in_array((int) $child->id, $gochildren, true) ? 'go' :
                (in_array((int) $child->id, $bothchildren, true) ? 'both' : 'stay');
            $children[] = ['id' => (int) $child->id, 'name' => panel::tag($child), 'options' => $options($now)];
        }
        $signups = [];
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $household->id]) as $signup) {
            $event = $DB->get_record(events::T, ['id' => $signup->eventid], 'id, name, timestart, householdid');
            if (!$event) {
                continue;
            }
            $now = in_array((int) $signup->id, $gosignups, true) ? 'go' :
                (in_array((int) $signup->eventid, $bothevents, true) ? 'both' : 'stay');
            $signups[] = ['eventid' => (int) $event->id, 'name' => format_string($event->name) . ' · ' .
                userdate((int) $event->timestart, get_string('strftimedateshort', 'langconfig')), 'options' => $options(
                    $now,
                    // A visit of its own is of one family.
                    (int) $event->householdid ? ['stay', 'go'] : ['stay', 'go', 'both']
                )];
        }
        // What it asked for and has no day yet, and what it was promised.
        $things = [];
        foreach ($DB->get_records(visits::REQUESTS, ['householdid' => $household->id, 'status' => 'pending'], 'id ASC') as $request) {
            $now = in_array((int) $request->id, array_map('intval', (array) ($log['tables'][visits::REQUESTS] ?? [])), true) ? 'go' : 'stay';
            $things[] = ['key' => 'r' . $request->id, 'name' => get_string('lookalike_split_asked', $c, visits::suits(
                $request,
                current_language()
            )), 'options' => $options($now, ['stay', 'go'])];
        }
        foreach ($DB->get_records(attend::PROMISES, ['householdid' => $household->id, 'timedone' => 0], 'id ASC') as $promise) {
            $now = in_array((int) $promise->id, array_map('intval', (array) ($log['tables'][attend::PROMISES] ?? [])), true) ? 'go' : 'stay';
            $things[] = ['key' => 'p' . $promise->id, 'name' => attend::sentence($promise, time()), 'options' => $options($now, ['stay', 'go'])];
        }
        $lines = [];
        $rows = $DB->get_records_select(
            households::CONTACTS,
            "householdid = ? AND (direction <> 'note' OR entryid > 0)",
            [$household->id],
            'timecreated DESC, id DESC',
            'id, direction, body, dayonly, timecreated',
            0,
            40
        );
        foreach ($rows as $row) {
            $lines[] = ['id' => (int) $row->id, 'checked' => in_array((int) $row->id, $golines, true),
                'when' => panel::day_hour((int) $row->timecreated, !empty($row->dayonly)),
                'text' => shorten_text(trim(preg_replace('/\s+/u', ' ', (string) $row->body)), 90)];
        }
        $from = '';
        if ($logrow) {
            $names = array_map(fn($a) => $a['name'], array_filter($adults, fn($a) => $a['checked']));
            $from = get_string('lookalike_split_from_log', $c, (object) ['who' => implode(', ', $names) ?: '—',
                'date' => userdate((int) $logrow->timecreated, get_string('strftimedate', 'langconfig'))]);
        }
        // What goes, said in one line that the script keeps up to date; with nothing going, the button waits.
        $go = array_merge(
            array_column(array_filter($adults, fn($a) => $a['checked']), 'name'),
            array_column(array_filter($children, fn($ch) => self::picked($ch['options']) !== 'stay'), 'name')
        );
        $stay = array_merge(
            array_column(array_filter($adults, fn($a) => self::picked($a['options']) !== 'go'), 'name'),
            array_column(array_filter($children, fn($ch) => self::picked($ch['options']) !== 'go'), 'name')
        );
        $summary = $go ? get_string('panel_js_split_go', $c, panel::and_list($go)) . ' ' .
            ($stay ? get_string('panel_js_split_stay', $c, panel::and_list($stay)) : '') :
            get_string('panel_js_split_nothing', $c);
        return ['adults' => $adults, 'children' => $children, 'haschildren' => (bool) $children, 'signups' => $signups,
            'summary' => trim($summary), 'nothinggoes' => !$go,
            'hassignups' => (bool) $signups, 'things' => $things, 'hasthings' => (bool) $things, 'lines' => $lines, 'haslines' => (bool) $lines, 'fromlog' => $from,
            'logid' => $logrow ? (int) $logrow->id : 0, 'nolog' => !$logrow,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'sesskey' => sesskey()];
    }
}
