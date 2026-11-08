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
 * An open day or a visit for the team: its QR and link for the poster, who signed up and who came, ticked at the door,
 * and a family that turns up without signing up, added there and then.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_event;
use local_oksigeniaeguahe\local\poster;

$id = required_param('id', PARAM_INT);
$poster = optional_param('poster', 0, PARAM_BOOL);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
$event = $DB->get_record(events::T, ['id' => $id], '*', MUST_EXIST);
$centre = centres::require_work((int) $event->centreid);
$url = new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]);
$PAGE->set_context(context_system::instance());
panel::setup($url, format_string($event->name));

$canact = centres::can_act((int) $USER->id);
if (optional_param('check', 0, PARAM_BOOL)) {
    // With two people receiving, what the other one marked: the families that came, with their hour.
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');
    echo json_encode(panel_event::check($event));
    die();
}
if (optional_param('howitwent', 0, PARAM_BOOL)) {
    // While the survey is asked, the results are read again every half minute.
    @header('Content-Type: text/html; charset=utf-8');
    @header('Cache-Control: no-store');
    $results = panel_event::howitwent($event, $centre, $canact, time());
    echo $results ? $OUTPUT->render_from_template('local_oksigeniaeguahe/panel_howitwent', $results + ['i' => panel::icons()]) : '';
    die();
}
if (data_submitted() && confirm_sesskey() && optional_param('action', '', PARAM_ALPHA) === 'outcome') {
    // What the centre does with what the families told: the families who answered read it on their page.
    centres::require_act((int) $centre->id);
    $outcome = trim(optional_param('outcome', '', PARAM_TEXT));
    $DB->set_field(events::T, 'outcome', $outcome === '' ? null : core_text::substr($outcome, 0, 600), ['id' => $event->id]);
    redirect(new moodle_url($url, [], 'como-fue'));
}
if (data_submitted() && confirm_sesskey()) {
    centres::require_act((int) $centre->id);
    $signupid = required_param('signup', PARAM_INT);
    events::attend($event, $signupid, (bool) required_param('came', PARAM_BOOL), optional_param('at', 0, PARAM_INT));
    redirect(new moodle_url($url, [], 's' . $signupid));
}

if ($poster) {
    // The poster to print, as a whole page of its own: A4, or A3 with the same sheet scaled.
    @header('Content-Type: text/html; charset=utf-8');
    echo poster::html($event, $centre, optional_param('paper', 'A4', PARAM_ALPHANUM));
    die();
}

panel::output(
    $centre,
    'events',
    format_string($event->name),
    'panel_event',
    panel_event::context($event, $centre, $canact),
    true
);
