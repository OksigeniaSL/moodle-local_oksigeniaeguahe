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
 * The forecast in the panel, whatever it looks like: each course of entry with its rail and, under it on the same
 * scale, the years to come in ranges, with the most likely of the next one and the last place as a dotted line; a
 * sentence and a twin table for each; the reach by place as a list, with two nested circles and no map; and, folded,
 * the age each course starts at and bringing the data of the area again. Always in ranges, never about one family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_forecast {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var float The largest radius of a circle of the reach, in its box of 48. */
    const RADIUS = 22.0;

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact, int $now = 0): array {
        $c = self::C;
        $now = $now ?: time();
        $taken = self::taken($centre);
        $courses = [];
        $filling = [];
        foreach (forecast::by_grade($centre) as $k => $grade) {
            $course = self::course($grade, $taken[recognise::plain((string) $grade['grade'])] ?? 0, $k === 0, $centre);
            if ($course['fills']) {
                $filling[] = ['name' => $course['name'], 'year' => $course['fills']];
            }
            $courses[] = $course;
        }
        if (!$courses || !array_filter($courses, fn($course) => $course['hasrange'])) {
            $title = get_string('panel_forecast_title_nodata', $c);
        } else if ($filling) {
            $first = $filling[0];
            $title = get_string(
                count($filling) === 1 ? 'panel_forecast_title_fills' : 'panel_forecast_title_fills_more',
                $c,
                (object) ['name' => $first['name'], 'year' => $first['year'], 'more' => count($filling) - 1]
            );
        } else {
            $title = get_string('panel_forecast_title_room', $c);
        }
        $status = opendata::status((int) $centre->id);
        $sources = [];
        foreach ($status as $source => $s) {
            $sources[] = get_string('panel_forecast_source', $c, (object) ['name' => get_string('opendata_source_' . $source, $c),
                'places' => $s['places'], 'when' => panel::when_words((int) $s['fetched'], $now)]);
        }
        $grades = [];
        $i = 0;
        foreach (forecast::grades((int) $centre->id) as $grade => $info) {
            $grades[] = ['i' => $i++, 'grade' => $grade, 'age' => $info['age'], 'forecast' => $info['forecast'],
                'suggested' => !$info['saved'] && $info['age'] !== null];
        }
        return [
            'head' => panel::head([
                'title' => $title,
                'sub' => get_string('panel_forecast_sub', $c),
                'action' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]))->out(false),
                    'name' => get_string('panel_import_title', $c), 'icon' => panel::icon('subir'), 'anchor' => false],
            ]),
            'source' => $sources ? implode(' ', $sources) : get_string('panel_forecast_source_none', $c),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/forecast.php', ['c' => $centre->id]))->out(false),
            'sesskey' => sesskey(),
            'canact' => $canact,
            'fetch' => panel::icon('subir'),
            'courses' => $courses,
            'hascourses' => (bool) $courses,
            'def' => get_string('panel_forecast_def', $c),
            'reach' => self::reach($centre),
            'grades' => $grades,
        ];
    }

    /**
     * A course of entry: its rail with the years to come, its sentence and its table.
     *
     * @param array $grade As forecast::by_grade() gives each.
     * @param int $taken Already enrolled for the coming school year.
     * @param bool $open Whether its table opens by itself on a wide screen.
     * @param \stdClass $centre
     * @return array
     */
    protected static function course(array $grade, int $taken, bool $open, \stdClass $centre): array {
        $c = self::C;
        $ahead = $grade['ahead'];
        $places = (int) ($ahead[0]['places'] ?? 0);
        $rows = [];
        $table = [];
        $fills = '';
        foreach ($ahead as $k => $year) {
            $has = $year['low'] !== null;
            $over = $has && $year['places'] && $year['high'] > $year['places'];
            if ($has) {
                $rows[] = ['low' => (int) $year['low'], 'mid' => $k === 0 ? (int) $year['mid'] : 0, 'high' => (int) $year['high'],
                    'text' => (string) $year['year']];
            }
            $fills = $fills === '' && $over ? (string) $year['year'] : $fills;
            $table[] = ['year' => $year['year'], 'range' => $has ? get_string('panel_range', $c, (object) ['low' => $year['low'],
                'high' => $year['high']]) : '–', 'mid' => $has ? (int) $year['mid'] : '–',
                'places' => (int) $year['places'] ?: '–', 'over' => $over];
        }
        $next = $rows[0] ?? null;
        if (!$rows) {
            $sentence = get_string('panel_forecast_course_nodata', $c);
        } else if ($fills !== '') {
            $sentence = get_string('panel_forecast_course_fills', $c, $fills);
        } else if ($places && $next['high'] < 0.6 * $places) {
            $sentence = get_string('panel_forecast_course_short', $c, (object) ($next + ['places' => $places]));
        } else {
            $sentence = get_string(
                $places ? 'panel_forecast_course_room' : 'panel_forecast_course_noplaces',
                $c,
                (object) ($next + ['places' => $places])
            );
        }
        $name = format_string($grade['grade']);
        return [
            'key' => 'c' . substr(md5((string) $grade['grade']), 0, 8),
            'name' => $name,
            'age' => $grade['age'] !== null ? get_string('panel_starts_at', $c, (int) $grade['age']) : '',
            'places' => $places ? get_string('panel_places_n', $c, $places) : get_string('panel_places_unknown', $c),
            'sentence' => $sentence,
            'rail' => $places ? panel::rail($places, min($taken, $places), $rows, get_string(
                'panel_forecast_rail',
                $c,
                (object) ['name' => $name, 'places' => $places, 'sentence' => $sentence]
            ), panel::hanging_object((string) $grade['grade'], $centre)) : '',
            'table' => $table,
            'open' => $open,
            'hasrange' => (bool) $rows,
            'fills' => $fills,
        ];
    }

    /**
     * Who is already enrolled for the coming school year, by course: the backpacks on the rail.
     *
     * @param \stdClass $centre
     * @return array plain name of the course => children
     */
    protected static function taken(\stdClass $centre): array {
        $counts = households::offer_counts((int) $centre->id);
        $out = [];
        foreach (centres::offers((int) $centre->id, true) as $offer) {
            $key = recognise::plain((string) $offer->name);
            $out[$key] = ($out[$key] ?? 0) + (int) ($counts[$offer->id]['enrolled'] ?? 0);
        }
        return $out;
    }

    /**
     * How far the centre reaches, by place: the children of those ages there, those of the centre, the part in words
     * and two nested circles, both on the scale of the place with most children. No map: nothing says where anyone lives.
     *
     * @param \stdClass $centre
     * @return array|null
     */
    protected static function reach(\stdClass $centre): ?array {
        $c = self::C;
        $list = forecast::reach($centre);
        if (!$list) {
            return null;
        }
        $most = max(1, ...array_column($list, 'children'));
        $radius = fn(int $n) => $n ? max(1.5, round(self::RADIUS * sqrt($n / $most), 1)) : 0;
        $rows = [];
        $best = null;
        foreach ($list as $row) {
            $r1 = $radius((int) $row['children']);
            $r2 = min($r1 ?: self::RADIUS, $radius((int) $row['students']));
            $share = $row['share'] ? (int) max(1, round(100 / $row['share'])) : 0;
            $rows[] = ['name' => format_string($row['name']), 'kids' => (int) $row['children'] ?: '–',
                'ours' => (int) $row['students'], 'share' => $share ? get_string('panel_one_in', $c, $share) : '–',
                'r1' => $r1, 'r2' => $r2, 'cy1' => round(46 - $r1, 1), 'cy' => round(46 - $r2, 1)];
            if ($row['share'] && $row['children'] >= 5 && (!$best || $row['share'] > $best['share'])) {
                $best = $row;
            }
        }
        // The cinematic skin: each town a star, its ring the children of those ages, its light inside, ours.
        $stars = cinematico::on($centre) ? cinematico::constellation(
            $centre,
            array_map(fn($r) => [format_string($r['name']), (int) $r['children'], $r['children'] ? min(1, $r['students'] /
                $r['children']) : 0], array_values(array_filter($list, fn($r) => (int) $r['children'] > 0))),
            get_string('cine_constellation_rings_foot', $c),
            true
        ) : false;
        return [
            'constellation' => $stars,
            'sentence' => $best ? get_string('panel_reach_best', $c, (object) ['name' => format_string($best['name']),
                'share' => get_string('panel_one_in', $c, (int) max(1, round(100 / $best['share'])))]) :
                get_string('panel_reach_nobest', $c),
            'def' => get_string('panel_reach_def', $c),
            'rows' => $rows,
        ];
    }
}
