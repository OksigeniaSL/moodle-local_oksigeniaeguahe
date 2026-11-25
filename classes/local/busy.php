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
 * What else there is in the calendars of the centre (the office, the general one), to see it faint behind the appointments
 * and to know before giving one that it falls on something. From Google, by the script that keeps the calendar of the team
 * (calsync), or from calendar files. Only its title, its hour and its calendar, from today on; only for the team.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class busy {
    /** @var string */
    const T = 'local_oksigeniaeguahe_busy';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int How far ahead it is kept. */
    const AHEAD = 90 * DAYSECS;

    /** @var int How far back: the week that is being looked at. */
    const BACK = 7 * DAYSECS;

    /** @var int How many things are kept of a centre from each source. */
    const MOST = 3000;

    /** @var string Whether it is shown behind, as each person left it. */
    const PREF = 'local_oksigeniaeguahe_behind';

    /**
     * Puts what a source says now in the place of what it said.
     *
     * @param int $centreid
     * @param string $source script or ics.
     * @param array[] $items Each with calendar, title, start, end and allday.
     * @param int $now
     * @return int How many stay.
     */
    public static function replace(int $centreid, string $source, array $items, int $now): int {
        global $DB;
        $rows = [];
        foreach ($items as $item) {
            $start = self::time($item['start'] ?? 0);
            $end = max($start, self::time($item['end'] ?? 0));
            if ($start <= 0 || $end < $now - self::BACK || $start > $now + self::AHEAD || count($rows) >= self::MOST) {
                continue;
            }
            $text = fn($value, int $length) => \core_text::substr(trim(clean_param((string) $value, PARAM_TEXT)), 0, $length);
            $rows[] = (object) ['centreid' => $centreid, 'source' => $source, 'calendar' => $text($item['calendar'] ?? '', 100),
                'title' => $text($item['title'] ?? '', 255), 'timestart' => $start, 'timeend' => $end,
                'allday' => empty($item['allday']) ? 0 : 1];
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::T, ['centreid' => $centreid, 'source' => $source]);
        if ($rows) {
            $DB->insert_records(self::T, $rows);
        }
        $transaction->allow_commit();
        return count($rows);
    }

    /**
     * A moment as a source says it: its time, or its day (YYYY-MM-DD) for what is of the whole day, wherever it is read.
     *
     * @param mixed $said
     * @return int
     */
    protected static function time($said): int {
        if (is_string($said) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $said, $m)) {
            return make_timestamp((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, \core_date::get_server_timezone());
        }
        return (int) $said;
    }

    /**
     * What there is between two moments, the things of the whole day first.
     *
     * @param int $centreid
     * @param int $from
     * @param int $to Not included.
     * @return \stdClass[]
     */
    public static function between(int $centreid, int $from, int $to): array {
        global $DB;
        return array_values($DB->get_records_select(
            self::T,
            'centreid = :centreid AND timestart < :to AND (timeend > :from OR (timestart >= :from2 AND timeend = timestart))',
            ['centreid' => $centreid, 'from' => $from, 'from2' => $from, 'to' => $to],
            'allday DESC, timestart ASC, id ASC'
        ));
    }

    /**
     * What there is each day, for a page: its minutes of that day, its title and the colour of its calendar.
     *
     * @param int $centreid
     * @param int[] $days The start of each day, in order.
     * @return array start of the day => [title, calendar, from, to, allday, color, hour]
     */
    public static function by_day(int $centreid, array $days): array {
        $out = array_fill_keys($days, []);
        if (!$days) {
            return $out;
        }
        $zone = \core_date::get_server_timezone_object();
        $next = fn(int $day) => (new \DateTimeImmutable('@' . $day))->setTimezone($zone)->modify('+1 day')->getTimestamp();
        $colours = self::colours($centreid);
        foreach (self::between($centreid, min($days), $next(max($days))) as $row) {
            foreach ($days as $day) {
                $end = $next($day);
                if ((int) $row->timestart >= $end || max((int) $row->timeend, (int) $row->timestart + 1) <= $day) {
                    continue;
                }
                $minute = fn(int $time) => max(0, min(1440, intdiv($time - $day, MINSECS)));
                $allday = (int) $row->allday || ($minute((int) $row->timestart) === 0 && $minute((int) $row->timeend) === 1440);
                $title = trim((string) $row->title);
                $out[$day][] = ['title' => $title !== '' ? $title : get_string('busy_untitled', self::C),
                    'calendar' => (string) $row->calendar, 'from' => $minute((int) $row->timestart),
                    'to' => max($minute((int) $row->timeend), $minute((int) $row->timestart)), 'allday' => $allday,
                    'color' => $colours[(string) $row->calendar] ?? 0,
                    'hour' => $allday ? '' : panel::hour(max((int) $row->timestart, $day)) . '–' . panel::hour(min((int) $row->timeend, $end))];
            }
        }
        return $out;
    }

    /**
     * The calendars of a centre, each with its colour: the same one whatever days are looked at.
     *
     * @param int $centreid
     * @return int[] Name => 0 to 3.
     */
    public static function colours(int $centreid): array {
        global $DB;
        $names = array_map('strval', $DB->get_fieldset_sql('SELECT DISTINCT calendar FROM {' . self::T .
            '} WHERE centreid = ? ORDER BY calendar', [$centreid]));
        return array_map(fn($n) => $n % 4, array_flip($names));
    }

    /**
     * What an hour falls on, in words; nothing when it is free. A thing of the whole day is not a clash: it is seen behind.
     *
     * @param int $centreid
     * @param int $start
     * @param int $end
     * @return string
     */
    public static function clash(int $centreid, int $start, int $end): string {
        $names = [];
        foreach (self::between($centreid, $start, $end) as $row) {
            if (!(int) $row->allday && (int) $row->timeend > $start && (int) $row->timeend - (int) $row->timestart < 20 * HOURSECS) {
                $title = trim((string) $row->title) !== '' ? trim((string) $row->title) : get_string('busy_untitled', self::C);
                $names[$title . (trim((string) $row->calendar) !== '' ? ' (' . trim((string) $row->calendar) . ')' : '')] = true;
            }
        }
        return $names ? get_string('busy_clash', self::C, implode(', ', array_slice(array_keys($names), 0, 3))) : '';
    }

    /**
     * What a calendar file says, with what repeats told day by day.
     *
     * @param string $ics
     * @param int $now
     * @return array[] As replace() takes them.
     */
    public static function read_ics(string $ics, int $now): array {
        $lines = preg_split('/\r\n|\r|\n/', preg_replace('/(\r\n|\r|\n)[ \t]/', '', $ics));
        $events = [];
        $event = null;
        $calendar = '';
        foreach ($lines as $line) {
            $word = strtoupper(trim($line));
            if ($word === 'BEGIN:VEVENT') {
                $event = [];
            } else if ($word === 'END:VEVENT' && $event !== null) {
                $events[] = $event;
                $event = null;
            } else if (preg_match('/^([A-Za-z-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) {
                $name = strtoupper($m[1]);
                if ($event === null) {
                    $calendar = $name === 'X-WR-CALNAME' ? self::unescape($m[3]) : $calendar;
                } else if ($name === 'EXDATE') {
                    $event['EXDATE'][] = [$m[2], $m[3]];
                } else {
                    $event[$name] = $event[$name] ?? [$m[2], trim($m[3])];
                }
            }
        }
        // One time of something that repeats, changed or called off, takes the place of that time.
        $instead = [];
        foreach ($events as $one) {
            if (isset($one['RECURRENCE-ID'], $one['UID'])) {
                $instead[$one['UID'][1]][] = $one['RECURRENCE-ID'];
            }
        }
        $out = [];
        foreach ($events as $one) {
            if (!isset($one['RECURRENCE-ID']) && isset($one['UID'])) {
                $one['EXDATE'] = array_merge($one['EXDATE'] ?? [], $instead[$one['UID'][1]] ?? []);
            }
            foreach (self::times($one, $now) as [$start, $end, $allday]) {
                $out[] = ['calendar' => $calendar, 'title' => self::unescape((string) ($one['SUMMARY'][1] ?? '')), 'start' => $start,
                    'end' => $end, 'allday' => $allday];
            }
            if (count($out) > self::MOST) {
                break;
            }
        }
        return $out;
    }

    /**
     * A text of a calendar file, as it is read.
     *
     * @param string $text
     * @return string
     */
    protected static function unescape(string $text): string {
        return trim(preg_replace('/\s+/', ' ', strtr($text, ['\\n' => ' ', '\\N' => ' ', '\\,' => ',', '\\;' => ';', '\\\\' => '\\'])));
    }

    /**
     * A moment of a calendar file.
     *
     * @param array|null $value [parameters, value]
     * @return \DateTimeImmutable|null
     */
    protected static function moment(?array $value): ?\DateTimeImmutable {
        if (!$value || !preg_match('/^(\d{8})(?:T(\d{6})(Z?))?$/', trim($value[1]), $m)) {
            return null;
        }
        $zone = \core_date::get_server_timezone_object();
        if (!empty($m[3])) {
            $zone = new \DateTimeZone('UTC');
        } else if (preg_match('/TZID=([^;:]+)/i', $value[0], $z)) {
            try {
                $zone = new \DateTimeZone(trim($z[1], '"'));
            } catch (\Throwable $e) {
                $zone = \core_date::get_server_timezone_object();
            }
        }
        return \DateTimeImmutable::createFromFormat('!Ymd His', $m[1] . ' ' . ($m[2] ?? '000000'), $zone) ?: null;
    }

    /**
     * When an event of a calendar file is, within what is kept: every day, week, month or year it repeats.
     *
     * @param array $event Its properties.
     * @param int $now
     * @return array[] start, end, allday
     */
    protected static function times(array $event, int $now): array {
        $start = self::moment($event['DTSTART'] ?? null);
        if (!$start || strtoupper((string) ($event['STATUS'][1] ?? '')) === 'CANCELLED') {
            return [];
        }
        $allday = strlen(trim((string) $event['DTSTART'][1])) === 8;
        $end = self::moment($event['DTEND'] ?? null);
        $length = $end && $end > $start ? $end->getTimestamp() - $start->getTimestamp() : ($allday ? DAYSECS : HOURSECS);
        $days = $allday ? max(1, (int) round($length / DAYSECS)) : 0;
        $from = $now - self::BACK;
        $to = $now + self::AHEAD;
        $rule = [];
        foreach (explode(';', strtoupper((string) ($event['RRULE'][1] ?? ''))) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $rule[$name] = $value;
        }
        $skip = [];
        foreach ($event['EXDATE'] ?? [] as [$parameters, $values]) {
            foreach (explode(',', $values) as $value) {
                $moment = self::moment([$parameters, $value]);
                $skip[$moment ? $moment->getTimestamp() : 0] = true;
            }
        }
        $until = self::moment(isset($rule['UNTIL']) ? ['', $rule['UNTIL']] : null);
        $last = min($to, $until ? $until->getTimestamp() + ($allday ? DAYSECS : 0) : $to);
        $every = max(1, (int) ($rule['INTERVAL'] ?? 1));
        $left = (int) ($rule['COUNT'] ?? 0) ?: PHP_INT_MAX;
        $step = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'][$rule['FREQ'] ?? ''] ?? '';
        $out = [];
        $put = function (\DateTimeImmutable $at) use (&$out, &$left, $skip, $length, $days, $allday, $from, $last): void {
            if ($left-- <= 0 || $at->getTimestamp() > $last || isset($skip[$at->getTimestamp()])) {
                return;
            }
            $finish = $allday ? $at->modify("+$days days")->getTimestamp() : $at->getTimestamp() + $length;
            if ($finish > $from) {
                $out[] = [$at->getTimestamp(), $finish, $allday];
            }
        };
        $names = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
        $on = array_values(array_intersect_key($names, array_flip(explode(',', (string) ($rule['BYDAY'] ?? '')))));
        $day = (int) $start->format('N');
        for ($i = 0; $i < 4000 && $left > 0; $i++) {
            $at = $i && $step !== '' ? $start->modify('+' . ($i * $every) . ' ' . $step) : $start;
            if ($step === 'week' && $on) {
                // The days of each week it falls on.
                $monday = $at->modify('-' . ($day - 1) . ' days');
                sort($on);
                foreach ($on as $n) {
                    $one = $monday->modify('+' . ($n - 1) . ' days');
                    if ($one >= $start) {
                        $put($one);
                    }
                }
            } else if ($step !== 'month' || $at->format('j') === $start->format('j')) {
                // A month without that day (the 31st) has none.
                $put($at);
            }
            if ($step === '' || $at->getTimestamp() > $last) {
                break;
            }
        }
        return $out;
    }

    /**
     * Whether there is something to show behind.
     *
     * @param int $centreid
     * @return bool
     */
    public static function has(int $centreid): bool {
        global $DB;
        return $DB->record_exists(self::T, ['centreid' => $centreid]);
    }
}
