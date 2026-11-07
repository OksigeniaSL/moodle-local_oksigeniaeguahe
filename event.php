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
use local_oksigeniaeguahe\local\poster;
use local_oksigeniaeguahe\local\staff;

$id = required_param('id', PARAM_INT);
$poster = optional_param('poster', 0, PARAM_BOOL);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
$event = $DB->get_record(events::T, ['id' => $id], '*', MUST_EXIST);
$centre = centres::require_work((int) $event->centreid);
$url = new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]);
$PAGE->set_context(context_system::instance());
staff::setup($url, format_string($event->name), $centre);

$canact = centres::can_act((int) $USER->id);
if (data_submitted() && confirm_sesskey()) {
    centres::require_act((int) $centre->id);
    $signupid = required_param('signup', PARAM_INT);
    events::attend($event, $signupid, (bool) required_param('came', PARAM_BOOL));
    redirect(new moodle_url($url, [], 's' . $signupid));
}

// The short link when the site has a shortener: the one to copy and to read aloud.
$public = events::short_url($event) ?? events::url($event)->out(false);
if ($poster) {
    // The poster to print, as a whole page of its own: A4, or A3 with the same sheet scaled.
    @header('Content-Type: text/html; charset=utf-8');
    echo poster::html($event, $centre, optional_param('paper', 'A4', PARAM_ALPHANUM));
    die();
}

$slots = events::slots((int) $event->id);
$groups = [];
foreach ($slots as $slot) {
    $groups[$slot->id] = ['title' => userdate((int) $slot->timestart, get_string('strftimedatetimeshort', 'langconfig')) . '–' .
        userdate((int) $slot->timeend, get_string('strftimetime24', 'langconfig')), 'capacity' => (int) $slot->capacity,
        'people' => 0, 'signups' => []];
}
$people = 0;
$came = 0;
$households = 0;
foreach (events::signups($event) as $signup) {
    $children = [];
    foreach ($signup->household->children as $child) {
        $children[] = $child->firstname . ($child->offer ? ' · ' . format_string($child->offer->name) : '');
    }
    $people += (int) $signup->people;
    $came += $signup->attended ? 1 : 0;
    $households++;
    $key = isset($groups[$signup->slotid]) ? (int) $signup->slotid : 0;
    if (!isset($groups[$key])) {
        // Without turns, or a family added at the door without one.
        $groups[$key] = ['title' => $slots ? get_string('event_noslot', $c) : '', 'capacity' => 0, 'people' => 0,
            'signups' => []];
    }
    $groups[$key]['people'] += (int) $signup->people;
    $groups[$key]['signups'][] = [
        'id' => $signup->id,
        'names' => staff::adult_names($signup->household->adults),
        'incentre' => !empty($signup->household->incentre),
        'children' => implode(', ', $children),
        'people' => (int) $signup->people,
        'came' => (bool) $signup->attended,
        'url' => (new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $signup->householdid]))->out(false),
    ];
}
foreach ($groups as $key => $group) {
    $groups[$key]['hassignups'] = (bool) $group['signups'];
    $groups[$key]['load'] = $group['capacity'] ? get_string('event_slot_load', $c, (object) ['people' => $group['people'],
        'capacity' => $group['capacity']]) : '';
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/event', [
    'tabs' => staff::tabs($centre, 'events'),
    'name' => format_string($event->name),
    'kind' => get_string('event_kind_' . $event->kind, $c),
    'when' => userdate((int) $event->timestart, get_string('strftimedaydatetime', 'langconfig')),
    'place' => $event->place ? format_string($event->place) : '',
    'open' => $event->open && $event->timeend >= time(),
    'capacity' => (int) $event->capacity,
    'households' => $households,
    'people' => $people,
    'came' => $came,
    'qr' => events::qr_svg($event),
    'link' => $public,
    'posterurl' => (new moodle_url($url, ['poster' => 1]))->out(false),
    'posterurla3' => (new moodle_url($url, ['poster' => 1, 'paper' => 'A3']))->out(false),
    'editurl' => (new moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id, 'id' => $event->id]))->out(false),
    'addurl' => (new moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id, 'eventid' => $event->id]))->out(false),
    'groups' => array_values($groups),
    'hassignups' => (bool) $households,
    'canact' => $canact,
    'action' => $url->out(false),
    'sesskey' => sesskey(),
]);
echo $OUTPUT->footer();
