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
 * Which student each row of a list of a school year is, all the rows at once: two rows of the same year of different
 * courses are two students, whatever they share (the name of a namesake, a cell with the code of the family, a key that an
 * earlier file gave to two brothers). A student is known by its code in the systems of the centre; by its name with its day
 * of birth, or with a contact of its family; and by its name alone while nobody else in the list has it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class liststudents {
    /** @var string */
    const T = 'local_oksigeniaeguahe_student';

    /** @var array How much each kind of key says. */
    const STRENGTH = ['id' => 4, 'with' => 3, 'ordered' => 2, 'name' => 1];

    /**
     * The token of the student of each row.
     *
     * @param int $centreid
     * @param pseudonym $keys
     * @param array[] $rows Each: codes, name and ordered (keys, or null), with (keys of its name with something else), grade
     *     (as Eguahe names it) and year.
     * @return string[] Row => token.
     */
    public static function tokens(int $centreid, pseudonym $keys, array $rows): array {
        $of = array_map(fn($row) => self::keys_of($row), $rows);
        $of = self::own($rows, $of);
        // The rows of one student: those that share a key that is of one student.
        $up = array_combine(array_keys($rows), array_keys($rows)) ?: [];
        $find = function (int $i) use (&$up): int {
            while ($up[$i] !== $i) {
                $i = $up[$i] = $up[$up[$i]];
            }
            return $i;
        };
        $first = [];
        foreach ($of as $i => $mine) {
            foreach ($mine as $key) {
                if (isset($first[$key])) {
                    $a = $find($first[$key]);
                    $b = $find($i);
                    $up[max($a, $b)] = min($a, $b);
                } else {
                    $first[$key] = $i;
                }
            }
        }
        $comps = [];
        foreach ($of as $i => $mine) {
            $root = $find($i);
            $comps[$root]['rows'][] = $i;
            foreach ($mine as $key) {
                $comps[$root]['keys'][$key] = true;
            }
        }
        $all = array_merge([], ...array_map(fn($row) => self::keys_of($row), array_values($rows)));
        $keys->warm('student', $all);
        // Who each was before: one token is of one student, the one its strongest keys say.
        $claims = [];
        foreach ($comps as $root => $comp) {
            foreach (array_keys($comp['keys'] ?? []) as $key) {
                $token = $keys->had('student', (string) $key);
                if ($token !== null) {
                    $claims[$token][$root][] = self::STRENGTH[strtok((string) $key, ':')] ?? 1;
                }
            }
        }
        $order = [];
        foreach ($claims as $token => $roots) {
            foreach ($roots as $root => $strengths) {
                $order[] = [max($strengths), count($strengths), $root, (string) $token];
            }
        }
        usort($order, fn($a, $b) => [$b[0], $b[1], $a[2], $a[3]] <=> [$a[0], $a[1], $b[2], $b[3]]);
        $called = [];
        $taken = [];
        foreach ($order as [, , $root, $token]) {
            if (!isset($called[$root]) && !isset($taken[$token])) {
                $called[$root] = $token;
                $taken[$token] = $root;
            }
        }
        $out = [];
        $merged = [];
        foreach ($comps as $root => $comp) {
            $token = $called[$root] ?? pseudonym::newstudent();
            foreach ($comp['rows'] as $i) {
                $out[$i] = $token;
            }
            foreach ($claims as $other => $roots) {
                if (!isset($roots[$root]) || (string) $other === $token) {
                    continue;
                }
                if (count($roots) === 1 && !isset($taken[$other]) && !self::both($centreid, (string) $other, $token)) {
                    // Two that were apart (the code in one file, the name in another) are the same.
                    $merged[(string) $other] = $token;
                } else if (isset($taken[$other])) {
                    self::parted($centreid, (string) $other, $token, $rows[$comp['rows'][0]], $rows[min(
                        $comps[$taken[$other]]['rows']
                    )]);
                }
            }
        }
        foreach ($merged as $from => $to) {
            $keys->one_student((string) $from, $to);
        }
        foreach ($comps as $root => $comp) {
            foreach (array_keys($comp['keys'] ?? []) as $key) {
                $keys->give('student', (string) $key, $out[$comp['rows'][0]]);
            }
        }
        // A key of several is of nobody.
        $theirs = array_merge([], ...array_map(fn($comp) => array_keys($comp['keys'] ?? []), array_values($comps)));
        foreach (array_diff(array_unique($all), array_map('strval', $theirs)) as $key) {
            $keys->give('student', (string) $key, null);
        }
        return $out;
    }

    /**
     * The keys of a row.
     *
     * @param array $row
     * @return string[]
     */
    protected static function keys_of(array $row): array {
        return array_values(array_unique(array_filter(array_merge(
            $row['codes'],
            $row['with'],
            [$row['ordered'], $row['name']]
        ))));
    }

    /**
     * The keys of each row that are of one student: not those that rows of the same year share and are two students.
     *
     * @param array[] $rows
     * @param array $of Row => its keys.
     * @return array Row => its keys.
     */
    protected static function own(array $rows, array $of): array {
        $by = [];
        foreach ($of as $i => $mine) {
            foreach ($mine as $key) {
                $by[$key][] = $i;
            }
        }
        // The codes first: one that students of different names share is not a code of a student.
        $bad = [];
        foreach ($by as $key => $list) {
            if (
                strpos((string) $key, 'id:') === 0 && self::differ($rows, $list, fn($a, $b) => $a['name'] !== null &&
                    $b['name'] !== null && $a['name'] !== $b['name'])
            ) {
                $bad[$key] = true;
            }
        }
        $codes = array_map(fn($row) => array_values(array_filter($row['codes'], fn($c) => !isset($bad[$c]))), $rows);
        foreach ($by as $key => $list) {
            if (strpos((string) $key, 'id:') === 0 || count($list) < 2) {
                continue;
            }
            $two = false;
            foreach ($list as $x => $i) {
                foreach (array_slice($list, $x + 1) as $j) {
                    if ($rows[$i]['year'] !== $rows[$j]['year']) {
                        continue;
                    }
                    if ($codes[$i] && $codes[$j]) {
                        $two = $two || !array_intersect($codes[$i], $codes[$j]);
                        continue;
                    }
                    $two = $two || ($rows[$i]['ordered'] !== $rows[$j]['ordered']) ||
                        ($rows[$i]['grade'] !== '' && $rows[$j]['grade'] !== '' && $rows[$i]['grade'] !== $rows[$j]['grade']);
                }
            }
            if ($two) {
                $bad[$key] = true;
            }
        }
        return array_map(fn($mine) => array_values(array_filter($mine, fn($key) => !isset($bad[$key]))), $of);
    }

    /**
     * Whether two of some rows of the same year differ.
     *
     * @param array[] $rows
     * @param int[] $list
     * @param callable $how
     * @return bool
     */
    protected static function differ(array $rows, array $list, callable $how): bool {
        foreach ($list as $x => $i) {
            foreach (array_slice($list, $x + 1) as $j) {
                if ($rows[$i]['year'] === $rows[$j]['year'] && $how($rows[$i], $rows[$j])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether two students are on the same list: they are two.
     *
     * @param int $centreid
     * @param string $a
     * @param string $b
     * @return bool
     */
    protected static function both(int $centreid, string $a, string $b): bool {
        global $DB;
        return $DB->record_exists_sql(
            'SELECT 1
               FROM {' . self::T . '} x
               JOIN {' . self::T . '} y ON y.importid = x.importid AND y.schoolyear = x.schoolyear AND y.student = :b
              WHERE x.centreid = :centreid AND x.student = :a',
            ['centreid' => $centreid, 'a' => $a, 'b' => $b]
        );
    }

    /**
     * Two students an earlier list had made one: the rows of the years before, of the course one of them was in then, are its.
     *
     * @param int $centreid
     * @param string $old The token both had.
     * @param string $token The one of the student that leaves it.
     * @param array $row A row of it.
     * @param array $stays A row of the one that keeps it.
     */
    protected static function parted(int $centreid, string $old, string $token, array $row, array $stays): void {
        global $DB;
        $centre = centres::get($centreid) ?: null;
        $mine = grades::age($row['grade'], $centre);
        $theirs = grades::age($stays['grade'], $centre);
        if ($mine === null || $mine === $theirs) {
            return;
        }
        $rows = $DB->get_records(self::T, ['centreid' => $centreid, 'student' => $old], 'id ASC', 'id, grade, schoolyear');
        foreach ($rows as $one) {
            $age = grades::age((string) $one->grade, $centre);
            if ($age !== null && $age + (int) substr($row['year'], 0, 4) - (int) substr((string) $one->schoolyear, 0, 4) === $mine) {
                $DB->set_field(self::T, 'student', $token, ['id' => $one->id]);
            }
        }
    }
}
