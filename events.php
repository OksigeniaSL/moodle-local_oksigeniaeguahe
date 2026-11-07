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
 * The open days and visits of a centre, and a new one.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\form\event_form;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_work($centreid);
$url = new moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id] + ($id ? ['id' => $id] : []));
staff::setup($url, get_string('tab_events', $c), $centre);

$canact = centres::can_act((int) $USER->id);
$event = $id ? events::get($id, (int) $centre->id) : null;
if ($id && !$canact) {
    centres::require_act((int) $centre->id);
}
$hosts = [];
foreach (centres::hosts((int) $centre->id) as $host) {
    $hosts[$host->memberid] = $host->name . ($host->role !== '' ? ' · ' . $host->role : '');
}
$form = new event_form($url, ['slots' => $event ? count(events::slots((int) $event->id)) : 0, 'hosts' => $hosts]);
if ($event) {
    $defaults = (array) $event + ['c' => $centre->id];
    $defaults['hosts'] = array_filter(array_map('intval', explode(',', (string) $event->hosts)));
    foreach (array_values(events::slots((int) $event->id)) as $i => $slot) {
        $defaults["slotid[$i]"] = $slot->id;
        $defaults["slotstart[$i]"] = $slot->timestart;
        $defaults["slotminutes[$i]"] = (int) round(($slot->timeend - $slot->timestart) / MINSECS);
        $defaults["slotcapacity[$i]"] = $slot->capacity;
    }
    $form->set_data((object) $defaults);
} else {
    $start = make_timestamp((int) date('Y'), (int) date('n'), (int) date('j') + 14, 10, 0);
    $form->set_data(['c' => $centre->id, 'id' => 0, 'timestart' => $start, 'timeend' => $start + 3 * HOURSECS,
        'name' => get_string('event_kind_openday', $c)]);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id]));
}
if ($canact && $data = $form->get_data()) {
    $data->slots = event_form::slots($data);
    $data->hosts = $data->hosts ?? [];
    $saved = events::save((int) $centre->id, $data);
    redirect(new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $saved]));
}

$list = [];
foreach (events::for_centre((int) $centre->id) as $each) {
    $list[] = [
        'name' => format_string($each->name),
        'kind' => get_string('event_kind_' . $each->kind, $c),
        'when' => userdate((int) $each->timestart, get_string('strftimedatetimeshort', 'langconfig')),
        'past' => $each->timeend < time(),
        'open' => (bool) $each->open,
        'signedup' => (int) $each->signedup,
        'people' => (int) $each->people,
        'came' => (int) $each->came,
        'capacity' => (int) $each->capacity,
        'url' => (new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $each->id]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/events', ['tabs' => staff::tabs($centre, 'events'),
    'events' => $list, 'hasevents' => (bool) $list, 'editing' => (bool) $event]);
if ($canact) {
    echo $OUTPUT->heading(get_string($event ? 'event_edit' : 'event_new', $c), 3);
    $form->display();
}
echo $OUTPUT->footer();
