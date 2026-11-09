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
 * What the cinematic skin (C, Jujol, 9 November 2026) needs from Eguahe, worked out on the server so the page reads the
 * same without JavaScript: the river of the inbox, where each family waiting is a lantern placed by how long it has
 * waited on a logarithmic scale of time, with the days marked along it; and the constellation of towns, each at its
 * real bearing from the centre and its distance on a square-root scale, from the positions of places.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cinematico {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int The lanterns on the river: the families that have waited longest. */
    const LANTERNS = 6;

    /** @var float The river reaches at least this far, in hours: a day and a half. */
    const HOURS = 36.0;

    /** @var float The room of a lantern on its lane, as a share of the river, without JavaScript (escena.js measures). */
    const ROOM = 0.5;

    /** @var float A town this near the centre, in kilometres, is where the centre is: one light. */
    const HERE = 2.0;

    /** @var int The lanes of the river. */
    const LANES = 3;

    /**
     * Whether a centre has the cinematic skin.
     *
     * @param \stdClass $centre
     * @return bool
     */
    public static function on(\stdClass $centre): bool {
        return panel::skin($centre) === 'cinematico';
    }

    /**
     * Where a wait falls on the river: 0, just arrived; 1, the counter.
     *
     * @param float $hours
     * @param float $most
     * @return float
     */
    protected static function x(float $hours, float $most): float {
        return log1p(max($hours, 0)) / log1p($most);
    }

    /**
     * The river of the inbox: the lanterns of the six families that have waited longest, with their place and lane,
     * the days along the river and where each begins, and how many were answered today.
     *
     * @param \stdClass $centre
     * @param array $queue Households waiting, as households::inbox() gives them, the longest first.
     * @param int $viewer Who looks: who attends each family.
     * @param int $now
     * @return array
     */
    public static function river(\stdClass $centre, array $queue, int $viewer, int $now): array {
        global $DB;
        $c = self::C;
        $midnight = usergetmidnight($now);
        $answered = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT householdid) FROM {" . households::CONTACTS . "}
              WHERE centreid = ? AND direction = 'out' AND timecreated >= ?",
            [$centre->id, $midnight]
        );
        $waiting = array_values(array_filter($queue, fn($h) => (int) $h->waitingsince > 0));
        if (!$waiting) {
            return ['empty' => true, 'answeredtoday' => $answered, 'lanterns' => [], 'cuts' => [], 'days' => []];
        }
        $hours = fn($h) => max(0, $now - (int) $h->waitingsince) / HOURSECS;
        $longest = max(array_map($hours, $waiting));
        $most = max(self::HOURS, 1.15 * $longest);
        // The six longest, placed from the most recent: each in the first lane where the one before leaves room.
        $six = array_slice($waiting, 0, self::LANTERNS);
        usort($six, fn($a, $b) => (int) $b->waitingsince <=> (int) $a->waitingsince);
        $last = array_fill(0, self::LANES, -1.0);
        $lanterns = [];
        foreach ($six as $household) {
            $x = self::x($hours($household), $most);
            $free = array_keys(array_filter($last, fn($end) => $end + self::ROOM <= $x));
            $lane = $free ? (int) $free[0] : (int) array_keys($last, min($last))[0];
            $last[$lane] = $x;
            $owner = attend::owner($household, $viewer);
            $since = (int) $household->waitingsince;
            $lanterns[] = [
                'x' => round($x, 3),
                'lane' => $lane,
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
                'adults' => panel::adults_line($household->adults),
                'since' => panel::wait_words($since, $now)['main'],
                'arrived' => get_string('cine_arrived', $c, (object) [
                    'day' => \core_text::strtolower(trim(preg_replace(
                        '/\s+/',
                        ' ',
                        userdate($since, get_string('vestibulo_day', $c))
                    ))),
                    'time' => userdate($since, get_string('strftimetime', 'langconfig'))]),
                'owner' => $owner ? $owner['first'] : '',
                'tags' => array_map(fn($t) => ['tag' => $t], array_values(array_filter(array_map(
                    [familypage::class, 'label_name'],
                    $household->children
                )))),
                'wait' => $since,
            ];
        }
        // The longest first, as the queue.
        usort($lanterns, fn($a, $b) => $a['wait'] <=> $b['wait']);
        // The days: the line of each midnight going back, and the name of each day in the middle of its stretch.
        $cuts = [];
        $days = [];
        $start = $now;
        $day = $midnight;
        $before = -1.0;
        for ($k = 0; ($now - $start) / HOURSECS < $most && $k < 60; $k++) {
            $from = ($now - $start) / HOURSECS;
            $to = ($now - $day) / HOURSECS;
            $x0 = self::x($from, $most);
            $x1 = min(1.0, self::x($to, $most));
            if ($from <= $longest) {
                if ($x1 < 0.995) {
                    $cuts[] = ['x' => round($x1, 3)];
                }
                $middle = ($x0 + $x1) / 2;
                if ($middle <= 0.96 && $middle - $before >= 0.08) {
                    $name = $k === 0 ? get_string('cine_today', $c) : ($k === 1 ? get_string('cine_yesterday', $c) :
                        \core_text::strtotitle(userdate($day, '%A')));
                    $short = $k === 0 ? $name : ($k === 1 ? $name : \core_text::strtotitle(userdate($day, '%a')));
                    $days[] = ['x' => round($middle, 3), 'name' => $name, 'short' => $short, 'narrow' => $x1 - $x0 < 0.08];
                    $before = $middle;
                }
            }
            $start = $day;
            $day = usergetmidnight($day - HOURSECS);
        }
        foreach ($lanterns as $i => $lantern) {
            unset($lanterns[$i]['wait']);
        }
        return ['empty' => false, 'answeredtoday' => $answered, 'lanterns' => $lanterns, 'cuts' => $cuts, 'days' => $days];
    }

    /**
     * The towns as stars around the centre: each at its bearing from it, its distance on a square-root scale (so the
     * near ones get room), one scale per box (the wide one, 16:6, with the centre where the sky glows; the phone, 8:9,
     * with the centre where the towns fit best). A town without a position goes in the row below, by its count.
     *
     * @param \stdClass $centre
     * @param array $items A list of [name, n, core]: core, from 0 to 1, the part that is the centre's (rings), or null.
     * @param string $foot What the figure says under it.
     * @param bool $rings
     * @return array|false The context of panel_constellation; false without towns.
     */
    public static function constellation(\stdClass $centre, array $items, string $foot, bool $rings) {
        if (!$items) {
            return false;
        }
        $known = places::known((int) $centre->id);
        $here = $known[places::CENTRE] ?? null;
        $most = max(1, ...array_map(fn($i) => (int) $i[1], $items));
        $placed = [];
        $points = [];
        $unplaced = [];
        $home = null;
        $nearest = self::HERE;
        foreach ($items as [$name, $n, $core]) {
            $where = $here ? ($known[places::key((string) $name)] ?? null) : null;
            if ($where) {
                [$east, $north] = places::offset($here, $where);
                $d = hypot($east, $north);
                // The town the centre is in: its light is the centre's.
                if ($d <= $nearest) {
                    $nearest = $d;
                    $home = count($placed);
                }
                $f = $d > 0 ? sqrt($d) / $d : 0;
                $points[] = [$east * $f, $north * $f];
                $placed[] = [$name, $n, $core];
            } else {
                $unplaced[] = [$name, $n];
            }
        }
        $wide = self::fit($points, 16, 6, 0.33, 0.5);
        $side = function (array $plus, array $minus): float {
            $a = max(array_merge([0], $plus));
            $b = max(array_merge([0], $minus));
            return 0.12 + 0.76 * ($a + $b ? $a / ($a + $b) : 0.5);
        };
        $cxm = $side(array_map(fn($p) => -$p[0], $points), array_map(fn($p) => $p[0], $points));
        $cym = $side(array_map(fn($p) => $p[1], $points), array_map(fn($p) => -$p[1], $points));
        $phone = self::fit($points, 8, 9, $cxm, $cym);
        $stars = [];
        foreach ($placed as $i => [$name, $n, $core]) {
            [$x, $y] = $wide[$i];
            [$xm, $ym] = $phone[$i];
            $stars[] = ['x' => round($x, 3), 'y' => round($y, 3), 'xm' => round($xm, 3), 'ym' => round($ym, 3),
                't' => round(sqrt((int) $n / $most), 3), 'core' => $core === null ? 0 : round((float) $core, 3),
                'hascore' => $core !== null, 'left' => $x > 0.72, 'leftm' => $xm > 0.58, 'name' => (string) $name,
                'n' => (string) $n, 'iscentre' => $i === $home, 'centrename' => $i === $home ? format_string($centre->name) : ''];
        }
        usort($unplaced, fn($a, $b) => (int) $b[1] <=> (int) $a[1]);
        $out = [];
        foreach ($unplaced as $k => [$name, $n]) {
            $out[] = ['k' => $k, 't' => round(sqrt((int) $n / $most), 3), 'name' => (string) $name, 'n' => (string) $n];
        }
        $credit = $stars ? places::credit((int) $centre->id) : '';
        return ['rings' => $rings, 'foot' => trim($foot . ' ' . $credit), 'centre' => $home !== null ? false : ['x' => 0.33,
            'y' => 0.5, 'xm' => round($cxm, 3),
            'ym' => round($cym, 3), 'name' => format_string($centre->name)], 'stars' => $stars, 'unplaced' => $out];
    }

    /**
     * Where points fall in a box of a shape, with one scale for both axes and the centre at (cx, cy): the largest scale
     * at which every point fits, with a margin.
     *
     * @param array $points A list of [east, north].
     * @param float $width
     * @param float $height
     * @param float $cx
     * @param float $cy
     * @return array A list of [x, y], from 0 to 1.
     */
    protected static function fit(array $points, float $width, float $height, float $cx, float $cy): array {
        $margin = 0.1;
        $room = fn(float $space, float $far) => $far > 0 ? $space / $far : INF;
        $scale = min(
            $room((1 - $margin - $cx) * $width, max(array_merge([0], array_column($points, 0)))),
            $room(($cx - $margin) * $width, max(array_merge([0], array_map(fn($p) => -$p[0], $points)))),
            $room(($cy - $margin) * $height, max(array_merge([0], array_column($points, 1)))),
            $room((1 - $margin - $cy) * $height, max(array_merge([0], array_map(fn($p) => -$p[1], $points))))
        );
        $scale = is_finite($scale) ? $scale : 0;
        return array_map(fn($p) => [$cx + $p[0] * $scale / $width, $cy - $p[1] * $scale / $height], $points);
    }
}
