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
 * The first day: who of the enrolled children started, marked at one touch, as the door of an open day.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_start;
use local_oksigeniaeguahe\local\settled;

require_login(null, false);

$centre = centres::require_work(required_param('c', PARAM_INT));
$c = 'local_oksigeniaeguahe';
$url = new moodle_url('/local/oksigeniaeguahe/start.php', ['c' => $centre->id]);
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('panel_firstday', $c));

if (data_submitted()) {
    require_sesskey();
    centres::require_act((int) $centre->id);
    $child = required_param('child', PARAM_INT);
    settled::start((int) $centre->id, $child, optional_param('started', 0, PARAM_BOOL), time());
    redirect(new moodle_url($url, [], 'n' . $child));
}

panel::output(
    $centre,
    'inbox',
    get_string('panel_firstday', $c),
    'panel_start',
    panel_start::context($centre, centres::can_act((int) $USER->id))
);
