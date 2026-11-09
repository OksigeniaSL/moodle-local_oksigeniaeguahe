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
 * What a report says, worked out once and kept as it was: the period in a sentence and its big figures against the
 * period before and the same one a year ago, what asks for attention now, the way to the enrolment, the pace, where the
 * families come from, the places, the acts, what the families tell, why some leave, what may come, advice with its why,
 * and what each figure means. Always added up: never a score, a chance or a rank for a family.
 *
 * Hot («How it goes»): the last seven days, to correct on the way. Cold («The year, cold»): a whole school year, to
 * prepare the next one. The campaign never ends: everything is counted the whole year, and the campaigns only compare.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_data {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The big figures of the period. */
    const FIGURES = ['arrived', 'came', 'enrolled', 'net'];

    /** @var int The weeks of pace a hot report draws. */
    const WEEKS = 8;

    /** @var int How far ahead the acts that ask for attention are looked for. */
    const SOON = 14 * DAYSECS;

    /** @var float Below this share of its room, an act coming soon asks for attention. */
    const LOW = 0.3;

    /** @var int A family that waits longer than this asks for attention. */
    const LATE = 2 * DAYSECS;

    /** @var int The channels drawn, at most. */
    const CHANNELS = 6;

    /** @var int The advice given, at most. */
    const ADVICE = 5;

    /**
     * Everything a report says, in the language of the moment.
     *
     * @param \stdClass $centre
     * @param string $kind hot or cold.
     * @param int $from
     * @param int $to
     * @param int $now When it is made: what waits is what waits then.
     * @return array
     */
    public static function build(\stdClass $centre, string $kind, int $from, int $to, int $now): array {
        $c = self::C;
        $id = (int) $centre->id;
        $all = self::families($centre);
        $length = $to - $from;
        $back = $kind === 'hot' ? $length : 0;
        // A period under way is compared with the same point of the one before, never with it whole.
        $sofar = $now < $to;
        $end = min($to, $now);
        $year = self::year_before($from);
        $covered = self::covered($id, $all, $year, $from);
        $mine = self::window($id, $all, $from, $end);
        $before = $back ? self::window($id, $all, $from - $back, $end - $back) : null;
        $ago = self::window($id, $all, $year, self::year_before($end));
        $figures = [];
        foreach (self::FIGURES as $key) {
            $compare = $before ? $before[$key] : ($covered[$key] ? $ago[$key] : null);
            $figures[] = [
                'key' => $key,
                'value' => $key === 'net' ? self::signed($mine[$key]) : (string) $mine[$key],
                'n' => $mine[$key],
                'label' => get_string('report_fig_' . $key . ($mine[$key] === 1 || $mine[$key] === -1 ? '_one' : ''), $c),
                'before' => $compare === null ? false : ($key === 'net' ? self::signed($compare) : (string) $compare),
                'beforelabel' => $compare === null ? false : get_string('report_before_' . ($kind === 'hot' ? 'week' : 'year') .
                    ($sofar ? '_sofar' : ''), $c),
                'ago' => $before && $covered[$key] ? ($key === 'net' ? self::signed($ago[$key]) : (string) $ago[$key]) : false,
                'agolabel' => $before && $covered[$key] ? get_string('report_ago', $c) : false,
                'trend' => $compare === null ? false : self::trend($mine[$key], $compare),
            ];
        }
        $response = self::response($id, $from, $end);
        $responsebefore = self::response($id, $from - max($length, 4 * WEEKSECS), $from);
        $cifras = panel_metrics::context($centre, ['campaign' => 'live', 'compare' => 1], false, $now);
        $channels = panel_channels::context($centre, $now);
        $period = self::period($kind, $from, $to);
        $data = [
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'made' => $now,
            'title' => get_string('report_title_' . $kind, $c, $period),
            'period' => $period,
            'sofar' => $sofar ? get_string('report_sofar', $c, self::day($now)) : false,
            'sentence' => self::sentence($kind, $mine, $before ?? ($covered['arrived'] ? $ago : null)),
            'figures' => $figures,
            'response' => $response === null ? false : self::response_words($response, $responsebefore),
            'attention' => $kind === 'hot' ? self::attention($centre, $cifras, $now) : false,
            'funnel' => self::funnel($cifras),
            'pace' => $kind === 'hot' ? self::weeks($id, $all, $to, $covered['arrived']) : self::months($all, $from, $to, $now),
            'channels' => self::channels($channels),
            'places' => self::places($cifras),
            'acts' => self::acts($id, $from, $to),
            'voices' => self::voices($id, $all, $from, $to),
            'gone' => $kind === 'cold' && $cifras['gone'] ? ['text' => $cifras['gone']['text'],
                'reasons' => $cifras['gone']['reasons'] ?? []] : false,
            'started' => $kind === 'cold' && $cifras['started'] ? ['text' => $cifras['started']['text']] : false,
            'referred' => $cifras['referred'] ? ['text' => $cifras['referred']['text']] : false,
            'forecast' => $kind === 'cold' ? self::forecast($centre) : false,
            'grow' => $kind === 'cold' ? self::grow($centre) : false,
        ];
        $data['advice'] = self::advice($centre, $data, $mine, $covered['arrived'] ? $ago : null, $channels, $now);
        $data['definitions'] = self::definitions($kind);
        return $data;
    }

    /**
     * Every family the centre knows of a campaign, the one under way and the imported ones: when it came, how far it
     * got, when it enrolled and where it came from.
     *
     * @param \stdClass $centre
     * @return array
     */
    protected static function families(\stdClass $centre): array {
        $all = [];
        foreach (panel_metrics::campaigns($centre) as $campaign) {
            foreach (panel_metrics::families($centre, $campaign) as $family) {
                $all[] = $family + ['live' => !$campaign['import']];
            }
        }
        return $all;
    }

    /**
     * The same day a year before, at the same hour.
     *
     * @param int $time
     * @return int
     */
    protected static function year_before(int $time): int {
        $date = new \DateTime('@' . $time);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->modify('-1 year');
        return $date->getTimestamp();
    }

    /**
     * Which figures can be said for a year ago: only what Eguahe or an imported file counted then. What was not counted
     * is not said, never a zero.
     *
     * @param int $centreid
     * @param array $all
     * @param int $agofrom
     * @param int $from
     * @return bool[] Figure => whether it was counted.
     */
    protected static function covered(int $centreid, array $all, int $agofrom, int $from): array {
        global $DB;
        $firsts = array_column($all, 'first');
        $any = $firsts && min($firsts) <= $agofrom && count(array_filter($firsts, fn($t) => $t < $from)) > 0;
        $live = (int) $DB->get_field_sql('SELECT MIN(timecreated) FROM {' . households::T . '} WHERE centreid = ?', [$centreid]);
        $eguahe = $live && $live <= $agofrom;
        return ['arrived' => $any, 'came' => $eguahe, 'enrolled' => $eguahe, 'net' => $eguahe];
    }

    /**
     * What happened in a period: the families that came in, those that came to see the centre, the children enrolled,
     * those that left, the net, and the families that looked at their page.
     *
     * @param int $centreid
     * @param array $all
     * @param int $from
     * @param int $to
     * @return int[]
     */
    protected static function window(int $centreid, array $all, int $from, int $to): array {
        global $DB;
        $arrived = count(array_filter($all, fn($f) => $f['first'] >= $from && $f['first'] < $to));
        $came = (int) $DB->count_records_sql(
            'SELECT COUNT(DISTINCT s.householdid)
                                                FROM {' . events::SIGNUPS . '} s
                                                JOIN {' . events::T . '} e ON e.id = s.eventid
                                               WHERE e.centreid = ? AND s.attended >= ? AND s.attended < ?',
            [$centreid, $from, $to]
        );
        $enrolled = $DB->count_records_select(
            households::CHILDREN,
            "centreid = ? AND stage = 'enrolled' AND stagetime >= ? AND stagetime < ?",
            [$centreid, $from, $to]
        );
        $left = $DB->count_records_select(households::CHILDREN, 'centreid = ? AND lefttime >= ? AND lefttime < ?', [$centreid,
            $from, $to]);
        return ['arrived' => $arrived, 'came' => $came, 'enrolled' => $enrolled, 'left' => $left, 'net' => $enrolled - $left];
    }

    /**
     * A day said with its month: «2 de noviembre».
     *
     * @param int $time
     * @return string
     */
    public static function day(int $time): string {
        return trim(userdate($time, get_string('report_day_month', self::C)));
    }

    /**
     * A number with its sign: +3, −2, 0.
     *
     * @param int $n
     * @return string
     */
    protected static function signed(int $n): string {
        return $n > 0 ? '+' . $n : ($n < 0 ? '−' . abs($n) : '0');
    }

    /**
     * Whether a figure goes up, down or stays, against the one before.
     *
     * @param int $now
     * @param int $before
     * @return string sube, baja or igual.
     */
    protected static function trend(int $now, int $before): string {
        return $now > $before ? 'sube' : ($now < $before ? 'baja' : 'igual');
    }

    /**
     * The period said for people: «from 2 to 8 November» or «September 2026 to August 2027».
     *
     * @param string $kind
     * @param int $from
     * @param int $to
     * @return string
     */
    public static function period(string $kind, int $from, int $to): string {
        $c = self::C;
        $last = $to - 1;
        if ($kind === 'cold') {
            $format = get_string('strftimemonthyear', 'langconfig');
            return get_string('report_period_year', $c, (object) ['from' => userdate($from, $format),
                'to' => userdate($last, $format)]);
        }
        $day = userdate($from, '%Y-%m') === userdate($last, '%Y-%m') ? '%e' : get_string('report_day_month', $c);
        return get_string('report_period_week', $c, (object) ['from' => trim(userdate($from, $day)),
            'to' => trim(userdate($last, get_string('report_day_month', $c)))]);
    }

    /**
     * The period in a sentence: what came in and how far it got, against the period it is compared with.
     *
     * @param string $kind
     * @param int[] $mine
     * @param int[]|null $before
     * @return string
     */
    protected static function sentence(string $kind, array $mine, ?array $before): string {
        $c = self::C;
        $a = (object) ['arrived' => $mine['arrived'], 'came' => $mine['came'], 'enrolled' => $mine['enrolled'],
            'net' => self::signed($mine['net']), 'before' => $before['arrived'] ?? 0];
        if (!$mine['arrived'] && !$mine['came'] && !$mine['enrolled'] && !$mine['left']) {
            return get_string('report_sentence_quiet_' . $kind, $c);
        }
        $text = get_string('report_sentence_' . $kind . ($mine['arrived'] === 1 ? '_one' : ''), $c, $a);
        if ($before !== null) {
            $text .= ' ' . get_string('report_sentence_before_' . $kind . ($before['arrived'] === 1 ? '_one' : ''), $c, $a);
        }
        return $text;
    }

    /**
     * Half the families that came in in a period had their first answer within this time (seconds); null without any.
     *
     * @param int $centreid
     * @param int $from
     * @param int $to
     * @return float|null
     */
    protected static function response(int $centreid, int $from, int $to): ?float {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT i.householdid, MIN(i.timecreated) AS asked,
                                             (SELECT MIN(o.timecreated) FROM {" . households::CONTACTS . "} o
                                               WHERE o.householdid = i.householdid AND o.direction = 'out'
                                                 AND o.timecreated >= MIN(i.timecreated)) AS answered
                                        FROM {" . households::CONTACTS . "} i
                                       WHERE i.centreid = :centreid AND i.direction = 'in' AND i.channel <> 'page'
                                    GROUP BY i.householdid
                                      HAVING MIN(i.timecreated) >= :since AND MIN(i.timecreated) < :until",
            ['centreid' => $centreid, 'since' => $from, 'until' => $to]
        );
        $times = [];
        foreach ($rows as $row) {
            if ($row->answered) {
                $times[] = (int) $row->answered - (int) $row->asked;
            }
        }
        if (!$times) {
            return null;
        }
        sort($times);
        $n = count($times);
        return $n % 2 ? (float) $times[intdiv($n, 2)] : ($times[$n / 2 - 1] + $times[$n / 2]) / 2;
    }

    /**
     * The first answer in a sentence: half the families had it within this time, and in the weeks before.
     *
     * @param float $now
     * @param float|null $before
     * @return array value, before, slower and text.
     */
    protected static function response_words(float $now, ?float $before): array {
        $a = (object) ['value' => self::hours($now), 'before' => $before === null ? '' : self::hours($before)];
        return ['value' => $a->value, 'before' => $before === null ? false : $a->before,
            'slower' => $before !== null && $now > 1.25 * $before && $now > DAYSECS,
            'text' => get_string('report_response' . ($before === null ? '' : '_before'), self::C, $a)];
    }

    /**
     * A time said in hours, or in days when it is long.
     *
     * @param float $seconds
     * @return string
     */
    protected static function hours(float $seconds): string {
        $c = self::C;
        $hours = $seconds / HOURSECS;
        if ($hours < 1) {
            return get_string('report_minutes', $c, max(1, (int) round($seconds / MINSECS)));
        }
        if ($hours < 48) {
            $n = (int) round($hours);
            return get_string($n === 1 ? 'report_hour' : 'report_hours', $c, $n);
        }
        return get_string('report_days', $c, (int) round($hours / 24));
    }

    /**
     * What asks for attention now, each with the view of the inbox that solves it: families that wait, what was
     * promised and is due, acts coming soon with little or no room, courses that may not fill.
     *
     * @param \stdClass $centre
     * @param array $cifras
     * @param int $now
     * @return array
     */
    protected static function attention(\stdClass $centre, array $cifras, int $now): array {
        global $DB;
        $c = self::C;
        $id = (int) $centre->id;
        $inbox = new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $id]);
        $out = [];
        $oldest = (int) $DB->get_field_sql('SELECT MIN(waitingsince) FROM {' . households::T . '}
                                             WHERE centreid = ? AND waitingsince > 0', [$id]);
        $waiting = households::waiting_count($id);
        if ($waiting) {
            $late = $oldest && $now - $oldest > self::LATE;
            $out[] = ['kind' => 'reply', 'count' => $waiting, 'late' => $late, 'url' => $inbox->out(false),
                'text' => get_string('report_att_waiting' . ($waiting === 1 ? '_one' : ''), $c, (object) ['n' => $waiting,
                'since' => self::hours($now - $oldest)])];
        }
        $due = count(attend::due($id, $now));
        if ($due) {
            $out[] = ['kind' => 'promise', 'count' => $due, 'late' => true, 'url' => $inbox->out(false),
                'text' => get_string('report_att_promised' . ($due === 1 ? '_one' : ''), $c, $due)];
        }
        $soon = $DB->get_records_select(
            events::T,
            'centreid = ? AND tailored = 0 AND open = 1 AND timestart >= ? AND timestart < ?',
            [$id, $now, $now + self::SOON],
            'timestart ASC'
        );
        foreach ($soon as $event) {
            $people = events::people((int) $event->id);
            $capacity = (int) $event->capacity;
            if ($capacity && $people >= $capacity) {
                $key = 'full';
            } else if ($capacity && $people < self::LOW * $capacity) {
                $key = 'low';
            } else {
                continue;
            }
            $out[] = ['kind' => 'act', 'count' => $people, 'late' => $key === 'low',
                'url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['c' => $id, 'id' => $event->id]))->out(false),
                'text' => get_string('report_att_act_' . $key, $c, (object) ['name' => format_string($event->name),
                'when' => self::day((int) $event->timestart), 'n' => $people,
                'room' => $capacity])];
        }
        foreach ($cifras['places'] ?: [] as $course) {
            if (isset($course['high'], $course['places']) && $course['places'] && $course['high'] < $course['places']) {
                $out[] = ['kind' => 'course', 'count' => $course['places'] - $course['high'], 'late' => false,
                    'url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $id]))->out(false),
                    'text' => get_string('report_att_course', $c, (object) ['name' => $course['name'],
                    'free' => $course['places'] - $course['high']])];
            }
        }
        return $out;
    }

    /**
     * The way to the enrolment, from the numbers of Cifras: each stop with the campaign before at the same height.
     *
     * @param array $cifras
     * @return array|false
     */
    protected static function funnel(array $cifras) {
        $reach = $cifras['reach'] ?? null;
        if (!$reach) {
            return false;
        }
        $steps = [];
        foreach ($reach['steps'] as $step) {
            $steps[] = ['name' => $step['name'], 'n' => (int) $step['n'], 'before' => !empty($step['hasghost']) ?
                (int) $step['gn'] : false, 'line' => $step['line']];
        }
        return ['sentence' => $reach['rest'], 'steps' => $steps, 'def' => $reach['def'], 'compare' => !empty($reach['compare'])];
    }

    /**
     * The families that came in each of the last weeks, and in the same weeks a year before when they were counted.
     *
     * @param int $centreid
     * @param array $all
     * @param int $to
     * @param bool $covered
     * @return array
     */
    protected static function weeks(int $centreid, array $all, int $to, bool $covered): array {
        $rows = [];
        for ($i = self::WEEKS; $i >= 1; $i--) {
            $start = $to - $i * WEEKSECS;
            $end = $start + WEEKSECS;
            $agostart = self::year_before($start);
            $rows[] = ['label' => self::day($start),
                'n' => count(array_filter($all, fn($f) => $f['first'] >= $start && $f['first'] < $end)),
                'before' => $covered ? count(array_filter($all, fn($f) => $f['first'] >= $agostart &&
                    $f['first'] < $agostart + WEEKSECS)) : false];
        }
        return self::scaled($rows, get_string('report_pace_weeks', self::C), $covered);
    }

    /**
     * The families that came in each month of the year, summer and September too, and in the same month a year before.
     *
     * @param array $all
     * @param int $from
     * @param int $to
     * @param int $now The months still to come are marked, with the year before at sight.
     * @return array
     */
    protected static function months(array $all, int $from, int $to, int $now): array {
        $rows = [];
        $date = new \DateTime('@' . $from);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $agofrom = self::year_before($from);
        $covered = $all && min(array_column($all, 'first')) <= $agofrom;
        while ($date->getTimestamp() < $to) {
            $start = $date->getTimestamp();
            $date->modify('+1 month');
            $end = min($to, $date->getTimestamp());
            $agostart = self::year_before($start);
            $agoend = self::year_before($end);
            $future = $start >= $now;
            $rows[] = ['label' => userdate($start, '%b'), 'name' => userdate($start, '%B'), 'future' => $future,
                'n' => $future ? false : count(array_filter($all, fn($f) => $f['first'] >= $start && $f['first'] < $end)),
                'before' => $covered ? count(array_filter($all, fn($f) => $f['first'] >= $agostart && $f['first'] < $agoend)) :
                    false];
        }
        return self::scaled($rows, get_string('report_pace_months', self::C), $covered);
    }

    /**
     * Rows of a chart on one scale for now and before, with the busiest one named.
     *
     * @param array $rows
     * @param string $title
     * @param bool $covered
     * @return array
     */
    protected static function scaled(array $rows, string $title, bool $covered): array {
        $top = max(array_merge([1], array_column($rows, 'n'), array_filter(array_column($rows, 'before'), 'is_int')));
        foreach ($rows as $i => $row) {
            $rows[$i]['v'] = $row['n'] === false ? false : round($row['n'] / $top, 3);
            $rows[$i]['future'] = $row['future'] ?? false;
            $rows[$i]['bv'] = $row['before'] === false ? false : round($row['before'] / $top, 3);
        }
        $busiest = $rows;
        usort($busiest, fn($x, $y) => $y['n'] <=> $x['n']);
        return ['title' => $title, 'rows' => $rows, 'compare' => $covered, 'total' => array_sum(array_column($rows, 'n')),
            'busiest' => $busiest && $busiest[0]['n'] ? ($busiest[0]['name'] ?? $busiest[0]['label']) : false];
    }

    /**
     * Where the families heard of the centre and where they came in by, from «Where from, month by month»: the
     * channels with the most, each with the year before and whether it goes up or down.
     *
     * @param array|false $channels
     * @return array|false
     */
    protected static function channels($channels) {
        if (!$channels) {
            return false;
        }
        $out = [];
        foreach ($channels['voices'] as $voice) {
            if (!in_array($voice['key'], ['know', 'arrive'], true)) {
                continue;
            }
            $rows = [];
            foreach (array_slice($voice['rows'], 0, self::CHANNELS) as $row) {
                $rows[] = ['name' => $row['name'], 'group' => $row['group'] ?: false, 'n' => (int) $row['total'],
                    'before' => (int) $row['before'], 'trend' => $row['trend']];
            }
            $out[] = ['key' => $voice['key'], 'name' => $voice['name'], 'rows' => $rows];
        }
        return $out ?: false;
    }

    /**
     * The places of each course this year, from Cifras: how many are taken and what may come.
     *
     * @param array $cifras
     * @return array|false
     */
    protected static function places(array $cifras) {
        $out = [];
        foreach ($cifras['places'] ?: [] as $course) {
            $out[] = ['name' => $course['name'], 'free' => $course['free'], 'sentence' => $course['sentence'],
                'taken' => (int) ($course['taken'] ?? 0), 'places' => (int) ($course['places'] ?? 0),
                'low' => $course['low'] ?? false, 'high' => $course['high'] ?? false];
        }
        return $out ?: false;
    }

    /**
     * The acts of the period: who signed up, who came and how many told how it went; the visits of their own, together.
     *
     * @param int $centreid
     * @param int $from
     * @param int $to
     * @return array|false
     */
    protected static function acts(int $centreid, int $from, int $to) {
        global $DB;
        $c = self::C;
        $events = $DB->get_records_select(
            events::T,
            'centreid = ? AND timestart >= ? AND timestart < ?',
            [$centreid, $from, $to],
            'timestart ASC'
        );
        if (!$events) {
            return false;
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($events));
        $signed = $DB->get_records_sql_menu("SELECT eventid, COUNT(DISTINCT householdid) FROM {" . events::SIGNUPS . "}
                                              WHERE eventid $in GROUP BY eventid", $params);
        $came = $DB->get_records_sql_menu("SELECT eventid, COUNT(DISTINCT householdid) FROM {" . events::SIGNUPS . "}
                                            WHERE eventid $in AND attended > 0 GROUP BY eventid", $params);
        $told = $DB->get_records_sql_menu("SELECT eventid, COUNT(DISTINCT householdid) FROM {" . surveys::T . "}
                                            WHERE eventid $in GROUP BY eventid", $params);
        $rows = [];
        $own = ['n' => 0, 'came' => 0];
        foreach ($events as $event) {
            if ($event->tailored) {
                $own['n']++;
                $own['came'] += (int) ($came[$event->id] ?? 0) ? 1 : 0;
                continue;
            }
            $s = (int) ($signed[$event->id] ?? 0);
            $k = (int) ($came[$event->id] ?? 0);
            $hour = userdate((int) $event->timestart, get_string('strftimetime', 'langconfig'));
            $when = self::day((int) $event->timestart) . ', ' . $hour;
            $rows[] = ['name' => format_string($event->name), 'when' => $when, 'signed' => $s,
                'came' => $k, 'told' => (int) ($told[$event->id] ?? 0),
                'rate' => $s && $event->timestart < time() ? get_string('report_rate', $c, (int) round(100 * $k / $s)) : false];
        }
        return ['rows' => $rows, 'own' => $own['n'] ? get_string(
            'report_acts_own' . ($own['n'] === 1 ? '_one' : ''),
            $c,
            (object) $own
        ) : false];
    }

    /**
     * What the families tell, counted: how they heard of the centre and what they like doing, of those that came in in
     * the period. The themes of what they write are the AI's, apart.
     *
     * @param int $centreid
     * @param array $all
     * @param int $from
     * @param int $to
     * @return array|false
     */
    protected static function voices(int $centreid, array $all, int $from, int $to) {
        $heard = [];
        $ids = [];
        foreach ($all as $f) {
            if ($f['first'] >= $from && $f['first'] < $to) {
                if ($f['voices']['said'] !== '') {
                    $heard[$f['voices']['said']] = ($heard[$f['voices']['said']] ?? 0) + 1;
                }
                if (!empty($f['live'])) {
                    $ids[] = (int) $f['id'];
                }
            }
        }
        arsort($heard);
        $counts = hobbies::counts($ids);
        $likes = [];
        foreach (array_slice($counts['list'], 0, 6, true) as $key => $n) {
            $likes[] = ['name' => hobbies::name((string) $key), 'n' => $n];
        }
        $out = ['heard' => array_map(
            fn($name, $n) => ['name' => $name, 'n' => $n],
            array_keys(array_slice($heard, 0, 6, true)),
            array_slice($heard, 0, 6, true)
        ), 'likes' => $likes, 'told' => $counts['told'], 'adults' => $counts['adults']];
        return $out['heard'] || $out['likes'] ? $out : false;
    }

    /**
     * What may come in the next years, course by course, from the forecast: in ranges, with the places as the ceiling.
     *
     * @param \stdClass $centre
     * @return array|false
     */
    protected static function forecast(\stdClass $centre) {
        $out = [];
        foreach (forecast::by_grade($centre) as $grade) {
            $years = [];
            foreach ($grade['ahead'] as $row) {
                if ($row['low'] !== null) {
                    $a = (object) ['year' => $row['year'], 'low' => $row['low'], 'high' => $row['high'],
                        'places' => $row['places']];
                    $years[] = (array) $a + ['text' => get_string(
                        'report_forecast_year' . ($row['places'] ? '_places' : ''),
                        self::C,
                        $a
                    )];
                }
            }
            if ($years) {
                $out[] = ['grade' => format_string($grade['grade']), 'years' => $years];
            }
        }
        return $out ?: false;
    }

    /**
     * Where the centre can grow: the municipalities with the most children of its ages and the least of them at the
     * centre.
     *
     * @param \stdClass $centre
     * @return array|false
     */
    protected static function grow(\stdClass $centre) {
        $list = array_values(array_filter(forecast::reach($centre), fn($r) => $r['share'] !== null && $r['children'] >= 50));
        usort($list, fn($x, $y) => [$x['share'], -$x['children']] <=> [$y['share'], -$y['children']]);
        return $list ? array_map(
            fn($r) => ['name' => $r['name'], 'children' => $r['children'], 'students' => $r['students'],
            'share' => format_float($r['share'], 1), 'line' => get_string('report_grow_line', self::C, (object) [
            'children' => $r['children'], 'students' => $r['students'], 'share' => format_float($r['share'], 1)])],
            array_slice($list, 0, 3)
        ) : false;
    }

    /**
     * Advice from fixed rules, each with its why and, where it can, the place that puts it to work. The AI of the
     * centre adds its own apart; each says where it comes from.
     *
     * @param \stdClass $centre
     * @param array $data
     * @param int[] $mine
     * @param int[]|null $ago
     * @param array|false $channels
     * @param int $now
     * @return array
     */
    protected static function advice(\stdClass $centre, array $data, array $mine, ?array $ago, $channels, int $now): array {
        global $DB;
        $c = self::C;
        $id = (int) $centre->id;
        $out = [];
        $add = function (string $key, $a, ?\moodle_url $url) use (&$out, $c) {
            $out[] = ['key' => $key, 'text' => get_string('report_tip_' . $key, $c, $a),
                'why' => get_string('report_tip_' . $key . '_why', $c, $a), 'from' => 'rule',
                'url' => $url ? $url->out(false) : false];
        };
        $inbox = new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $id]);
        if ($data['response'] && $data['response']['slower']) {
            $add('faster', (object) $data['response'], $inbox);
        }
        if ($ago !== null && $ago['arrived'] >= 5 && $mine['arrived'] < 0.7 * $ago['arrived']) {
            $add('fewer', (object) ['n' => $mine['arrived'], 'before' => $ago['arrived']], new \moodle_url(
                '/local/oksigeniaeguahe/events.php',
                ['c' => $id]
            ));
        }
        // Families that wrote in the last weeks and have not come to see the centre yet, with an act ahead to invite them.
        $next = $DB->get_record_select(
            events::T,
            'centreid = ? AND tailored = 0 AND open = 1 AND timestart > ?',
            [$id, $now],
            '*',
            IGNORE_MULTIPLE
        );
        if ($next) {
            $notyet = (int) $DB->count_records_sql("SELECT COUNT(1) FROM {" . households::T . "} h
                                                     WHERE h.centreid = ? AND h.incentre = 0 AND h.timecreated >= ?
                                                       AND NOT EXISTS (SELECT 1 FROM {" . events::SIGNUPS . "} s
                                                                        WHERE s.householdid = h.id)
                                                       AND EXISTS (SELECT 1 FROM {" . households::CHILDREN . "} k
                                                                    WHERE k.householdid = h.id
                                                                      AND k.stage = 'inquiry')", [$id,
                $now - 6 * WEEKSECS]);
            if ($notyet >= 3) {
                $a = (object) ['n' => $notyet, 'name' => format_string($next->name), 'when' => self::day((int) $next->timestart)];
                $add('invite', $a, new \moodle_url('/local/oksigeniaeguahe/event.php', ['c' => $id, 'id' => $next->id]));
            }
            $volunteers = $DB->count_records_select(households::T, 'centreid = ? AND volunteer > 0', [$id]);
            if ($volunteers) {
                $add('volunteers', (object) ['n' => $volunteers, 'name' => format_string($next->name)], new \moodle_url(
                    '/local/oksigeniaeguahe/index.php',
                    ['c' => $id, 'voluntarias' => 1]
                ));
            }
        }
        foreach ($channels ? $channels['voices'] : [] as $voice) {
            if ($voice['key'] !== 'know') {
                continue;
            }
            foreach ($voice['rows'] as $row) {
                if ($row['trend'] === 'sube' && $row['total'] >= 3 && $row['before'] < $row['total']) {
                    $add('rising', (object) ['name' => $row['name'], 'n' => $row['total'], 'before' => $row['before']], null);
                    break;
                }
            }
            foreach ($voice['rows'] as $row) {
                if ($row['trend'] === 'baja' && $row['before'] >= 3) {
                    $add('falling', (object) ['name' => $row['name'], 'n' => $row['total'], 'before' => $row['before']], null);
                    break;
                }
            }
        }
        if ($data['kind'] === 'cold') {
            if ($data['gone'] && !empty($data['gone']['reasons'])) {
                $top = $data['gone']['reasons'][0];
                $add('leaving', (object) ['name' => \core_text::strtolower($top['name']), 'n' => $top['n']], null);
            }
            // The months between two school years, the last two and the first: families come then too, and someone has
            // to answer them.
            $rows = $data['pace']['rows'];
            $between = array_values(array_filter(
                array_merge(array_slice($rows, -2), array_slice($rows, 0, 1)),
                fn($r) => $r['n'] > 0
            ));
            $n = array_sum(array_column($between, 'n'));
            // Only of a school year that ended: half way, its first month is the campaign itself.
            if ($n >= 3 && empty($data['sofar'])) {
                $names = array_column($between, 'name');
                $last = array_pop($names);
                $add('between', (object) ['n' => $n, 'months' => $names ? get_string('andlist', $c, (object) [
                    'list' => implode(', ', $names), 'last' => $last]) : $last], null);
            }
            if ($data['grow']) {
                $add('grow', (object) $data['grow'][0], new \moodle_url('/local/oksigeniaeguahe/forecast.php', ['c' => $id]));
            }
        }
        return array_slice($out, 0, self::ADVICE);
    }

    /**
     * What each figure means, at sight.
     *
     * @param string $kind
     * @return array
     */
    protected static function definitions(string $kind): array {
        $c = self::C;
        $out = [];
        foreach (array_merge(self::FIGURES, ['response', 'range']) as $key) {
            $out[] = ['term' => get_string('report_def_' . $key, $c), 'text' => get_string('report_def_' . $key . '_text', $c)];
        }
        $out[] = ['term' => get_string('report_def_compare', $c), 'text' => get_string('report_def_compare_' . $kind, $c)];
        return $out;
    }
}
