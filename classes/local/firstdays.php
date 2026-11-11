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
 * The first day of each course: courses start on different days (the nursery before, the three-year-olds a day or two
 * later to know the playground without the older ones, the first year of primary on its own), and each school year has
 * its own dates. The story, the eve, the day after, the birthday and the voices of each child go by the first day of its
 * course; without one, by the general first day of the centre when it is of that school year.
 *
 * A date written a year before is not trusted (Enrique, 11-11): decisions change in between and nobody remembers what
 * the system keeps. So no date passes from one year to the next. The date of last year only says when to ask: some
 * weeks before the usual start, Eguahe asks a person for the date of each course with enrolled children; and when the
 * story comes to the families, three weeks before the date, it asks whether it still holds.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class firstdays {
    /** @var int How long before the usual start of a course Eguahe asks for its first day. */
    const ASK = 60 * DAYSECS;

    /** @var int How long after the usual start it keeps asking for a date still missing. */
    const LATE = 14 * DAYSECS;

    /** @var int How long before the first day it asks whether the date holds: when the story comes to the families. */
    const CHECK = story::BEFORE;

    /**
     * The first day of a course: its own; else the general one of the centre when it is of the school year of the course
     * (or there is no course); else 0.
     *
     * @param \stdClass|null $offer
     * @param \stdClass $centre
     * @return int The start of the day, or 0.
     */
    public static function of(?\stdClass $offer, \stdClass $centre): int {
        if ($offer && (int) ($offer->firstday ?? 0)) {
            return (int) $offer->firstday;
        }
        $first = (int) ($centre->firstday ?? 0);
        if (!$first || !$offer) {
            return $first;
        }
        $tz = \core_date::get_server_timezone_object();
        $year = (int) (new \DateTimeImmutable('@' . $first))->setTimezone($tz)->format('Y');
        return $year === (int) substr((string) $offer->schoolyear, 0, 4) ? $first : 0;
    }

    /**
     * When a course usually starts, only to know when to ask: the first day of the course of the same name the year
     * before, a year later on the same weekday; without one, the start of its school year.
     *
     * @param \stdClass $offer
     * @param \stdClass[] $offers All the courses of the centre.
     * @param \stdClass $centre
     * @return int
     */
    public static function usual(\stdClass $offer, array $offers, \stdClass $centre): int {
        $year = (int) substr((string) $offer->schoolyear, 0, 4);
        foreach ($offers as $before) {
            if (
                (int) substr((string) $before->schoolyear, 0, 4) === $year - 1 &&
                grades::plain((string) $before->name) === grades::plain((string) $offer->name)
            ) {
                $last = self::of($before, $centre);
                if ($last) {
                    return $last + 364 * DAYSECS;
                }
            }
        }
        return imports::year_start((string) $offer->schoolyear);
    }

    /**
     * What a person has to say about the first days now, course by course, among those with enrolled children: the
     * date of a course that has none, from ASK before its usual start until LATE after it; and whether a date holds,
     * from CHECK before it, unless someone wrote or confirmed it since then.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array[] [offer, kind (missing or check), day (the date, or the usual start of a missing one)], by day.
     */
    public static function asking(\stdClass $centre, int $now): array {
        global $DB;
        $offers = centres::offers((int) $centre->id);
        if (!$offers) {
            return [];
        }
        $with = $DB->get_fieldset_sql(
            'SELECT DISTINCT offerid
               FROM {' . households::CHILDREN . '}
              WHERE centreid = :centreid AND stage = :stage AND lefttime = 0 AND offerid > 0',
            ['centreid' => $centre->id, 'stage' => 'enrolled']
        );
        $out = [];
        foreach ($with as $id) {
            $offer = $offers[$id] ?? null;
            if (!$offer) {
                continue;
            }
            $first = self::of($offer, $centre);
            if ($first) {
                if ($now >= $first - self::CHECK && $now < $first && (int) $offer->firstdayok < $first - self::CHECK) {
                    $out[] = ['offer' => $offer, 'kind' => 'check', 'day' => $first];
                }
                continue;
            }
            $usual = self::usual($offer, $offers, $centre);
            if ($now >= $usual - self::ASK && $now < $usual + self::LATE) {
                $out[] = ['offer' => $offer, 'kind' => 'missing', 'day' => $usual];
            }
        }
        usort($out, fn($a, $b) => [$a['day'], (int) $a['offer']->sortorder] <=> [$b['day'], (int) $b['offer']->sortorder]);
        return $out;
    }

    /**
     * The names of some courses in a list: «a, b y c».
     *
     * @param \stdClass[] $offers
     * @return string
     */
    public static function names(array $offers): string {
        $words = array_map(fn($o) => format_string($o->name), array_values($offers));
        $last = array_pop($words);
        return $words ? get_string('andlist', 'local_oksigeniaeguahe', (object) ['list' => implode(', ', $words),
            'last' => $last]) : (string) $last;
    }

    /**
     * Writes the first day of a course, or takes it away (0): a person said it, so it holds from now.
     *
     * @param \stdClass $offer
     * @param int $day
     * @param int $now
     */
    public static function set(\stdClass $offer, int $day, int $now): void {
        global $DB;
        $DB->update_record(centres::OFFERS, (object) ['id' => $offer->id, 'firstday' => max(0, $day),
            'firstdayok' => $day > 0 ? $now : 0, 'timemodified' => $now]);
    }

    /**
     * A person says the first day of a course still holds; the general one of the centre becomes its own.
     *
     * @param \stdClass $offer
     * @param \stdClass $centre
     * @param int $now
     * @return bool False when the course has no date to confirm.
     */
    public static function confirm(\stdClass $offer, \stdClass $centre, int $now): bool {
        $first = self::of($offer, $centre);
        if (!$first) {
            return false;
        }
        self::set($offer, $first, $now);
        return true;
    }

    /**
     * A day as a form sends it (YYYY-MM-DD), at the start of the day; 0 when it is not a real day.
     *
     * @param string $value
     * @return int
     */
    public static function parse(string $value): int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return 0;
        }
        return make_timestamp((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * The first day of the children of a family who started a little while ago: the latest one that was between half a
     * day and $within ago. For «How was their first day?» on their page.
     *
     * @param \stdClass[] $children
     * @param \stdClass $centre
     * @param int $now
     * @param int $within
     * @return int The first day, or 0.
     */
    public static function started_lately(array $children, \stdClass $centre, int $now, int $within): int {
        global $DB;
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id]);
        $found = 0;
        foreach ($children as $child) {
            if (($child->stage ?? '') !== 'enrolled' || !empty($child->lefttime)) {
                continue;
            }
            $first = self::of($offers[$child->offerid ?? 0] ?? null, $centre);
            if ($first && $now >= $first + DAYSECS / 2 && $now < $first + $within) {
                $found = max($found, $first);
            }
        }
        return $found;
    }
}
