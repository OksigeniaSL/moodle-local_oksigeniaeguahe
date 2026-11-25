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
        $all = array_values(facts::everyone($centre, $now));
        // The campaign under way and the imported ones: each family once, the first time it came.
        $new = array_values(array_filter($all, fn($f) => $f['group'] === 'campaign'));
        $live = panel_metrics::campaigns($centre, $now)['live'];
        $mineoflive = facts::families($centre, $live, $now);
        $length = $to - $from;
        $back = $kind === 'hot' ? $length : 0;
        // A period under way is compared with the same point of the one before, never with it whole.
        $sofar = $now < $to;
        $end = min($to, $now);
        $year = self::year_before($from);
        $covered = self::covered($id, $new, $year, $from);
        $mine = self::window($centre, $kind, $all, $from, $end);
        $before = $back ? self::window($centre, $kind, $all, panel_metrics::days_after($from, -7), panel_metrics::days_after($end, -7)) : null;
        $ago = self::window($centre, $kind, $all, $year, self::year_before($end));
        $figures = [];
        foreach (self::FIGURES as $key) {
            $compare = $before ? $before[$key] : ($covered[$key] ? $ago[$key] : null);
            // A balance by the list is never set against one without it.
            if ($key === 'net' && $compare !== null && ($before ?? $ago)['listed'] !== $mine['listed']) {
                $compare = null;
            }
            $figures[] = [
                'key' => $key,
                'value' => $key === 'net' ? self::signed($mine[$key]) : (string) $mine[$key],
                'n' => $mine[$key],
                'label' => get_string('report_fig_' . $key . ($key === 'net' ? ($mine['listed'] ? '_list' : '') :
                    ($mine[$key] === 1 ? '_one' : '')), $c),
                'note' => $key === 'enrolled' && $mine['notes'] !== '' ? $mine['notes'] : ($key === 'arrived' && $mine['undated'] ?
                    get_string('report_arrived_undated' . ($mine['undated'] === 1 ? '_one' : ''), $c, $mine['undated']) : ($key === 'came' && $mine['noday'] ?
                    get_string('report_came_noday' . ($mine['noday'] === 1 ? '_one' : ''), $c, $mine['noday']) : false)),
                'before' => $compare === null ? false : ($key === 'net' ? self::signed($compare) : (string) $compare),
                'beforelabel' => $compare === null ? false : get_string('report_before_' . ($kind === 'hot' ? 'week' : 'year') .
                    ($sofar ? '_sofar' : ''), $c),
                'ago' => $before && $covered[$key] && ($key !== 'net' || $ago['listed'] === $mine['listed']) ?
                    ($key === 'net' ? self::signed($ago[$key]) : (string) $ago[$key]) : false,
                'agolabel' => $before && $covered[$key] ? get_string('report_ago', $c) : false,
                'trend' => $compare === null ? false : self::trend($mine[$key], $compare),
            ];
        }
        $ids = fn(int $a, int $b) => facts::ids(array_filter($new, fn($f) => facts::lives($f) &&
            $f['first'] >= $a && $f['first'] < $b));
        $response = panel_metrics::said(metrics::response($id, $ids($from, $end), $now));
        $responsebefore = panel_metrics::said(metrics::response($id, $ids($from - max($length, 4 * WEEKSECS), $from), $now));
        $cifras = panel_metrics::context($centre, ['campaign' => 'live', 'compare' => 1], false, $now);
        // The channels in time are already in Cifras: not counted twice.
        $channels = $cifras['channelstime'] ?? panel_channels::context($centre, $now);
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
            'response' => $response['asked'] ? self::response_words($response, $responsebefore) : false,
            'attention' => $kind === 'hot' ? self::attention($centre, $cifras, $now) : false,
            'funnel' => self::funnel($cifras),
            'pacefrom' => $kind === 'hot' ? panel_metrics::days_after($to, -7 * self::WEEKS) : $from,
            'pace' => $kind === 'hot' ? self::weeks($new, $to, $covered['arrived']) : self::months($new, $from, $to, $now),
            'channels' => self::channels($channels),
            'places' => self::places($cifras),
            'acts' => self::acts($id, $from, $to),
            'voices' => self::voices($id, $new, $from, $to),
            'bychannel' => self::by_channel($centre, $all, $from, $to),
            'kinds' => panel_metrics::kinds_sentence(facts::read($centre, $live, $now), $live, get_string('report_kinds_now', $c)),
            // How the families of the campaign under way decide, so far.
            'decide' => decide::summary(
                $centre,
                $mineoflive,
                (int) $live['since'],
                $end
            ),
            // Those that go, of the campaign under way, and the contacts that were not families.
            'outs' => outs::summary(
                $centre,
                $mineoflive,
                (int) $live['since']
            ) ?: false,
            // Those that left in the course of the report, whichever school year they were enrolled for.
            'gone' => $kind === 'cold' ? array_intersect_key(
                panel_metrics::gone($centre, $from, $end, panel_metrics::course_name($from)),
                ['text' => 1, 'reasons' => 1]
            ) : false,
            'started' => $kind === 'cold' && $cifras['started'] ? ['text' => $cifras['started']['text']] : false,
            'referred' => $cifras['referred'] ? ['text' => $cifras['referred']['text']] : false,
            'forecast' => $kind === 'cold' ? self::forecast($centre) : false,
            'grow' => $kind === 'cold' ? self::grow($centre) : false,
        ];
        // Those of the sheet of the team carry the day of their visit: said by the chart that has them.
        $undated = facts::sum(array_filter($new, fn($f) => $f['first'] >= $data['pacefrom'] && $f['first'] < $to))['undated'];
        $data['pace']['note'] = $undated ? get_string('report_pace_undated', $c, $undated) : false;
        $data['advice'] = self::advice($centre, $data, $mine, $covered['arrived'] ? $ago : null, $channels, $now);
        $data['definitions'] = self::definitions($kind);
        return $data;
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
     * What happened in a period: the new families that came in, those that came to see the centre (on the day they came), the
     * children of new families enrolled, those that left and the balance. The children of families already of the centre are
     * said apart: they go on, the campaign did not bring them. With the list of the school year, the balance is its joins and
     * its leaves; without it, the enrolled of new families less those that left.
     *
     * @param \stdClass $centre
     * @param string $kind hot or cold.
     * @param array $all
     * @param int $from
     * @param int $to
     * @return array arrived, came, enrolled, ofcentre, left, net, listed (whether the balance is of the list) and notes
     */
    protected static function window(\stdClass $centre, string $kind, array $all, int $from, int $to): array {
        global $DB;
        $c = self::C;
        $inside = fn(int $time) => $time >= $from && $time < $to;
        $new = array_filter($all, fn($f) => $f['group'] === 'campaign');
        $enrolled = 0;
        $ofcentre = 0;
        $years = [];
        foreach ($all as $f) {
            foreach ($f['kids'] as $kid) {
                if ($kid['stage'] !== 'enrolled' || !$inside($kid['stagetime'])) {
                    continue;
                }
                // For the school year of the child: a family may be new for one and of the centre for the next.
                if (in_array(who::kind((int) $centre->id, (int) $f['id'], $kid['year']), who::CENTRE, true)) {
                    $ofcentre++;
                } else {
                    $enrolled++;
                    $years[$kid['year']] = ($years[$kid['year']] ?? 0) + 1;
                }
            }
        }
        $left = $DB->count_records_select(
            households::CHILDREN,
            "centreid = ? AND stage = 'enrolled' AND lefttime >= ? AND lefttime < ?",
            [$centre->id, max(1, $from), $to]
        );
        $net = $enrolled - $left;
        $listed = false;
        $year = panel_metrics::course_name(reports::current($centre, 'cold', $from)[0]);
        if (in_array($year, metrics::school_years((int) $centre->id), true)) {
            $m = metrics::movements((int) $centre->id, $year);
            if ($m['hasroll']) {
                $listed = true;
                $net = $kind === 'cold' ? (int) $m['totals']['net'] : array_sum(array_map(
                    fn($d) => $inside($d[0]) ? ($d[1] === 'joins' ? 1 : -1) : 0,
                    $m['dated']
                ));
            }
        }
        ksort($years);
        $notes = [];
        if (count($years) > 1) {
            foreach ($years as $of => $n) {
                $notes[] = get_string('report_enrolled_year' . ((string) $of === '' ? '_none' : ''), $c, (object) ['n' => $n, 'year' => $of]);
            }
        }
        if ($ofcentre) {
            $notes[] = get_string('report_enrolled_centre' . ($ofcentre === 1 ? '_one' : ''), $c, $ofcentre);
        }
        // Those that came and nothing says which day: in no period, and said.
        $noday = count(array_filter($new, fn($f) => $inside($f['first']) && !empty($f['came']) && !array_filter($f['visits'], fn($t) => $t > 0) &&
            facts::lives($f)));
        return ['noday' => $noday, 'undated' => count(array_filter($new, fn($f) => $inside($f['first']) && !$f['dated'])),
            'arrived' => count(array_filter($new, fn($f) => $inside($f['first']))),
            'came' => count(array_filter($new, fn($f) => (bool) array_filter($f['visits'], $inside))),
            'enrolled' => $enrolled, 'ofcentre' => $ofcentre, 'left' => $left, 'net' => $net, 'listed' => $listed,
            'notes' => implode('; ', $notes)];
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
        $text = get_string('report_sentence_' . $kind . ($mine['arrived'] ? ($mine['arrived'] === 1 ? '_one' : '') : '_none'), $c, $a);
        if ($before !== null) {
            $text .= ' ' . get_string('report_sentence_before_' . $kind . ($before['arrived'] === 1 ? '_one' : ''), $c, $a);
        }
        return $text;
    }

    /**
     * The first answer in a sentence, with those that still wait for it, and how it was in the weeks before.
     *
     * @param array $now From metrics::response().
     * @param array $before
     * @return array value, before, slower and text.
     */
    protected static function response_words(array $now, array $before): array {
        $both = $now['median'] !== null && $before['median'] !== null;
        $a = (object) ['value' => $now['median'] === null ? '' : panel_metrics::hours_words((float) $now['median']),
            'before' => $before['median'] === null ? '' : panel_metrics::hours_words((float) $before['median'])];
        return ['value' => $a->value, 'before' => $both ? $a->before : false,
            'slower' => $both && $now['median'] > 1.25 * $before['median'] && $now['median'] > 24,
            'text' => panel_metrics::response_sentence($now, true) . ($both ? ' ' . get_string('report_response_before', self::C, $a) : '')];
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
        $first = $DB->get_records_select(
            households::T,
            'centreid = ? AND waitingsince > 0',
            [$id],
            'waitingsince ASC, id ASC',
            'id, waitingsince, waitingdayonly',
            0,
            1
        );
        $first = reset($first);
        $oldest = $first ? (int) $first->waitingsince : 0;
        // Of a day without its hour, whole days.
        $since = $first && $first->waitingdayonly ? max(1, panel::days_between($oldest, $now)) * DAYSECS : $now - $oldest;
        $waiting = households::waiting_count($id);
        if ($waiting) {
            $late = $oldest && $now - $oldest > self::LATE;
            $out[] = ['kind' => 'reply', 'count' => $waiting, 'late' => $late, 'url' => $inbox->out(false),
                'text' => get_string('report_att_waiting' . ($waiting === 1 ? '_one' : ''), $c, (object) ['n' => $waiting,
                'since' => self::hours($since)])];
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
            // Only when there is something to expect by: without a campaign before, nothing is known of what may come.
            if (($course['high'] ?? false) !== false && $course['places'] && $course['high'] < $course['places']) {
                $out[] = ['kind' => 'course', 'count' => $course['places'] - $course['high'], 'late' => false,
                    'url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $id, 'pregunta' => 'places']))->out(false),
                    'text' => get_string('report_att_course' . ($course['places'] - $course['high'] === 1 ? '_one' : ''), $c, (object) [
                    'name' => $course['name'], 'free' => $course['places'] - $course['high']])];
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
     * @param array $all
     * @param int $to
     * @param bool $covered
     * @return array
     */
    protected static function weeks(array $all, int $to, bool $covered): array {
        $rows = [];
        for ($i = self::WEEKS; $i >= 1; $i--) {
            // Weeks of the calendar: the change of the hour never moves a day.
            $start = panel_metrics::days_after($to, -7 * $i);
            $end = panel_metrics::days_after($start, 7);
            $agostart = self::year_before($start);
            $rows[] = ['label' => self::day($start),
                'n' => count(array_filter($all, fn($f) => $f['first'] >= $start && $f['first'] < $end)),
                'before' => $covered ? count(array_filter($all, fn($f) => $f['first'] >= $agostart &&
                    $f['first'] < panel_metrics::days_after($agostart, 7))) : false];
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
        $told = $DB->get_records_sql_menu("SELECT eventid, COUNT(DISTINCT householdid) FROM {" . surveys::T . "}
                                            WHERE eventid $in GROUP BY eventid", $params);
        // Who the families of each act are: those signed up, those that came, whether anybody said who did, and those that only
        // came to it.
        $people = [];
        $marked = [];
        $guests = [];
        $rs = $DB->get_recordset_sql("SELECT s.id, s.eventid, s.householdid, s.attended, s.missed, h.guest, h.outreason
                                        FROM {" . events::SIGNUPS . "} s
                                        JOIN {" . households::T . "} h ON h.id = s.householdid
                                       WHERE s.eventid $in", $params);
        foreach ($rs as $r) {
            $marked[(int) $r->eventid] = !empty($marked[(int) $r->eventid]) || (int) $r->attended || (int) $r->missed;
            if ((int) $r->guest) {
                if (trim((string) $r->outreason) === '') {
                    $guests[(int) $r->eventid][(int) $r->householdid] = true;
                }
                continue;
            }
            $people[(int) $r->eventid][(int) $r->householdid] = !empty($people[(int) $r->eventid][(int) $r->householdid]) ||
                (int) $r->attended > 0;
        }
        $rs->close();
        $centre = centres::get($centreid);
        $rows = [];
        $own = ['n' => 0, 'came' => 0];
        foreach ($events as $event) {
            $mine = $people[(int) $event->id] ?? [];
            if ($event->tailored || (int) $event->householdid) {
                // What each family asked for, and the visits of its own.
                if ((int) $event->householdid && !(int) $event->cancelled) {
                    $own['n']++;
                    $own['came'] += array_filter($mine) ? 1 : 0;
                }
                continue;
            }
            if ((int) $event->cancelled) {
                continue;
            }
            $s = count($mine);
            $k = count(array_filter($mine));
            $only = count($guests[(int) $event->id] ?? []);
            $hour = userdate((int) $event->timestart, get_string('strftimetime', 'langconfig'));
            $when = self::day((int) $event->timestart) . ', ' . $hour;
            $past = $event->timestart < time();
            $nobody = $past && ($s || $only) && empty($marked[(int) $event->id]);
            $those = array_keys(array_filter($mine, fn($came) => $came || !$past));
            $year = facts::year_at($centre, (int) $event->timestart, facts::years($centreid));
            $who = who::sentence(who::count(array_map(fn($id) => ['who' => who::kind($centreid, $id, $year),
                'also' => who::kinds($centreid, $id, $year)], $those)), false, false);
            $said = array_filter([$nobody ? get_string('report_act_unmarked', $c) : ($who !== '' ? get_string($past ?
                'report_act_who_came' : 'report_act_who_signed', $c, $who) : ''), $only ? get_string('report_act_guests' .
                ($only === 1 ? '_one' : ''), $c, $only) : '']);
            $rows[] = ['name' => format_string($event->name), 'when' => $when, 'signed' => $s,
                'who' => $said ? implode(' ', $said) : false,
                'came' => $k, 'told' => (int) ($told[$event->id] ?? 0),
                // With fewer than ten, the two numbers at its side say it.
                'rate' => $s >= facts::FEW && $past && !$nobody ? get_string('report_rate', $c, (int) round(100 * $k / $s)) : false];
        }
        return ['rows' => $rows, 'own' => $own['n'] ? get_string(
            'report_acts_own' . ($own['n'] === 1 ? '_one' : ''),
            $c,
            (object) $own
        ) : false];
    }

    /**
     * Where the families of the period really came from, by channel and how far they went: the work of the centre
     * itself, what it paid for, and what was not measured, apart.
     *
     * @param \stdClass $centre
     * @param array $all
     * @param int $from
     * @param int $to
     * @return array|false sentence, blocks (name, rows with line), sheet (arrival::sheet()) and raw (for the AI).
     */
    protected static function by_channel(\stdClass $centre, array $all, int $from, int $to) {
        $c = 'local_oksigeniaeguahe';
        $inside = fn(string $group) => array_values(array_filter($all, fn($f) => $f['group'] === $group &&
            $f['first'] >= $from && $f['first'] < $to && ($f['voices']['channel'] ?? '') !== ''));
        $mine = $inside('campaign');
        // Those already of the centre that came in the period, set against the others.
        $theirs = $inside('centre');
        if (!$mine && !$theirs) {
            return false;
        }
        $basis = fn($f) => ['basis' => $f['voices']['basis'] ?? ''] + $f;
        $sum = arrival::by_channel(array_map($basis, $mine), array_map($basis, $theirs));
        // Each block with its name: the centre's own work, advertising, other ways, and what was not measured.
        $blocks = [];
        foreach ($sum['rows'] as $r) {
            $note = arrival::row_note($r);
            $blocks[$r['group']]['rows'][] = ['line' => get_string($r['n'] === 1 ? 'panel_bychannel_line_one' :
                'panel_bychannel_line', $c, (object) (array_diff_key($r, ['who' => 1]) + ['share' =>
                panel_metrics::share($r['enrolled'], $r['n'], true)])) . ($note !== '' ? ' ' . $note : '')];
        }
        $out = [];
        foreach (['own', 'paid', 'other', 'unmeasured'] as $group) {
            if (!empty($blocks[$group])) {
                $out[] = ['name' => get_string('panel_bychannel_group_' . $group, $c), 'rows' => $blocks[$group]['rows']];
            }
        }
        $raw = array_map(fn($r) => ['channel' => $r['name'], 'block' => $r['group'], 'families' => $r['n'],
            'came' => $r['visited'], 'applied' => $r['applied'], 'enrolled' => $r['enrolled'],
            'only from what they said' => $r['said'], 'left their contact in an ad later' => $r['adlater'],
            'really new' => $r['who']['new']['n'], 'really new that enrolled' => $r['who']['new']['enrolled'],
            'already of the centre, set apart' => who::centre($r['who']),
            'of them with a child in its nursery' => $r['who']['nursery']['all'],
            'of them of its staff' => $r['who']['staff']['all'],
            'had children at the centre before' => $r['who']['former']['n'],
            'of former students' => $r['who']['alumni']['n']], $sum['rows']);
        return ['sentence' => arrival::by_channel_text($sum), 'blocks' => $out, 'sheet' => arrival::sheet($sum), 'raw' => $raw];
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
                if (facts::lives($f)) {
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
        $next = current($DB->get_records_select(
            events::T,
            'centreid = ? AND tailored = 0 AND open = 1 AND timestart > ?',
            [$id, $now],
            'timestart ASC, id ASC',
            '*',
            0,
            1
        ));
        if ($next) {
            // Only those the invitation can reach: never whoever asked for no invitations, or not to be written to.
            $reachable = array_flip(array_map(fn($h) => (int) $h->id, invites::eligible($next)));
            $signed = array_flip($DB->get_fieldset_sql(
                "SELECT DISTINCT s.householdid
                   FROM {" . events::SIGNUPS . "} s
                   JOIN {" . events::T . "} v ON v.id = s.eventid
                  WHERE v.centreid = ? AND v.kind <> 'other'",
                [$id]
            ));
            $notyet = count(array_filter(
                facts::families($centre, facts::live($centre, $now), $now),
                fn($f) => $f['first'] >= $now - 6 * WEEKSECS && $f['step'] === 1 && !$f['closed'] && isset($reachable[$f['id']]) &&
                    !isset($signed[$f['id']])
            ));
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
