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

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_eventform;
use local_oksigeniaeguahe\local\panel_events;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_work($centreid);
$new = optional_param('new', 0, PARAM_BOOL);
$canact = centres::can_act((int) $USER->id);
if (!$id && !$new) {
    // The list of open days and visits, in the panel.
    panel::setup(new moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id]), get_string('tab_events', $c));
    panel::output($centre, 'events', get_string('tab_events', $c), 'panel_events', panel_events::context($centre, $canact));
}
centres::require_act((int) $centre->id);
// Creating or changing one, in the panel: it comes back with what was posted until it is saved.
$url = new moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id] + ($id ? ['id' => $id] : ['new' => 1]));
$event = $id ? events::get($id, (int) $centre->id) : null;
if ($id && !$event) {
    throw new moodle_exception('invalidrecord', 'error');
}
panel::setup($url, get_string($id ? 'panel_event_edit' : 'panel_event_new', $c));
$old = panel_eventform::values($event);
$values = $old;
$errors = [];
$notice = null;
if (data_submitted()) {
    require_sesskey();
    $values = panel_eventform::posted($old);
    $notify = optional_param('notify', -1, PARAM_INT);
    if (($notify >= 0 || optional_param('save', 0, PARAM_BOOL)) && !($errors = panel_eventform::errors($values))) {
        [$changes, $reached] = panel_eventform::changes($centre, $old, $values);
        if ($changes && $notify < 0) {
            // Nothing is saved or sent before the team sees what reaches the families.
            $notice = panel_eventform::notice($centre, $changes, $reached, $values);
        } else {
            $saved = events::save((int) $centre->id, panel_eventform::data($values, $old));
            $told = $changes && $notify > 0 ? panel_eventform::tell(
                $centre,
                events::get($saved, (int) $centre->id),
                $reached,
                (int) $USER->id
            ) : 0;
            redirect(
                new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $saved]),
                $told ? get_string($told === 1 ? 'panel_told_one' : 'panel_told', $c, $told) : '',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }
}
panel::output(
    $centre,
    'events',
    get_string($id ? 'panel_event_edit' : 'panel_event_new', $c),
    'panel_eventform',
    panel_eventform::context($centre, $values, $errors, $notice)
);
