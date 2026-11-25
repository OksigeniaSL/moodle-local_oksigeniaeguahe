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
 * Who a family that comes is, as far as the centre knows: really new, already of the centre (a child in its nursery,
 * children inside, or someone who works there), a family that already had children at the centre, or one where a mother,
 * a father or someone of the family studied there. A family that comes to an open day with a child already in the nursery knew the centre:
 * counting it as new gives a picture that is not true. It is told by what the centre itself knows (its lists, the
 * family that says it, a person of the team, the list of former students), never guessed, and never a mark on a family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class who {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] Who a family is, from the most to the least known: in the nursery of the centre, with children
     * inside, of its staff, with children there before, of former students, and new. A family that is two things counts
     * once, as the first of them. */
    const KINDS = ['nursery', 'centre', 'staff', 'former', 'alumni', 'new'];

    /** @var string[] Those already of the centre: they come, but the centre has not attracted them. */
    const CENTRE = ['nursery', 'centre', 'staff'];

    /** @var int The oldest age of a course that is the nursery of a centre. */
    const NURSERY_AGE = 2;

    /** @var array Centre id => household id => what is known of each family, once per request. */
    protected static $cache = [];

    /**
     * Who each family of a centre is, when it is not new: the first of the things it is.
     *
     * @param int $centreid
     * @param string|null $year
     * @return array household id => nursery, centre, staff, former or alumni.
     */
    public static function of_centre(int $centreid, ?string $year = null): array {
        return array_map(fn($kinds) => $kinds[0], self::all_of_centre($centreid, $year));
    }

    /**
     * Every thing each family of a centre is, when it is not new: one may have a child in the nursery and work there.
     *
     * A family is of the centre by what it was when its campaign began: the child a campaign brought is never what makes its
     * own family one of the centre, though the lists of the years after name it; for the campaign of a later year it is.
     *
     * @param int $centreid
     * @param string|null $year The school year of the campaign it is seen from; null, the one under way.
     * @return array household id => kinds, in the order of KINDS.
     */
    public static function all_of_centre(int $centreid, ?string $year = null): array {
        $known = self::known($centreid);
        $year = $year ?? $known['year'];
        $out = [];
        foreach ($known['families'] as $id => $family) {
            $kinds = $family['kinds'];
            // Children it enrolled through Eguahe for an earlier school year: of the centre while they are there.
            foreach ($family['enrolled'] as [$for, $left]) {
                if ($for !== '' && strcmp($for, $year) < 0) {
                    $kinds[$left ? 'former' : 'centre'] = true;
                }
            }
            // What the lists say of its children there, but those this campaign or a later one brought.
            $grades = array_filter($family['rows'], fn($r) => $r[1] === '' || strcmp($r[1], $year) < 0);
            $marked = $family['marked'];
            if ($family['incentre'] && ($grades || $marked || $family['said'])) {
                if ($grades || !$marked) {
                    // Only in the nursery: every child it has there is of a nursery age.
                    $ages = array_column($grades, 2);
                    $nursery = $grades && !array_filter($ages, fn($age) => $age === null || $age > self::NURSERY_AGE);
                    $kinds[$nursery ? 'nursery' : 'centre'] = true;
                }
            }
            $list = array_values(array_filter(self::KINDS, fn($k) => isset($kinds[$k])));
            if ($list) {
                $out[$id] = $list;
            }
        }
        return $out;
    }

    /**
     * What is known of the families of a centre, to say who each is.
     *
     * @param int $centreid
     * @return array year (of the campaign under way) and families (household id => incentre, said, marked, kinds, rows (the
     *     lists: grade, the school year its campaign brought that student for, age) and enrolled (school year, whether left)).
     */
    protected static function known(int $centreid): array {
        global $DB;
        if (isset(self::$cache[$centreid]) && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return self::$cache[$centreid];
        }
        $centre = centres::get($centreid) ?: null;
        $families = [];
        $blank = ['incentre' => false, 'said' => false, 'marked' => false, 'kinds' => [], 'rows' => [], 'enrolled' => []];
        $at = function (int $id) use (&$families, $blank): void {
            $families[$id] = $families[$id] ?? $blank;
        };
        // What the lists of the centre said of each family: its children already there, whether it had some there in the
        // years before, and the students its own campaigns brought.
        $rows = $DB->get_records_select(
            listmatch::T,
            "centreid = :centreid AND kind IN ('incentre', 'former', 'enrolled')",
            ['centreid' => $centreid],
            'id ASC',
            'id, householdid, kind, grade, student, schoolyear, marked'
        );
        $brought = [];
        foreach ($rows as $row) {
            if ($row->kind === 'enrolled') {
                $key = $row->householdid . ':' . $row->student;
                $brought[$key] = isset($brought[$key]) ? min($brought[$key], (string) $row->schoolyear) : (string) $row->schoolyear;
            }
        }
        $first = [];
        foreach ($rows as $row) {
            $id = (int) $row->householdid;
            if ($row->kind === 'former') {
                $at($id);
                $families[$id]['kinds']['former'] = true;
            } else if ($row->kind === 'incentre') {
                $at($id);
                $first[$id] = $first[$id] ?? (int) $row->marked;
                $families[$id]['rows'][] = [(string) $row->grade, $brought[$id . ':' . $row->student] ?? '',
                    grades::age((string) $row->grade, $centre)];
            }
        }
        foreach ($DB->get_fieldset_select(households::T, 'id', 'centreid = ? AND incentre = 1', [$centreid]) as $id) {
            $at((int) $id);
            $families[(int) $id]['incentre'] = true;
            // The family said it, or a person did: it was marked before any list named a child of it.
            $families[(int) $id]['said'] = !isset($first[(int) $id]) || $first[(int) $id] !== listmatch::MADE;
        }
        // What who knows the families marked.
        foreach (ties::families($centreid) as $id => $kinds) {
            $at((int) $id);
            $families[(int) $id]['marked'] = (bool) $kinds;
            foreach (array_keys($families[(int) $id]['incentre'] ? $kinds : []) as $kind) {
                $families[(int) $id]['kinds'][ties::WHO[$kind]] = true;
            }
        }
        // The children it enrolled through Eguahe, by the school year they were enrolled for.
        $children = $DB->get_records_sql(
            "SELECT c.id, c.householdid, c.startyear, c.lefttime, o.schoolyear
               FROM {" . households::CHILDREN . "} c
          LEFT JOIN {" . centres::OFFERS . "} o ON o.id = c.offerid
              WHERE c.centreid = :centreid AND c.stage = 'enrolled' AND c.roster = 0
           ORDER BY c.id ASC",
            ['centreid' => $centreid]
        );
        foreach ($children as $child) {
            $at((int) $child->householdid);
            $families[(int) $child->householdid]['enrolled'][] = [(string) ($child->schoolyear ?: $child->startyear),
                (int) $child->lefttime > 0];
        }
        foreach (array_keys(alumni::families($centreid)) as $id) {
            $at((int) $id);
            $families[(int) $id]['kinds']['alumni'] = true;
        }
        return self::$cache[$centreid] = ['year' => $centre ? facts::year_at($centre, time(), facts::years($centreid)) : '',
            'families' => $families];
    }

    /**
     * Who a family is.
     *
     * @param int $centreid
     * @param int $householdid
     * @param string|null $year The school year of the campaign it is seen from; null, the one under way.
     * @return string One of KINDS.
     */
    public static function kind(int $centreid, int $householdid, ?string $year = null): string {
        return self::kinds($centreid, $householdid, $year)[0];
    }

    /**
     * Every thing a family is.
     *
     * @param int $centreid
     * @param int $householdid
     * @param string|null $year The school year of the campaign it is seen from; null, the one under way.
     * @return string[] Of KINDS; new when it is nothing else.
     */
    public static function kinds(int $centreid, int $householdid, ?string $year = null): array {
        $key = $centreid . '|' . ($year ?? '-');
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return self::all_of_centre($centreid, $year)[$householdid] ?? ['new'];
        }
        self::$cache['all'][$key] = self::$cache['all'][$key] ?? self::all_of_centre($centreid, $year);
        return self::$cache['all'][$key][$householdid] ?? ['new'];
    }

    /**
     * Forget what was worked out, after the lists or a family change.
     */
    public static function reset(): void {
        self::$cache = [];
    }

    /**
     * How many families of each kind (each family once, as the first thing it is), how many of each enrolled, and how many
     * are that too, whatever else they are.
     *
     * @param array[] $families Each with who, enrolled and, when known, also (every thing it is).
     * @return array kind => [n, enrolled, all], every kind.
     */
    public static function count(array $families): array {
        $out = array_fill_keys(self::KINDS, ['n' => 0, 'enrolled' => 0, 'all' => 0]);
        foreach ($families as $family) {
            $kind = in_array($family['who'] ?? '', self::KINDS, true) ? $family['who'] : 'new';
            $out[$kind]['n']++;
            $out[$kind]['enrolled'] += !empty($family['enrolled']) ? 1 : 0;
            foreach (array_intersect(self::KINDS, $family['also'] ?? [$kind]) as $one) {
                $out[$one]['all']++;
            }
        }
        return $out;
    }

    /**
     * Those already of the centre, in a count.
     *
     * @param array $counts From count().
     * @return int
     */
    public static function centre(array $counts): int {
        // A report kept from before may not have every kind.
        return array_sum(array_map(fn($kind) => $counts[$kind]['n'] ?? 0, self::CENTRE));
    }

    /**
     * What those already of the centre are, in words: «8 con un hijo en su guardería, 12 con hijos en el centro, 4 de
     * personal». One that is two things is in both.
     *
     * @param array $counts From count().
     * @return string Empty when the only thing known is that they have children there.
     */
    public static function centre_parts(array $counts): string {
        $parts = [];
        foreach (self::CENTRE as $kind) {
            $n = $counts[$kind]['all'] ?? $counts[$kind]['n'] ?? 0;
            if ($n) {
                $parts[$kind] = get_string('who_bit_' . $kind, self::C, $n);
            }
        }
        return array_keys($parts) === ['centre'] ? '' : implode(', ', $parts);
    }

    /**
     * Who they are, in words: the really new first, then the others that are there; nothing for a kind with none.
     *
     * @param array $counts From count().
     * @param bool $capital With its first letter in capitals and its full stop.
     * @param bool $enrolled With how many of the really new enrolled.
     * @return string
     */
    public static function sentence(array $counts, bool $capital = true, bool $enrolled = true): string {
        $c = self::C;
        $parts = [];
        if ($counts['new']['n']) {
            $parts[] = $enrolled && $counts['new']['enrolled'] ? get_string('who_part_new', $c, (object) $counts['new']) :
                get_string('who_part_new_plain', $c, $counts['new']['n']);
        }
        $centre = self::centre($counts);
        if ($centre) {
            $bits = self::centre_parts($counts);
            $parts[] = $bits !== '' ? get_string('who_part_centre_parts', $c, (object) ['n' => $centre, 'parts' => $bits]) :
                get_string('who_part_centre', $c, $centre);
        }
        foreach (['former', 'alumni'] as $kind) {
            if ($counts[$kind]['n']) {
                $parts[] = get_string('who_part_' . $kind, $c, $counts[$kind]['n']);
            }
        }
        if (!$parts) {
            return '';
        }
        $text = implode('; ', $parts);
        return $capital ? \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1) . '.' : $text;
    }

    /**
     * The name of a kind, as the team reads it.
     *
     * @param string $kind
     * @return string
     */
    public static function name(string $kind): string {
        return get_string('who_' . (in_array($kind, self::KINDS, true) ? $kind : 'new'), self::C);
    }
}
