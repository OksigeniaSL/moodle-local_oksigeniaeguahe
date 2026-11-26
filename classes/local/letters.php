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
 * The letters of the acts, all together: when each went and when its family reacted, by the hour and the day it went, to
 * learn when it is best to send them. A letter counts as opened or not only once it has had two days; a time only with five
 * families behind; a comparison only between hours with ten letters each.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class letters {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int What a letter has to open: whether it was, is said only after it. */
    const WAIT = 2 * DAYSECS;

    /** @var int A reaction later than this is not one to the letter. */
    const LATE = 7 * DAYSECS;

    /** @var int The hours, two by two. */
    const SPAN = 2;

    /** @var int The families that reacted behind a time. */
    const HALF = 5;

    /** @var int The letters that had their two days behind each hour or day compared. */
    const FAIR = 10;

    /** @var int Two hours or days are said to differ from this many points. */
    const APART = 10;

    /**
     * Whether a row of the invites is a letter that went: a family that answered without one (a letter sent by hand, or the
     * one tried on) has one made at that moment, opened and answered at once.
     *
     * @param \stdClass $row timecreated, opened, replied.
     * @return bool
     */
    public static function went(\stdClass $row): bool {
        $sent = (int) $row->timecreated;
        return $sent > 0 && !((int) $row->opened === $sent && (int) ($row->replied ?? 0) === $sent);
    }

    /**
     * When a family first reacted to its letter (opened it or answered), if it did within a week.
     *
     * @param \stdClass $row timecreated, opened, replied, reply.
     * @return int|null Seconds after it went.
     */
    public static function reaction(\stdClass $row): ?int {
        $sent = (int) $row->timecreated;
        $replied = in_array((string) ($row->reply ?? ''), ['yes', 'no'], true) ? (int) $row->replied : 0;
        $first = array_filter([(int) $row->opened, $replied], fn($t) => $t >= $sent && $t > 0);
        if (!$sent || !$first || min($first) - $sent > self::LATE) {
            return null;
        }
        return min($first) - $sent;
    }

    /**
     * The letters of the acts of a centre that went by mail between two moments, with what came of them.
     *
     * @param \stdClass $centre
     * @param int $since
     * @param int $until 0 for until now.
     * @param int $now
     * @return array sent, back, arrived, opened, yes, ripe, ripeopened, half, byhour and byday (rows of each, with its key
     *     and name), best and worst (of the hours), daybest and dayworst, and first (the time the first went).
     */
    public static function figures(\stdClass $centre, int $since, int $until, int $now): array {
        global $DB;
        $until = $until ?: $now;
        $rows = array_values(array_filter($DB->get_records_select(
            invites::T,
            'centreid = ? AND channel = ? AND timecreated >= ? AND timecreated <= ? AND ' . households::NOTSAMPLE,
            [$centre->id, 'email', $since, $until],
            'timecreated ASC, id ASC',
            'id, eventid, householdid, opened, reply, replied, timecreated'
        ), [self::class, 'went']));
        $back = self::back($rows);
        $all = self::empty();
        $byhour = [];
        $byday = [];
        $zone = \core_date::get_user_timezone_object();
        foreach ($rows as $row) {
            if (isset($back[(int) $row->id])) {
                $all['sent']++;
                $all['back']++;
                continue;
            }
            // The hour and the day it went, where the team is.
            $went = (new \DateTime('@' . (int) $row->timecreated))->setTimezone($zone);
            $hour = (int) $went->format('G');
            $span = $hour - $hour % self::SPAN;
            $day = (int) $went->format('N');
            $byhour[$span] = $byhour[$span] ?? self::empty();
            $byday[$day] = $byday[$day] ?? self::empty();
            self::add($all, $row, $now);
            self::add($byhour[$span], $row, $now);
            self::add($byday[$day], $row, $now);
        }
        $out = self::finish($all) + ['first' => $rows ? (int) $rows[0]->timecreated : 0];
        ksort($byhour);
        $out['byhour'] = [];
        foreach ($byhour as $span => $one) {
            $out['byhour'][] = self::finish($one) + ['key' => $span, 'name' => self::span($span)];
        }
        $out['byday'] = [];
        foreach (self::week() as $day => $name) {
            if (isset($byday[$day])) {
                $out['byday'][] = self::finish($byday[$day]) + ['key' => $day, 'name' => $name];
            }
        }
        [$out['best'], $out['worst']] = self::ends($out['byhour']);
        [$out['daybest'], $out['dayworst']] = self::ends($out['byday']);
        return $out;
    }

    /**
     * The answer of the question, in a sentence or two: how many went and opened, and the hour they open best, when there are
     * letters enough to say it.
     *
     * @param array $f From figures().
     * @return string Empty with no letter.
     */
    public static function sentence(array $f): string {
        $c = self::C;
        if (!$f['sent']) {
            return '';
        }
        if (!$f['ripe']) {
            $out = [get_string($f['sent'] === 1 ? 'letters_sent_one' : 'letters_sent', $c, $f['sent']) . ($f['back'] ?
                get_string($f['back'] === 1 ? 'letters_back_one' : 'letters_back', $c, $f['back']) : '') . '.',
                get_string($f['opened'] ? ($f['opened'] === 1 ? 'letters_sofar_one' : 'letters_sofar') : 'letters_sofar_none', $c, $f['opened']),
                get_string('letters_wait', $c)];
            return implode(' ', $out);
        }
        $a = (object) ['ripe' => $f['ripe'], 'opened' => $f['ripeopened'], 'share' => panel_metrics::share($f['ripeopened'], $f['ripe'], true)];
        if ($f['ripe'] === 1) {
            $key = $f['ripeopened'] ? 'letters_ripe_single_yes' : 'letters_ripe_single_no';
        } else {
            $key = !$f['ripeopened'] ? 'letters_ripe_none' : ($f['ripeopened'] === 1 ? 'letters_ripe_opened_one' : 'letters_ripe');
        }
        $out = [get_string($key, $c, $a)];
        if ($f['best'] && $f['worst']) {
            $out[] = $f['best']['rate'] - $f['worst']['rate'] >= self::APART / 100 ? get_string('letters_compare', $c, (object) [
                'best' => \core_text::strtolower($f['best']['name']), 'bestp' => panel_metrics::percent($f['best']['ripeopened'], $f['best']['ripe']),
                'worst' => \core_text::strtolower($f['worst']['name']), 'worstp' => panel_metrics::percent($f['worst']['ripeopened'], $f['worst']['ripe'])]) :
                get_string('letters_even', $c);
        }
        return implode(' ', $out);
    }

    /**
     * A while, in the words of a clock: minutes; hours and minutes while it is a few; hours; days.
     *
     * @param int $seconds
     * @return string
     */
    public static function lapse(int $seconds): string {
        $c = self::C;
        $m = max(1, (int) round($seconds / 60));
        if ($m < 60) {
            return get_string('panel_lapse_min', $c, $m);
        }
        $h = intdiv($m, 60);
        if ($h < 6 && $m % 60) {
            return get_string('panel_lapse_hmin', $c, (object) ['h' => $h, 'm' => $m % 60]);
        }
        if ($h < 48) {
            return get_string('panel_lapse_h', $c, (int) round($m / 60));
        }
        return get_string('panel_lapse_days', $c, (int) round($m / 1440));
    }

    /**
     * The letters that came back: a mail of that family failed from just before it went to two days after.
     *
     * @param \stdClass[] $rows
     * @return bool[] By the id of the invite.
     */
    protected static function back(array $rows): array {
        global $DB;
        if (!$rows) {
            return [];
        }
        $out = [];
        $ids = array_values(array_unique(array_map(fn($r) => (int) $r->householdid, $rows)));
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $bounced = [];
            foreach ($DB->get_records_select(bounces::T, 'householdid ' . $in, $params, '', 'id, householdid, timecreated, timebounced') as $b) {
                $bounced[(int) $b->householdid][] = $b;
            }
            foreach ($rows as $row) {
                foreach ($bounced[(int) $row->householdid] ?? [] as $b) {
                    $sent = (int) $row->timecreated;
                    if ((int) $b->timebounced >= $sent - 600 && (int) $b->timecreated <= $sent + self::WAIT) {
                        $out[(int) $row->id] = true;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Nothing counted yet.
     *
     * @return array
     */
    protected static function empty(): array {
        return ['sent' => 0, 'back' => 0, 'arrived' => 0, 'opened' => 0, 'yes' => 0, 'ripe' => 0, 'ripeopened' => 0, 'times' => []];
    }

    /**
     * One letter that arrived, counted.
     *
     * @param array $one
     * @param \stdClass $row
     * @param int $now
     */
    protected static function add(array &$one, \stdClass $row, int $now): void {
        $one['sent']++;
        $one['arrived']++;
        $secs = self::reaction($row);
        $one['opened'] += $secs !== null || (int) $row->opened ? 1 : 0;
        $one['yes'] += (string) $row->reply === 'yes' ? 1 : 0;
        if ($secs !== null) {
            $one['times'][] = $secs;
        }
        if ((int) $row->timecreated <= $now - self::WAIT) {
            $one['ripe']++;
            $one['ripeopened'] += $secs !== null && $secs <= self::WAIT ? 1 : 0;
        }
    }

    /**
     * The rate and the time of half of them, once counted.
     *
     * @param array $one
     * @return array
     */
    protected static function finish(array $one): array {
        $times = $one['times'];
        sort($times);
        unset($one['times']);
        return $one + ['rate' => $one['ripe'] ? $one['ripeopened'] / $one['ripe'] : 0.0, 'reacted' => count($times),
            'half' => count($times) >= self::HALF ? $times[(int) ceil(count($times) / 2) - 1] : null];
    }

    /**
     * Of the hours or days with letters enough, the one where most open them and the one where fewest; none with fewer
     * than two of them.
     *
     * @param array[] $rows
     * @return array [best, worst], each a row or null.
     */
    protected static function ends(array $rows): array {
        $fair = array_values(array_filter($rows, fn($r) => $r['ripe'] >= self::FAIR));
        if (count($fair) < 2) {
            return [null, null];
        }
        usort($fair, fn($a, $b) => [$b['rate'], $b['ripe']] <=> [$a['rate'], $a['ripe']]);
        return [$fair[0], $fair[count($fair) - 1]];
    }

    /**
     * Two hours, as the clock of the language says them: «De 9:00 a 11:00».
     *
     * @param int $from
     * @return string
     */
    protected static function span(int $from): string {
        // Without the zero of a clock of 24 hours: «9:00», never «09:00».
        $at = fn(int $h) => preg_replace('/^0(?=\d)/', '', userdate(make_timestamp(2026, 1, 5, $h % 24, 0), get_string('strftimetime', 'langconfig')));
        return get_string('letters_span', self::C, (object) ['from' => $at($from), 'to' => $at($from + self::SPAN)]);
    }

    /**
     * The days of the week, from the one the calendar of the language starts with.
     *
     * @return string[] From 1, Monday, to 7, Sunday, in their order.
     */
    protected static function week(): array {
        $days = [];
        // The 5th of January of 2026 was a Monday.
        for ($day = 1; $day <= 7; $day++) {
            $days[$day] = panel::upper_first(userdate(make_timestamp(2026, 1, 4 + $day, 12), '%A'));
        }
        if ((int) get_string('firstdayofweek', 'langconfig') === 0) {
            $days = [7 => $days[7]] + array_slice($days, 0, 6, true);
        }
        return $days;
    }
}
