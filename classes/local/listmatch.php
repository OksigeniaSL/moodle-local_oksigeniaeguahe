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
 * What the list of the centre says of a family of Eguahe: a family that asked for a place becomes, from one day to the
 * next, a family of the centre when it enrols its child. Each time the list of the year comes in (by hand or every night
 * from the management program), the families of Eguahe are crossed with it by the keys of their emails and phones,
 * never by a name; the name only tells which of its children a student is. A new student of the year in a family enrols
 * its child: the one that asked for that course; with doubt, a person says which. A child already at the centre when the
 * family came makes it a family of the centre.
 *
 * What the list says is worked out again each time: what it no longer says is taken back, and nothing a person said or
 * did is. With a contact that families of different surnames share, only a person says whether it is this family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class listmatch {
    /** @var string What the list said of each family, once per student and year. */
    const T = 'local_oksigeniaeguahe_listmatch';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int A child already there: it was this that made the family one of the centre. */
    const MADE = 1;

    /** @var int A child already there, of a family that already was one of the centre. */
    const WAS = 2;

    /** @var string The families that are somebody's: not those made up to be looked at (cli/writeby_sample.php), which are in
     *     no list: they are marked (guest 2) or say they came long ago. */
    const REAL = 'guest <> 2 AND timecreated > 946684800';

    /** @var int How long after a student joined a family that came is taken for one that came for it. */
    const AFTER = 60 * DAYSECS;

    /** @var string[] What the list may say of a student, the one that says most first. */
    const RANK = ['enrolled', 'inside', 'gone', 'incentre', 'none', 'ask', 'maybe', 'former'];

    /**
     * Crosses the families of Eguahe of a centre with its lists.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array [enrolled, of the centre, asked]
     */
    public static function detect(\stdClass $centre, int $now): array {
        global $DB;
        $centreid = (int) $centre->id;
        $counts = ['enrolled' => 0, 'incentre' => 0, 'ask' => 0];
        if (centres::is_practice($centreid)) {
            return array_values($counts);
        }
        $lists = self::lists($centreid, $now);
        if (!$lists['now'] && !$lists['before']) {
            return array_values($counts);
        }
        $keys = new pseudonym($centreid);
        $homes = $DB->get_records_select(
            households::T,
            'centreid = :centreid AND roster = 0 AND ' . self::REAL,
            ['centreid' => $centreid],
            'id ASC'
        );
        $people = self::people($centreid, $homes);
        $found = $keys->find_each('household', array_column($people, 'contacts', 'id'));
        $facts = ['centre' => $centre, 'lists' => $lists, 'keys' => $keys, 'now' => $now,
            'offers' => $DB->get_records(centres::OFFERS, ['centreid' => $centreid], '', 'id, name, schoolyear'),
            'doubts' => listhomes::doubts($centreid)];
        $rows = [];
        foreach ($DB->get_records(self::T, ['centreid' => $centreid], 'id ASC') as $row) {
            $rows[(int) $row->householdid][] = $row;
        }
        foreach ($homes as $household) {
            $id = (int) $household->id;
            $did = self::family($household, $people[$id], $found[$id] ?? [], $rows[$id] ?? [], $facts);
            foreach ($did as $what => $n) {
                $counts[$what] += $n;
            }
        }
        who::reset();
        return array_values($counts);
    }

    /**
     * Who is in the lists of a centre: the students there now (in the last school year with its whole list, and in what has
     * come of a later one) and those of the years before who are not any more.
     *
     * @param int $centreid
     * @param int $now
     * @return array now (student => student, inside (whether it was there before it joins anything, with the year), join (the
     *     year, the grade and the day it joins, or null), grade, year, timein, firstin (when it first came, 0 not known), n
     *     and s (the words of its name)), before (student => grade and year), homes and past (token => students), homesof.
     */
    protected static function lists(int $centreid, int $now): array {
        global $DB;
        $partial = array_flip($DB->get_fieldset_select(imports::T, 'id', 'centreid = ? AND partial = 1', [$centreid]));
        $all = [];
        $whole = [];
        $years = [];
        $left = [];
        $homesof = [];
        $rows = $DB->get_recordset_select(
            metrics::STUDENTS,
            'centreid = :centreid',
            ['centreid' => $centreid],
            'schoolyear ASC, id ASC',
            'id, importid, student, household, movement, grade, timein, timeout, schoolyear, marks'
        );
        foreach ($rows as $row) {
            $student = (string) $row->student;
            $year = (string) $row->schoolyear;
            if ((string) $row->household !== '') {
                $homesof[$student][$row->household] = true;
            }
            if ($row->movement === 'leave') {
                if ((int) $row->timeout <= $now) {
                    $left[$student] = max($left[$student] ?? '', $year);
                }
                continue;
            }
            $years[$year] = true;
            if ($row->movement === 'roll' && !isset($partial[$row->importid])) {
                $whole[$year] = true;
            }
            $s = $all[$student][$year] ?? ['join' => false, 'grade' => '', 'timein' => 0, 'first' => 0];
            $s['join'] = $s['join'] || $row->movement === 'join';
            $s['grade'] = $s['grade'] !== '' ? $s['grade'] : (string) $row->grade;
            $s['timein'] = max($s['timein'], (int) $row->timein);
            $all[$student][$year] = $s;
            if ((string) $row->marks !== '') {
                $marks = (array) json_decode((string) $row->marks, true);
                foreach (['n', 's'] as $what) {
                    foreach ((array) ($marks[$what] ?? []) as $word) {
                        $all[$student]['words'][$what][(string) $word] = true;
                    }
                }
            }
        }
        $rows->close();
        ksort($years);
        ksort($whole);
        $from = (string) (array_key_last($whole) ?? array_key_last($years) ?? '');
        $out = ['now' => [], 'before' => [], 'homes' => [], 'past' => [], 'homesof' => $homesof];
        foreach ($all as $student => $byyear) {
            $student = (string) $student;
            $words = $byyear['words'] ?? [];
            unset($byyear['words']);
            $last = (string) array_key_last($byyear);
            $first = (string) array_key_first($byyear);
            $here = strcmp($last, $from) >= 0 && !(isset($left[$student]) && strcmp($left[$student], $last) >= 0);
            if (!$here) {
                $out['before'][$student] = ['student' => $student, 'grade' => $byyear[$last]['grade'], 'year' => $last];
                foreach (array_keys($homesof[$student] ?? []) as $token) {
                    $out['past'][$token][$student] = true;
                }
                continue;
            }
            // When it first came to the centre, as far as the lists know: the day it joined or, without it, the first list it
            // is on, when there was a whole list before it was on.
            $known = $byyear[$first]['join'] || ($whole && strcmp((string) array_key_first($whole), $first) < 0);
            $s = ['student' => $student, 'year' => $last, 'grade' => $byyear[$last]['grade'], 'timein' => $byyear[$last]['timein'],
                'firstin' => $known ? $byyear[$first]['timein'] : 0, 'inside' => false, 'join' => null,
                'n' => array_map('strval', array_keys($words['n'] ?? [])), 's' => array_map('strval', array_keys($words['s'] ?? []))];
            foreach ($byyear as $year => $one) {
                if (strcmp((string) $year, $from) < 0) {
                    continue;
                }
                if ($one['join']) {
                    $s['join'] = ['year' => (string) $year, 'grade' => $one['grade'], 'timein' => $one['timein']];
                } else if (!$s['join']) {
                    $s['inside'] = true;
                }
            }
            $out['now'][$student] = $s;
            foreach (array_keys($homesof[$student] ?? []) as $token) {
                $out['homes'][$token][$student] = true;
            }
        }
        return $out;
    }

    /**
     * The people of some families: their contacts, the words of their surnames, their children, and when they first came.
     *
     * @param int $centreid
     * @param \stdClass[] $homes
     * @return array[] household id => id, contacts, words, children and arrival.
     */
    protected static function people(int $centreid, array $homes): array {
        global $DB;
        $out = [];
        foreach ($homes as $home) {
            $out[(int) $home->id] = ['id' => (int) $home->id, 'contacts' => [], 'words' => [], 'children' => [],
                'arrival' => (int) $home->timecreated];
        }
        if (!$out) {
            return $out;
        }
        $in = "householdid IN (SELECT id FROM {" . households::T . "} WHERE centreid = :centreid AND roster = 0)";
        $params = ['centreid' => $centreid];
        if (count($homes) === 1) {
            $in = 'householdid = :householdid';
            $params = ['householdid' => array_key_first($out)];
        }
        $rows = $DB->get_recordset_select(
            households::ADULTS,
            $in,
            $params,
            'id ASC',
            'id, householdid, firstname, lastname, email, phone'
        );
        foreach ($rows as $row) {
            $id = (int) $row->householdid;
            if (!isset($out[$id])) {
                continue;
            }
            $out[$id]['contacts'][] = $row->email ? pseudonym::email((string) $row->email) : null;
            $out[$id]['contacts'][] = $row->phone ? pseudonym::phone((string) $row->phone) : null;
            $out[$id]['words'] = array_merge(
                $out[$id]['words'],
                pseudonym::words((string) $row->lastname),
                array_slice(pseudonym::words((string) $row->firstname), 1)
            );
        }
        $rows->close();
        $rows = $DB->get_recordset_select(households::CHILDREN, $in, $params, 'id ASC');
        foreach ($rows as $row) {
            $id = (int) $row->householdid;
            if (!isset($out[$id])) {
                continue;
            }
            $out[$id]['children'][(int) $row->id] = $row;
            $out[$id]['words'] = array_merge($out[$id]['words'], array_slice(pseudonym::words((string) $row->firstname), 1));
        }
        $rows->close();
        $rows = $DB->get_recordset_sql(
            'SELECT householdid, MIN(timecreated) AS first
               FROM {' . households::CONTACTS . "}
              WHERE $in AND direction <> 'note'
           GROUP BY householdid",
            $params
        );
        foreach ($rows as $row) {
            $id = (int) $row->householdid;
            if (!isset($out[$id])) {
                continue;
            }
            $out[$id]['arrival'] = min($out[$id]['arrival'], (int) $row->first);
        }
        $rows->close();
        return $out;
    }

    /**
     * What the lists say of one family, against what they had said.
     *
     * @param \stdClass $household
     * @param array $me As people() gives it.
     * @param string[] $tokens Its families in the lists.
     * @param \stdClass[] $rows What the lists had said of it.
     * @param array $facts centre, lists, keys, offers, doubts and now.
     * @return array enrolled, incentre and ask => how many.
     */
    protected static function family(
        \stdClass $household,
        array $me,
        array $tokens,
        array $rows,
        array $facts
    ): array {
        global $DB;
        [$mine, $past, $maybe] = self::of($me, $tokens, $facts);
        $did = ['enrolled' => 0, 'incentre' => 0, 'ask' => 0];
        if (!$mine && !$past && !$maybe && !$rows) {
            return $did;
        }
        $by = [];
        foreach ($rows as $row) {
            $by[(string) $row->student][] = $row;
        }
        $unmade = false;
        // What the lists no longer say.
        foreach ($rows as $row) {
            $is = [isset($mine[$row->student]), isset($past[$row->student]), isset($maybe[$row->student])];
            [$keep, $made] = self::holds($me, $row, $is, $facts);
            $unmade = $unmade || $made;
            if (!$keep) {
                $by[(string) $row->student] = array_filter($by[(string) $row->student], fn($r) => $r->id !== $row->id);
            }
        }
        // What they say now of those who are there.
        $older = [];
        $joins = [];
        foreach ($mine as $student => $s) {
            $had = $by[$student] ?? [];
            if (array_filter($had, fn($r) => $r->kind === 'none' && (int) $r->said)) {
                // A person said it is not so.
                continue;
            }
            // One that joined long before its family came to Eguahe was there when it came.
            $late = $s['join'] && self::late($me, $s['join'], $facts);
            // A question still open is looked at again: the child may have been written down since, or the list say its name.
            $open = array_filter($had, fn($r) => $r->kind === 'ask' && !(int) $r->done && !(int) $r->said);
            if ($late && $open) {
                $DB->delete_records_list(self::T, 'id', array_column($open, 'id'));
                $had = array_udiff($had, $open, fn($a, $b) => (int) $a->id <=> (int) $b->id);
                $by[$student] = $had;
            } else if (
                $s['join'] && !$late && !array_filter($had, fn($r) => !in_array($r->kind, ['incentre', 'former'], true) &&
                    (string) $r->schoolyear === $s['join']['year'] && !in_array($r, $open, true))
            ) {
                foreach ($had as $row) {
                    if (
                        $row->kind === 'incentre' && !$s['inside'] && !(int) $row->said && (int) $row->marked &&
                            (string) $row->schoolyear === $s['join']['year']
                    ) {
                        // The list of the new ones came after the list of the year: it was not there before, it has just come.
                        $DB->delete_records(self::T, ['id' => $row->id]);
                        $unmade = $unmade || (int) $row->marked === self::MADE;
                        $had = array_filter($had, fn($r) => $r->id !== $row->id);
                    }
                }
                $by[$student] = $had;
                $joins[$student] = $s;
            }
            if ($s['inside'] || $late) {
                $was = array_filter($had, fn($r) => in_array($r->kind, ['incentre', 'enrolled', 'inside', 'gone'], true));
                foreach ($had as $row) {
                    if ($row->kind === 'incentre' && strcmp((string) $row->schoolyear, $s['year']) < 0 && !$s['join']) {
                        $DB->update_record(self::T, (object) ['id' => $row->id, 'schoolyear' => $s['year'],
                            'grade' => self::grade($facts, $s['grade'])]);
                    }
                }
                if (!$was && !self::came_for($me, $s, $by, $facts)) {
                    $older[$student] = $s;
                }
            }
        }
        // Those who join: the child of this family they are.
        while ($joins) {
            $s = reset($joins);
            $what = self::enrol($household, $me, $s, $joins, $by, $facts);
            if ($what !== '') {
                $did[$what]++;
            }
            unset($joins[$s['student']]);
        }
        foreach ($maybe as $student => $s) {
            if (!($by[$student] ?? [])) {
                self::add($household, ['student' => $student, 'kind' => 'maybe', 'schoolyear' => $s['year'],
                    'grade' => self::grade($facts, $s['grade']), 'timein' => $s['timein']], $facts['now']);
                $did['ask']++;
            }
        }
        // Children there in the years before and not now: the family already knew the centre.
        foreach (array_diff_key($past, $mine) as $student => $s) {
            if (!array_filter($by[$student] ?? [], fn($r) => $r->kind === 'former')) {
                self::add($household, ['student' => $student, 'kind' => 'former', 'schoolyear' => $s['year'],
                    'grade' => self::grade($facts, $s['grade']), 'done' => $facts['now']], $facts['now']);
            }
        }
        if ($older) {
            $grades = [];
            $isof = (int) $household->incentre;
            foreach ($older as $student => $s) {
                $grades[self::grade($facts, $s['grade'])] = true;
                self::add($household, ['student' => $student, 'kind' => 'incentre', 'schoolyear' => $s['year'],
                    'grade' => self::grade($facts, $s['grade']), 'timein' => $s['timein'], 'done' => $facts['now'],
                    'marked' => $isof && !$unmade ? self::WAS : self::MADE], $facts['now']);
            }
            if (!$isof) {
                // A family with a child already at the centre: out of the numbers of who comes, as one of the centre.
                $DB->set_field(households::T, 'incentre', 1, ['id' => $household->id]);
                $household->incentre = 1;
                households::note($household, 0, get_string('listmatch_incentre_note', self::C, implode(
                    ', ',
                    array_map('strval', array_keys($grades))
                )));
                $did['incentre']++;
            }
        }
        if ($unmade) {
            self::reckon($household);
        }
        return $did;
    }

    /**
     * The students of the lists that are of a family: those of its families there (found by a contact that is of one family)
     * and those with a contact that several share, when something else says so; and those that it may be.
     *
     * @param array $me
     * @param string[] $tokens
     * @param array $facts
     * @return array [student => now, student => before, student => now that may be]
     */
    protected static function of(array $me, array $tokens, array $facts): array {
        $lists = $facts['lists'];
        $maybe = [];
        if ($facts['doubts']) {
            $shorts = $facts['keys']->shorts($me['contacts']);
            $words = $facts['keys']->wordmarks(array_values(array_unique($me['words'])));
            foreach ($shorts as $k) {
                foreach ($facts['doubts'][$k] ?? [] as $student => $one) {
                    if (array_intersect($words, $one['s']) || count(array_intersect($shorts, $one['k'])) > 1) {
                        $tokens = array_merge($tokens, array_keys($lists['homesof'][$student] ?? []));
                    } else if (isset($lists['now'][$student])) {
                        $maybe[$student] = $lists['now'][$student];
                    }
                }
            }
        }
        $mine = [];
        $past = [];
        foreach (array_unique($tokens) as $token) {
            $mine += array_intersect_key($lists['now'], $lists['homes'][$token] ?? []);
            $past += array_intersect_key($lists['before'], $lists['past'][$token] ?? []);
        }
        return [$mine, $past, array_diff_key($maybe, $mine)];
    }

    /**
     * Whether what the list had said of a student still holds; what does not is taken back, unless a person said it. A child
     * it enrolled by itself is never taken back alone: a person is asked.
     *
     * @param array $me
     * @param \stdClass $row
     * @param bool[] $is Whether the student is of this family and there now, of it in the years before, or may be of it.
     * @param array $facts
     * @return array [whether it stays, whether what made it a family of the centre went]
     */
    protected static function holds(array $me, \stdClass $row, array $is, array $facts): array {
        global $DB;
        [$mine, $past, $maybe] = $is;
        $there = isset($facts['lists']['now'][$row->student]);
        $gone = false;
        if ($row->kind === 'gone' && !(int) $row->done && $mine) {
            $DB->update_record(self::T, (object) ['id' => $row->id, 'kind' => 'enrolled', 'done' => $facts['now']]);
            $row->kind = 'enrolled';
        } else if ($row->kind === 'ask' && !(int) $row->done && !(int) $row->said) {
            $gone = !$mine;
        } else if ($row->kind === 'maybe' && !(int) $row->done) {
            $gone = !$maybe;
        } else if ($row->kind === 'former') {
            $gone = !$past && ($there || isset($facts['lists']['before'][$row->student]));
        } else if ($row->kind === 'incentre' && !$mine) {
            $gone = $there ? !(int) $row->said : self::over($me, (string) $row->schoolyear, $facts);
        } else if ($row->kind === 'enrolled' && !$mine && $there && !(int) $row->said && (string) $row->was !== '') {
            $child = $me['children'][(int) $row->childid] ?? null;
            if ($child && $child->stage === 'enrolled' && !(int) $child->lefttime) {
                // A row of its own, so that it is said as something new.
                $DB->delete_records(self::T, ['id' => $row->id]);
                $new = ['kind' => 'gone', 'done' => 0, 'timecreated' => $facts['now']] + array_diff_key((array) $row, ['id' => 1]);
                $DB->insert_record(self::T, (object) $new);
            }
            return [true, false];
        }
        if ($gone) {
            $DB->delete_records(self::T, ['id' => $row->id]);
        }
        return [!$gone, $gone && $row->kind === 'incentre' && (int) $row->marked === self::MADE];
    }

    /**
     * Whether a child that was at the centre in a school year and is not any more has stopped making its family one of the
     * centre: it asks for a place for a year that began after that child had gone. What a family was when it came stays.
     *
     * @param array $me
     * @param string $year
     * @param array $facts
     * @return bool
     */
    protected static function over(array $me, string $year, array $facts): bool {
        $asks = [];
        foreach ($me['children'] as $child) {
            $for = (string) ($facts['offers'][$child->offerid]->schoolyear ?? '');
            if ($for !== '') {
                $asks[] = $for;
            }
        }
        $after = renewal::year_after($year);
        return !$asks || $after === '' || strcmp(max($asks), $after) > 0;
    }

    /**
     * Whether a student already there is the one this family came to Eguahe for: the family came before it did, or it is a
     * child of the family already enrolled here in an earlier year. It does not make it a family of the centre.
     *
     * @param array $me
     * @param array $s
     * @param array $by What the list said, by student. Changed in place.
     * @param array $facts
     * @return bool
     */
    protected static function came_for(array $me, array $s, array &$by, array $facts): bool {
        $taken = self::taken($by);
        $age = grades::age((string) $s['grade'], $facts['centre']);
        $fits = [];
        foreach ($me['children'] as $child) {
            $from = trim((string) ($child->startyear ?? '')) ?: (string) ($facts['offers'][$child->offerid]->schoolyear ?? '');
            if (
                $child->stage !== 'enrolled' || (int) $child->roster || isset($taken[(int) $child->id]) || $from === '' ||
                    strcmp($from, $s['year']) >= 0
            ) {
                continue;
            }
            $then = grades::age((string) ($facts['offers'][$child->offerid]->name ?? ''), $facts['centre']);
            $for = (string) ($facts['offers'][$child->offerid]->schoolyear ?? $from);
            $expected = $then === null ? null : $then + (int) substr($s['year'], 0, 4) - (int) substr($for, 0, 4);
            $named = self::named($child, $s, $facts);
            if ($named === false || ($named === null && ($age === null || $expected !== $age))) {
                continue;
            }
            $fits[] = $child;
        }
        if (count($fits) === 1) {
            // Only for what is left of this family tonight: no list of its year said it, so nothing is written.
            $by[$s['student']][] = (object) ['id' => 0, 'kind' => 'inside', 'childid' => (int) $fits[0]->id, 'said' => 0,
                'marked' => 0, 'schoolyear' => $s['year']];
            return true;
        }
        return $s['firstin'] > $me['arrival'];
    }

    /**
     * Whether a child of a family has the name of a student: true, false, or null when it cannot be told.
     *
     * @param \stdClass $child
     * @param array $s
     * @param array $facts
     * @return bool|null
     */
    protected static function named(\stdClass $child, array $s, array $facts): ?bool {
        if (!$s['n']) {
            return null;
        }
        $words = array_filter([array_slice(pseudonym::words((string) $child->firstname), 0, 1),
            array_slice(pseudonym::words((string) ($child->tagname ?? '')), 0, 1)]);
        if (!$words) {
            return null;
        }
        return (bool) array_intersect($facts['keys']->wordmarks(array_merge([], ...array_values($words))), $s['n']);
    }

    /**
     * The children the list already knows.
     *
     * @param array $by What the list said, by student.
     * @return array child id => true
     */
    protected static function taken(array $by): array {
        $out = [];
        foreach ($by as $rows) {
            foreach ($rows as $row) {
                if (in_array($row->kind, ['enrolled', 'gone', 'inside'], true) && (int) $row->childid) {
                    $out[(int) $row->childid] = true;
                }
            }
        }
        return $out;
    }

    /**
     * A new student of a year in a family of Eguahe: the child it is, when it is clear. One that a person had already
     * enrolled is it, and nothing changes; the one that asked for that course is enrolled. A child is never enrolled by
     * being the only one left: with doubt, a person is asked which.
     *
     * @param \stdClass $household
     * @param array $me
     * @param array $s
     * @param array $joins The students of the family still to be told, with this one.
     * @param array $by What the list said, by student. Changed in place.
     * @param array $facts
     * @return string enrolled, ask or empty (it is known already).
     */
    protected static function enrol(
        \stdClass $household,
        array $me,
        array $s,
        array $joins,
        array &$by,
        array $facts
    ): string {
        global $DB;
        $join = $s['join'];
        $age = grades::age((string) $join['grade'], $facts['centre']);
        $taken = self::taken($by);
        $known = isset($by[$s['student']]) && array_filter($by[$s['student']], fn($r) => in_array(
            $r->kind,
            ['enrolled', 'inside'],
            true
        ));
        // Only a child that asked for a course of the year of the list: a brother or sister asking for a later year, or a
        // child with no course asked for, is never taken for the student.
        $same = array_filter($me['children'], fn($k) => (string) ($facts['offers'][$k->offerid]->schoolyear ?? '') === $join['year'] &&
            $k->stage !== 'closed' && !(int) $k->lefttime && !isset($taken[(int) $k->id]));
        $pool = $age === null ? $same : array_filter($same, fn($k) => grades::age(
            (string) $facts['offers'][$k->offerid]->name,
            $facts['centre']
        ) === $age);
        $child = self::which($pool, $s, $joins, $age, $facts);
        if (!$child && !$pool && $known) {
            // A child that came by Eguahe and only goes on to another course.
            return '';
        }
        $open = array_filter($by[$s['student']] ?? [], fn($r) => $r->kind === 'ask' && !(int) $r->done &&
            (string) $r->schoolyear === $join['year']);
        if ($open) {
            if (!$child) {
                // It is asked already.
                return '';
            }
            $DB->delete_records_list(self::T, 'id', array_column($open, 'id'));
            $by[$s['student']] = array_udiff($by[$s['student']], $open, fn($a, $b) => (int) $a->id <=> (int) $b->id);
        }
        $row = ['student' => $s['student'], 'kind' => $child ? 'enrolled' : 'ask', 'schoolyear' => $join['year'],
            'grade' => self::grade($facts, $join['grade']), 'timein' => $join['timein'], 'childid' => $child ? (int) $child->id : 0,
            'done' => $child ? $facts['now'] : 0, 'was' => $child && $child->stage !== 'enrolled' ? $child->stage . ':' .
                (int) $child->stagetime : null];
        $row = self::add($household, $row, $facts['now']);
        $by[$s['student']][] = $row;
        if (!$child) {
            return 'ask';
        }
        if ($child->stage !== 'enrolled') {
            self::set_enrolled($household, $child, $row, $facts['now']);
            $child->stage = 'enrolled';
        }
        return 'enrolled';
    }

    /**
     * Whether a student joined long before its family came to Eguahe, and the family asked for nothing for that school year: it
     * already had a child there when it came.
     *
     * @param array $me
     * @param array $join
     * @param array $facts
     * @return bool
     */
    protected static function late(array $me, array $join, array $facts): bool {
        if ($join['timein'] <= 0 || $me['arrival'] <= $join['timein'] + self::AFTER) {
            return false;
        }
        return !array_filter($me['children'], fn($k) => (string) ($facts['offers'][$k->offerid]->schoolyear ?? '') ===
            $join['year']);
    }

    /**
     * Which of the children of a family that asked for the course of a new student it is, when it is clear: the only one with
     * its name or, when the list does not say names, the only one, or any when as many join as there are.
     *
     * @param \stdClass[] $pool
     * @param array $s
     * @param array $joins
     * @param int|null $age
     * @param array $facts
     * @return \stdClass|null
     */
    protected static function which(array $pool, array $s, array $joins, ?int $age, array $facts): ?\stdClass {
        if (!$pool) {
            return null;
        }
        $named = array_filter($pool, fn($k) => self::named($k, $s, $facts) === true);
        if (count($named) > 1) {
            // «María José» and «María Luisa»: the one whose whole first name is in the list.
            $whole = array_filter($named, fn($k) => !array_diff($facts['keys']->wordmarks(pseudonym::words(
                (string) $k->firstname
            )), $s['n']));
            $named = $whole ?: $named;
        }
        if (count($named) === 1) {
            return reset($named);
        }
        if ($named) {
            return null;
        }
        $unknown = array_filter($pool, fn($k) => self::named($k, $s, $facts) === null);
        if (count($unknown) !== count($pool)) {
            // Those with a name have another one: only one without any could be it, and a person says.
            return null;
        }
        if (count($pool) === 1) {
            return reset($pool);
        }
        $alike = array_filter($joins, fn($j) => $j['join']['year'] === $s['join']['year'] &&
            grades::age((string) $j['join']['grade'], $facts['centre']) === $age);
        return count($alike) === count($pool) ? reset($pool) : null;
    }

    /**
     * The name of a course as the rows keep it.
     *
     * @param array $facts
     * @param string $grade
     * @return string
     */
    protected static function grade(array $facts, string $grade): string {
        return \core_text::substr(grades::label((int) $facts['centre']->id, $grade), 0, 100);
    }

    /**
     * Writes what the list says of a student of a family.
     *
     * @param \stdClass $household
     * @param array $row
     * @param int $now
     * @return \stdClass The row.
     */
    protected static function add(\stdClass $household, array $row, int $now): \stdClass {
        global $DB;
        $row = (object) ($row + ['timein' => 0, 'childid' => 0, 'done' => 0, 'marked' => 0, 'was' => null, 'said' => 0]);
        $row->centreid = (int) $household->centreid;
        $row->householdid = (int) $household->id;
        $row->schoolyear = \core_text::substr((string) $row->schoolyear, 0, 9);
        $row->timecreated = $now;
        $row->id = (int) $DB->insert_record(self::T, $row);
        return $row;
    }

    /**
     * Enrols a child as the list says, on the day it joined, with a line in the history of its family.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @param \stdClass $row
     * @param int $now
     */
    protected static function set_enrolled(\stdClass $household, \stdClass $child, \stdClass $row, int $now): void {
        global $DB;
        households::set_stage($child, 'enrolled');
        // It was enrolled the day it joined, not the night the list said it.
        $when = (int) $row->timein > 0 ? min($now, (int) $row->timein) : $now;
        $DB->set_field(households::CHILDREN, 'stagetime', $when, ['id' => $child->id]);
        households::note($household, 0, get_string('listmatch_enrolled_note', self::C, (object) [
            'child' => format_string($child->firstname), 'grade' => $row->grade,
            'date' => userdate((int) $row->timein ?: $now, get_string('strftimedate', 'langconfig'))]));
    }

    /**
     * What the list had made a family of the centre for went: it still is one if the list says it of another child, a person
     * marked it, or it came from the roll; else it is not any more.
     *
     * @param \stdClass $household
     */
    public static function reckon(\stdClass $household): void {
        global $DB;
        $where = ['householdid' => (int) $household->id, 'kind' => 'incentre'];
        if ($DB->record_exists(self::T, $where)) {
            // It is for what is left that it is one now.
            $DB->set_field(self::T, 'marked', self::MADE, $where);
            return;
        }
        if (ties::any((int) $household->id)) {
            ties::answers_for((int) $household->id);
            return;
        }
        if ((int) ($household->roster ?? 0) || !(int) $DB->get_field(households::T, 'incentre', ['id' => $household->id])) {
            return;
        }
        $DB->set_field(households::T, 'incentre', 0, ['id' => $household->id]);
        $household->incentre = 0;
        households::note($household, 0, get_string('listmatch_notcentre_note', self::C));
    }

    /**
     * A person says which child the list enrolled, or that none. A child asking for another school year is already there, in
     * the course of the list: what it asks for goes on as it was.
     *
     * @param \stdClass $household
     * @param int $rowid
     * @param int $childid 0, none of them.
     */
    public static function choose(\stdClass $household, int $rowid, int $childid): void {
        global $DB;
        $row = $DB->get_record(self::T, ['id' => $rowid, 'householdid' => $household->id, 'kind' => 'ask', 'done' => 0]);
        if (!$row) {
            return;
        }
        $now = time();
        $child = $childid ? $DB->get_record(households::CHILDREN, ['id' => $childid, 'householdid' => $household->id]) : null;
        $done = ['id' => $row->id, 'done' => $now, 'childid' => $child ? (int) $child->id : 0, 'said' => 1];
        if (!$child) {
            // None of those written down: it may still be another child of the family, so the list may say it is there.
            $DB->update_record(self::T, (object) (['kind' => 'none', 'said' => 0] + $done));
            return;
        }
        $offer = (int) $child->offerid ? $DB->get_record(centres::OFFERS, ['id' => $child->offerid]) : null;
        $back = $child->stage === 'enrolled' && (int) $child->lefttime;
        if (!$offer || $back) {
            // It had no course written down, or it is one that had gone and is back: the one of the list, when the centre
            // offers it.
            $of = self::offer((int) $household->centreid, $row);
            $changes = ($of ? ['offerid' => (int) $of->id] : []) + ($back ? ['stage' => 'inquiry', 'lefttime' => 0,
                'leftreason' => null, 'leftnote' => null, 'startyear' => null, 'familygone' => 0] : []);
            if ($changes) {
                $DB->update_record(households::CHILDREN, (object) (['id' => $child->id] + $changes));
                $child = $DB->get_record(households::CHILDREN, ['id' => $child->id], '*', MUST_EXIST);
            }
            $offer = $of ?? ($back ? null : $offer);
        }
        if (
            $offer && (string) $offer->schoolyear !== (string) $row->schoolyear && ($child->stage !== 'enrolled' ||
                strcmp((string) $offer->schoolyear, (string) $row->schoolyear) > 0)
        ) {
            $DB->update_record(self::T, (object) ($done + ['kind' => 'inside']));
            self::already($household, $child, $row);
            return;
        }
        $was = $child->stage !== 'enrolled' ? $child->stage . ':' . (int) $child->stagetime : null;
        $DB->update_record(self::T, (object) ($done + ['kind' => 'enrolled', 'was' => $back ? null : $was]));
        if ($was !== null) {
            self::set_enrolled($household, $child, $row, $now);
        }
    }

    /**
     * A child that asks for a place for another year is already there, as a person said: when it was there before its family
     * came, it goes on (ties); when it came after, its family is one the centre attracted, and it stays so.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @param \stdClass $row
     */
    protected static function already(\stdClass $household, \stdClass $child, \stdClass $row): void {
        $centre = centres::get((int) $household->centreid);
        $arrival = self::people((int) $household->centreid, [$household])[(int) $household->id]['arrival'];
        households::note($household, 0, get_string('listmatch_inside_note', self::C, (object) [
            'child' => format_string($child->firstname), 'grade' => $row->grade, 'year' => $row->schoolyear]));
        if ((int) $row->timein > 0 && $arrival > (int) $row->timein + self::AFTER) {
            $age = grades::age((string) $row->grade, $centre ?: null);
            ties::set($household, (int) $child->id, $age !== null && $age <= who::NURSERY_AGE ? 'nursery' : 'student', true, 0);
        }
    }

    /**
     * The course of a row, as the centre offers it for its year.
     *
     * @param int $centreid
     * @param \stdClass $row
     * @return \stdClass|null
     */
    protected static function offer(int $centreid, \stdClass $row): ?\stdClass {
        $centre = centres::get($centreid) ?: null;
        $age = grades::age((string) $row->grade, $centre);
        $found = array_filter(centres::offers($centreid), fn($o) => (string) $o->schoolyear === (string) $row->schoolyear &&
            (grades::plain((string) $o->name) === grades::plain((string) $row->grade) ||
            ($age !== null && grades::age((string) $o->name, $centre) === $age)));
        return count($found) === 1 ? reset($found) : null;
    }

    /**
     * What a person says of something the list said: that it is not so (no), or that it is (yes). Of a child the list enrolled
     * by itself, no puts it back where it was; of a family of the centre, no takes it out; whether a student with a contact
     * that several families share is of this one; and whether a child the list no longer has is still enrolled.
     *
     * @param \stdClass $household
     * @param int $rowid
     * @param bool $yes
     * @param int $userid
     * @return bool Whether there was something to say.
     */
    public static function say(\stdClass $household, int $rowid, bool $yes, int $userid): bool {
        global $DB;
        $row = $DB->get_record(self::T, ['id' => $rowid, 'householdid' => $household->id]);
        if (!$row) {
            return false;
        }
        $now = time();
        $set = fn(array $what) => $DB->update_record(self::T, (object) (['id' => $row->id, 'done' => $now, 'said' => 1] + $what));
        if ($row->kind === 'maybe' && !(int) $row->done) {
            if (!$yes) {
                $set(['kind' => 'none']);
                return true;
            }
            $s = self::lists((int) $household->centreid, $now)['now'][$row->student] ?? null;
            if ($s && $s['join'] && !$s['inside']) {
                // Of this family, and new: now, which of its children.
                $set(['kind' => 'ask', 'done' => 0, 'schoolyear' => $s['join']['year'], 'timein' => $s['join']['timein']]);
                return true;
            }
            $isof = (int) $DB->get_field(households::T, 'incentre', ['id' => $household->id]);
            $set(['kind' => 'incentre', 'marked' => $isof ? self::WAS : self::MADE]);
            if (!$isof) {
                $DB->set_field(households::T, 'incentre', 1, ['id' => $household->id]);
                $household->incentre = 1;
                households::note($household, $userid, get_string('listmatch_incentre_note', self::C, $row->grade));
            }
            who::reset();
            return true;
        }
        if ($row->kind === 'gone' && !(int) $row->done && $yes) {
            $set(['kind' => 'enrolled']);
            return true;
        }
        if ($yes) {
            return false;
        }
        if ($row->kind === 'incentre') {
            $set(['kind' => 'none', 'marked' => 0]);
            households::note($household, $userid, get_string('listmatch_undone_incentre', self::C));
            if ((int) $row->marked === self::MADE) {
                self::reckon($household);
            }
            who::reset();
            return true;
        }
        if (in_array($row->kind, ['enrolled', 'gone'], true) && ((string) $row->was !== '' || $row->kind === 'gone')) {
            $child = $DB->get_record(households::CHILDREN, ['id' => $row->childid, 'householdid' => $household->id]);
            $set(['kind' => 'none', 'childid' => 0]);
            if ($child && $child->stage === 'enrolled') {
                [$stage, $time] = array_pad(explode(':', (string) $row->was, 2), 2, '0');
                $stage = in_array($stage, lists::STAGES, true) && $stage !== 'enrolled' ? $stage : 'inquiry';
                households::set_stage($child, $stage);
                $DB->update_record(households::CHILDREN, (object) (['id' => $child->id, 'startyear' => null] +
                    ((int) $time ? ['stagetime' => (int) $time] : [])));
                households::note($household, $userid, get_string('listmatch_undone_enrolled', self::C, panel::tag($child)));
            }
            return true;
        }
        return false;
    }

    /**
     * What a family can download of it: what the lists of the centre say of it.
     *
     * @param int $householdid
     * @return array
     */
    public static function export(int $householdid): array {
        global $DB;
        $names = $DB->get_records_menu(households::CHILDREN, ['householdid' => $householdid], '', 'id, firstname');
        $out = [];
        foreach ($DB->get_records(self::T, ['householdid' => $householdid], 'id ASC') as $row) {
            if (in_array($row->kind, ['enrolled', 'inside', 'incentre', 'former'], true)) {
                $out[] = array_filter(['what' => ['enrolled' => 'enrolled', 'inside' => 'already there', 'incentre' => 'children there',
                    'former' => 'children there before'][$row->kind], 'child' => (string) ($names[$row->childid] ?? ''),
                    'grade' => (string) $row->grade, 'school_year' => (string) $row->schoolyear]);
            }
        }
        return $out;
    }

    /**
     * The question of a row, with its answers, as a person reads it.
     *
     * @param \stdClass $row Of kind ask, maybe or gone, still to be said.
     * @param \stdClass[] $children Those of its family, by id.
     * @param array $taken Those the list already knows, by id.
     * @param array $offers The offers of the centre, by id.
     * @return array id, text, which (it asks which child) or yes and no, the words of its answers; children.
     */
    public static function question(\stdClass $row, array $children, array $taken, array $offers): array {
        $c = self::C;
        $a = (object) ['grade' => $row->grade, 'year' => $row->schoolyear,
            'date' => userdate((int) $row->timein ?: (int) $row->timecreated, get_string('strftimedate', 'langconfig'))];
        $out = ['id' => (int) $row->id, 'which' => false, 'children' => [], 'haschildren' => false, 'yes' => '', 'no' => ''];
        if ($row->kind === 'maybe') {
            return ['text' => get_string('panel_fromlist_maybe', $c, $a), 'yes' => get_string('panel_fromlist_maybe_yes', $c),
                'no' => get_string('panel_fromlist_maybe_no', $c)] + $out;
        }
        if ($row->kind === 'gone') {
            $a->child = isset($children[(int) $row->childid]) ? format_string(panel::tag($children[(int) $row->childid])) : '—';
            return ['text' => get_string('panel_fromlist_gone', $c, $a), 'yes' => get_string('panel_fromlist_gone_yes', $c),
                'no' => get_string('panel_fromlist_gone_no', $c)] + $out;
        }
        $options = [];
        foreach ($children as $child) {
            if (isset($taken[(int) $child->id])) {
                continue;
            }
            $offer = $offers[$child->offerid] ?? null;
            $name = format_string(panel::tag($child)) ?: get_string('panel_fromlist_noname', $c);
            $options[] = ['id' => (int) $child->id, 'name' => $offer ? get_string(
                $child->stage === 'enrolled' ? 'panel_fromlist_this_enrolled' : 'panel_fromlist_this_asks',
                $c,
                (object) ['name' => $name, 'course' => format_string((string) $offer->name), 'year' => $offer->schoolyear]
            ) : get_string('panel_fromlist_this', $c, $name)];
        }
        return ['text' => get_string('panel_fromlist_ask', $c, $a), 'which' => true, 'children' => $options,
            'haschildren' => (bool) $options] + $out;
    }

    /**
     * What the file of a household shows of the list of the centre: its children already there, those enrolled by the
     * list, and the questions when it was not clear.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param bool $canact
     * @return array|false
     */
    public static function context(\stdClass $household, \stdClass $full, bool $canact) {
        global $DB;
        $rows = $DB->get_records(self::T, ['householdid' => $household->id], 'timecreated ASC, id ASC');
        if (!$rows) {
            return false;
        }
        $c = self::C;
        $centregrades = [];
        $formergrades = [];
        $made = 0;
        $lines = [];
        $asks = [];
        $taken = self::taken([array_values($rows)]);
        $offers = centres::offers((int) $household->centreid);
        foreach ($rows as $row) {
            $date = userdate((int) $row->timein ?: (int) $row->timecreated, get_string('strftimedate', 'langconfig'));
            $child = $full->children[(int) $row->childid] ?? null;
            $a = (object) ['child' => $child ? format_string($child->firstname) : '', 'grade' => $row->grade, 'date' => $date,
                'year' => $row->schoolyear];
            if ($row->kind === 'incentre') {
                $centregrades[$row->grade] = true;
                $made = (int) $row->id;
            } else if ($row->kind === 'former') {
                $formergrades[$row->grade . ' (' . $row->schoolyear . ')'] = true;
            } else if ($row->kind === 'enrolled') {
                $lines[] = ['text' => get_string($child ? 'panel_fromlist_enrolled' : 'panel_fromlist_enrolled_one', $c, $a),
                    'undo' => $canact && $child && (string) $row->was !== '' && !(int) $row->said &&
                        $child->stage === 'enrolled' ? (int) $row->id : false];
            } else if ($row->kind === 'inside') {
                $lines[] = ['text' => get_string($child ? 'panel_fromlist_inside' : 'panel_fromlist_enrolled_one', $c, $a),
                    'undo' => false];
            } else if (in_array($row->kind, ['ask', 'maybe', 'gone'], true) && !(int) $row->done && $canact) {
                $asks[] = self::question($row, $full->children, $taken, $offers);
            }
        }
        if ($formergrades) {
            array_unshift($lines, ['text' => get_string('panel_fromlist_former', $c, implode(', ', array_keys($formergrades))),
                'undo' => false]);
        }
        if ($centregrades) {
            array_unshift($lines, ['text' => get_string('panel_fromlist_incentre', $c, implode(', ', array_keys($centregrades))),
                'undo' => $canact && count($centregrades) === 1 ? $made : false]);
        }
        if (!$lines && !$asks) {
            return false;
        }
        return ['lines' => $lines, 'asks' => $asks, 'hasasks' => (bool) $asks,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'sesskey' => sesskey()];
    }
}
