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
 * The numbers of a centre: how fast its families get an answer, a past campaign from the first contact to the
 * enrolment with what each channel brought and cost, who came and went in a school year, and the years before.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$campaignid = optional_param('campaign', 0, PARAM_INT);
$year = optional_param('year', '', PARAM_RAW_TRIMMED);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_work($centreid);
$canact = centres::can_act((int) $USER->id);
$campaigns = imports::for_centre((int) $centre->id, 'contacts');
$campaign = $campaigns[$campaignid] ?? reset($campaigns) ?: null;
$years = metrics::school_years((int) $centre->id);
$year = in_array($year, $years, true) ? $year : (string) reset($years);
$url = new moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id] +
    ($campaign ? ['campaign' => $campaign->id] : []) + ($year !== '' ? ['year' => $year] : []));
staff::setup($url, get_string('tab_metrics', $c), $centre);

if ($campaign && data_submitted() && confirm_sesskey()) {
    centres::require_act((int) $centre->id);
    $amounts = optional_param_array('amount', [], PARAM_RAW_TRIMMED);
    foreach ($amounts as $key => $amount) {
        [$channel, $source] = array_pad(explode('|', (string) $key, 2), 2, '');
        $value = unformat_float($amount, true);
        metrics::set_spend(
            (int) $centre->id,
            (string) $campaign->label,
            clean_param($channel, PARAM_ALPHA),
            clean_param($source, PARAM_TEXT),
            $value === false || $value === null ? 0 : (float) $value,
            (int) $USER->id
        );
    }
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$money = fn(?float $value) => $value === null ? '' : format_float($value, 2, true, true);
$bars = function (array $steps): array {
    $top = max(1, (int) ($steps[0]['value'] ?? 1));
    foreach ($steps as $i => $step) {
        $steps[$i]['width'] = round(100 * $step['value'] / $top, 1);
    }
    return $steps;
};
$context = ['tabs' => staff::tabs($centre, 'metrics'), 'canact' => $canact,
    'importurl' => (new moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]))->out(false),
    'response' => metrics::response((int) $centre->id)];
$context['response']['hasmedian'] = $context['response']['median'] !== null;

if ($campaign) {
    $data = metrics::campaign($campaign);
    $t = $data['totals'];
    $rows = function (array $list) use ($money, $c): array {
        foreach ($list as $i => $g) {
            $list[$i]['rate'] = format_float($g['rate'], 1, true, true);
            $list[$i]['spendvalue'] = $g['spend'] === null ? '' : format_float($g['spend'], 2, true, true);
            $list[$i]['cost'] = $money($g['costperenrolled']);
        }
        return $list;
    };
    $context['campaign'] = [
        'label' => s($campaign->label),
        'options' => array_values(array_map(fn($i) => ['id' => $i->id, 'label' => s($i->label),
            'selected' => (int) $i->id === (int) $campaign->id], $campaigns)),
        'steps' => $bars([
            ['name' => get_string('metrics_households', $c), 'value' => $t['households']],
            ['name' => get_string('metrics_inquiries', $c), 'value' => $t['inquiries']],
            ['name' => get_string('metrics_visited', $c), 'value' => $t['visited']],
            ['name' => get_string('metrics_applied', $c), 'value' => $t['applied']],
            ['name' => get_string('metrics_enrolled', $c), 'value' => $t['enrolled']],
        ]),
        'contacts' => $t['contacts'],
        'incentre' => $t['incentre'],
        'households' => $t['households'],
        'rate' => format_float($t['inquirytoenrolled'], 1, true, true),
        'visitrate' => format_float($t['visitedtoenrolled'], 1, true, true),
        'spend' => $money($t['spend']),
        'cost' => $money($t['costperenrolled']),
        'hascost' => $t['costperenrolled'] !== null,
        'channels' => $rows($data['channel']),
        'sources' => $rows($data['source']),
        'platforms' => $rows($data['platform']),
        'hasplatforms' => (bool) $data['platform'],
        'months' => $rows($data['month']),
        'grades' => $rows($data['grade']),
        'sesskey' => sesskey(),
        'action' => $url->out(false),
    ];
}

if ($year !== '') {
    $m = metrics::movements((int) $centre->id, $year);
    $context['year'] = array_merge($m, [
        'options' => array_map(fn($y) => ['value' => $y, 'selected' => $y === $year], $years),
        'startday' => $m['start'] ? userdate($m['start'], get_string('strftimedate', 'langconfig')) : '',
        'hasreasons' => (bool) $m['reasons'],
        'hasmonths' => (bool) $m['bymonth'],
        'places' => array_map(fn($p) => $p + ['sharetext' => format_float($p['share'], 1, true, true)], $m['places']),
        'hasplaces' => (bool) $m['places'],
        'hasfrom' => (bool) $m['from'],
        'hasto' => (bool) $m['to'],
        'from' => array_slice($m['from'], 0, 12),
        'to' => array_slice($m['to'], 0, 12),
    ]);
    foreach ($context['year']['bymonth'] as $i => $row) {
        $context['year']['bymonth'][$i]['name'] = userdate((int) strtotime($row['month'] . '-15'), '%B %Y');
    }
}

[$hyears, $hgrades, $matrix, $htotals] = metrics::history((int) $centre->id);
if ($hyears) {
    $context['history'] = [
        'years' => array_map(fn($y) => ['year' => $y], $hyears),
        'rows' => array_map(fn($g) => ['grade' => $g, 'cells' => array_map(
            fn($y) => ['value' => $matrix[$g][$y] ?? '–'],
            $hyears
        )], $hgrades),
        'totals' => array_map(fn($y) => ['value' => $htotals[$y]], $hyears),
    ];
}
$context['empty'] = !$campaign && $year === '' && !$hyears;

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/metrics', $context);
echo $OUTPUT->footer();
