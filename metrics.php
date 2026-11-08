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
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_metrics;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_work($centreid);
$canact = centres::can_act((int) $USER->id);
$view = [
    'campaign' => optional_param('campana', 'live', PARAM_ALPHANUM),
    'course' => optional_param('curso', '', PARAM_TEXT),
    'compare' => optional_param('comparar', 1, PARAM_BOOL),
    'voice' => optional_param('voz', 'first', PARAM_ALPHA),
    'show' => optional_param('vista', 'llegadas', PARAM_ALPHA),
    'ensenar' => optional_param('ensenar', 0, PARAM_BOOL),
];
$url = panel_metrics::url($centre, $view);
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('tab_metrics', $c));

$action = data_submitted() ? optional_param('action', '', PARAM_ALPHA) : '';
if ($action !== '') {
    require_sesskey();
    centres::require_act((int) $centre->id);
    if ($action === 'spend') {
        $campaigns = panel_metrics::campaigns($centre);
        $campaign = $campaigns[$view['campaign']] ?? $campaigns['live'];
        panel_metrics::save_spend($centre, $campaign, optional_param_array('amount', [], PARAM_RAW_TRIMMED), (int) $USER->id);
    } else if ($action === 'starts') {
        panel_metrics::save_starts($centre, optional_param_array('start', [], PARAM_RAW_TRIMMED));
    }
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

panel::output(
    centres::get((int) $centre->id),
    'metrics',
    get_string('tab_metrics', $c),
    'panel_metrics',
    panel_metrics::context(centres::get((int) $centre->id), $view, $canact)
);
