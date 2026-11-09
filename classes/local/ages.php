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
 * Each child by its own age: a family that brings one of three and one of fifteen gets for each what goes with it. The
 * age is the one its course starts at (grades::age()); its step, the one of the ladder of data/escalera.json, the same
 * that chooses what hangs (panel::ladder()). The drawing is asked for only while they are young, and what is written
 * to the family speaks of each in the tone of its step. No stage is named, so it works in any country.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ages {
    /** Up to this age a child is young: its drawing is asked for and its visit shows where its backpack would go. */
    const YOUNG_UNTIL = 11;

    /** Without a course yet: what is written to the youngest. */
    const NOCOURSE = 'nocourse';

    /** A course whose age is not known (languages, trades, a whole stage): the plain tone, neither small nor adult. */
    const UNKNOWN = 'unknown';

    /**
     * The course of a child: the name of the offer it asks for or is in, or '' without one.
     *
     * @param \stdClass $child
     * @param array $offers The offers of the centre, by id (centres::offers()).
     * @return string
     */
    public static function course(\stdClass $child, array $offers): string {
        $offer = $offers[(int) ($child->offerid ?? 0)] ?? null;
        return $offer ? (string) $offer->name : '';
    }

    /**
     * The age the course of a child starts at, or null without a course or when it is not known.
     *
     * @param \stdClass $child
     * @param array $offers
     * @param \stdClass $centre
     * @return int|null
     */
    public static function age(\stdClass $child, array $offers, \stdClass $centre): ?int {
        $course = self::course($child, $offers);
        return trim($course) === '' ? null : grades::age($course, $centre);
    }

    /**
     * The step of a child for what is written about it: «r1» to the last of the ladder, in its order, or NOCOURSE, or
     * UNKNOWN.
     *
     * @param \stdClass $child
     * @param array $offers
     * @param \stdClass $centre
     * @return string
     */
    public static function step(\stdClass $child, array $offers, \stdClass $centre): string {
        if (trim(self::course($child, $offers)) === '') {
            return self::NOCOURSE;
        }
        return self::step_of(self::age($child, $offers, $centre));
    }

    /**
     * The step of an age on the ladder: «r1» for the first, «r2» for the next…; UNKNOWN without an age.
     *
     * @param int|null $age
     * @return string
     */
    public static function step_of(?int $age): string {
        if ($age === null) {
            return self::UNKNOWN;
        }
        foreach (array_values(panel::ladder()['peldanos'] ?? []) as $i => $step) {
            $from = $step['desde'] ?? null;
            $to = $step['hasta'] ?? null;
            if (($from === null || $age >= (int) $from) && ($to === null || $age <= (int) $to)) {
                return 'r' . ($i + 1);
            }
        }
        return self::UNKNOWN;
    }

    /**
     * Whether a child is young: without a course yet, or while its course is of the ages of the backpacks. Its drawing
     * is asked for only then; what was already sent is kept and seen, whatever the age.
     *
     * @param \stdClass $child
     * @param array $offers
     * @param \stdClass $centre
     * @return bool
     */
    public static function young(\stdClass $child, array $offers, \stdClass $centre): bool {
        return self::young_course(self::course($child, $offers), $centre);
    }

    /**
     * Whether a course is of the young: none yet, or one whose age is known and of the backpacks.
     *
     * @param string $course
     * @param \stdClass $centre
     * @return bool
     */
    public static function young_course(string $course, \stdClass $centre): bool {
        if (trim($course) === '') {
            return true;
        }
        $age = grades::age($course, $centre);
        return $age !== null && $age <= self::YOUNG_UNTIL;
    }

    /**
     * Whether any of the children is no longer young: then what is said to all of them is what fits the eldest, who
     * is never treated as a little one.
     *
     * @param array $children
     * @param \stdClass $centre
     * @param array|null $offers The offers of the centre, by id; read when not given.
     * @return bool
     */
    public static function older(array $children, \stdClass $centre, ?array $offers = null): bool {
        $offers = $offers ?? centres::offers((int) $centre->id);
        foreach ($children as $child) {
            if (!self::young($child, $offers, $centre)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What hangs for a child on the pages of its family: by the ladder, as in the panel (panel::hanging_object()), and
     * always by its age, whatever the skin of the panel; without a course yet, the small backpack.
     *
     * @param \stdClass $child
     * @param array $offers
     * @param \stdClass $centre
     * @return string mochila, escolar, figura, llavero, equipaje, credencial or credencial-neutra.
     */
    public static function hanging(\stdClass $child, array $offers, \stdClass $centre): string {
        $course = self::course($child, $offers);
        return trim($course) === '' ? panel::hanging_object('') : panel::age_object(grades::age($course, $centre));
    }

    /**
     * The children in groups by the steps their texts are written in, each group in the order of its first child.
     *
     * @param array $children
     * @param array $offers
     * @param \stdClass $centre
     * @param callable|null $band From a step to the group it is written with (all apart if null).
     * @return array Of [band, children].
     */
    public static function groups(array $children, array $offers, \stdClass $centre, ?callable $band = null): array {
        $groups = [];
        foreach ($children as $child) {
            $step = self::step($child, $offers, $centre);
            $key = $band ? $band($step) : $step;
            $groups[$key] = $groups[$key] ?? [$key, []];
            $groups[$key][1][] = $child;
        }
        return array_values($groups);
    }
}
