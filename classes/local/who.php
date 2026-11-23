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
 * Who a family that comes is, as far as the centre knows: really new, already of the centre (a child in its nursery, or
 * children inside), a family that already had children at the centre, or one where a mother, a father or someone of
 * the family studied there. A family that comes to an open day with a child already in the nursery knew the centre:
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
     * inside, with children there before, of former students, and new. */
    const KINDS = ['nursery', 'centre', 'former', 'alumni', 'new'];

    /** @var string[] Those already of the centre: they come, but they are not new. */
    const CENTRE = ['nursery', 'centre'];

    /** @var int The oldest age of a course that is the nursery of a centre. */
    const NURSERY_AGE = 2;

    /** @var array Centre id => household id => kind, once per request. */
    protected static $cache = [];

    /**
     * Who each family of a centre is, when it is not new.
     *
     * @param int $centreid
     * @return array household id => nursery, centre, former or alumni.
     */
    public static function of_centre(int $centreid): array {
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
        foreach ($DB->get_fieldset_select(households::T, 'id', 'centreid = ? AND incentre = 1', [$centreid]) as $id) {
            $mine = $grades[(int) $id] ?? [];
            // Only in the nursery: every child it has there is of a nursery age.
            $nursery = $mine && !array_filter($mine, function ($grade) use ($centre) {
                $age = grades::age($grade, $centre);
                return $age === null || $age > self::NURSERY_AGE;
            });
            $out[(int) $id] = $nursery ? 'nursery' : 'centre';
        }
        foreach (array_keys($former) as $id) {
            $out[$id] = $out[$id] ?? 'former';
        }
        foreach (array_keys(alumni::families($centreid)) as $id) {
            $out[(int) $id] = $out[(int) $id] ?? 'alumni';
        }
        return self::$cache[$centreid] = $out;
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
     * Forget what was worked out, after the lists or a family change.
     */
    public static function reset(): void {
        self::$cache = [];
    }

    /**
     * How many families of each kind, and how many of each enrolled.
     *
     * @param array[] $families Each with who and step.
     * @return array kind => [n, enrolled], every kind.
     */
    public static function count(array $families): array {
        $out = array_fill_keys(self::KINDS, ['n' => 0, 'enrolled' => 0]);
        foreach ($families as $family) {
            $kind = in_array($family['who'] ?? '', self::KINDS, true) ? $family['who'] : 'new';
            $out[$kind]['n']++;
            $out[$kind]['enrolled'] += ($family['step'] ?? 0) >= 5 ? 1 : 0;
        }
        return $out;
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
        $centre = $counts['nursery']['n'] + $counts['centre']['n'];
        if ($centre) {
            $parts[] = $counts['nursery']['n'] ? get_string('who_part_centre_nursery', $c, (object) ['n' => $centre,
                'nursery' => $counts['nursery']['n']]) : get_string('who_part_centre', $c, $centre);
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
