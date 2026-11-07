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

if (data_submitted() && confirm_sesskey()) {
    $signupid = required_param('signup', PARAM_INT);
    events::attend($event, $signupid, (bool) required_param('came', PARAM_BOOL));
    redirect(new moodle_url($url, [], 's' . $signupid));
}

$public = events::url($event)->out(false);
if ($poster) {
    // The poster to print: the centre, the event, the QR and the link, nothing else.
    $PAGE->set_pagelayout('print');
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniaeguahe/poster', [
        'centre' => format_string($centre->name),
        'event' => format_string($event->name),
        'when' => userdate((int) $event->timestart, get_string('strftimedaydatetime', 'langconfig')),
        'qr' => events::qr_svg($event),
        'link' => $public,
    ]);
    echo $OUTPUT->footer();
    die();
}

$signups = [];
$people = 0;
$came = 0;
foreach (events::signups($event) as $signup) {
    $children = [];
    foreach ($signup->household->children as $child) {
        $children[] = $child->firstname . ($child->offer ? ' · ' . format_string($child->offer->name) : '');
    }
    $people += (int) $signup->people;
    $came += $signup->attended ? 1 : 0;
    $signups[] = [
        'id' => $signup->id,
        'names' => staff::adult_names($signup->household->adults),
        'children' => implode(', ', $children),
        'people' => (int) $signup->people,
        'came' => (bool) $signup->attended,
        'url' => (new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $signup->householdid]))->out(false),
    ];
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
    'households' => count($signups),
    'people' => $people,
    'came' => $came,
    'qr' => events::qr_svg($event),
    'link' => $public,
    'posterurl' => (new moodle_url($url, ['poster' => 1]))->out(false),
    'editurl' => (new moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id, 'id' => $event->id]))->out(false),
    'addurl' => (new moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id, 'eventid' => $event->id]))->out(false),
    'signups' => $signups,
    'hassignups' => (bool) $signups,
    'action' => $url->out(false),
    'sesskey' => sesskey(),
]);
echo $OUTPUT->footer();
