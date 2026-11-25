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

    /** @var array Centre id => household id => kinds, once per request. */
    protected static $cache = [];

    /**
     * Who each family of a centre is, when it is not new: the first of the things it is.
     *
     * @param int $centreid
     * @return array household id => nursery, centre, staff, former or alumni.
     */
    public static function of_centre(int $centreid): array {
        return array_map(fn($kinds) => $kinds[0], self::all_of_centre($centreid));
    }

    /**
     * Every thing each family of a centre is, when it is not new: one may have a child in the nursery and work there.
     *
     * @param int $centreid
     * @return array household id => kinds, in the order of KINDS.
     */
    public static function all_of_centre(int $centreid): array {
        global $DB;
        if (isset(self::$cache[$centreid])) {
            return self::$cache[$centreid];
        }
        $centre = centres::get($centreid) ?: null;
        $out = [];
        // What the lists of the centre said of each family: the grades of its children already there this year, and
        // whether it had children there in the years before.
        $grades = [];
        $former = [];
        $rows = $DB->get_recordset_select(
            listmatch::T,
            "centreid = :centreid AND kind IN ('incentre', 'former')",
            ['centreid' => $centreid],
            '',
            'id, householdid, kind, grade'
        );
        foreach ($rows as $row) {
            if ($row->kind === 'former') {
                $former[(int) $row->householdid] = true;
            } else {
                $grades[(int) $row->householdid][] = (string) $row->grade;
            }
        }
        $rows->close();
        // And what who knows the families marked.
        $marked = ties::families($centreid);
        foreach ($DB->get_fieldset_select(households::T, 'id', 'centreid = ? AND incentre = 1', [$centreid]) as $id) {
            $mine = $grades[(int) $id] ?? [];
            foreach (array_keys($marked[(int) $id] ?? []) as $kind) {
                $out[(int) $id][ties::WHO[$kind]] = true;
            }
            if (!$mine && !empty($out[(int) $id])) {
                // No list says it: what was marked is what is known.
                continue;
            }
            // Only in the nursery: every child it has there is of a nursery age.
            $nursery = $mine && !array_filter($mine, function ($grade) use ($centre) {
                $age = grades::age($grade, $centre);
                return $age === null || $age > self::NURSERY_AGE;
            });
            $out[(int) $id][$nursery ? 'nursery' : 'centre'] = true;
        }
        foreach (array_keys($former) as $id) {
            $out[$id]['former'] = true;
        }
        foreach (array_keys(alumni::families($centreid)) as $id) {
            $out[(int) $id]['alumni'] = true;
        }
        return self::$cache[$centreid] = array_map(
            fn($kinds) => array_values(array_filter(self::KINDS, fn($k) => isset($kinds[$k]))),
            $out
        );
    }

    /**
     * Who a family is.
     *
     * @param int $centreid
     * @param int $householdid
     * @return string One of KINDS.
     */
    public static function kind(int $centreid, int $householdid): string {
        return self::of_centre($centreid)[$householdid] ?? 'new';
    }

    /**
     * Every thing a family is.
     *
     * @param int $centreid
     * @param int $householdid
     * @return string[] Of KINDS; new when it is nothing else.
     */
    public static function kinds(int $centreid, int $householdid): array {
        return self::all_of_centre($centreid)[$householdid] ?? ['new'];
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
     * @param array[] $families Each with who, step and, when known, also (every thing it is).
     * @return array kind => [n, enrolled, all], every kind.
     */
    public static function count(array $families): array {
        $out = array_fill_keys(self::KINDS, ['n' => 0, 'enrolled' => 0, 'all' => 0]);
        foreach ($families as $family) {
            $kind = in_array($family['who'] ?? '', self::KINDS, true) ? $family['who'] : 'new';
            $out[$kind]['n']++;
            $out[$kind]['enrolled'] += ($family['step'] ?? 0) >= 5 ? 1 : 0;
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
