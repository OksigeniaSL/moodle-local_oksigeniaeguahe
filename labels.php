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
 * The sheet of labels of the children of an event or of a household: the label of each backpack, or the sticker of
 * each bottle, on the sheet of sticky labels the centre buys; printed from the browser.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\labels;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_labels;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$eventid = optional_param('event', 0, PARAM_INT);
$householdid = optional_param('household', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_work($centreid);
$event = $eventid ? events::get($eventid, (int) $centre->id) : null;
$household = !$event && $householdid ? households::get($householdid, (int) $centre->id) : null;
if (!$event && !$household) {
    throw new moodle_exception('invalidrecord', 'error');
}
$view = [
    'kind' => labels::kind(optional_param('kind', 'labels', PARAM_ALPHA)),
    'sheet' => optional_param('sheet', '', PARAM_ALPHANUMEXT),
    'start' => optional_param('start', 1, PARAM_INT),
    'test' => optional_param('test', 0, PARAM_BOOL),
];
$where = $event ? ['event' => $event->id] : ['household' => $household->id];
$url = new moodle_url('/local/oksigeniaeguahe/labels.php', ['c' => $centre->id] + $where + ['kind' => $view['kind']] +
    ($view['sheet'] !== '' ? ['sheet' => $view['sheet']] : []) + ($view['start'] > 1 ? ['start' => $view['start']] : []));
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('panel_labels', $c));

if (data_submitted()) {
    // A favourite marked or unmarked, or the print moved for this sheet: kept for the centre.
    require_sesskey();
    centres::require_act((int) $centre->id);
    $sheet = labels::chosen($centre, $view['kind'], $view['sheet']);
    $action = optional_param('action', '', PARAM_ALPHA);
    if ($action === 'favourite') {
        labels::favourite($centre, $view['kind'], $sheet);
    } else if ($action === 'nudge') {
        labels::nudge($centre, $sheet, (float) optional_param('x', 0, PARAM_FLOAT), (float) optional_param('y', 0, PARAM_FLOAT));
    }
    redirect(
        new moodle_url($url, ['sheet' => $sheet]),
        get_string('changessaved'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

panel::output(
    $centre,
    $event ? 'events' : 'inbox',
    get_string('panel_labels', $c),
    'panel_labels',
    panel_labels::context($centre, $event, $household, $view)
);
