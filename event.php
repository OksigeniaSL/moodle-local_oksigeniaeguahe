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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
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
if (data_submitted() && confirm_sesskey() && optional_param('action', '', PARAM_ALPHA) === 'invitego') {
    // Seen and confirmed: the invitation to the families that are not of the centre yet, once, and only these.
    centres::require_act((int) $centre->id);
    // Hundreds of mails do not fit in a page: they go in the background.
    $sent = invites::queue($event, $centre, (int) $USER->id, time(), '', optional_param_array('para', [], PARAM_INT));
    $lines = [];
    if ($sent['email']) {
        $lines[] = get_string($sent['email'] === 1 ? 'panel_invite_going_one' : 'panel_invite_going', $c, $sent['email']);
    }
    if ($sent['phone']) {
        $lines[] = $sent['said'] !== '' ? $sent['said'] : get_string('panel_invite_going_phone', $c, $sent['phone']);
    }
    invites::say((int) $event->id, $lines ? implode(' ', $lines) : get_string('panel_invite_nobody', $c), !$lines);
    redirect(new moodle_url($url, [], 'invitar'));
}
if (data_submitted() && confirm_sesskey() && optional_param('invitar', 0, PARAM_BOOL)) {
    // The invitation being prepared: the words of the team are kept with the act, and who is ticked with the session of
    // whoever prepares it, whatever is pressed.
    centres::require_act((int) $centre->id);
    $event->invitewords = core_text::substr(trim(optional_param('invitewords', '', PARAM_TEXT)), 0, 1500) ?: null;
    $DB->set_field(events::T, 'invitewords', $event->invitewords, ['id' => $event->id]);
    $eligible = invites::eligible($event);
    $groups = invites::groups($event, $eligible);
    $group = optional_param('gusto', '', PARAM_ALPHANUMEXT);
    $group = isset($groups[$group]) ? $group : '';
    $shown = $group !== '' ? $groups[$group]['ids'] : array_map('intval', array_keys($eligible));
    $action = optional_param('action', '', PARAM_ALPHA);
    $ticked = array_map('intval', optional_param_array('para', [], PARAM_INT));
    $ticked = $action === 'invitetick' ? $shown : ($action === 'inviteuntick' ? [] : $ticked);
    invites::tick((int) $event->id, $shown, $ticked);
    // Another group of families to choose from.
    $other = optional_param('pasar', null, PARAM_ALPHANUMEXT);
    $group = $other !== null ? (isset($groups[$other]) ? $other : '') : $group;
    $params = $group !== '' ? ['gusto' => $group] : [];
    if ($action === 'invitetest') {
        // To whoever is going to send it, to see how it arrives: it invites nobody.
        $out = invites::try_on($event, $centre, $USER);
        invites::say(
            (int) $event->id,
            $out ? get_string('panel_invite_tested', $c, $USER->email) : get_string('panel_invite_tested_no', $c),
            !$out
        );
    } else if ($action === 'invite') {
        // Before it goes, how many and who, counted here: never what the button said before.
        if (array_intersect($shown, $ticked)) {
            $params['confirmar'] = 1;
        } else {
            invites::say((int) $event->id, get_string('panel_invite_nobody', $c), true);
        }
    }
    redirect(new moodle_url($url, $params, 'invitar'));
}
if (data_submitted() && confirm_sesskey() && optional_param('action', '', PARAM_ALPHA) === 'invitestop') {
    // The letters that have not gone out yet, stopped: those families can be invited again.
    centres::require_act((int) $centre->id);
    $left = invites::stop((int) $event->id);
    if ($left) {
        invites::say((int) $event->id, get_string($left === 1 ? 'panel_invite_stopped_one' : 'panel_invite_stopped', $c, $left));
    }
    redirect(new moodle_url($url, [], 'invitar'));
}
if (data_submitted() && confirm_sesskey() && optional_param('action', '', PARAM_ALPHA) === 'withdraw') {
    // A send by phone still waiting for approval, taken back: its families can be invited again.
    centres::require_act((int) $centre->id);
    $bulk = required_param('bulk', PARAM_INT);
    if ($bulk === invites::last_bulk((int) $event->id)) {
        invites::withdraw($bulk, (int) $USER->id);
    }
    redirect(new moodle_url($url, [], 'invitar'));
}
if (data_submitted() && confirm_sesskey() && optional_param('action', '', PARAM_ALPHA) === 'cancel') {
    // The act is called off: every family signed up is told, and nothing more goes out for it.
    centres::require_act((int) $centre->id);
    $told = events::cancel($event, $centre, (int) $USER->id, optional_param('message', '', PARAM_TEXT), time());
    redirect(
        new moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id]),
        get_string('event_cancelled_done', $c, $told),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
if (data_submitted() && confirm_sesskey() && optional_param('action', '', PARAM_ALPHA) === 'unsign') {
    // A family is not coming after all, as it told a person.
    centres::require_act((int) $centre->id);
    $signup = $DB->get_record(events::SIGNUPS, ['id' => required_param('signup', PARAM_INT), 'eventid' => $event->id]);
    $household = $signup ? households::get((int) $signup->householdid, (int) $centre->id) : null;
    if ($household) {
        events::unsign($event, $household, get_string('event_unsigned_team', $c, format_string($event->name)), false);
    }
    redirect(new moodle_url($url, [], 'puerta'));
}
if (data_submitted() && confirm_sesskey()) {
    centres::require_act((int) $centre->id);
    $signupid = required_param('signup', PARAM_INT);
    // Marking that a family came, only from the day of the act on: before it, the door is not open.
    $came = (bool) required_param('came', PARAM_BOOL);
    if (!$came || \local_oksigeniaeguahe\local\panel::days_between((int) $event->timestart, time()) >= 0) {
        events::attend($event, $signupid, $came, optional_param('at', 0, PARAM_INT));
    }
    redirect(new moodle_url($url, [], 's' . $signupid));
}

if ($poster) {
    // The poster to print, as a whole page of its own: A4, or A3 with the same sheet scaled.
    @header('Content-Type: text/html; charset=utf-8');
    echo poster::html(
        $event,
        $centre,
        optional_param('paper', 'A4', PARAM_ALPHANUM),
        optional_param('door', 0, PARAM_BOOL),
        optional_param('qr', 'puerta', PARAM_ALPHANUMEXT)
    );
    die();
}

panel::output(
    $centre,
    'events',
    format_string($event->name),
    'panel_event',
    panel_event::context(
        $event,
        $centre,
        $canact,
        0,
        optional_param('gusto', '', PARAM_ALPHANUMEXT),
        optional_param('confirmar', 0, PARAM_BOOL)
    ),
    true
);
