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
 * The reports of a centre: «How it goes», each week, and «The year, cold», each school year; one at a time, with the
 * ones before, to read in the panel and to keep as a PDF.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_report;
use local_oksigeniaeguahe\local\reports;

require_login(null, false);

$centre = centres::require_work(required_param('c', PARAM_INT));
$c = 'local_oksigeniaeguahe';
$id = optional_param('id', 0, PARAM_INT);
$url = new moodle_url('/local/oksigeniaeguahe/report.php', ['c' => $centre->id] + ($id ? ['id' => $id] : []));
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('report_title', $c));

if (data_submitted()) {
    require_sesskey();
    centres::require_act((int) $centre->id);
    $kind = optional_param('kind', 'hot', PARAM_ALPHA) === 'cold' ? 'cold' : 'hot';
    $now = time();
    // The same period again, or the week or the school year under way, so far.
    $from = optional_param('from', 0, PARAM_INT);
    if ($from) {
        $to = $kind === 'cold' ? strtotime('+1 year', $from) : $from + WEEKSECS;
    } else {
        [$from, $to] = reports::current($centre, $kind, $now);
    }
    $made = reports::make($centre, $kind, $from, $to, $now, (int) $USER->id);
    redirect(new moodle_url('/local/oksigeniaeguahe/report.php', ['c' => $centre->id, 'id' => $made]));
}

$report = $id ? reports::get($id, (int) $centre->id) : (reports::latest((int) $centre->id, 'hot') ??
    reports::latest((int) $centre->id, 'cold'));
panel::output(
    $centre,
    'metrics',
    get_string('report_title', $c),
    'panel_report',
    panel_report::context($centre, $report, centres::can_act((int) $USER->id))
);
