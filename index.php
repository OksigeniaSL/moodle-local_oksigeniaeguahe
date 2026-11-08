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
 * The inbox of a centre: who waits for an answer, since when, and how each offer is going.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);

$centreid = optional_param('c', 0, PARAM_INT);
$all = optional_param('all', 0, PARAM_BOOL);
$c = 'local_oksigeniaeguahe';

$centre = centres::pick($centreid);
if (!$centre) {
    // Someone with several centres chooses one.
    staff::setup(new moodle_url('/local/oksigeniaeguahe/index.php'), get_string('navname', $c));
    $list = [];
    foreach (centres::for_user((int) $USER->id) as $each) {
        $list[] = ['name' => format_string($each->name), 'url' => (new moodle_url(
            '/local/oksigeniaeguahe/index.php',
            ['c' => $each->id]
        ))->out(false), 'waiting' => households::waiting_count((int) $each->id)];
    }
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniaeguahe/pick_centre', ['centres' => $list, 'hascentres' => (bool) $list,
        'manageurl' => has_capability('local/oksigeniaeguahe:manage', context_system::instance()) ?
            (new moodle_url('/local/oksigeniaeguahe/centres.php'))->out(false) : '']);
    echo $OUTPUT->footer();
    die();
}

if (optional_param('away', 0, PARAM_BOOL) && data_submitted() && confirm_sesskey()) {
    // The relay: until when this person is away, or that they are back.
    $until = optional_param('until', '', PARAM_TEXT);
    $when = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $until, $d) ? make_timestamp((int) $d[1], (int) $d[2], (int) $d[3], 12) : 0;
    attend::set_away((int) $centre->id, (int) $USER->id, optional_param('back', 0, PARAM_BOOL) ? 0 : $when);
    redirect(new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]));
}
if (optional_param('check', 0, PARAM_BOOL)) {
    // What the page asks every minute: how many wait, and the family that arrived last (its courses are formatted).
    $PAGE->set_context(context_system::instance());
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');
    echo json_encode(panel_inbox::check($centre));
    die();
}
// The list as cards or as a ledger, kept for the person.
$density = optional_param('vista', '', PARAM_ALPHA);
if (in_array($density, ['fichas', 'lista'], true)) {
    set_user_preference(panel_inbox::DENSITY, $density);
}
$view = ['course' => optional_param('curso', 0, PARAM_INT), 'stage' => optional_param('etapa', '', PARAM_ALPHA),
    'density' => $density];
$url = new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id] + ($all ? ['all' => 1] : []));
panel::setup($url, get_string('inbox', $c));
panel::output($centre, 'inbox', get_string('inbox', $c), 'panel_inbox', panel_inbox::context(
    $centre,
    $all,
    (int) $USER->id,
    centres::can_act((int) $USER->id),
    $view
));
