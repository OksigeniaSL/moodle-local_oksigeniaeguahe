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
 * A household for the team: who they are, the way of each child, what was said, the permissions, and what to do next
 * (note an answer, a note, move a way, give the family a new link).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\household_ai;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\family;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\messaging;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\renewal;
use local_oksigeniaeguahe\local\settled;
use local_oksigeniaeguahe\local\siblings;
use local_oksigeniaeguahe\local\visits;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
$household = households::get($id, 0);
if (!$household) {
    throw new moodle_exception('invalidrecord', 'error');
}
$centre = centres::require_work((int) $household->centreid);
$url = new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]);
panel::setup($url, get_string('household', $c));

$canact = centres::can_act((int) $USER->id);
if ($action && !$canact) {
    centres::require_act((int) $centre->id);
}

if (optional_param('typing', 0, PARAM_BOOL)) {
    // The beat of the composer: someone is writing to this family now. Only who may write.
    require_sesskey();
    centres::require_act((int) $centre->id);
    attend::beat((int) $household->id, (int) $USER->id);
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    die();
}

$newlink = '';
$prefill = '';
$drafted = false;
$use = optional_param('use', 0, PARAM_INT);
if ($use && $canact && data_submitted() && confirm_sesskey()) {
    // An answer of always chosen without the script: the file comes back with it in the answer.
    $prefill = (string) attend::use_reply(households::full($household), $use);
}
if (optional_param('despedida', 0, PARAM_BOOL) && $canact && $prefill === '') {
    // The farewell, without the script: the message comes written in the answer.
    $farewell = panel_household::context($household, $centre, true)['proposal'];
    $prefill = $farewell ? $farewell['text'] : '';
}
if (optional_param('primerdia', 0, PARAM_BOOL) && $canact && $prefill === '') {
    // What to write around the first day, without the script: the message comes written in the answer.
    $proposal = panel_household::firstday_proposal($household, households::full($household), $centre, time());
    $prefill = $proposal ? $proposal['text'] : '';
}
if (optional_param('cumple', 0, PARAM_BOOL) && $canact && $prefill === '') {
    // A birthday, without the script: the message comes written in the answer.
    $proposal = panel_household::birthday_proposal($household, households::full($household), $centre, time());
    $prefill = $proposal ? $proposal['text'] : '';
}
if (optional_param('hermano', 0, PARAM_INT) && $canact && $prefill === '') {
    // The proposal of a brother or sister, without the script: the message comes written in the answer.
    $proposal = panel_household::sibling_proposal($household, households::full($household), $centre, time());
    $prefill = $proposal ? $proposal['text'] : '';
}
if (optional_param('ia', 0, PARAM_BOOL) && $canact && data_submitted() && confirm_sesskey()) {
    // A draft of the AI of the centre, to read and change before sending: nothing is sent, and what the person had begun
    // to write is kept or finished.
    $started = trim(optional_param('body', '', PARAM_TEXT));
    $greeting = panel_household::greeting(households::full($household));
    $started = $started === trim($greeting) ? '' : $started;
    $draft = household_ai::draft($household, $centre, (int) $USER->id, $started, time());
    if ($draft && $draft['draft'] !== '') {
        $prefill = rtrim($greeting) . ($greeting !== '' ? "\n\n" : '') . $draft['draft'];
        $drafted = true;
    } else {
        $prefill = $started;
        \core\notification::warning(get_string('panel_ai_draft_failed', $c));
    }
    $action = '';
}
if ($action && data_submitted() && confirm_sesskey()) {
    $body = trim(optional_param('body', '', PARAM_TEXT));
    // The greeting the answer starts with is not something said.
    if ($body === trim(panel_household::greeting(households::full($household)))) {
        $body = '';
    }
    $channel = optional_param('channel', 'other', PARAM_ALPHA);
    if ($action === 'send') {
        // The answer of the file, by the way chosen: the phone app, email, or a call written down.
        $action = ['mobile' => 'replyphone', 'email' => 'reply'][$channel] ?? 'answer';
    }
    // What the team writes down by hand: the phone app counts as WhatsApp.
    $channel = ['mobile' => 'whatsapp'][$channel] ?? $channel;
    $channel = in_array($channel, lists::HAND_CHANNELS, true) ? $channel : 'other';
    // After answering: what comes next, promised with its day, and the answer kept as one of always if asked.
    $aftersend = function () use ($household, $body) {
        global $USER;
        $day = publicpage::date_param('nextday');
        $when = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $d) ? make_timestamp(
            (int) $d[1],
            (int) $d[2],
            (int) $d[3],
            12,
            0,
            0,
            \core_date::get_server_timezone()
        ) : 0;
        attend::promise($household, (int) $USER->id, optional_param('next', '', PARAM_TEXT), $when);
        if ($body !== '' && optional_param('savereply', 0, PARAM_BOOL)) {
            attend::keep_reply($household, (int) $USER->id, $body);
        }
        // A brother or sister whose turn it is, named in what was sent: it is written to.
        $said = \core_text::strtolower($body);
        foreach (siblings::of((int) $household->id) as $sibling) {
            $named = $said !== '' && \core_text::strpos($said, \core_text::strtolower($sibling->firstname)) !== false;
            if ($named && !(int) $sibling->proposed) {
                siblings::proposed($household, (int) $sibling->id, time());
            }
        }
    };
    switch ($action) {
        case 'leave':
            // They are not with us any more: why and a line; the farewell is proposed, and a person sends it.
            $reason = optional_param('reason', 'unknown', PARAM_ALPHA);
            settled::leave($household, (int) $USER->id, $reason, optional_param('note', '', PARAM_TEXT), time());
            redirect(new moodle_url($url, [], 'se-van'));
            break;
        case 'renewal':
            // Next school year, as the family told a person: the answer, or why it does not go on.
            $child = required_param('child', PARAM_INT);
            renewal::from_team(
                $centre,
                $household,
                $child,
                optional_param('answer', '', PARAM_ALPHA),
                optional_param('reason', '', PARAM_ALPHA),
                (int) $USER->id,
                time()
            );
            redirect(new moodle_url($url, [], 'sigue-' . $child));
            break;
        case 'stay':
            settled::stay($household, time());
            redirect(new moodle_url($url, [], 'se-van'));
            break;
        case 'familysame':
            // A person says it is that family of the centre: its children go to it when they enrol.
            $familyid = optional_param('familia', 0, PARAM_INT);
            if (array_key_exists($familyid, family::hints(households::full($household)))) {
                family::confirm($household, $familyid);
            }
            redirect(new moodle_url($url, [], 'familia-centro'));
            break;
        case 'familynot':
            family::confirm($household, -1);
            redirect(new moodle_url($url, [], 'familia-centro'));
            break;
        case 'familystill':
            // The common record said they left; a person says they are still here.
            family::still_here($household);
            redirect(new moodle_url($url, [], 'se-van'));
            break;
        case 'ambassador':
            // They told a person they would gladly tell another family how it went: an ambassador, with its invitation.
            if (settled::in_centre(array_values(households::full($household)->children))) {
                settled::volunteer($household, $centre, time());
            }
            redirect(new moodle_url($url, [], 'embajadora'));
            break;
        case 'notambassador':
            // No longer: its invitation stops working, and nothing else changes.
            $DB->set_field(households::T, 'volunteer', 0, ['id' => $household->id]);
            redirect(new moodle_url($url, [], 'embajadora'));
            break;
        case 'thanked':
            // The family that told them about the centre was thanked, or the person decided not to.
            $DB->set_field(households::T, 'thanked', time(), ['id' => $household->id]);
            redirect($url);
            break;
        case 'siblingadd':
            siblings::add(
                $household,
                optional_param('siblingname', '', PARAM_TEXT),
                optional_param('siblingyear', 0, PARAM_INT),
                'team',
                time()
            );
            redirect(new moodle_url($url, [], 'hermanos'));
            break;
        case 'siblingremove':
            siblings::remove($household, optional_param('hermano', 0, PARAM_INT));
            redirect(new moodle_url($url, [], 'hermanos'));
            break;
        case 'take':
            attend::take($household, (int) $USER->id);
            redirect($url);
            break;
        case 'promisedone':
            attend::done($household, required_param('promise', PARAM_INT), (int) $USER->id);
            redirect($url);
            break;
        case 'reply':
            // The answer goes by email as it was written, with the page of the family below.
            if ($body === '') {
                redirect($url, get_string('reply_empty', $c), null, \core\output\notification::NOTIFY_ERROR);
            }
            $sent = false;
            ob_start();
            try {
                $sent = familymail::send(
                    'reply',
                    $household,
                    $centre,
                    null,
                    $household->lang ?: $centre->lang,
                    households::new_link($household, 'reply'),
                    null,
                    $body
                );
            } catch (\Throwable $e) {
                $sent = false;
            } finally {
                ob_end_clean();
            }
            if (!$sent) {
                redirect($url, get_string('reply_failed', $c), null, \core\output\notification::NOTIFY_ERROR);
            }
            households::answered($household, (int) $USER->id, 'email', $body);
            $aftersend();
            redirect($url, get_string('reply_sent', $c), null, \core\output\notification::NOTIFY_SUCCESS);
            break;
        case 'replyphone':
            // The answer on the phones the family joined with, Telegram or WhatsApp.
            if ($body === '') {
                redirect($url, get_string('reply_empty', $c), null, \core\output\notification::NOTIFY_ERROR);
            }
            $sentby = messaging::reply($household, $centre, $body);
            if ($sentby === null) {
                redirect($url, get_string('reply_failed', $c), null, \core\output\notification::NOTIFY_ERROR);
            }
            households::answered($household, (int) $USER->id, $sentby, $body);
            $aftersend();
            redirect($url, get_string('reply_phone_sent', $c), null, \core\output\notification::NOTIFY_SUCCESS);
            break;
        case 'answer':
            households::answered($household, (int) $USER->id, $channel, $body);
            $aftersend();
            redirect($url, get_string('answered_saved', $c), null, \core\output\notification::NOTIFY_SUCCESS);
            break;
        case 'received':
            households::received($household, $channel, $body, true);
            redirect($url);
            break;
        case 'note':
            if ($body !== '') {
                households::note($household, (int) $USER->id, $body);
            }
            redirect($url);
            break;
        case 'stage':
            $childid = required_param('child', PARAM_INT);
            $child = $DB->get_record(
                households::CHILDREN,
                ['id' => $childid, 'householdid' => $household->id],
                '*',
                MUST_EXIST
            );
            $stage = required_param('stage', PARAM_ALPHA);
            if (in_array($stage, lists::STAGES, true)) {
                households::set_stage($child, $stage, optional_param('reason', null, PARAM_ALPHA));
            }
            redirect($url);
            break;
        case 'link':
            // The new link is shown once, to send it to the family; all the ones before stop working.
            $newlink = (new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => households::renew_links($household)]))->out(false);
            break;
        case 'propose':
            // A visit of its own: the day, the hour and who guides them; the invitation goes by email if they have one.
            $date = publicpage::date_param('date');
            $time = panel::clock(optional_param('time', '', PARAM_TEXT));
            $start = 0;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) && preg_match('/^(\d{1,2}):(\d{2})$/', $time, $t)) {
                // Where the centre lives, as the act form reads its hours: whoever proposes it may have another zone.
                $start = make_timestamp(
                    (int) $d[1],
                    (int) $d[2],
                    (int) $d[3],
                    (int) $t[1],
                    (int) $t[2],
                    0,
                    \core_date::get_server_timezone()
                );
            }
            if ($start <= time()) {
                redirect($url, get_string('visit_err_when', $c), null, \core\output\notification::NOTIFY_ERROR);
            }
            $event = visits::propose(
                $household,
                $start,
                optional_param('minutes', 45, PARAM_INT),
                optional_param('guide', 0, PARAM_INT),
                optional_param('place', '', PARAM_TEXT),
                optional_param('people', 2, PARAM_INT)
            );
            $token = households::new_link($household, 'invitation');
            $lang = $household->lang ?: $centre->lang;
            $signup = familymail::signup($household, (int) $event->id);
            $sent = false;
            ob_start();
            try {
                $sent = $signup && familymail::send('invitation', $household, $centre, $signup, $lang, $token);
            } catch (\Throwable $e) {
                $sent = false;
            } finally {
                ob_end_clean();
            }
            $host = centres::hosts((int) $centre->id, array_filter([(int) $event->hosts]))[0] ?? null;
            $note = get_string('visit_proposed_note', $c, (object) [
                'when' => userdate($start, get_string('strftimedaydatetime', 'langconfig')),
                'guide' => $host ? $host->name : get_string('visit_guide_none', $c),
            ]);
            households::answered($household, (int) $USER->id, $sent ? 'email' : 'other', $note);
            if ($sent) {
                redirect($url, get_string('visit_proposed_sent', $c), null, \core\output\notification::NOTIFY_SUCCESS);
            }
            // Without an email, the team tells them, with the link of their page shown once.
            \core\notification::warning(get_string('visit_proposed_nomail', $c));
            $newlink = (new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]))->out(false);
            break;
        case 'erase':
            households::erase($household);
            redirect(
                new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]),
                get_string('erased_by_team', $c),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
    }
}

panel::output(
    $centre,
    'inbox',
    panel::adults_line(households::full($household)->adults),
    'panel_household',
    panel_household::context($household, $centre, $canact, $newlink, 0, $prefill, $drafted)
);
