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
 * The working days of a centre, so that what it promises to the families («two working days») is counted as they read it:
 * without the weekend of its country and without the days it closes, which come from a calendar (.ics) the centre gives.
 * The day a family writes does not count, and the last one counts whole.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workdays {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int The largest calendar that is read, in bytes. */
    const MAXBYTES = 1048576;

    /** @var int The most days of an event, and of a centre. */
    const MAXDAYS = 2000;

    /** @var int How many days are walked at most: a calendar that closes every day never stops the count. */
    const WALK = 400;

    /** @var int[][] The days of the weekend of each country, kept for the request. */
    protected static $weekends = [];

    /**
     * The days of the weekend in the country of a centre, as ICU knows them; Saturday and Sunday without it.
     *
     * @param \stdClass $centre
     * @return int[] From 1, Monday, to 7, Sunday.
     */
    public static function weekend(\stdClass $centre): array {
        $country = strtoupper((string) ($centre->country ?? ''));
        if (!isset(self::$weekends[$country])) {
            $days = [];
            $calendar = $country !== '' && class_exists('\IntlCalendar') ?
                \IntlCalendar::createInstance('UTC', 'und_' . $country) : null;
            for ($day = 1; $calendar && $day <= 7; $day++) {
                // ICU counts from 1, Sunday.
                if ($calendar->getDayOfWeekType($day % 7 + 1) === \IntlCalendar::DOW_TYPE_WEEKEND) {
                    $days[] = $day;
                }
            }
            self::$weekends[$country] = $days && count($days) < 4 ? $days : [6, 7];
        }
        return self::$weekends[$country];
    }

    /**
     * The days a centre closes, as its calendar said the last time it was read.
     *
     * @param \stdClass $centre
     * @return bool[] By day, as 20261208.
     */
    public static function closed(\stdClass $centre): array {
        $kept = json_decode((string) ($centre->closeddays ?? ''), true);
        return array_fill_keys(is_array($kept) ? (array) ($kept['days'] ?? []) : [], true);
    }

    /**
     * Whether a centre works on a day.
     *
     * @param \stdClass $centre
     * @param \DateTimeInterface $day
     * @param bool[]|null $closed What closed() gives, when it is already known.
     * @return bool
     */
    public static function is_open(\stdClass $centre, \DateTimeInterface $day, ?array $closed = null): bool {
        return !in_array((int) $day->format('N'), self::weekend($centre), true) &&
            !isset(($closed ?? self::closed($centre))[$day->format('Ymd')]);
    }

    /**
     * Until when a centre has to answer someone who wrote at a moment: the end of the last of its working days.
     *
     * @param \stdClass $centre
     * @param int $since
     * @return int
     */
    public static function deadline(\stdClass $centre, int $since): int {
        $left = max(1, (int) ($centre->replydays ?? 2));
        $closed = self::closed($centre);
        $day = self::day($since);
        for ($i = 0; $i < self::WALK && $left > 0; $i++) {
            $day = $day->modify('+1 day');
            $left -= self::is_open($centre, $day, $closed) ? 1 : 0;
        }
        return $day->setTime(23, 59, 59)->getTimestamp();
    }

    /**
     * Whoever waits since before this moment waits for longer than the centre promises.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return int
     */
    public static function limit(\stdClass $centre, int $now): int {
        $left = max(1, (int) ($centre->replydays ?? 2));
        $closed = self::closed($centre);
        $day = self::day($now);
        for ($i = 0; $i < self::WALK && $left > 0; $i++) {
            $day = $day->modify('-1 day');
            $left -= self::is_open($centre, $day, $closed) ? 1 : 0;
        }
        return $day->getTimestamp();
    }

    /**
     * The days a calendar closes: its events of whole days. One with an hour in its title («Admissions: 7:30-13:00») is a
     * day with other hours, and one with a name before a colon («Canteen: closed») is of only a part of the centre.
     *
     * @param string $ics
     * @param int $now
     * @return string[] Days as 20261208, in order.
     */
    public static function read(string $ics, int $now): array {
        // A long line goes on in the next one, after a space.
        $lines = preg_split('/\r\n|\r|\n/', preg_replace('/(\r\n|\r|\n)[ \t]/', '', $ics));
        $days = [];
        $event = null;
        foreach ($lines as $line) {
            if (strcasecmp(trim($line), 'BEGIN:VEVENT') === 0) {
                $event = [];
            } else if (strcasecmp(trim($line), 'END:VEVENT') === 0 && $event !== null) {
                foreach (self::days_of($event, $now) as $day) {
                    $days[$day] = true;
                }
                $event = null;
                if (count($days) > self::MAXDAYS) {
                    break;
                }
            } else if ($event !== null && preg_match('/^([A-Za-z-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) {
                $event[strtoupper($m[1])] = $event[strtoupper($m[1])] ?? trim($m[3]);
            }
        }
        $days = array_map('strval', array_keys($days));
        sort($days);
        return array_slice($days, 0, self::MAXDAYS);
    }

    /**
     * The days of an event of a calendar.
     *
     * @param string[] $event Its properties.
     * @param int $now
     * @return string[]
     */
    protected static function days_of(array $event, int $now): array {
        $title = (string) ($event['SUMMARY'] ?? '');
        $start = (string) ($event['DTSTART'] ?? '');
        if (
            !preg_match('/^\d{8}$/', $start) || strtoupper((string) ($event['STATUS'] ?? '')) === 'CANCELLED' ||
                strpos($title, ':') !== false || preg_match('/\d{1,2}[.h]\d{2}/', $title)
        ) {
            return [];
        }
        $utc = new \DateTimeZone('UTC');
        $from = \DateTimeImmutable::createFromFormat('!Ymd', $start, $utc);
        $end = (string) ($event['DTEND'] ?? '');
        $to = preg_match('/^\d{8}$/', $end) ? \DateTimeImmutable::createFromFormat('!Ymd', $end, $utc) : null;
        $length = $from && $to && $to > $from ? min(self::WALK, (int) $from->diff($to)->days) : 1;
        if (!$from) {
            return [];
        }
        // The same day every year, until the one after next.
        $years = [0];
        $rule = strtoupper((string) ($event['RRULE'] ?? ''));
        if (strpos($rule, 'FREQ=YEARLY') !== false && strpos($rule, 'BYDAY') === false) {
            $last = (int) gmdate('Y', $now) + 2;
            $until = preg_match('/UNTIL=(\d{4})/', $rule, $m) ? (int) $m[1] : $last;
            $years = range(0, max(0, min(100, min($last, $until) - (int) $from->format('Y'))));
        }
        $days = [];
        foreach ($years as $year) {
            for ($i = 0; $i < $length; $i++) {
                $days[] = $from->modify('+' . $year . ' years')->modify('+' . $i . ' days')->format('Ymd');
            }
        }
        $floor = gmdate('Ymd', $now - YEARSECS);
        return array_values(array_filter($days, fn($day) => $day >= $floor));
    }

    /**
     * Keeps what a calendar says now. The days it no longer tells because they went by stay, a year.
     *
     * @param \stdClass $centre
     * @param string $ics
     * @param int $now
     * @return bool False when it is not a calendar: what there was stays.
     */
    public static function keep(\stdClass $centre, string $ics, int $now): bool {
        global $DB;
        $kept = json_decode((string) ($centre->closeddays ?? ''), true);
        $kept = is_array($kept) ? $kept : [];
        $good = stripos($ics, 'BEGIN:VCALENDAR') !== false && strlen($ics) <= self::MAXBYTES;
        if ($good) {
            $days = self::read($ics, $now);
            $today = gmdate('Ymd', $now);
            $floor = gmdate('Ymd', $now - YEARSECS);
            $first = $days[0] ?? $today;
            $before = array_filter(
                (array) ($kept['days'] ?? []),
                fn($day) => $day >= $floor && $day < $today && $day < $first
            );
            $kept = ['days' => array_values(array_unique(array_merge($before, $days))), 'read' => $now, 'failed' => 0];
        } else {
            $kept = ['days' => (array) ($kept['days'] ?? []), 'read' => (int) ($kept['read'] ?? 0),
                'failed' => (int) ($kept['failed'] ?? 0) ?: $now];
        }
        $centre->closeddays = json_encode($kept);
        $DB->set_field(centres::T, 'closeddays', $centre->closeddays, ['id' => $centre->id]);
        return $good;
    }

    /**
     * Reads the calendar of a centre where the centre says it is. Without one, nothing closes.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return bool
     */
    public static function fetch(\stdClass $centre, int $now = 0): bool {
        global $CFG, $DB;
        $url = self::address((string) ($centre->closedics ?? ''));
        if ($url === '' || !empty($centre->practice)) {
            $centre->closeddays = null;
            $DB->set_field(centres::T, 'closeddays', null, ['id' => $centre->id]);
            return $url === '';
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader(['Accept: text/calendar']);
        $body = (string) $curl->get($url, [], ['CURLOPT_TIMEOUT' => 20, 'CURLOPT_CONNECTTIMEOUT' => 8,
            'CURLOPT_MAXFILESIZE' => self::MAXBYTES]);
        $good = !$curl->get_errno() && (int) ($curl->get_info()['http_code'] ?? 0) === 200;
        return self::keep($centre, $good ? $body : '', $now ?: time());
    }

    /**
     * The address of a calendar, as it is fetched: calendars are often given as webcal.
     *
     * @param string $url
     * @return string Empty when it is not one.
     */
    public static function address(string $url): string {
        $url = preg_replace('/^webcals?:\/\//i', 'https://', trim($url));
        return preg_match('/^https:\/\//i', $url) ? clean_param($url, PARAM_URL) : '';
    }

    /**
     * How the calendar of a centre is, in words, for its settings.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return string Empty without a calendar.
     */
    public static function words(\stdClass $centre, int $now): string {
        if (trim((string) ($centre->closedics ?? '')) === '') {
            return '';
        }
        $kept = json_decode((string) ($centre->closeddays ?? ''), true);
        $failed = is_array($kept) ? (int) ($kept['failed'] ?? 0) : $now;
        if ($failed) {
            return get_string('panel_daysoff_failed', self::C, userdate($failed, get_string('strftimedate', 'langconfig')));
        }
        $next = [];
        foreach (array_keys(self::closed($centre)) as $day) {
            $date = \DateTimeImmutable::createFromFormat('!Ymd', (string) $day, \core_date::get_server_timezone_object());
            if ($date && $date >= self::day($now) && !in_array((int) $date->format('N'), self::weekend($centre), true)) {
                $next[(string) $day] = $date;
            }
        }
        if (!$next) {
            return get_string('panel_daysoff_none', self::C);
        }
        ksort($next);
        $a = (object) ['n' => count($next), 'next' => userdate(
            reset($next)->getTimestamp(),
            get_string('strftimedate', 'langconfig')
        )];
        return get_string(count($next) === 1 ? 'panel_daysoff_one' : 'panel_daysoff_many', self::C, $a);
    }

    /**
     * The beginning of the day of a moment, in the time of the site.
     *
     * @param int $time
     * @return \DateTimeImmutable
     */
    protected static function day(int $time): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(\core_date::get_server_timezone_object())->setTime(0, 0);
    }
}
