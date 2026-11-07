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

/**
 * The forecast of a centre: its courses with their age, the students it can expect in the next years in ranges, and
 * how far it reaches in each municipality, with the open data of its area and their source.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\forecast;
use local_oksigeniaeguahe\local\opendata;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_work($centreid);
$canact = centres::can_act((int) $USER->id);
$url = new moodle_url('/local/oksigeniaeguahe/forecast.php', ['c' => $centre->id]);
staff::setup($url, get_string('tab_forecast', $c), $centre);

if ($action && data_submitted() && confirm_sesskey()) {
    centres::require_act((int) $centre->id);
    if ($action === 'fetch') {
        try {
            $n = opendata::fetch_istac((int) $centre->id, forecast::zone($centre));
            redirect(
                $url,
                get_string($n ? 'forecast_fetched' : 'forecast_nosource', $c, $n),
                null,
                $n ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
            );
        } catch (moodle_exception $e) {
            redirect($url, get_string('opendata_failed', $c, 'ISTAC'), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
    if ($action === 'ages') {
        $grades = optional_param_array('grade', [], PARAM_TEXT);
        $ages = optional_param_array('age', [], PARAM_INT);
        $flags = optional_param_array('forecast', [], PARAM_BOOL);
        $rows = [];
        foreach ($grades as $i => $grade) {
            if (isset($ages[$i]) && $ages[$i] !== '') {
                $rows[$grade] = ['age' => (int) $ages[$i], 'forecast' => !empty($flags[$i])];
            }
        }
        forecast::save_ages((int) $centre->id, $rows);
        redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$number = fn($v) => $v === null ? '–' : format_float((float) $v, 0, true, true);
$percent = fn($v) => $v === null ? '–' : format_float(100 * $v, 1, true, true);
$grades = [];
$i = 0;
foreach (forecast::grades((int) $centre->id) as $grade => $info) {
    $grades[] = ['i' => $i++, 'grade' => $grade, 'age' => $info['age'], 'forecast' => $info['forecast'],
        'suggested' => !$info['saved'] && $info['age'] !== null];
}
$courses = [];
foreach (forecast::by_grade($centre) as $g) {
    $courses[] = [
        'grade' => $g['grade'],
        'age' => $g['age'],
        'past' => array_map(fn($p) => ['year' => $p['year'], 'students' => $p['students'], 'cohort' => $number($p['cohort']),
            'share' => $percent($p['share'])], $g['past']),
        'ahead' => array_map(
            fn($a) => ['year' => $a['year'], 'cohort' => $number($a['cohort']),
            'fromage' => $a['kind'] === 'age', 'hasrange' => $a['low'] !== null,
            'low' => $a['low'], 'mid' => $a['mid'], 'high' => $a['high'],
            'places' => $a['places'], 'over' => $a['places'] && $a['high'] !== null && $a['high'] > $a['places']],
            $g['ahead']
        ),
    ];
}
$reach = array_map(fn($r) => array_merge($r, ['code' => $r['name'] === $r['code'] ? '' : $r['code'],
    'sharetext' => $r['share'] === null ? '–' : format_float($r['share'], 1, true, true)]), forecast::reach($centre));
$status = opendata::status((int) $centre->id);
$sources = [];
foreach ($status as $source => $s) {
    $sources[] = ['name' => get_string('opendata_source_' . $source, $c), 'numbers' => $s['numbers'],
        'places' => $s['places'], 'when' => userdate($s['fetched'], get_string('strftimedatetimeshort', 'langconfig'))];
}
$zone = forecast::zone($centre);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/forecast', [
    'tabs' => staff::tabs($centre, 'forecast'),
    'canact' => $canact,
    'action' => $url->out(false),
    'sesskey' => sesskey(),
    'zone' => count($zone),
    'covered' => count(array_filter($zone, [opendata::class, 'istac_covers'])),
    'sources' => $sources,
    'hassources' => (bool) $sources,
    'istac' => isset($status['istac']),
    'grades' => $grades,
    'hasgrades' => (bool) $grades,
    'courses' => $courses,
    'hascourses' => (bool) $courses,
    'reach' => $reach,
    'hasreach' => (bool) $reach,
    'importurl' => (new moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]))->out(false),
]);
echo $OUTPUT->footer();
