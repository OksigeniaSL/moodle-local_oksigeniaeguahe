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
     * The children of a family in the days of their birthday, in the centre: on the day and SHOWN days in all.
     *
     * @param \stdClass $household
     * @param int $now
     * @return array[] [child, day, age]
     */
    public static function celebrating(\stdClass $household, int $now): array {
        $out = [];
        foreach (households::full($household)->children as $child) {
            $date = trim((string) ($child->birthdate ?? ''));
            if ($child->stage !== 'enrolled' || !empty($child->lefttime) || $date === '') {
                continue;
            }
            [$day, $age] = self::last($date, $now);
            if ($now < $day + self::SHOWN * DAYSECS) {
                $out[] = [$child, $day, $age];
            }
        }
        return $out;
    }

    /**
     * What a person of the team writes today: the day before the birthday of a child in the centre, or on the day, until
     * someone writes to the family in its time.
     *
     * @param \stdClass $household
     * @param int $now
     * @return array|null step (eve or day), day (its start), children (who turn years) and ages (by child id).
     */
    public static function step(\stdClass $household, int $now): ?array {
        global $DB;
        $found = null;
        foreach (households::full($household)->children as $child) {
            $date = trim((string) ($child->birthdate ?? ''));
            if ($child->stage !== 'enrolled' || !empty($child->lefttime) || $date === '') {
                continue;
            }
            [$day, $age] = self::next($date, $now);
            $step = $now >= $day ? 'day' : ($now >= $day - DAYSECS ? 'eve' : null);
            if (!$step || ($found && $found['day'] < $day)) {
                continue;
            }
            if (!$found || $found['day'] > $day) {
                $found = ['step' => $step, 'day' => $day, 'children' => [], 'ages' => []];
            }
            $found['children'][] = $child;
            $found['ages'][(int) $child->id] = $age;
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
