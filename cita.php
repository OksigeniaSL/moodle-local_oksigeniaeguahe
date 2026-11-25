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
 * Giving an appointment to a family, changing it or calling it off.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\appointments;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_give;

require_login(null, false);

$c = 'local_oksigeniaeguahe';
$day = optional_param('dia', '', PARAM_RAW_TRIMMED);
$day = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1]) ? $day : '';
// The fifteen days that are looked at.
$from = optional_param('desde', '', PARAM_RAW_TRIMMED);
$from = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1]) ? $from : '';
$hid = optional_param('h', 0, PARAM_INT);
if (!$hid) {
    // From the week: whose it is, first.
    $centre = centres::require_act(required_param('c', PARAM_INT));
    panel::setup(new moodle_url('/local/oksigeniaeguahe/cita.php', ['c' => $centre->id]), get_string('give_title', $c));
    panel::output($centre, 'events', get_string('give_title', $c), 'panel_give_who', panel_give::who(
        $centre,
        \core_text::substr(optional_param('q', '', PARAM_TEXT), 0, 100),
        $day
    ));
    die();
}
$household = households::get($hid, 0);
if (!$household) {
    throw new moodle_exception('invalidrecord', 'error');
}
$centre = centres::require_work((int) $household->centreid);
centres::require_act((int) $centre->id);
$id = optional_param('id', 0, PARAM_INT);
$event = $id ? $DB->get_record(events::T, ['id' => $id, 'householdid' => $household->id, 'cancelled' => 0]) : null;
if ($id && !$event) {
    throw new moodle_exception('invalidrecord', 'error');
}
$off = $event && optional_param('anular', 0, PARAM_BOOL);
$url = new moodle_url('/local/oksigeniaeguahe/cita.php', ['h' => $household->id] + ($event ? ['id' => $event->id] : []) +
    ($off ? ['anular' => 1] : []));
$file = new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]);
panel::setup($url, get_string($off ? 'give_off_title' : ($event ? 'give_move_title' : 'give_title'), $c));
$values = [];
// Moved in the calendar of the team: it comes with that day and hour, and how long.
$at = $event ? optional_param('a', 0, PARAM_INT) : 0;
if ($at > time()) {
    $values = ['start' => $at, 'minutes' => max(15, min(240, optional_param('m', 45, PARAM_INT)))];
}
if (data_submitted() && confirm_sesskey()) {
    if ($off) {
        $tell = optional_param('tell', 0, PARAM_BOOL);
        $done = appointments::off(
            $household,
            $centre,
            (int) $event->id,
            optional_param('message', '', PARAM_TEXT),
            (int) $USER->id,
            (bool) $tell
        );
        redirect($file, get_string($done ? ($tell ? 'give_off_done_told' : 'give_off_done') : 'give_off_late', $c));
    }
    $values = panel_give::posted($centre, time());
    if ($values['error'] === '') {
        if ($event) {
            $sent = appointments::move(
                $household,
                $centre,
                (int) $event->id,
                $values['start'],
                $values['minutes'],
                $values['guide'],
                $values['place'],
                (bool) $values['tell']
            );
        } else {
            $given = appointments::give(
                $household,
                $centre,
                $values['start'],
                $values['minutes'],
                $values['guide'],
                $values['place'],
                $values['people'],
                (bool) $values['tell']
            );
            $sent = $given['sent'];
            if ($given['again']) {
                // The same one sent twice: it was already given, and told.
                redirect($file, get_string('give_done_again', $c));
            }
        }
        $clash = \local_oksigeniaeguahe\local\busy::clash(
            (int) $centre->id,
            $values['start'],
            $values['start'] + $values['minutes'] * MINSECS
        );
        $taken = appointments::taken(
            $centre,
            (int) $values['guide'],
            $values['start'],
            $values['start'] + $values['minutes'] * MINSECS,
            (int) ($event->id ?? $given['event']->id)
        );
        $line = get_string($event ? 'give_moved_note' : 'give_note', $c, userdate(
            $values['start'],
            get_string('strftimedaydatetime', 'langconfig')
        ));
        if (!$event && ($sent || $given['asked'])) {
            // Who gives what it asked for, or writes to it, answers the family, with its day in the history.
            households::answered($household, (int) $USER->id, $sent ? 'email' : 'other', $line);
        } else {
            // Changing one, or giving one without telling it, does not answer what else it asked.
            households::told($household, (int) $USER->id, $sent ? 'email' : 'other', $line);
        }
        $late = \local_oksigeniaeguahe\local\calsync::stopped($centre, time());
        redirect($file, implode(' ', array_filter([get_string($sent ? 'give_done_told' : ($values['tell'] ? 'give_done_untold' : 'give_done'), $c),
            $taken, $clash, $late])));
    }
}
panel::output($centre, 'inbox', get_string('give_title', $c), 'panel_give', panel_give::context(
    households::full($household),
    $centre,
    $event ?: null,
    $values + array_filter(['day' => $day, 'from' => $from]),
    (bool) $off
));
