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

use local_oksigeniaeguahe\local\alumni;
use local_oksigeniaeguahe\local\appointments;
use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\household_ai;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\family;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\lookalike;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\messaging;
use local_oksigeniaeguahe\local\notices;
use local_oksigeniaeguahe\local\outs;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_agenda;
use local_oksigeniaeguahe\local\panel_notices;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\renewal;
use local_oksigeniaeguahe\local\settle;
use local_oksigeniaeguahe\local\settled;
use local_oksigeniaeguahe\local\siblings;
use local_oksigeniaeguahe\local\ties;
use local_oksigeniaeguahe\local\visits;
use local_oksigeniaeguahe\local\writeby;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
$household = households::get($id, 0);
if (!$household && ($joined = lookalike::now_in($id))) {
    // It was put together with another: what was open, and the notices of before, lead to the one that stays. What was
    // written there goes with it, to send it from here.
    $household = households::get($joined, 0);
    centres::require_work((int) $household->centreid);
    $written = trim(optional_param('body', '', PARAM_TEXT));
    if ($written !== '') {
        $SESSION->local_oksigeniaeguahe_written[$joined] = ['body' => $written, 'next' => optional_param('next', '', PARAM_TEXT),
            'day' => publicpage::date_param('nextday'), 'why' => ''];
    }
    redirect(
        new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $joined], $written !== '' ? 'contestar' : null),
        get_string($written !== '' ? 'lookalike_now_in_written' : 'lookalike_now_in', $c),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}
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
// What a person had written and did not go (the mail failed, the file was put together with another): it is here again.
$written = [];
if (isset($SESSION->local_oksigeniaeguahe_written[$household->id]) && $canact && !$action) {
    $written = (array) $SESSION->local_oksigeniaeguahe_written[$household->id];
    unset($SESSION->local_oksigeniaeguahe_written[$household->id]);
    $prefill = (string) ($written['body'] ?? '');
}
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
if (optional_param('matricula', 0, PARAM_BOOL) && $canact && $prefill === '') {
    // How to ask for a place, when the family is ready: the message comes written in the answer, and a person sends it.
    $prefill = panel_household::apply_text($household, households::full($household), $centre);
}
if (optional_param('eligieron', 0, PARAM_BOOL) && $canact && $prefill === '') {
    // The goodbye to a family that chose another centre, without the script: the message comes written in the answer.
    $proposal = panel_household::chose_proposal($household, households::full($household), time());
    $prefill = $proposal ? $proposal['text'] : '';
}
if (optional_param('espera', 0, PARAM_BOOL) && $canact && $prefill === '') {
    // On the waiting list, without the script: the message comes written in the answer.
    $proposal = panel_household::waitlist_proposal($household, households::full($household), time());
    $prefill = $proposal ? $proposal['text'] : '';
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
if (optional_param('preguntar', 0, PARAM_BOOL) && $canact && $prefill === '') {
    // Asked as a family on its way not to be written to, and now of the centre: the warm question, written, for a person.
    $prefill = panel_household::writeby_question(households::full($household), $centre);
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
    if ($action === 'keep' || $action === 'apart') {
        // Which of two appointments is the one, or that they are two.
        [$one, $other] = array_pad(explode(',', optional_param('pair', '', PARAM_TAGLIST)), 2, '');
        appointments::$action($household, ...($action === 'keep' ? [$centre, $one, $other, (int) $USER->id] : [$one, $other, (int) $USER->id]));
        redirect($url);
    }
    if ($action === 'resend') {
        // A notice that went, or did not, again.
        $went = notices::resend($household, $centre, optional_param('notice', 0, PARAM_INT));
        redirect($url, get_string($went ? 'notice_resent' : 'notice_notresent', $c));
    }
    if ($action === 'admit') {
        // A guest of an act, now a family of the admissions: the team says so.
        if (households::admit((int) $household->id)) {
            households::note($household, (int) $USER->id, get_string('household_admitted', $c));
        }
        redirect($url);
    }
    if ($action === 'send') {
        // The answer of the file, by the way chosen: the phone app, email, or a call written down.
        $action = ['mobile' => 'replyphone', 'email' => 'reply'][$channel] ?? 'answer';
    }
    // What the team writes down by hand: the phone app counts as WhatsApp.
    $channel = ['mobile' => 'whatsapp'][$channel] ?? $channel;
    $channel = in_array($channel, lists::HAND_CHANNELS, true) ? $channel : 'other';
    // Not sent: what was written, and what was left for later, stays in its place, with why.
    $notsent = function (string $why) use ($household, $url) {
        global $SESSION;
        $SESSION->local_oksigeniaeguahe_written[$household->id] = ['body' => trim(optional_param('body', '', PARAM_TEXT)),
            'next' => optional_param('next', '', PARAM_TEXT), 'day' => publicpage::date_param('nextday'), 'why' => $why];
        redirect(new moodle_url($url, [], 'contestar'));
    };
    // After answering: what comes next, promised with its day, and the answer kept as one of always if asked.
    $aftersend = function () use ($household, $body, $centre) {
        global $USER;
        // What was sent says how to ask for a place (the centre's address or its words): it is written down as sent.
        $how = trim(strtok((string) ($centre->applyhow ?? ''), "\n") ?: '');
        $url = trim((string) ($centre->applyurl ?? ''));
        if ($body !== '' && (($url !== '' && strpos($body, $url) !== false) || ($how !== '' && strpos($body, $how) !== false))) {
            households::apply_sent($household);
        }
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
    if (in_array($action, ['reply', 'replyphone'], true) && writeby::silent($household) && !writeby::wrote_after($household)) {
        // They asked not to be written to: a person may write anyway, once told and having said so.
        if (!optional_param('anyway', 0, PARAM_BOOL)) {
            $notsent(get_string('panel_writeby_need_anyway', $c));
        }
        writeby::anyway((int) $household->id);
    }
    switch ($action) {
        case 'sheetlift':
            // What the sheet said of the calls kept them from the phone: a person says they may be invited by phone after all.
            \local_oksigeniaeguahe\local\teamnotes::lift($household, (int) $USER->id);
            redirect(new moodle_url($url, [], 'como-escribimos'));
            break;
        case 'writeby':
            // What the family said by phone or in person: how to write to them, or not to. Saving with nothing marked
            // is not «no messages»: that has its own button.
            $none = optional_param('nada', 0, PARAM_BOOL);
            $ways = array_values(array_intersect(writeby::channels($centre), optional_param_array('por', [], PARAM_ALPHA)));
            // Apart from the ways: whether they want to be invited to events.
            $noted = !$none && optional_param('evt', 0, PARAM_BOOL) &&
                invites::wanted($household, !optional_param('noinvites', 0, PARAM_BOOL), 'team', (int) $USER->id);
            if (!$none && !$ways && !$noted) {
                redirect(
                    new moodle_url($url, [], 'como-escribimos'),
                    get_string('panel_writeby_pick_one', $c),
                    null,
                    \core\output\notification::NOTIFY_WARNING
                );
            }
            // Only that was noted: the ways stay as they were, never written as if the family had said them.
            if ($none || ($ways && !($noted && $ways === writeby::now($household, $centre)))) {
                writeby::set($household, $none ? [] : $ways, 'team', (int) $USER->id);
            }
            redirect(new moodle_url($url, [], 'como-escribimos'));
            break;
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
        case 'merge':
            // A person says it is the same family written down twice: both go together in the older one.
            // One of those proposed, or another of the centre found by hand.
            $other = households::get(required_param('other', PARAM_INT), (int) $centre->id);
            if (!$other || (int) $other->id === (int) $household->id) {
                redirect(new moodle_url($url, [], 'misma-familia'));
            }
            $kept = lookalike::merge($household, $other, (int) $USER->id);
            redirect(
                new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $kept->id]),
                get_string('lookalike_merged', $c),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
        case 'listchild':
            // Which child the list of the centre enrolled, said by a person.
            listmatch::choose($household, required_param('fila', PARAM_INT), optional_param('nino', 0, PARAM_INT));
            redirect(new moodle_url($url, [], 'segun-lista'));
            break;
        case 'listsay':
            // What the list said by itself is not so, or it is: said by a person.
            listmatch::say(
                $household,
                required_param('fila', PARAM_INT),
                optional_param('si', 0, PARAM_BOOL),
                (int) $USER->id
            );
            redirect(new moodle_url($url, [], 'segun-lista'));
            break;
        case 'notsame':
            // Two different families: it is not asked again.
            lookalike::distinct((int) $centre->id, (int) $household->id, required_param('other', PARAM_INT), (int) $USER->id);
            redirect(new moodle_url($url, [], 'misma-familia'));
            break;
        case 'mailreply':
            // What an answer by mail seemed to say of an invitation: right, noted as on the page of the letter; or not that.
            $done = \local_oksigeniaeguahe\local\replies::confirm(
                $household,
                required_param('respuesta', PARAM_INT),
                optional_param('bien', 0, PARAM_BOOL),
                (int) $USER->id
            );
            redirect(
                $url,
                get_string($done ? 'replies_done' : 'bounce_notdone', $c),
                null,
                $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
            );
            break;
        case 'bounce':
            // What a person heard when calling because the mail came back.
            $done = \local_oksigeniaeguahe\local\bounces::say(
                $household,
                required_param('devuelto', PARAM_INT),
                required_param('dijo', PARAM_ALPHA),
                (int) $USER->id,
                optional_param('correo', '', PARAM_RAW_TRIMMED)
            );
            redirect(
                new moodle_url($url, [], 'quien-escribe'),
                get_string($done ? 'bounce_done' : 'bounce_notdone', $c),
                null,
                $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
            );
            break;
        case 'twice':
            // A person says two lines of the file are the same child: they become one, with everything of both.
            $done = lookalike::join_twice($household, required_param('uno', PARAM_INT), required_param('otro', PARAM_INT), (int) $USER->id);
            redirect(
                new moodle_url($url, [], 'mismo-nino'),
                get_string($done ? 'twice_done' : 'twice_gone', $c),
                null,
                $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
            );
            break;
        case 'nottwice':
            // Two children: it is not asked again.
            lookalike::not_twice($household, required_param('uno', PARAM_INT), required_param('otro', PARAM_INT), (int) $USER->id);
            redirect(new moodle_url($url, [], 'mismo-nino'));
            break;
        case 'untwice':
            $done = lookalike::undo_twice($household, required_param('vez', PARAM_INT), (int) $USER->id);
            redirect(
                new moodle_url($url, [], 'mismo-nino'),
                get_string($done ? 'twice_undone' : 'twice_gone', $c),
                null,
                $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
            );
            break;
        case 'split':
            // Two families joined by mistake: the adults, children, sign-ups and lines chosen go to a new one.
            // Each adult stays or goes (adultgo[id]), or is in both when it was; a page from before sent the ones that go (adults[]).
            $said = optional_param_array('adultgo', [], PARAM_ALPHA);
            $goadults = array_keys(array_filter($said, fn($v) => $v === 'go'));
            $choice = [
                'adults' => array_values(array_unique(array_merge(
                    array_map('intval', $goadults),
                    optional_param_array('adults', [], PARAM_INT)
                ))),
                'both' => array_keys(array_filter($said, fn($v) => $v === 'both')),
                'children' => optional_param_array('children', [], PARAM_ALPHA),
                'signups' => optional_param_array('signups', [], PARAM_ALPHA),
                'contacts' => optional_param_array('contacts', [], PARAM_INT),
                'log' => optional_param('log', 0, PARAM_INT),
            ];
            if (optional_param('hasthings', 0, PARAM_BOOL)) {
                $choice['things'] = optional_param_array('things', [], PARAM_ALPHA);
            }
            try {
                $new = lookalike::split($household, $choice, (int) $USER->id);
            } catch (\moodle_exception $e) {
                redirect(new moodle_url($url, [], 'separar'), $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
            }
            redirect(
                new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $new->id]),
                get_string('lookalike_split_done', $c),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
        case 'familystill':
            // The common record said they left; a person says they are still here.
            family::still_here($household);
            redirect(new moodle_url($url, [], 'se-van'));
            break;
        case 'tie':
            // What ties a child to the centre, as who knows the family says it: one touch marks it, another takes it out.
            $childid = required_param('child', PARAM_INT);
            ties::set(
                $household,
                $childid,
                required_param('tie', PARAM_ALPHA),
                optional_param('on', 0, PARAM_BOOL),
                (int) $USER->id
            );
            redirect(new moodle_url($url, [], 'relacion-' . $childid));
            break;
        case 'out':
            // They go, or they never were a family: why, with what they said and what stops with it.
            $done = outs::set(
                $household,
                $centre,
                optional_param('reason', '', PARAM_ALPHA),
                optional_param('note', '', PARAM_TEXT),
                optional_param('stops', '', PARAM_ALPHA),
                (int) $USER->id
            );
            redirect(
                new moodle_url($url, [], 'salida'),
                get_string($done ? 'out_done' : 'out_pick', $c),
                null,
                $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
            );
            break;
        case 'outundo':
            // It was not so, or they want to go on.
            outs::undo($household, $centre, (int) $USER->id);
            redirect(new moodle_url($url, [], 'salida'), get_string('out_undone', $c));
            break;
        case 'outnotso':
            // What the notes suggest is not so: it is not asked again.
            outs::not_so($household);
            redirect(new moodle_url($url, [], 'salida'));
            break;
        case 'alumni':
            // A person of the team says whether an adult studied at the centre, or answers the question of the list.
            $adultid = required_param('adulto', PARAM_INT);
            if (isset(households::full($household)->adults[$adultid])) {
                $said = optional_param('dijo', '', PARAM_ALPHA);
                alumni::set($household, $adultid, $said === 'si' ? alumni::YES : alumni::NO, 'team', (int) $USER->id);
            }
            redirect(new moodle_url($url, [], 'quien-escribe'));
            break;
        case 'alumniyear':
            // The year a former student finished, as they told a person; nothing else of what was said changes.
            $adultid = required_param('adulto', PARAM_INT);
            $year = alumni::year(optional_param('ano', '', PARAM_TEXT));
            if ($year !== '' && isset(households::full($household)->adults[$adultid])) {
                alumni::set($household, $adultid, alumni::YES, 'team', (int) $USER->id, $year);
            }
            redirect(new moodle_url($url, [], 'quien-escribe'));
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
            // And what was promised for the next campaign goes with it.
            attend::withdraw($household, array_map(
                fn($lang) => \local_oksigeniaeguahe\local\publiclang::str('promise_volunteer', $lang),
                lists::LANGS
            ), 0, (int) $USER->id);
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
                $notsent(get_string('reply_failed', $c));
            }
            households::answered($household, (int) $USER->id, 'email', $body);
            try {
                // And on the phone of the adults of the family with an account, that they have it.
                \local_oksigeniaeguahe\local\appnotices::replied($household, $centre);
            } catch (\Throwable $e) {
                // The answer went: a notice that fails changes nothing of it.
                debugging('Eguahe: notice of the answer not sent: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
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
                $notsent(messaging::$why === 'long' ? get_string('reply_too_long', $c, messaging::innotice()) : get_string('reply_failed', $c));
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
            // Who of the team notes it, for the family: it wrote or called somewhere else.
            households::received($household, $channel, $body, true, null, 0, '', '', true, false, (int) $USER->id);
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
            // The step the page showed: if it is another one now, nothing is changed without seeing it.
            $seen = optional_param('era', '', PARAM_ALPHA);
            if ($seen !== '' && $seen !== $child->stage) {
                redirect($url, get_string('panel_stage_moved', $c, (object) ['name' => panel::tag($child) ?: format_string($child->firstname),
                    'stage' => get_string('stage_' . $child->stage, $c)]), null, \core\output\notification::NOTIFY_WARNING);
            }
            if ($stage === 'closed' && $child->stage === 'enrolled') {
                // One that is at the centre does not «close»: it leaves, with its reason and its farewell.
                redirect(new moodle_url($url, [], 'se-van'), get_string('panel_stage_leave', $c), null, \core\output\notification::NOTIFY_WARNING);
            }
            if ($stage === 'reopen') {
                // Where it was when it was closed.
                $stage = in_array((string) $child->closedstage, lists::STAGES, true) && $child->closedstage !== 'enrolled' ?
                    (string) $child->closedstage : 'inquiry';
            }
            if (in_array($stage, lists::STAGES, true)) {
                households::set_stage($child, $stage, optional_param('reason', null, PARAM_ALPHA));
                if ($stage === 'closed' && optional_param('stopwait', 0, PARAM_BOOL)) {
                    // With its way closed it waits for nobody, and that is not an answer.
                    settle::closed($household, (int) $USER->id);
                }
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
            $given = appointments::give(
                $household,
                $centre,
                $start,
                optional_param('minutes', 45, PARAM_INT),
                optional_param('guide', 0, PARAM_INT),
                optional_param('place', '', PARAM_TEXT),
                optional_param('people', 2, PARAM_INT),
                true
            );
            if ($given['again']) {
                redirect($url, get_string('give_done_again', $c));
            }
            $event = $given['event'];
            $sent = $given['sent'];
            $token = $sent ? '' : households::new_link($household, 'invitation');
            $host = centres::hosts((int) $centre->id, array_filter([(int) $event->hosts]))[0] ?? null;
            $note = get_string('visit_proposed_note', $c, (object) [
                'when' => userdate($start, get_string('strftimedaydatetime', 'langconfig')),
                'guide' => $host ? $host->name : get_string('visit_guide_none', $c),
            ]);
            if ($sent || $given['asked']) {
                households::answered($household, (int) $USER->id, $sent ? 'email' : 'other', $note);
            } else {
                households::told($household, (int) $USER->id, 'other', $note);
            }
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

$context = panel_household::context($household, $centre, $canact, $newlink, 0, $prefill, $drafted);
if ($written) {
    $context['composer']['failed'] = (string) ($written['why'] ?? '');
    $context['composer']['next'] = ['value' => (string) ($written['next'] ?? '')] + $context['composer']['next'];
    $context['composer']['next']['date'] = ['labelled' => false, 'arialabel' => get_string('panel_next_day', $c)] + panel::date_input(
        'nextday',
        (string) ($written['day'] ?? ''),
        $centre,
        'siguiente-dia'
    );
}
panel::output(
    $centre,
    'inbox',
    panel::adults_line(households::full($household)->adults),
    'panel_household',
    $context + [
        // Its appointments, wherever they come from, and the pairs that may be one; and what it was sent.
        'citas' => panel_agenda::of_family(households::full($household), $centre, $canact, time()),
        'avisos' => panel_notices::context($household, $centre, $canact, time()),
        // To put it together with another that Eguahe did not recognise.
        'joinhand' => $canact ? lookalike::by_hand($household, \core_text::substr(optional_param('juntar', '', PARAM_TEXT), 0, 80)) : false,
        // Only signed up to an open act: it is not in admissions, and the team may take it there.
        'guest' => !empty($household->guest) ? ['may' => $canact, 'posturl' => $url->out(false), 'sesskey' => sesskey()] : false,
    ]
);
