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
 * Which rows of the imported lists are the same family, worked out from all of them at once. Each row keeps its own
 * contacts and the surnames of its student as keyed hashes (marks), so what one night joined by mistake the next one takes
 * apart: nothing depends on the order things came in, nor on what was joined before.
 *
 * An email or a phone joins the rows that have it, as long as the students that share it are of one family: they have a
 * surname in common, or another contact. Shared by students of different surnames (a grandmother, a number written in the
 * wrong file) it joins only those that also share a surname or another contact: with doubt, nobody is joined. In more
 * than a few families at once (the office of the centre, a number to fill the box), it is nobody's and joins nobody.
 *
 * Rows imported before they kept their marks stay as they were, until their list comes in again.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class listhomes {
    /** @var string[] The tables with rows of families: the students of the lists, the contacts of the campaigns. */
    const TABLES = ['s' => 'local_oksigeniaeguahe_student', 'c' => 'local_oksigeniaeguahe_pastcontact'];

    /** @var int A contact in more families than these, told apart by their surnames, is nobody's. */
    const MANY = 4;

    /** @var string A contact of one family. */
    const PLAIN = 'plain';

    /** @var string A contact that students of different surnames share: it joins only with something else in common. */
    const DOUBT = 'doubt';

    /** @var string A contact of nobody. */
    const NEVER = 'never';

    /**
     * What a row keeps to be joined with the others.
     *
     * @param pseudonym $keys
     * @param array $contacts Keys from pseudonym::email() and phone().
     * @param string[] $surnames The words of the surnames of its student, when they are known.
     * @param string[] $names The words of the first name of its student; all the words, when they are not told apart.
     * @param bool $alumni Whether a column says someone of the family studied at the centre.
     * @return string|null
     */
    public static function marks(
        pseudonym $keys,
        array $contacts,
        array $surnames,
        array $names,
        bool $alumni = false
    ): ?string {
        $marks = array_filter(['k' => $keys->shorts($contacts), 's' => $keys->wordmarks($surnames),
            'n' => $keys->wordmarks($names), 'a' => $alumni ? 1 : 0]);
        return $marks ? json_encode($marks) : null;
    }

    /**
     * What each contact is, and which rows are one family.
     *
     * @param array[] $rows Each: t (s, a student; c, a contact), student (its key), k (contacts) and s (surnames).
     * @return array status (contact => one of PLAIN, DOUBT, NEVER), students (contact => how many students have it),
     *     families (contact => how many families, as far as its surnames tell) and home (row => the first row of its
     *     family; only the rows with a contact of somebody).
     */
    public static function judge(array $rows): array {
        $byk = [];
        foreach ($rows as $i => $row) {
            foreach ($row['k'] as $k) {
                $byk[$k][] = $i;
            }
        }
        $up = array_combine(array_keys($rows), array_keys($rows)) ?: [];
        $find = function (array &$tree, int $i): int {
            while ($tree[$i] !== $i) {
                $tree[$i] = $tree[$tree[$i]];
                $i = $tree[$i];
            }
            return $i;
        };
        $join = function (array &$tree, int $a, int $b) use ($find): void {
            $a = $find($tree, $a);
            $b = $find($tree, $b);
            if ($a !== $b) {
                $tree[max($a, $b)] = min($a, $b);
            }
        };
        $status = [];
        $students = [];
        $families = [];
        $groups = [];
        foreach ($byk as $k => $list) {
            $status[$k] = self::PLAIN;
            $students[$k] = count(array_unique(array_filter(array_map(
                fn($i) => $rows[$i]['t'] === 's' ? (string) $rows[$i]['student'] : '',
                $list
            ))));
            $families[$k] = 1;
            if (count($list) < 2) {
                continue;
            }
            // The rows with a surname, put together by the ones they share (and the same student, always itself).
            $tree = array_combine($list, $list);
            $first = [];
            foreach ($list as $i) {
                $by = $rows[$i]['s'];
                if ($rows[$i]['t'] === 's' && (string) $rows[$i]['student'] !== '') {
                    $by[] = 'st:' . $rows[$i]['student'];
                }
                foreach ($by as $word) {
                    if (isset($first[$word])) {
                        $join($tree, $first[$word], $i);
                    } else {
                        $first[$word] = $i;
                    }
                }
            }
            $known = [];
            $other = [];
            foreach ($list as $i) {
                if ($rows[$i]['t'] === 's' && $rows[$i]['s']) {
                    $known[$find($tree, $i)] = true;
                } else if (!$rows[$i]['s']) {
                    $other[] = $i;
                }
            }
            // Those without a surname, by another contact they share: many apart are many families.
            $apart = array_combine($other, $other) ?: [];
            $first = [];
            $counted = [];
            foreach ($other as $i) {
                $by = array_diff($rows[$i]['k'], [$k]);
                foreach ($by as $word) {
                    if (isset($first[$word])) {
                        $join($apart, $first[$word], $i);
                    } else {
                        $first[$word] = $i;
                    }
                }
                if ($by) {
                    $counted[] = $i;
                }
            }
            $unknown = count(array_unique(array_map(fn($i) => $find($apart, $i), $counted)));
            $families[$k] = max(1, count($known));
            if (count($known) > self::MANY || $unknown > self::MANY) {
                $status[$k] = self::NEVER;
                $families[$k] = max(count($known), $unknown);
            } else if (count($known) > 1) {
                $status[$k] = self::DOUBT;
                $groups[$k] = $tree;
            }
        }
        // Now the families: by each contact of one of them; by a shared one, those with a surname in common; and those
        // that share two of the shared ones.
        $pairs = [];
        foreach ($byk as $k => $list) {
            if ($status[$k] === self::PLAIN) {
                foreach (array_slice($list, 1) as $i) {
                    $join($up, $list[0], $i);
                }
            } else if ($status[$k] === self::DOUBT) {
                $lead = null;
                foreach ($list as $i) {
                    $join($up, $find($groups[$k], $i), $i);
                    if ($rows[$i]['t'] === 'c' && !$rows[$i]['s']) {
                        // The contacts of a campaign with the same phone and nothing else are one family, whoever it is.
                        $lead = $lead ?? $i;
                        $join($up, $lead, $i);
                    }
                }
            }
        }
        foreach ($rows as $i => $row) {
            $shared = array_values(array_filter($row['k'], fn($k) => $status[$k] === self::DOUBT));
            sort($shared);
            for ($a = 0; $a < count($shared); $a++) {
                for ($b = $a + 1; $b < count($shared); $b++) {
                    $pair = $shared[$a] . '|' . $shared[$b];
                    if (isset($pairs[$pair])) {
                        $join($up, $pairs[$pair], $i);
                    } else {
                        $pairs[$pair] = $i;
                    }
                }
            }
        }
        $home = [];
        foreach ($rows as $i => $row) {
            if (array_filter($row['k'], fn($k) => $status[$k] !== self::NEVER)) {
                $home[$i] = $find($up, $i);
            }
        }
        return ['status' => $status, 'students' => $students, 'families' => $families, 'home' => $home];
    }

    /**
     * The rows of a centre that keep their marks.
     *
     * @param int $centreid
     * @return array[] Each: t, id, token, importid, student, k, s and a.
     */
    protected static function rows(int $centreid): array {
        global $DB;
        $out = [];
        foreach (self::TABLES as $t => $table) {
            $set = $DB->get_recordset_select(
                $table,
                'centreid = :centreid AND marks IS NOT NULL',
                ['centreid' => $centreid],
                'id ASC',
                'id, importid, household, student, marks'
            );
            foreach ($set as $row) {
                $marks = (array) json_decode((string) $row->marks, true);
                $out[] = ['t' => $t, 'id' => (int) $row->id, 'token' => (string) $row->household,
                    'importid' => (int) $row->importid, 'student' => (string) $row->student,
                    'k' => array_map('strval', (array) ($marks['k'] ?? [])), 's' => array_map('strval', (array) ($marks['s'] ?? [])),
                    'a' => !empty($marks['a'])];
            }
            $set->close();
        }
        return $out;
    }

    /**
     * The students that have a contact of doubt, to find out whether a family of Eguahe is one of them.
     *
     * @param int $centreid
     * @return array contact (short) => student key => [k, s], each as its rows have them.
     */
    public static function doubts(int $centreid): array {
        $rows = self::rows($centreid);
        $status = self::judge($rows)['status'];
        $out = [];
        foreach ($rows as $row) {
            if ($row['t'] !== 's') {
                continue;
            }
            foreach ($row['k'] as $k) {
                if ($status[$k] === self::DOUBT) {
                    $was = $out[$k][$row['student']] ?? ['k' => [], 's' => []];
                    $out[$k][$row['student']] = ['k' => array_values(array_unique(array_merge($was['k'], $row['k']))),
                        's' => array_values(array_unique(array_merge($was['s'], $row['s'])))];
                }
            }
        }
        return $out;
    }

    /**
     * Works out the families of a centre again, from all its rows: each family with a token, the one most of it had; the
     * keys of the families with the token of theirs, and none for a contact of doubt or of nobody.
     *
     * @param int $centreid
     * @return array import id => [never, doubt], how many contacts of each it has.
     */
    public static function settle(int $centreid): array {
        global $DB;
        $rows = self::rows($centreid);
        if (!$rows) {
            return [];
        }
        $judged = self::judge($rows);
        $status = $judged['status'];
        $keyrows = [];
        $set = $DB->get_recordset(pseudonym::T, ['centreid' => $centreid, 'kind' => 'household'], '', 'id, keyhash, token, live');
        foreach ($set as $row) {
            $keyrows[substr($row->keyhash, 0, pseudonym::SHORT)] = [(int) $row->id, (string) $row->token, (int) $row->live];
        }
        $set->close();
        // The families, with what each would be called.
        $comps = [];
        foreach ($judged['home'] as $i => $root) {
            $comps[$root]['rows'][] = $i;
            foreach ($rows[$i]['k'] as $k) {
                if ($status[$k] === self::PLAIN) {
                    $comps[$root]['keys'][$k] = true;
                }
            }
        }
        $claims = [];
        $claimed = [];
        $mine = [];
        foreach ($comps as $root => $comp) {
            $votes = [];
            foreach ($comp['rows'] as $i) {
                if ($rows[$i]['token'] !== '') {
                    $votes[$rows[$i]['token']] = ($votes[$rows[$i]['token']] ?? 0) + 1;
                }
            }
            foreach (array_keys($comp['keys'] ?? []) as $k) {
                $token = $keyrows[$k][1] ?? '';
                if ($token !== '') {
                    $votes[$token] = ($votes[$token] ?? 0) + 1;
                }
            }
            foreach ($votes as $token => $n) {
                $claims[] = [$n, $root, (string) $token];
                $claimed[$token][$root] = $n;
                $mine[$root][] = (string) $token;
            }
        }
        $legacy = self::legacy($centreid);
        usort($claims, fn($a, $b) => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        $called = [];
        $taken = [];
        foreach ($claims as [, $root, $token]) {
            // A token of old rows that several families claim was several families made one: none of them keeps it.
            if (isset($called[$root]) || isset($taken[$token]) || (isset($legacy[$token]) && count($claimed[$token]) > 1)) {
                continue;
            }
            $called[$root] = $token;
            $taken[$token] = $root;
        }
        $moves = [];
        $keymoves = [];
        $tokenof = [];
        foreach ($comps as $root => $comp) {
            $token = $called[$root] ?? pseudonym::newhome();
            foreach ($comp['rows'] as $i) {
                $tokenof[$i] = $token;
                if ($rows[$i]['token'] !== $token) {
                    $moves[$rows[$i]['t']][$token][] = $rows[$i]['id'];
                }
            }
            foreach (array_keys($comp['keys'] ?? []) as $k) {
                if (isset($keyrows[$k]) && $keyrows[$k][1] !== $token) {
                    $keymoves[$token][] = $keyrows[$k][0];
                }
            }
            foreach ($mine[$root] ?? [] as $old) {
                if ($old !== $token && isset($legacy[$old])) {
                    $alone = count($claimed[$old]) === 1 && !isset($taken[$old]);
                    self::old_rows($centreid, $old, $token, $alone ? null : self::students_of($comp['rows'], $rows));
                }
            }
        }
        // A student whose contacts are all of nobody has no family to be told by; a contact of a campaign is its own.
        foreach ($rows as $i => $row) {
            if (isset($tokenof[$i])) {
                continue;
            }
            if (!$row['k']) {
                // Without contacts of its own: what it came with.
                $tokenof[$i] = $row['token'];
                continue;
            }
            $own = $row['t'] === 'c' ? ($row['token'] !== '' && !isset($taken[$row['token']]) ? $row['token'] :
                pseudonym::newhome()) : '';
            if ($own !== '') {
                $taken[$own] = $i;
            }
            $tokenof[$i] = $own;
            if ($row['token'] !== $own) {
                $moves[$row['t']][$own][] = $row['id'];
            }
        }
        foreach ($moves as $t => $tokens) {
            foreach ($tokens as $token => $ids) {
                self::set(self::TABLES[$t], 'household', (string) $token === '' ? null : (string) $token, $ids);
            }
        }
        foreach ($keymoves as $token => $ids) {
            self::set(pseudonym::T, 'token', (string) $token, $ids);
        }
        // A contact of doubt or of nobody finds nobody; nor does one that was in the list of the night before and is in none any
        // more (corrected), unless it is of a family that is only in the rows of before.
        $gone = [];
        $live = [];
        foreach ($keyrows as $k => [$id, $token, $was]) {
            $what = $status[$k] ?? null;
            if ($what === self::PLAIN) {
                if (!$was) {
                    $live[] = $id;
                }
            } else if ($what !== null || ($was && (isset($taken[$token]) || !isset($legacy[$token])))) {
                $gone[] = $id;
            }
        }
        foreach (array_chunk($live, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $DB->set_field_select(pseudonym::T, 'live', 1, "id $in", $params);
        }
        foreach (array_chunk($gone, 500) as $chunk) {
            $DB->delete_records_list(pseudonym::T, 'id', $chunk);
        }
        self::alumni($centreid, $rows, $tokenof);
        return self::told($rows, $status);
    }

    /**
     * Sets a field of many rows.
     *
     * @param string $table
     * @param string $field
     * @param string|null $value
     * @param int[] $ids
     */
    protected static function set(string $table, string $field, ?string $value, array $ids): void {
        global $DB;
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $DB->set_field_select($table, $field, $value, "id $in", $params);
        }
    }

    /**
     * The tokens of the rows imported before they kept their marks.
     *
     * @param int $centreid
     * @return array token => true
     */
    protected static function legacy(int $centreid): array {
        global $DB;
        $out = [];
        foreach (self::TABLES as $table) {
            foreach (
                $DB->get_fieldset_select(
                    $table,
                    'DISTINCT household',
                    'centreid = :centreid AND marks IS NULL AND household IS NOT NULL',
                    ['centreid' => $centreid]
                ) as $token
            ) {
                $out[$token] = true;
            }
        }
        return $out;
    }

    /**
     * The students of some rows.
     *
     * @param int[] $indexes
     * @param array[] $rows
     * @return string[]
     */
    protected static function students_of(array $indexes, array $rows): array {
        $out = [];
        foreach ($indexes as $i) {
            if ($rows[$i]['t'] === 's' && $rows[$i]['student'] !== '') {
                $out[$rows[$i]['student']] = $rows[$i]['student'];
            }
        }
        return array_values($out);
    }

    /**
     * The rows imported before they kept their marks, of a family that now has another token: all of them, when nobody else
     * claims theirs; else only those of its own students.
     *
     * @param int $centreid
     * @param string $old Their token.
     * @param string $token The one of the family now.
     * @param string[]|null $students Null, all of them.
     */
    protected static function old_rows(int $centreid, string $old, string $token, ?array $students): void {
        global $DB;
        $where = 'centreid = :centreid AND household = :old AND marks IS NULL';
        $params = ['centreid' => $centreid, 'old' => $old];
        if ($students === null) {
            foreach (self::TABLES as $table) {
                $DB->set_field_select($table, 'household', $token, $where, $params);
            }
            $DB->set_field(alumni::T, 'token', $token, ['centreid' => $centreid, 'token' => $old]);
            $DB->set_field(pseudonym::T, 'token', $token, ['centreid' => $centreid, 'kind' => 'household', 'token' => $old]);
            return;
        }
        foreach (array_chunk($students, 500) as $chunk) {
            [$in, $p] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $DB->set_field_select(self::TABLES['s'], 'household', $token, $where . " AND student $in", $params + $p);
        }
    }

    /**
     * The families whose column says someone studied at the centre, with their token now.
     *
     * @param int $centreid
     * @param array[] $rows
     * @param array $tokenof Row => token.
     */
    protected static function alumni(int $centreid, array $rows, array $tokenof): void {
        global $DB;
        $now = [];
        foreach ($rows as $i => $row) {
            if ($row['a'] && ($tokenof[$i] ?? '') !== '') {
                $now[$row['importid']][$tokenof[$i]] = true;
            }
        }
        foreach ($now as $importid => $tokens) {
            $had = $DB->get_fieldset_select(
                alumni::T,
                'token',
                'centreid = :centreid AND importid = :importid AND token IS NOT NULL',
                ['centreid' => $centreid, 'importid' => $importid]
            );
            if (array_diff(array_keys($tokens), $had) || array_diff($had, array_keys($tokens))) {
                $DB->delete_records_select(
                    alumni::T,
                    'centreid = :centreid AND importid = :importid AND token IS NOT NULL',
                    ['centreid' => $centreid, 'importid' => $importid]
                );
                alumni::from_import($centreid, (int) $importid, array_keys($tokens));
            }
        }
    }

    /**
     * How many contacts of each import join nobody, or only with something else in common.
     *
     * @param array[] $rows
     * @param array $status
     * @return array import id => [never, doubt]
     */
    protected static function told(array $rows, array $status): array {
        $seen = [];
        foreach ($rows as $row) {
            foreach ($row['k'] as $k) {
                if ($status[$k] !== self::PLAIN) {
                    $seen[$row['importid']][$status[$k]][$k] = true;
                }
            }
        }
        return array_map(fn($s) => [count($s[self::NEVER] ?? []), count($s[self::DOUBT] ?? [])], $seen);
    }
}
