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

    /** @var string[] Gestures of a person who tried to speak with the family and did not: it answers nothing. */
    const TRIED = ['noanswer', 'wrongnumber'];

    /** @var string[] Gestures that say a person of the team was in touch: the family no longer waits. */
    const TOUCH = ['contacted', 'invited', 'infosent', 'familycalls', 'callback', 'visitbooked',
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
     * What a line of a sheet is, wherever it is written: its gestures and its words.
     *
     * @param array $gestures
     * @param string $value
     * @return string
     */
    protected static function line_key(array $gestures, string $value): string {
        // Whether a day in it is a visit depends on the column: it is not what tells one line from another.
        $kinds = [];
        foreach ($gestures as $g) {
            if ($g['kind'] !== 'visitbooked') {
                $kinds[] = $g['kind'] . ':' . ($g['channel'] ?? '') . ':' . ($g['stage'] ?? $g['reason'] ?? '');
            }
        }
        return implode(',', $kinds) . '|' . self::plain($value);
    }

    /**
     * Whether what a row says makes of its day, without an hour, something else than an appointment: they only called and
     * nobody answered, or they come to an open day that is another day.
     *
     * @param array $lines question, value and gestures.
     * @param int $day
     * @return bool
     */
    protected static function no_visit(array $lines, int $day): bool {
        $gestures = array_merge([], ...array_map(fn($line) => $line[2], $lines));
        $kinds = array_column($gestures, 'kind');
        if (array_intersect($kinds, ['attended', 'visitbooked', 'contacted', 'familycalls', 'infosent', 'stage'])) {
            return false;
        }
        $date = fn(int $time) => userdate($time, '%Y-%m-%d', 99, false);
        foreach ($gestures as $g) {
            if ($g['kind'] === 'openday' && !empty($g['time']) && $date((int) $g['time']) !== $date($day)) {
                return true;
            }
        }
        return (bool) array_intersect($kinds, ['noanswer', 'wrongnumber']);
    }

    /**
     * The lines a sheet has already left in the history of a family, by what each one is.
     *
     * @param int $householdid
     * @param int $around
     * @return array[] line_key() => ids of its lines, the oldest first.
     */
    public static function lines_before(int $householdid, int $around): array {
        global $DB;
        $out = [];
        $rows = $DB->get_records_select(
            households::CONTACTS,
            "householdid = ? AND entryid = 0 AND direction IN ('note', 'out')",
            [$householdid],
            'id ASC',
            'id, body, direction'
        );
        foreach ($rows as $row) {
            // As each one is written: what was understood, the title of its column and its words.
            if (!preg_match('/^(?:.* · )?([^:·\n]{1,80}): (.+)$/su', (string) $row->body, $m)) {
                continue;
            }
            $gestures = self::read($m[1], $m[2], $around) ?? [];
            $marked = array_values(array_filter($gestures, fn($g) => !in_array($g['kind'], ['alumni', 'notalumni'], true)));
            $out[self::line_key($marked, $m[2])][] = (int) $row->id;
        }
        return $out;
    }

    /**
     * The lines of a column that a family already had under another name, on a day a sheet was read again: for
     * cli/sheet_repeated.php. Taken out with $run, and whoever stopped waiting because of them waits again.
     *
     * @param \stdClass $centre
     * @param string $title The column that was read as new; empty, any.
     * @param int $from When it was read.
     * @param int $to
     * @param bool $run
     * @return array lines (ids), titles (title => how many) and waiting (household id => since)
     */
    public static function repeated(\stdClass $centre, string $title, int $from, int $to, bool $run): array {
        global $DB;
        $out = ['lines' => [], 'titles' => [], 'waiting' => []];
        // A line has the day of its row, not the one it was read: what was written that day is told by where it is in the table.
        $edge = fn(int $time) => (int) $DB->get_field_sql('SELECT MIN(id) FROM {' . households::CONTACTS . '} WHERE timecreated >= ?', [$time]);
        $first = $edge($from);
        $last = $edge($to);
        $like = $DB->sql_like('body', ':title', false);
        $rows = !$first ? [] : $DB->get_records_select(
            households::CONTACTS,
            "centreid = :centre AND entryid = 0 AND direction IN ('note', 'out') AND id >= :first AND id < :last AND $like",
            ['centre' => $centre->id, 'first' => $first, 'last' => $last ?: PHP_INT_MAX, 'title' => '%' . $DB->sql_like_escape($title) . ':%'],
            'id ASC'
        );
        $families = [];
        foreach ($rows as $row) {
            if (!preg_match('/^(?:.* · )?([^:·\n]{1,80}): (.+)$/su', (string) $row->body, $m) || ($title !== '' && trim($m[1]) !== $title)) {
                continue;
            }
            $gestures = array_values(array_filter(self::read($m[1], $m[2], (int) $row->timecreated) ?? [], fn($g) =>
                !in_array($g['kind'], ['alumni', 'notalumni'], true)));
            $same = self::lines_before((int) $row->householdid, (int) $row->timecreated)[self::line_key($gestures, $m[2])] ?? [];
            // Of the ones there are, the first stays; one of the same moment is another column of the same row.
            $older = array_filter($same, fn($id) => $id < (int) $row->id && !isset($rows[$id]));
            if ($older) {
                $out['lines'][] = (int) $row->id;
                $out['titles'][trim($m[1])] = ($out['titles'][trim($m[1])] ?? 0) + 1;
                $families[(int) $row->householdid][] = (int) $row->id;
            }
        }
        foreach ($families as $householdid => $ids) {
            $household = households::get($householdid, (int) $centre->id);
            if (!$household || (int) $household->waitingsince) {
                continue;
            }
            [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'x', false);
            $asked = (int) $DB->get_field_sql(
                'SELECT MAX(timecreated) FROM {' . households::CONTACTS . "} WHERE householdid = :h AND direction = 'in' AND userid = 0
                    AND channel <> 'event'",
                ['h' => $householdid]
            );
            $answered = $asked && $DB->record_exists_select(
                households::CONTACTS,
                "householdid = :h AND direction = 'out' AND aim IS NULL AND timecreated >= :since AND id $in",
                ['h' => $householdid, 'since' => usergetmidnight($asked)] + $params
            );
            $closed = $DB->count_records_select(households::CHILDREN, "householdid = ? AND stage <> 'closed'", [$householdid]) === 0 &&
                $DB->record_exists(households::CHILDREN, ['householdid' => $householdid]);
            if ($asked && !$answered && !$closed) {
                $out['waiting'][$householdid] = $asked;
            }
        }
        if ($run) {
            $transaction = $DB->start_delegated_transaction();
            foreach ($out['lines'] as $id) {
                $DB->delete_records(households::CONTACTS, ['id' => $id]);
            }
            foreach ($out['waiting'] as $householdid => $since) {
                $DB->update_record(households::T, (object) ['id' => $householdid, 'waitingsince' => $since, 'timemodified' => time()]);
            }
            $transaction->allow_commit();
        }
        return $out;
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
     * Whether what is written as an hour is one («11:00», «17h»), and not a word («por la tarde»).
     *
     * @param string $hour
     * @return bool
     */
    public static function has_hour(string $hour): bool {
        return (bool) preg_match('/\b(\d{1,2})(?:[:.h](\d{2}))?\s*h?\b/', self::plain($hour), $m) && (int) $m[1] <= 23;
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
     *     (a moment, or 0), appointmenthour (whether its hour is known), hour, time, keytime and monthfirst; and entryid
     *     and past (whether the whole sheet is being sent again), from entries::receive().
     * @return array Counts of what was done, by gesture, plus «note» and «already».
     */
    public static function apply(\stdClass $household, array $row): array {
        global $DB;
        $centre = centres::get((int) $household->centreid);
        $lang = (string) ($centre->lang ?: 'en');
        $str = fn(string $id, $a = null) => get_string_manager()->get_string($id, self::C, $a, $lang);
        // What the row says happened on the day of its visit, when it has one.
        $stable = (int) ($row['appointment'] ?? 0) ?: (int) ($row['time'] ?? 0);
        $time = $stable ?: time();
        // The day of the row, as it always was known by it: its hour never makes it another line.
        $keytime = (int) ($row['keytime'] ?? 0) ?: $stable;
        $marktime = trim((string) ($row['hour'] ?? '')) !== '' && $keytime ? self::at($keytime, (string) $row['hour']) : $keytime;
        $at = fn(array $g) => (int) ($g['time'] ?? 0) === $time && $stable ? $marktime : (int) ($g['time'] ?? 0);
        // A moment the sheet does not write (no date in the row) is not part of what a line is: the same sheet sent again
        // every night is the same line, and it never says when the team answered.
        $stamp = fn(array $g) => !$stable && (int) ($g['time'] ?? $time) === $time ? 0 : $at($g);
        $entryid = (int) ($row['entryid'] ?? 0);
        $visit = null;
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
            // already their day; before it, or without a day and its hour, it says nothing (an empty box is a «no» too).
            $past = (int) ($row['appointment'] ?? 0) && !empty($row['appointmenthour']) && (int) $row['appointment'] < time();
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
        if ($appointment && empty($row['appointmenthour']) && self::no_visit($lines, $appointment)) {
            // The day of a call nobody answered, or of telling them of an open day: nobody was given an appointment then.
            $DB->set_field_select(
                self::T,
                'state',
                'note',
                'householdid = ? AND entryid = ? AND visit = ? AND dayonly = 1 AND state IS NULL AND replacedby = 0',
                [$household->id, $entryid, $appointment]
            );
            $appointment = 0;
        }
        if ($appointment) {
            $lines[] = ['', '', [['kind' => 'visitbooked', 'time' => $appointment,
                'exact' => !empty($row['appointmenthour']), 'row' => true]]];
        }
        foreach ($names as $name) {
            $lines[] = ['', $name, [['kind' => 'by', 'time' => $time]]];
        }
        $inrow = [];
        $before = null;
        foreach ($lines as [$question, $value, $gestures]) {
            // Someone of the family studied at the centre: said again each time the sheet comes, and never a new line
            // for a note already written before Eguahe knew these words.
            if (array_filter($gestures, fn($g) => $g['kind'] === 'alumni')) {
                alumni::set($household, 0, alumni::YES, 'sheet', $userid, '', false);
            }
            $marked = array_values(array_filter($gestures, fn($g) => !in_array($g['kind'], ['alumni', 'notalumni'], true)));
            // What a line is: its words and its gestures with the moments the sheet writes; not the household (a merge
            // keeps its lines), nor tonight. The marks written before this (with the household) still count.
            $kinds = fn(callable $at) => implode(',', array_map(fn($g) => $g['kind'] . '@' . $at($g), $marked));
            $mark = hash('sha256', self::plain($question) . '|' . self::plain($value) . '|' . $kinds($stamp));
            $legacy = hash('sha256', $household->id . '|' . self::plain($question) . '|' . self::plain($value) . '|' .
                $kinds($at));
            // The day the sheet itself gives for the visit, followed while the sheet is sent: it may change.
            $given = array_values(array_filter($gestures, fn($g) => !empty($g['row'])))[0] ?? null;
            [$in, $inparams] = $DB->get_in_or_equal([$mark, $legacy], SQL_PARAMS_NAMED, 'mk');
            $known = $DB->get_records_select(
                self::T,
                "householdid = :h AND mark $in",
                ['h' => $household->id] + $inparams,
                'id ASC',
                '*',
                0,
                1
            );
            if ($known) {
                $counts['already'] = ($counts['already'] ?? 0) + 1;
                if ($given) {
                    $visit = self::seen_again(reset($known), $household, $given, $entryid, $lang);
                }
                continue;
            }
            if ($question !== '' && !$given) {
                // The same words, with the same gesture, in another column or another tab (one renamed, a row copied): it is not
                // said again, nor done again. In two columns of one row (two calls nobody answered) it is said twice.
                $key = self::line_key($marked, $value);
                $inrow[$key] = ($inrow[$key] ?? 0) + 1;
                $before = $before ?? self::lines_before((int) $household->id, $time);
                $same = $before[$key] ?? [];
                if (count($same) >= $inrow[$key]) {
                    $counts['already'] = ($counts['already'] ?? 0) + 1;
                    $DB->insert_record(self::T, (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
                        'mark' => $mark, 'contactid' => $same[$inrow[$key] - 1], 'timecreated' => time()]);
                    continue;
                }
            }
            $said = $question !== '' ? trim($question) . ': ' . $value : $value;
            $what = [];
            $channel = null;
            $when = $time;
            // A call nobody answered is only that, unless something else in the same words did reach them.
            $tried = $gestures && !array_filter($gestures, fn($g) => !in_array($g['kind'], self::TRIED, true) &&
                in_array($g['kind'], ['contacted', 'familycalls', 'invited', 'infosent'], true));
            foreach ($gestures as $gesture) {
                $kind = $gesture['kind'];
                $moment = (int) ($gesture['time'] ?? $time);
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                if (in_array($kind, self::TOUCH, true)) {
                    // Only a moment the sheet writes says the team answered then.
                    $latest = $stamp($gesture) ? max($latest, min($moment, time())) : $latest;
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
                        $when = min($time, $moment);
                        $what[] = self::booked($moment, !empty($gesture['exact']), $lang);
                        if ($moment > time() && !attend::open_promise((int) $household->id)) {
                            attend::promise(
                                $household,
                                $userid,
                                self::visit_promise($moment, !empty($gesture['exact']), $lang),
                                $moment
                            );
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
            $contactid = (int) $DB->insert_record(households::CONTACTS, (object) [
                'centreid' => $household->centreid,
                'householdid' => $household->id,
                'direction' => $channel !== null ? 'out' : 'note',
                'channel' => $channel ?? 'other',
                'body' => $body,
                'entryid' => 0,
                'detail' => null,
                'userid' => $userid,
                'aim' => $channel !== null && $tried ? 'tried' : null,
                // The sheet never writes at what time the team did it: the hour of the row is of something else, and without
                // a day in it the hour is that of the load.
                'dayonly' => 1,
                'timecreated' => min($when, time()),
            ]);
            $note = (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
                'mark' => $mark, 'timecreated' => time()];
            if ($given) {
                $note->entryid = $entryid;
                $note->contactid = $contactid;
                $note->visit = (int) $given['time'];
                $note->dayonly = empty($given['exact']) ? 1 : 0;
                $note->seen = time();
            }
            $note->id = $DB->insert_record(self::T, $note);
            if ($given) {
                $visit = $DB->get_record(self::T, ['id' => $note->id]);
            }
        }
        if ($visit) {
            self::supersede($visit, $household, !empty($row['past']), $lang);
        }
        $fresh = households::get((int) $household->id, (int) $household->centreid);
        if ($fresh) {
            $changes = ['id' => $fresh->id];
            if ($latest && $fresh->waitingsince && $fresh->waitingsince <= $latest) {
                $changes['waitingsince'] = 0;
                $changes['waitingdayonly'] = 0;
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
     * The lines taken before a call that nobody answered was told apart: they were kept as an answer of the team. By what the
     * sheet said, which is the first thing of the line, in any language of the plugin.
     *
     * @return int How many were marked.
     */
    public static function mark_tried(): int {
        global $DB;
        $words = [];
        foreach (lists::LANGS as $lang) {
            foreach (self::TRIED as $kind) {
                $words[publiclang::str('teamnote_' . $kind, $lang)] = true;
            }
        }
        $n = 0;
        $rows = $DB->get_recordset_select(
            households::CONTACTS,
            "direction = 'out' AND entryid = 0 AND aim IS NULL AND channel = 'phone'",
            [],
            'id ASC',
            'id, householdid, body'
        );
        foreach ($rows as $row) {
            $gestures = array_filter(array_map('trim', explode('. ', explode(' · ', (string) $row->body)[0])), fn($w) => $w !== '');
            $only = $gestures && !array_filter($gestures, fn($w) => !isset($words[rtrim($w, '.')]));
            if ($only && $DB->record_exists(self::T, ['householdid' => $row->householdid])) {
                $DB->update_record(households::CONTACTS, (object) ['id' => $row->id, 'aim' => 'tried', 'dayonly' => 1]);
                $n++;
            }
        }
        $rows->close();
        return $n;
    }

    /**
     * The visit a sheet gives, in words: «Visita citada el jueves 19», with its hour when the sheet says it.
     *
     * @param int $moment
     * @param bool $exact Whether its hour is known.
     * @param string $lang
     * @return string
     */
    protected static function booked(int $moment, bool $exact, string $lang): string {
        $str = fn(string $id, $a = null) => get_string_manager()->get_string($id, self::C, $a, $lang);
        $day = userdate($moment, get_string_manager()->get_string('strftimedaydate', 'langconfig', null, $lang));
        return $exact ? $str('teamnote_visitbooked_at', (object) ['day' => $day, 'hour' => userdate($moment, '%H:%M', 99, false)]) :
            $str('teamnote_visitbooked', $day);
    }

    /**
     * What the team promised with that visit.
     *
     * @param int $moment
     * @param bool $exact
     * @param string $lang
     * @return string
     */
    public static function visit_promise(int $moment, bool $exact, string $lang): string {
        return $exact ? get_string_manager()->get_string(
            'teamnote_visit_promise',
            self::C,
            userdate($moment, '%H:%M', 99, false),
            $lang
        ) : get_string_manager()->get_string('teamnote_visit_promise_day', self::C, null, $lang);
    }

    /**
     * A visit the sheet still gives: it was seen again, so it was never replaced. One taken before its line was followed
     * starts being so.
     *
     * @param \stdClass $note
     * @param \stdClass $household
     * @param array $given Its gesture.
     * @param int $entryid
     * @param string $lang
     * @return \stdClass The note, as it is now.
     */
    protected static function seen_again(
        \stdClass $note,
        \stdClass $household,
        array $given,
        int $entryid,
        string $lang
    ): \stdClass {
        global $DB;
        if (!(int) $note->visit) {
            $note->visit = (int) $given['time'];
            $note->dayonly = empty($given['exact']) ? 1 : 0;
            $note->entryid = $entryid;
            $words = [self::booked($note->visit, true, $lang), self::booked($note->visit, false, $lang)];
            foreach (
                $DB->get_records(
                    households::CONTACTS,
                    ['householdid' => $household->id, 'direction' => 'note', 'entryid' => 0],
                    'id ASC',
                    'id, body'
                ) as $line
            ) {
                if (in_array(trim((string) $line->body), $words, true)) {
                    $note->contactid = (int) $line->id;
                    break;
                }
            }
        }
        $note->seen = time();
        $DB->update_record(self::T, $note);
        if ((int) $note->replacedby) {
            $by = $DB->get_record(self::T, ['id' => $note->replacedby]);
            // Its hour came later, on the same day: that one stays, whatever is sent again.
            if (!$by || !self::gave_hour($note, $by)) {
                self::restore($note, $by ?: null);
            }
        }
        return $note;
    }

    /**
     * Whether a visit is the same one as another, with the hour it did not have.
     *
     * @param \stdClass $old
     * @param \stdClass $new
     * @return bool
     */
    protected static function gave_hour(\stdClass $old, \stdClass $new): bool {
        $zone = \core_date::get_server_timezone_object();
        $day = fn(int $t) => (new \DateTime('@' . $t))->setTimezone($zone)->format('Ymd');
        return $old->dayonly && !$new->dayonly && $day((int) $old->visit) === $day((int) $new->visit);
    }

    /**
     * The visit the sheet gives now takes the place of the one it gave before in the same row: its hour was added, or
     * its day changed and the old one no longer comes when the whole sheet is sent again. The team reads the new one,
     * with the day it had before, and the old line says it was before.
     *
     * @param \stdClass $visit The visit as the sheet gives it now.
     * @param \stdClass $household
     * @param bool $whole Whether the whole sheet is being sent again.
     * @param string $lang
     */
    protected static function supersede(\stdClass $visit, \stdClass $household, bool $whole, string $lang): void {
        global $DB;
        if ((int) $visit->replacedby) {
            return;
        }
        $others = $DB->get_records_select(
            self::T,
            'householdid = ? AND entryid = ? AND visit > 0 AND id <> ? AND replacedby = 0',
            [$household->id, $visit->entryid, $visit->id],
            'id ASC'
        );
        // A visit new in the sheet takes at once the place of the one this sending has not brought yet (which comes back if
        // it does); later, only of one that missed a whole night.
        $missed = time() - ((int) $visit->timecreated > time() - pulse::NIGHT ? pulse::APART : pulse::NIGHT);
        foreach ($others as $old) {
            if (!self::gave_hour($old, $visit) && !($whole && (int) $old->seen < $missed)) {
                continue;
            }
            $str = fn(string $id, $a = null) => get_string_manager()->get_string($id, self::C, $a, $lang);
            $words = fn(\stdClass $note) => userdate((int) $note->visit, get_string_manager()->get_string(
                'strftimedaydate',
                'langconfig',
                null,
                $lang
            )) . ($note->dayonly ? '' : ', ' . userdate((int) $note->visit, '%H:%M', 99, false));
            $line = $old->contactid ? $DB->get_record(households::CONTACTS, ['id' => $old->contactid]) : null;
            if ($line) {
                $old->body = (string) ($old->body ?? '') !== '' ? $old->body : (string) $line->body;
                $DB->set_field(households::CONTACTS, 'body', $str('teamnote_visit_was', $old->body), ['id' => $line->id]);
            }
            $old->replacedby = (int) $visit->id;
            $DB->update_record(self::T, $old);
            $now = $visit->contactid ? $DB->get_record(households::CONTACTS, ['id' => $visit->contactid]) : null;
            if ($now) {
                $visit->body = (string) ($visit->body ?? '') !== '' ? $visit->body : (string) $now->body;
                $DB->set_field(households::CONTACTS, 'body', $str('teamnote_visit_changed', (object) ['now' => $visit->body,
                    'was' => $words($old)]), ['id' => $now->id]);
                $DB->update_record(self::T, $visit);
            }
            // What was promised for that day goes with it.
            $promise = attend::open_promise((int) $household->id);
            $texts = [self::visit_promise((int) $old->visit, true, $lang), self::visit_promise((int) $old->visit, false, $lang)];
            if ($promise && in_array((string) $promise->body, $texts, true)) {
                $due = (new \DateTime('@' . $visit->visit))->setTimezone(\core_date::get_user_timezone_object())->setTime(0, 0);
                $DB->update_record(attend::PROMISES, (object) ['id' => $promise->id, 'dueday' => $due->getTimestamp(),
                    'body' => self::visit_promise((int) $visit->visit, !$visit->dayonly, $lang)]);
            }
        }
    }

    /**
     * A visit that was taken for replaced and comes again with the sheet: both are in it, so both are as they were.
     *
     * @param \stdClass $note
     * @param \stdClass|null $by The one that took its place.
     */
    protected static function restore(\stdClass $note, ?\stdClass $by): void {
        global $DB;
        if ($note->contactid && (string) ($note->body ?? '') !== '') {
            $DB->set_field(households::CONTACTS, 'body', $note->body, ['id' => $note->contactid]);
        }
        $note->body = null;
        $note->replacedby = 0;
        $DB->update_record(self::T, $note);
        if ($by && !$DB->record_exists(self::T, ['replacedby' => $by->id]) && (string) ($by->body ?? '') !== '') {
            $DB->set_field(households::CONTACTS, 'body', $by->body, ['id' => $by->contactid]);
            $by->body = null;
            $DB->update_record(self::T, $by);
        }
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
