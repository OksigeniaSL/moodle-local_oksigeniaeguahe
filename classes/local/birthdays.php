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
 * The birthday of each child: the date the family gives if they wish, the age, the course it is of, and what a person
 * of the team writes to the family the day before and on the day, in the tone of the age. Never sent by itself.
 *
 * Who greets depends on where the child is (Enrique, 10-11): a child already in class gets the piece of its age signed
 * by its class; one with a place that has not started yet, the same piece with «we are waiting for you in your class»;
 * a family still deciding, only a short message from a person of the centre, signed by the centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class birthdays {
    /** @var int The oldest date of birth taken, in years: adults study too. */
    const OLDEST = 100;

    /** @var int The days the piece of the birthday stays on the page of the family, the day itself included. */
    const SHOWN = 7;

    /** @var int Up to this age the piece is the video with the name said; from the next, the card. */
    const VIDEO = 5;

    /** @var int Up to this age, the card; from the next one, and adults, the seal. */
    const CARD = 11;

    /** @var string[] The stages of a family that is still deciding: a birthday gets only a short message. */
    const DECIDING = ['inquiry', 'visit', 'application', 'admitted', 'waitlist'];

    /**
     * A date of birth as a form sends it (YYYY-MM-DD), when it is a real day of the last OLDEST years and not after
     * today; null otherwise.
     *
     * @param string $date
     * @param int $now
     * @return string|null
     */
    public static function clean(string $date, int $now): ?string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $today = self::today($now);
        $year = (int) $today->format('Y');
        if ($m[0] > $today->format('Y-m-d') || (int) $m[1] < $year - self::OLDEST) {
            return null;
        }
        return $m[0];
    }

    /**
     * Today at the start of the day, in the time zone of the site.
     *
     * @param int $now
     * @return \DateTimeImmutable
     */
    protected static function today(int $now): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $now))->setTimezone(\core_date::get_server_timezone_object())->setTime(0, 0);
    }

    /**
     * The birthday of a date of birth in a year: on 28 February when born on the 29th and the year is not leap.
     *
     * @param string $birthdate
     * @param int $year
     * @return \DateTimeImmutable
     */
    protected static function in_year(string $birthdate, int $year): \DateTimeImmutable {
        [, $month, $day] = array_map('intval', explode('-', $birthdate));
        if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
            $day = 28;
        }
        return (new \DateTimeImmutable('now', \core_date::get_server_timezone_object()))->setDate($year, $month, $day)
            ->setTime(0, 0);
    }

    /**
     * The age on a day.
     *
     * @param string $birthdate
     * @param int $now
     * @return int
     */
    public static function age(string $birthdate, int $now): int {
        $today = self::today($now);
        $age = (int) $today->format('Y') - (int) substr($birthdate, 0, 4);
        return self::in_year($birthdate, (int) $today->format('Y')) > $today ? $age - 1 : $age;
    }

    /**
     * The next birthday from today, today included: its day and the age it turns.
     *
     * @param string $birthdate
     * @param int $now
     * @return array [timestamp of the start of the day, age]
     */
    public static function next(string $birthdate, int $now): array {
        $today = self::today($now);
        $year = (int) $today->format('Y');
        $day = self::in_year($birthdate, $year);
        if ($day < $today) {
            $day = self::in_year($birthdate, ++$year);
        }
        return [$day->getTimestamp(), $year - (int) substr($birthdate, 0, 4)];
    }

    /**
     * The last birthday until today, today included: its day and the age it turned.
     *
     * @param string $birthdate
     * @param int $now
     * @return array [timestamp of the start of the day, age]
     */
    public static function last(string $birthdate, int $now): array {
        $today = self::today($now);
        $year = (int) $today->format('Y');
        $day = self::in_year($birthdate, $year);
        if ($day > $today) {
            $day = self::in_year($birthdate, --$year);
        }
        return [$day->getTimestamp(), $year - (int) substr($birthdate, 0, 4)];
    }

    /**
     * The piece of a birthday by the age it turns: the video, the card or the seal.
     *
     * @param int $age
     * @return string video, card or seal
     */
    public static function piece(int $age): string {
        return $age <= self::VIDEO ? 'video' : ($age <= self::CARD ? 'card' : 'seal');
    }

    /**
     * Where a child is, for its birthday: in class, with a place and waiting for its first day, or in a family still
     * deciding. Null when it left or the way was closed.
     *
     * In class: marked «Started», or its course has begun (the first day the centre wrote, when it is of that school
     * year; if not, the start of the school year).
     *
     * @param \stdClass $child
     * @param \stdClass|null $offer Its course.
     * @param \stdClass $centre
     * @param int $now
     * @return string|null class, waiting or deciding.
     */
    public static function moment(\stdClass $child, ?\stdClass $offer, \stdClass $centre, int $now): ?string {
        if (!empty($child->lefttime)) {
            return null;
        }
        if (in_array($child->stage, self::DECIDING, true)) {
            return 'deciding';
        }
        if ($child->stage !== 'enrolled') {
            return null;
        }
        if (!empty($child->started) || !$offer) {
            return 'class';
        }
        $year = (int) substr((string) $offer->schoolyear, 0, 4);
        $first = (int) ($centre->firstday ?? 0);
        $tz = \core_date::get_server_timezone_object();
        $start = $first && (int) (new \DateTimeImmutable('@' . $first))->setTimezone($tz)->format('Y') === $year ?
            $first : imports::year_start((string) $offer->schoolyear);
        return $now >= $start ? 'class' : 'waiting';
    }

    /**
     * Where each child of a family is, for its birthday, by child id (moment()).
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return array [child id => [child, moment]], only those with a date of birth and a moment.
     */
    protected static function children(\stdClass $household, \stdClass $centre, int $now): array {
        global $DB;
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id]);
        $out = [];
        foreach (households::full($household)->children as $child) {
            if (trim((string) ($child->birthdate ?? '')) === '') {
                continue;
            }
            $moment = self::moment($child, $offers[$child->offerid ?? 0] ?? null, $centre, $now);
            if ($moment) {
                $out[(int) $child->id] = [$child, $moment];
            }
        }
        return $out;
    }

    /**
     * The children of a family in the days of their birthday who get its piece on their page, those in class and those
     * waiting for their first day: on the day and SHOWN days in all.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return array[] [child, day, age, moment]
     */
    public static function celebrating(\stdClass $household, \stdClass $centre, int $now): array {
        $out = [];
        foreach (self::children($household, $centre, $now) as [$child, $moment]) {
            if ($moment === 'deciding') {
                continue;
            }
            [$day, $age] = self::last(trim($child->birthdate), $now);
            if ($now < $day + self::SHOWN * DAYSECS) {
                $out[] = [$child, $day, $age, $moment];
            }
        }
        return $out;
    }

    /**
     * What a person of the team writes today: the day before the birthday of a child, or on the day, until someone
     * writes to the family in its time. For the children of the centre and for those of families still deciding.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return array|null step (eve or day), day (its start), children (who turn years), ages and moments (by child id).
     */
    public static function step(\stdClass $household, \stdClass $centre, int $now): ?array {
        global $DB;
        $found = null;
        foreach (self::children($household, $centre, $now) as [$child, $moment]) {
            [$day, $age] = self::next(trim($child->birthdate), $now);
            $step = $now >= $day ? 'day' : ($now >= $day - DAYSECS ? 'eve' : null);
            if (!$step || ($found && $found['day'] < $day)) {
                continue;
            }
            if (!$found || $found['day'] > $day) {
                $found = ['step' => $step, 'day' => $day, 'children' => [], 'ages' => [], 'moments' => []];
            }
            $found['children'][] = $child;
            $found['ages'][(int) $child->id] = $age;
            $found['moments'][(int) $child->id] = $moment;
        }
        if (!$found) {
            return null;
        }
        $written = $DB->record_exists_select(
            households::CONTACTS,
            'householdid = ? AND direction = ? AND timecreated >= ?',
            [$household->id, 'out', $found['day'] - DAYSECS]
        );
        return $written ? null : $found;
    }

    /**
     * The courses of the centre a child is of by its date of birth: those whose school year starts in the year it turns
     * the age of the course (the rule of the school systems of Eguahe). Only the active ones, from the current year on.
     *
     * @param string $birthdate
     * @param \stdClass $centre
     * @param int $now
     * @return \stdClass[] The offers.
     */
    public static function courses(string $birthdate, \stdClass $centre, int $now): array {
        $born = (int) substr($birthdate, 0, 4);
        $year = (int) self::today($now)->format('Y');
        $out = [];
        foreach (centres::offers((int) $centre->id) as $offer) {
            $start = (int) substr((string) $offer->schoolyear, 0, 4);
            if (empty($offer->active) || $start < $year - 1) {
                continue;
            }
            $age = grades::age((string) $offer->name, $centre);
            if ($age !== null && $start - $born === $age) {
                $out[] = $offer;
            }
        }
        return $out;
    }
}
