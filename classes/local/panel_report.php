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
 * A report in the panel (report.php): what was kept when it was made, with what the AI of the centre added and where
 * each piece of advice comes from, the reports before to go from one to another, making one again, and the sheet to
 * print it as a PDF. The data apart from the template, so the design of Jujol only changes the template and the styles.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_report {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param \stdClass|null $report Null when the centre has none yet.
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, ?\stdClass $report, bool $canact, int $now = 0): array {
        $c = self::C;
        $now = $now ?: time();
        $id = (int) $centre->id;
        $url = fn(array $params) => (new \moodle_url('/local/oksigeniaeguahe/report.php', ['c' => $id] + $params))->out(false);
        $others = [];
        foreach (reports::for_centre($id) as $row) {
            $others[] = ['name' => report_data::period($row->kind, (int) $row->periodfrom, (int) $row->periodto),
                'kind' => get_string('report_kind_' . $row->kind, $c), 'url' => $url(['id' => $row->id]),
                'current' => $report && (int) $row->id === (int) $report->id, 'hot' => $row->kind === 'hot'];
        }
        [$weekfrom] = reports::week($now);
        [$yearfrom] = reports::school_year($centre, $now);
        $make = $canact ? ['action' => $url([]), 'sesskey' => sesskey(), 'buttons' => [
            ['kind' => 'hot', 'text' => get_string('report_make_hot', $c)],
            ['kind' => 'cold', 'text' => get_string('report_make_cold', $c)],
        ]] : false;
        $base = [
            'head' => panel::head(['title' => get_string('report_title', $c), 'sub' => get_string('report_sub', $c),
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $id]))->out(false),
                'name' => get_string('tab_metrics', $c)]]),
            'others' => $others,
            'hasothers' => count($others) > 1,
            'make' => $make,
            'weekfrom' => $weekfrom,
            'yearfrom' => $yearfrom,
        ];
        if (!$report) {
            return $base + ['report' => false, 'empty' => get_string('report_empty', $c)];
        }
        $data = $report->data;
        // A «0» is a figure, not nothing: Mustache would take it as empty.
        foreach ($data['figures'] ?? [] as $i => $figure) {
            $data['figures'][$i]['hasbefore'] = $figure['before'] !== false;
            $data['figures'][$i]['hasago'] = $figure['ago'] !== false;
            $data['figures'][$i]['trendtext'] = self::trend($figure['trend']);
        }
        $data = self::scales($data);
        $ai = $report->ai;
        $advice = array_map(fn($a) => $a + ['fromtext' => get_string('report_from_rule', $c)], $data['advice'] ?? []);
        foreach ($ai['advice'] ?? [] as $tip) {
            $advice[] = ['text' => $tip['text'], 'why' => $tip['why'], 'from' => 'ai', 'url' => false,
                'fromtext' => get_string('report_from_ai', $c, s($report->aiprovider ?: 'IA'))];
        }
        $themes = [];
        $most = max(array_merge([1], array_column($ai['themes'] ?? [], 'texts')));
        foreach ($ai['themes'] ?? [] as $theme) {
            $themes[] = $theme + ['v' => round($theme['texts'] / $most, 3), 'count' => get_string(
                $theme['texts'] === 1 ? 'report_theme_one' : 'report_theme_n',
                $c,
                $theme['texts']
            )];
        }
        $status = $report->aistatus;
        $base['head']['title'] = $data['title'] ?? get_string('report_title', $c);
        $base['head']['ante'] = get_string('report_kind_' . $report->kind, $c);
        $made = userdate((int) $data['made'], get_string('strftimedaydatetime', 'langconfig'));
        $base['head']['sub'] = get_string('report_made', $c, $made) . '.' . (!empty($data['sofar']) ? ' ' . $data['sofar'] : '');
        return $base + [
            'report' => true,
            'id' => (int) $report->id,
            'kind' => $report->kind,
            'hot' => $report->kind === 'hot',
            'data' => $data + ['attention' => false, 'funnel' => false, 'pace' => false, 'channels' => false,
                'places' => false, 'acts' => false, 'voices' => false, 'gone' => false, 'started' => false, 'referred' => false,
                'forecast' => false, 'grow' => false, 'response' => false] + [
                'hasattention' => !empty($data['attention']), 'haschannels' => !empty($data['channels']),
                'hasplaces' => !empty($data['places']), 'hasforecast' => !empty($data['forecast']),
                'hasgrow' => !empty($data['grow'])],
            'reading' => ($ai['reading'] ?? '') !== '' ? $ai['reading'] : false,
            'ainame' => ($ai['reading'] ?? '') !== '' ? get_string('report_ai_signed', $c, s($report->aiprovider ?: 'IA')) : false,
            'themes' => $themes,
            'hasthemes' => (bool) $themes,
            'themesfrom' => $themes ? get_string('report_themes_from', $c, (object) ['n' => (int) ($ai['texts'] ?? 0),
                'provider' => s($report->aiprovider ?: 'IA')]) : false,
            'advice' => $advice,
            'hasadvice' => (bool) $advice,
            'aistatus' => in_array($status, ['waiting', 'failed', 'none'], true) ? ['text' => get_string('report_ai_' .
                $status, $c), 'waiting' => $status === 'waiting'] : false,
            'pdf' => get_string('report_pdf', $c),
            'again' => $canact ? ['action' => $url([]), 'sesskey' => sesskey(), 'kind' => $report->kind,
                'from' => (int) $report->periodfrom, 'text' => get_string('report_again', $c)] : false,
        ];
    }

    /**
     * Where each bar and range of the design ends, from 0 to 1, on one scale per chart: what is now and what was before
     * on the same one (Jujol). A course of the places and a course of what may come, each on its own, with a little air
     * above. Nothing is made up: the same counts, scaled.
     *
     * @param array $data
     * @return array
     */
    public static function scales(array $data): array {
        $r = fn(float $x) => round($x, 3);
        $bars = function (array $rows, array $keys) use ($r): array {
            $top = 1;
            foreach ($rows as $row) {
                foreach ($keys as $key) {
                    $top = max($top, (float) ($row[$key] ?? 0));
                }
            }
            foreach ($rows as $i => $row) {
                $rows[$i]['v'] = $r((float) ($row['n'] ?? 0) / $top);
                if (in_array('before', $keys, true)) {
                    $rows[$i]['bv'] = $r((float) ($row['before'] ?: 0) / $top);
                }
            }
            return $rows;
        };
        if (!empty($data['funnel']['steps'])) {
            $data['funnel']['steps'] = $bars($data['funnel']['steps'], ['n', 'before']);
        }
        if (!empty($data['pace']['rows'])) {
            $most = max(array_merge([0], array_map(
                fn($row) => empty($row['future']) ? (int) $row['n'] : 0,
                $data['pace']['rows']
            )));
            foreach ($data['pace']['rows'] as $i => $row) {
                $data['pace']['rows'][$i]['top'] = $most > 0 && empty($row['future']) && (int) $row['n'] === $most;
                // A month to come has no bar of its own, only the one of the year before.
                $data['pace']['rows'][$i]['v'] = $row['v'] ?: 0;
                $data['pace']['rows'][$i]['bv'] = $row['bv'] ?: 0;
            }
        }
        foreach ($data['channels'] ?: [] as $i => $voice) {
            $data['channels'][$i]['rows'] = $bars($voice['rows'], ['n', 'before']);
            foreach ($voice['rows'] as $j => $row) {
                $data['channels'][$i]['rows'][$j]['trendtext'] = self::trend($row['trend'] ?? false);
            }
        }
        foreach ($data['places'] ?: [] as $i => $course) {
            $top = max(1, $course['places'], (int) $course['high'], $course['taken']) * 1.06;
            $data['places'][$i] += ['vt' => $r($course['taken'] / $top), 'vp' => $r($course['places'] / $top),
                'vl' => $r((int) $course['low'] / $top), 'vh' => $r((int) $course['high'] / $top)];
        }
        foreach (['heard', 'likes'] as $key) {
            if (!empty($data['voices'][$key])) {
                $data['voices'][$key] = $bars($data['voices'][$key], ['n']);
            }
        }
        if (!empty($data['gone']['reasons'])) {
            $data['gone']['reasons'] = $bars($data['gone']['reasons'], ['n']);
        }
        foreach ($data['forecast'] ?: [] as $i => $grade) {
            $top = max(1, ...array_map(fn($y) => max((int) $y['high'], (int) $y['places']), $grade['years'])) * 1.06;
            foreach ($grade['years'] as $j => $year) {
                $data['forecast'][$i]['years'][$j] += ['vl' => $r($year['low'] / $top), 'vh' => $r($year['high'] / $top),
                    'vp' => $r((int) $year['places'] / $top)];
            }
        }
        if (!empty($data['grow'])) {
            $share = fn($g) => (float) str_replace(',', '.', (string) $g['share']);
            $top = max(0.001, ...array_map($share, $data['grow']));
            foreach ($data['grow'] as $i => $g) {
                $data['grow'][$i]['v'] = $r($share($g) / $top);
            }
        }
        return $data;
    }

    /**
     * Whether a figure goes up, down or holds, in words of the language of the page; the key stays for the styles.
     *
     * @param string|false $trend sube, baja or igual.
     * @return string|false
     */
    protected static function trend($trend) {
        return in_array($trend, ['sube', 'baja', 'igual'], true) ? get_string('panel_ct_' . $trend, self::C) : false;
    }
}
