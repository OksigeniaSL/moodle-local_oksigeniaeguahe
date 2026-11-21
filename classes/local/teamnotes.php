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
 * What the team wrote by hand in a sheet of families (a follow-up column, the answer of a call, an observation, who
 * attended them, the day and hour of their visit), read as gestures of the family: a call without answer, a WhatsApp
 * sent, a visit on a day, a request made, a family that chose another centre. Each one leaves its line in the history
 * with what was written; what cannot be understood stays as it was, as a note of the team. Nothing is ever sent to the
 * family, and the same thing written twice is kept once, so a sheet can be sent again every night.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teamnotes {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string What was already taken from a sheet, by household, so that nothing comes in twice. */
    const T = 'local_oksigeniaeguahe_teamnote';

    /** @var string */
    const DATA = '/local/oksigeniaeguahe/data/team-notes.json';

    /** @var string[] Gestures that say a person of the team was in touch: the family no longer waits. */
    const TOUCH = ['contacted', 'noanswer', 'wrongnumber', 'invited', 'infosent', 'familycalls', 'callback', 'visitbooked',
        'attended', 'absent', 'cancelled', 'stage', 'closed'];

    /** @var array|null */
    protected static $data = null;

    /**
     * The words, as data.
     *
     * @return array
     */
    public static function data(): array {
        global $CFG;
        if (self::$data === null) {
            self::$data = json_decode((string) file_get_contents($CFG->dirroot . self::DATA), true) ?: [];
        }
        return self::$data;
    }

    /**
     * A text in lower case, without accents, with one space between words.
     *
     * @param string $value
     * @return string
     */
    public static function plain(string $value): string {
        $value = str_replace(['º', 'ª', '°'], ['o', 'a', 'o'], $value);
        $value = \core_text::strtolower(\core_text::specialtoascii($value));
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * The way a family came in, from a column such as «Canal»: phone, email, an open day, the ads…
     *
     * @param string $value
     * @return string|null One of lists::CHANNELS, or null when the words say none.
     */
    public static function channel(string $value): ?string {
        $text = self::plain($value);
        foreach (self::data()['channels'] ?? [] as $each) {
            if (preg_match('/' . $each['re'] . '/', $text)) {
                return in_array($each['channel'], lists::CHANNELS, true) ? $each['channel'] : null;
            }
        }
        return null;
    }

    /**
     * How a family heard of the centre, from the same column: other families, an ad, the networks…
     *
     * @param string $value
     * @return string|null One of lists::HEARD_FROM, or null.
     */
    public static function heard(string $value): ?string {
        $text = self::plain($value);
        foreach (self::data()['heard'] ?? [] as $each) {
            if (preg_match('/' . $each['re'] . '/', $text)) {
                return in_array($each['heard'], lists::HEARD_FROM, true) ? $each['heard'] : null;
            }
        }
        return null;
    }

    /**
     * The gestures of one value written by the team.
     *
     * @param string $header The name of its column.
     * @param string $value
     * @param int $around When the row happened, to place a day written without its year.
     * @param bool $free Whether it is free text (an observation): a date in it is never a visit.
     * @param bool $monthfirst Whether dates are written with the month first.
     * @return array|null List of gestures (kind, and channel, stage, reason or time when the kind has one); empty when
     *     it is not understood and stays as a note; null when there is nothing to write (a «no» that says nothing, the
     *     state a tool gives every contact).
     */
    public static function read(
        string $header,
        string $value,
        int $around,
        bool $free = false,
        bool $monthfirst = false
    ): ?array {
        $data = self::data();
        $text = self::plain($value);
        if ($text === '' || preg_match('/' . ($data['ignore'] ?? '^$') . '/', $text)) {
            return null;
        }
        $head = self::plain($header);
        foreach ($data['columns'] ?? [] as $column) {
            if (!preg_match('/' . $column['header'] . '/', $head)) {
                continue;
            }
            if (isset($column['date'])) {
                // A column of days (the day of a call): the day is when it happened.
                $day = self::day($text, $around, $monthfirst);
                if ($day) {
                    $gesture = self::gesture($column['date'], $day);
                    return [$gesture];
                }
                continue;
            }
            $said = preg_match('/' . $data['yes'] . '/', $text) ? 'yes' : (preg_match('/' . $data['no'] . '/', $text) ?
                'no' : null);
            if ($said !== null) {
                return $column[$said] === '' ? null : [self::gesture($column[$said], $around) + ['column' => true]];
            }
        }
        $out = [];
        foreach ($data['words'] ?? [] as $word) {
            $text = preg_replace_callback('/' . $word['re'] . '/', function ($m) use (&$out, $word, $around, $monthfirst) {
                $gesture = self::gesture($word['gesture'], $around);
                $day = self::day($m[0], $around, $monthfirst);
                if ($day) {
                    $gesture['time'] = $day;
                }
                $out[] = $gesture;
                return ' ';
            }, $text, 1);
        }
        // A day alone in a column of the team (not in an observation) is the day they were given to come.
        $day = $free ? 0 : self::day($text, $around, $monthfirst);
        if ($day && !array_filter($out, fn($g) => in_array($g['kind'], ['visitbooked', 'openday', 'callback'], true))) {
            $out[] = ['kind' => 'visitbooked', 'time' => $day];
        }
        return $out;
    }

    /**
     * A gesture from its name in the data: «closed:noplace», «contacted:whatsapp», «stage:waitlist».
     *
     * @param string $name
     * @param int $time
     * @return array
     */
    protected static function gesture(string $name, int $time): array {
        [$kind, $what] = array_pad(explode(':', $name, 2), 2, '');
        $gesture = ['kind' => $kind, 'time' => $time];
        if ($kind === 'contacted') {
            $gesture['channel'] = in_array($what, lists::CHANNELS, true) ? $what : 'other';
        } else if ($kind === 'closed') {
            $gesture['reason'] = in_array($what, lists::CLOSED_REASONS, true) ? $what : 'other';
        } else if ($kind === 'stage') {
            $gesture['stage'] = $what;
        }
        return $gesture;
    }

    /**
     * The day written in a text («13/11», «23/11/2026», «21 nov»), at noon; 0 when there is none. Without its year, the
     * one that leaves it closest after the row.
     *
     * @param string $text
     * @param int $around
     * @param bool $monthfirst
     * @return int
     */
    public static function day(string $text, int $around, bool $monthfirst = false): int {
        $text = self::plain($text);
        $months = ['ene' => 1, 'jan' => 1, 'feb' => 2, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'apr' => 4, 'may' => 5,
            'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'aug' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'out' => 10,
            'nov' => 11, 'dic' => 12, 'dec' => 12, 'dez' => 12];
        if (preg_match('/\b(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?\b/', $text, $m)) {
            [$d, $mo] = $monthfirst ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];
            $y = isset($m[3]) ? (int) $m[3] : 0;
        } else if (
            preg_match(
                '/\b(\d{1,2})(?: de)? (' . implode('|', array_keys($months)) . ')[a-z]*\.?(?: (?:de )?(\d{4}))?/',
                $text,
                $m
            )
        ) {
            [$d, $mo, $y] = [(int) $m[1], $months[$m[2]], isset($m[3]) ? (int) $m[3] : 0];
        } else {
            return 0;
        }
        $y = $y && $y < 100 ? 2000 + $y : $y;
        $tz = \core_date::get_server_timezone_object();
        $base = $around ?: time();
        $year = $y ?: (int) (new \DateTime('@' . $base))->setTimezone($tz)->format('Y');
        if (!checkdate($mo, $d, $year)) {
            return 0;
        }
        $time = (new \DateTime('now', $tz))->setDate($year, $mo, $d)->setTime(12, 0)->getTimestamp();
        if (!$y && $time < $base - 60 * DAYSECS) {
            $time = (new \DateTime('now', $tz))->setDate($year + 1, $mo, $d)->setTime(12, 0)->getTimestamp();
        }
        return $time;
    }

    /**
     * A day and an hour («11:00», «9.30», «17h») as one moment.
     *
     * @param int $day
     * @param string $hour
     * @return int The day at that hour; the day itself without an hour.
     */
    public static function at(int $day, string $hour): int {
        if (!$day || !preg_match('/\b(\d{1,2})(?:[:.h](\d{2}))?\s*h?\b/', self::plain($hour), $m) || (int) $m[1] > 23) {
            return $day;
        }
        $tz = \core_date::get_server_timezone_object();
        return (new \DateTime('@' . $day))->setTimezone($tz)->setTime((int) $m[1], (int) ($m[2] ?? 0))->getTimestamp();
    }

    /**
     * Who of the team a name written in the sheet is: the one person of the team of the centre with that first name.
     *
     * @param int $centreid
     * @param string $name
     * @return int The user, or 0.
     */
    public static function member(int $centreid, string $name): int {
        $want = self::plain(preg_split('/\s+/u', trim($name))[0] ?? '');
        if ($want === '') {
            return 0;
        }
        $found = [];
        foreach (centres::members($centreid) as $user) {
            $first = self::plain(preg_split('/\s+/u', trim((string) $user->firstname))[0] ?? '');
            if ($first === $want) {
                $found[] = (int) $user->id;
            }
        }
        return count($found) === 1 ? $found[0] : 0;
    }

    /**
     * Writes in a household what the team wrote of it in one row: a line in its history for each value, with its
     * gestures read; the stage of its children, its promises and who attends it. What was taken before is not taken
     * again.
     *
     * @param \stdClass $household
     * @param array $row What entries::read() kept of the team: team (question, value, role), staff, appointment
     *     (a moment, or 0), time and monthfirst.
     * @return array Counts of what was done, by gesture, plus «note» and «already».
     */
    public static function apply(\stdClass $household, array $row): array {
        global $DB;
        $centre = centres::get((int) $household->centreid);
        $lang = (string) ($centre->lang ?: 'en');
        $str = fn(string $id, $a = null) => get_string_manager()->get_string($id, self::C, $a, $lang);
        // What the row says happened on the day of its visit, when it has one.
        $time = (int) ($row['appointment'] ?? 0) ?: ((int) ($row['time'] ?? 0) ?: time());
        $counts = [];
        $latest = 0;
        $userid = 0;
        $names = [];
        foreach ($row['staff'] ?? [] as $name) {
            $id = self::member((int) $household->centreid, $name);
            if ($id && !$userid) {
                // The first one of the team who attended them writes the lines and attends them from now on.
                $userid = $id;
            } else {
                $names[] = $name;
            }
        }
        $lines = [];
        foreach ($row['team'] ?? [] as [$question, $value, $role]) {
            // An observation or the way they came is free text: a date in it is never a visit.
            $free = in_array($role, ['notes', 'channel', 'channelknown'], true);
            $gestures = self::read($question, $value, $time, $free, !empty($row['monthfirst']));
            // A «no» in a column of yes or no («Asistencia: FALSE») only says they did not come to a visit that was
            // already their day; before it, or without a day, it says nothing.
            $past = (int) ($row['appointment'] ?? 0) && (int) $row['appointment'] < time();
            if ($gestures) {
                $gestures = array_values(array_filter($gestures, fn($g) => $g['kind'] !== 'absent' || empty($g['column']) ||
                    $past));
                if (!$gestures) {
                    continue;
                }
            }
            if ($gestures === null || (!$gestures && $role === 'channelknown')) {
                // Nothing to write, or the way they came, already understood.
                continue;
            }
            $lines[] = [$question, $value, $gestures];
        }
        $appointment = (int) ($row['appointment'] ?? 0);
        if ($appointment) {
            $lines[] = ['', '', [['kind' => 'visitbooked', 'time' => $appointment, 'exact' => true]]];
        }
        foreach ($names as $name) {
            $lines[] = ['', $name, [['kind' => 'by', 'time' => $time]]];
        }
        foreach ($lines as [$question, $value, $gestures]) {
            // Someone of the family studied at the centre: said again each time the sheet comes, and never a new line
            // for a note already written before Eguahe knew these words.
            if (array_filter($gestures, fn($g) => $g['kind'] === 'alumni')) {
                alumni::set($household, 0, alumni::YES, 'sheet', $userid, '', false);
            }
            $marked = array_values(array_filter($gestures, fn($g) => !in_array($g['kind'], ['alumni', 'notalumni'], true)));
            $mark = hash('sha256', $household->id . '|' . self::plain($question) . '|' . self::plain($value) . '|' .
                implode(',', array_map(fn($g) => $g['kind'] . '@' . ($g['time'] ?? 0), $marked)));
            if ($DB->record_exists(self::T, ['householdid' => $household->id, 'mark' => $mark])) {
                $counts['already'] = ($counts['already'] ?? 0) + 1;
                continue;
            }
            $said = $question !== '' ? trim($question) . ': ' . $value : $value;
            $what = [];
            $channel = null;
            $when = $time;
            foreach ($gestures as $gesture) {
                $kind = $gesture['kind'];
                $at = (int) ($gesture['time'] ?? $time);
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                if (in_array($kind, self::TOUCH, true)) {
                    $latest = max($latest, min($at, time()));
                }
                switch ($kind) {
                    case 'contacted':
                        $channel = $channel ?? $gesture['channel'];
                        $what[] = $str('teamnote_contacted_' . $gesture['channel']);
                        break;
                    case 'noanswer':
                    case 'wrongnumber':
                    case 'familycalls':
                        $channel = $channel ?? 'phone';
                        $what[] = $str('teamnote_' . $kind);
                        break;
                    case 'invited':
                    case 'infosent':
                        $channel = $channel ?? 'other';
                        $what[] = $str('teamnote_' . $kind);
                        break;
                    case 'callback':
                        $what[] = $str('teamnote_callback');
                        $open = attend::open_promise((int) $household->id);
                        if (!$open) {
                            $day = (int) ($gesture['time'] ?? 0);
                            attend::promise(
                                $household,
                                $userid,
                                $value !== '' ? $value : $str('teamnote_callback'),
                                $day > time() - DAYSECS && $day !== $time ? $day : 0
                            );
                        }
                        break;
                    case 'visitbooked':
                        // Noted when the row was written, not on the day of the visit.
                        $when = min($time, $at);
                        $day = userdate($at, get_string_manager()->get_string('strftimedaydate', 'langconfig', null, $lang));
                        $hour = !empty($gesture['exact']) ? userdate($at, '%H:%M', 99, false) : '';
                        $what[] = $hour !== '' ? $str('teamnote_visitbooked_at', (object) ['day' => $day, 'hour' => $hour]) :
                            $str('teamnote_visitbooked', $day);
                        if ($at > time() && !attend::open_promise((int) $household->id)) {
                            attend::promise($household, $userid, $hour !== '' ? $str('teamnote_visit_promise', $hour) :
                                $str('teamnote_visit_promise_day'), $at);
                        }
                        break;
                    case 'attended':
                        $what[] = $str('teamnote_attended');
                        households::advance((int) $household->id, 'visit');
                        break;
                    case 'absent':
                    case 'cancelled':
                    case 'nowhatsapp':
                        $what[] = $str('teamnote_' . $kind);
                        break;
                    case 'openday':
                        $what[] = isset($gesture['time']) && $gesture['time'] !== $time ?
                            $str('teamnote_openday_day', userdate($gesture['time'], get_string_manager()->get_string(
                                'strftimedaydate',
                                'langconfig',
                                null,
                                $lang
                            ))) : $str('teamnote_openday');
                        break;
                    case 'incentre':
                        $what[] = $str('teamnote_incentre');
                        $DB->set_field(households::T, 'incentre', 1, ['id' => $household->id]);
                        break;
                    case 'stage':
                        $what[] = $str('teamnote_stage_' . $gesture['stage']);
                        foreach ($DB->get_records(households::CHILDREN, ['householdid' => $household->id]) as $child) {
                            $go = $gesture['stage'] === 'waitlist' ?
                                in_array($child->stage, ['inquiry', 'visit', 'application', 'admitted'], true) :
                                lists::further((string) $child->stage, $gesture['stage']);
                            if ($go) {
                                households::set_stage($child, $gesture['stage']);
                            }
                        }
                        break;
                    case 'closed':
                        $what[] = $str('teamnote_closed', get_string_manager()->get_string(
                            'closed_' . $gesture['reason'],
                            self::C,
                            null,
                            $lang
                        ));
                        foreach ($DB->get_records(households::CHILDREN, ['householdid' => $household->id]) as $child) {
                            if (!in_array($child->stage, ['enrolled', 'closed'], true)) {
                                households::set_stage($child, 'closed', $gesture['reason']);
                            }
                        }
                        break;
                    case 'by':
                        $what[] = $str('teamnote_by', $value);
                        $said = '';
                        break;
                    case 'alumni':
                        $what[] = $str('teamnote_alumni');
                        break;
                    case 'notalumni':
                        break;
                }
            }
            if (!$gestures) {
                $counts['note'] = ($counts['note'] ?? 0) + 1;
            }
            $body = implode('. ', array_unique($what));
            $body = trim($body . ($body !== '' && $said !== '' ? ' · ' : '') . $said);
            $DB->insert_record(households::CONTACTS, (object) [
                'centreid' => $household->centreid,
                'householdid' => $household->id,
                'direction' => $channel !== null ? 'out' : 'note',
                'channel' => $channel ?? 'other',
                'body' => $body,
                'entryid' => 0,
                'detail' => null,
                'userid' => $userid,
                'timecreated' => min($when, time()),
            ]);
            $DB->insert_record(self::T, (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
                'mark' => $mark, 'timecreated' => time()]);
        }
        $fresh = households::get((int) $household->id, (int) $household->centreid);
        if ($fresh) {
            $changes = ['id' => $fresh->id];
            if ($latest && $fresh->waitingsince && $fresh->waitingsince <= $latest) {
                $changes['waitingsince'] = 0;
            }
            if ($latest > (int) $fresh->lastcontact) {
                $changes['lastcontact'] = $latest;
            }
            if (count($changes) > 1) {
                $DB->update_record(households::T, (object) $changes);
            }
            if ($userid && !$fresh->ownerid) {
                // Who attended them in the sheet attends them here, without a line saying so.
                $DB->set_field(households::T, 'ownerid', $userid, ['id' => $fresh->id]);
            }
        }
        return $counts;
    }

    /**
     * Forgets what was taken from the sheets for a household that is erased.
     *
     * @param int $householdid
     */
    public static function forget(int $householdid): void {
        global $DB;
        $DB->delete_records(self::T, ['householdid' => $householdid]);
    }
}
