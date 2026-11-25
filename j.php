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
 * Signing up for an open day or a visit, from the phone of the family: what the QR of the poster opens.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Families have no account: the link of the event lets them in.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\acts;
use local_oksigeniaeguahe\local\brief;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\joinpage;
use local_oksigeniaeguahe\local\kit;
use local_oksigeniaeguahe\local\knownpage;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\seen;
use local_oksigeniaeguahe\local\share;
use local_oksigeniaeguahe\local\signup;
use local_oksigeniaeguahe\local\writeby;

$token = optional_param('t', '', PARAM_ALPHANUM);
// Inside the web of the centre (events::embed_code()): it says how tall it is, and after signing up the page of the family
// opens apart.
$embedded = optional_param('embed', 0, PARAM_BOOL);
$url = new moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $token] + ($embedded ? ['embed' => 1] : []));
publicpage::setup($url);
publicpage::$embedded = (bool) $embedded;
// All of it in sight, for the window the web of the centre opens (brief).
$brief = $embedded && optional_param('breve', 0, PARAM_BOOL);
$button = $brief ? optional_param('boton', '', PARAM_ALPHANUM) : '';
if ($brief) {
    $url->params(['breve' => 1] + ($button !== '' ? ['boton' => $button] : []));
}

$event = events::by_token($token);
$centre = $event ? centres::get((int) $event->centreid) : null;
publicpage::practice_gate($centre, $url);
if (!$event || !$centre) {
    $lang = publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en';
    publicpage::message(
        null,
        $lang,
        publiclang::str('pub_notfound_title', $lang),
        publiclang::str('pub_notfound', $lang),
        $url
    );
}
// A computer of the centre at the act (its office, the door), opened from the page of the act by the team: after each
// family the form comes back empty, and never the page of a family, which the next one would see. On the day of the act,
// whoever signs up there has come.
$desk = optional_param('pk', '', PARAM_ALPHANUM);
$desk = $desk !== '' && hash_equals(share::signature('puesto', 0, (int) $event->id), $desk);
if ($desk) {
    $url->param('pk', share::signature('puesto', 0, (int) $event->id));
}
// The QR of the act at the act itself (its door, its tables), on the phone of each family: as at the computers of the
// centre, whoever signs up there on its day has come; it says where they found it, and the screen is theirs.
$where = $desk ? substr(
    preg_replace('/[^a-z0-9-]/', '', core_text::strtolower(optional_param('qr', '', PARAM_ALPHANUMEXT))),
    0,
    30
) : '';
if ($where !== '') {
    $url->param('qr', $where);
}
// From the invitation to an open act in a mail: the family that pressed it, counted.
$invited = optional_param('k', '', PARAM_ALPHANUMEXT);
$from = households::by_link($invited);
// From the invitation in a message (WhatsApp, Telegram): signed for this family and this act, it only counts.
$invitedid = optional_param('ih', 0, PARAM_INT);
if (!$from && $invitedid && share::signed('invited', $invitedid, (int) $event->id, optional_param('is', '', PARAM_ALPHANUM))) {
    $from = households::get($invitedid, (int) $centre->id);
    $invited = $from ? 'signed' : '';
}
if ($from && (int) $from->centreid === (int) $centre->id && data_submitted() === false && !seen::from_team($centre)) {
    $SESSION->local_oksigeniaeguahe_pressed = [(int) $event->id => [(int) $from->id, optional_param('de', '', PARAM_ALPHA)]];
}
if ($from && $invited !== 'signed' && (int) $from->centreid === (int) $centre->id && data_submitted() === false) {
    // The letter has one link: on the page it opens, how they want to be written to. Their private link is kept for this
    // visit only, never in the address.
    $SESSION->local_oksigeniaeguahe_invited = [(int) $event->id => $invited];
}
if ($invited !== '' && data_submitted() === false) {
    // Counted, the private link of the family leaves the address at once: never kept in the history of the browser or
    // passed on with the page.
    $lang = optional_param('idioma', '', PARAM_ALPHANUMEXT);
    redirect(new moodle_url($url, $lang !== '' ? ['idioma' => $lang] : []));
}
// Passed on by a family of the centre (share::act_url()), or reached from the invitation at the end of a piece shared:
// whoever signs up here comes by it, and the family that passed it on has it counted.
$sharedby = optional_param('f', 0, PARAM_INT);
$sharesig = optional_param('c', '', PARAM_ALPHANUM);
// Or invited by an ambassador family of the centre, from its invitation (invita.php).
$ambassador = optional_param('p', '', PARAM_ALPHA) === 'familia' ? share::invited_by($centre, $sharedby, $sharesig) : null;
$sharer = $ambassador ?? ($sharedby && share::signed('acto', $sharedby, (int) $event->id, $sharesig) ?
    households::get($sharedby, (int) $centre->id) : null);
// Or by the link of a network, from the kit of the act: the network is where they came from.
$came = $ambassador ? 'familia' : ($sharer ? 'acto' : optional_param('d', '', PARAM_ALPHA));
$piece = in_array($came, share::PIECES, true) ? $came : '';
$network = $piece === '' ? kit::network_of($centre, $came) : '';
if ($sharer) {
    $url->params(['f' => $sharedby, 'c' => $sharesig] + ($ambassador ? ['p' => 'familia'] : []));
    // The invitation of an ambassador was counted where it was opened.
    if (data_submitted() === false && !$ambassador) {
        share::count($sharer, $centre, 'opened', 'acto');
    }
} else if ($piece !== '' || $network !== '') {
    $url->param('d', $came);
}
$lang = publiclang::choose($centre);
if (!$event->open || $event->timeend < time()) {
    // Of a talk or a party, what happened to it; of a visit, that there is no room and how to come another day.
    $act = $event->kind === 'other' ? (!empty($event->cancelled) ? 'off' : ($event->timeend < time() ? 'over' : 'closed')) : '';
    publicpage::message(
        $centre,
        $lang,
        $act !== '' ? format_string($event->name) : publiclang::str('pub_closed_title', $lang),
        publiclang::str($act !== '' ? 'pub_act_' . $act : 'pub_closed', $lang),
        $url
    );
}

// Whoever comes by the letter of an invitation is already known: they only say whether they come, and it is kept by itself.
$mine = $desk || $embedded ? '' : (string) ($SESSION->local_oksigeniaeguahe_invited[(int) $event->id] ?? '');
$family = $mine !== '' ? households::by_link($mine) : null;
$family = $family && (int) $family->centreid === (int) $centre->id ? $family : null;
$known = $family && empty($event->tailored) && !events::slots((int) $event->id) && (int) $event->free !== acts::FREE &&
    !optional_param('otra', 0, PARAM_BOOL);
// The link pressed is counted where its page says a person looked at it (formulario.js); where it does not, at once.
$pressed = function () use ($event, $centre): void {
    global $SESSION;
    $press = $SESSION->local_oksigeniaeguahe_pressed[(int) $event->id] ?? null;
    $presser = $press ? households::get((int) $press[0], (int) $centre->id) : null;
    unset($SESSION->local_oksigeniaeguahe_pressed);
    if ($presser) {
        seen::record($presser, 'touch', 'open_act', (string) $press[1]);
    }
};
if (!$known && data_submitted() === false) {
    $pressed();
}
if ($known && data_submitted() && optional_param('known', 0, PARAM_BOOL)) {
    publiclang::speak_to($family);
    $kept = events::mark_ok($event, optional_param('sesskey', '', PARAM_RAW_TRIMMED));
    $wrong = $kept ? 'pub_err_full' : 'pub_err_session';
    $first = false;
    $last = false;
    if ($kept) {
        $pressed();
    }
    if ($kept && optional_param('opened', 0, PARAM_BOOL)) {
        // The page says a person looked at it, never the team that looks at it.
        if (!seen::from_team($centre)) {
            invites::opened($event, $family);
        }
    } else if ($kept && optional_param('ask', 0, PARAM_BOOL)) {
        // They marked WhatsApp a moment ago: asked there, apart from what they touch, which never waits for Meta.
        writeby::ask($family, $centre);
    } else if ($kept) {
        $lock = events::lock($event);
        $first = !$DB->record_exists(events::SIGNUPS, ['eventid' => $event->id, 'householdid' => $family->id]);
        $kept = joinpage::said($event, $centre, $family);
        $last = joinpage::last_way($centre, $family);
        if ($lock) {
            $lock->release();
        }
        $first = $first && $DB->record_exists(events::SIGNUPS, ['eventid' => $event->id, 'householdid' => $family->id]);
        if (!optional_param('ajax', 0, PARAM_BOOL)) {
            writeby::ask($family, $centre);
        }
    }
    if ($first) {
        // Their place, with its calendar, as any other sign-up; after answering, so the button never waits for the mail.
        \local_oksigeniaeguahe\task\send_confirmation::queue($family, $event, $lang);
    }
    if (optional_param('ajax', 0, PARAM_BOOL)) {
        $said = joinpage::known($event, $centre, households::get((int) $family->id, (int) $centre->id), $lang, $url, events::mark($event));
        publicpage::headers();
        @header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $kept, 'answer' => $said['answer'], 'answered' => $said['answered'],
            'ask' => $said['askwhatsapp'], 'coming' => $said['coming'], 'done' => $said['done'], 'ways' => $said['now'], 'last' => $last ? $said['lastway'] : '',
            'noinvites' => $said['noinvites'], 'people' => $said['people']['value'], 'full' => $kept ? '' : publiclang::str($wrong, $lang),
            'missing' => $OUTPUT->render_from_template('local_oksigeniaeguahe/public_known_missing', $said)]);
        die();
    }
    redirect(new moodle_url($url, ['idioma' => $lang] + ($kept || $wrong !== 'pub_err_full' ? [] : ['llena' => 1]) +
        ($last ? ['una' => 1] : [])));
}

if ((int) $event->free === acts::FREE && !$desk) {
    // Anybody may come: what it is, when and where, and no form.
    publicpage::output(
        $centre,
        $lang,
        format_string($event->name),
        'public_join',
        ['form' => false, 'bare' => true, 'pegs' => false] +
            joinpage::context($event, $centre, $lang, signup::read($centre), [], $url, events::mark($event)),
        $url
    );
}
$data = signup::read($centre);
// A student signing up for themselves is spoken to as one person from here on (what is wrong, the wall afterwards).
publiclang::$self = !empty($data['self']);
$data['answers'] = forms::read((int) $event->formid);
// Inside a web, the form alone; everywhere else, the whole page, with all the questions of the centre.
$brief = $brief && empty($event->tailored) && !$desk;
$question = null;
if ($brief) {
    $question = brief::question((int) $event->formid, (string) ($centre->lang ?: 'es'));
    if ($question && !data_submitted()) {
        // The web says which of the options was pressed there.
        $data['answers'][$question->id] = brief::option(
            $question,
            optional_param('o', '', PARAM_ALPHANUMEXT),
            (string) ($centre->lang ?: 'es')
        );
    }
}
$data = brief::data($data, $event, $question, !$brief);
$errors = [];
if (data_submitted()) {
    // The page carries its own mark (events::mark()): inside the web of the centre there is no session of this site.
    if (!events::mark_ok($event, optional_param('sesskey', '', PARAM_RAW_TRIMMED))) {
        // A page left open for a day. Nothing is lost, it is sent again.
        $errors['form'] = publiclang::str('pub_err_session', $lang);
    } else {
        $errors = brief::errors(signup::check($data, $event, $centre, $lang, true), $question, $data, !$brief);
        if (
            !$errors && !brief::person(
                optional_param('sesskey', '', PARAM_RAW_TRIMMED),
                optional_param(brief::TRAP, '', PARAM_RAW_TRIMMED),
                (int) $event->id,
                getremoteaddr(),
                time(),
                $desk
            )
        ) {
            $errors['form'] = publiclang::str(
                brief::refusal(optional_param('sesskey', '', PARAM_RAW_TRIMMED), time()),
                $lang
            );
        }
    }
    $lock = null;
    if (!$errors) {
        // The room again, now under the lock of the act: two families sending at once never take its last place twice.
        $lock = events::lock($event);
        $errors = signup::fits($data, $event, $centre, $lang, $desk);
        if ($errors && $lock) {
            $lock->release();
            $lock = null;
        }
    }
    if (!$errors) {
        $origin = $piece !== '' ? publiclang::str('share_origin_' . $piece, (string) $centre->lang) : $network;
        $origin = $desk ? ($where !== '' ? publiclang::str('door_origin', (string) $centre->lang, $where) :
            publiclang::str('desk_origin', (string) $centre->lang)) : $origin;
        $data = $ambassador ? share::told_by($data, $ambassador, $lang) : $data;
        [$household, $link, $before, $existed] = signup::save($data, $event, $centre, $lang, null, $origin);
        if ($lock) {
            $lock->release();
            $lock = null;
        }
        if ($ambassador && !$existed) {
            // One more family that came by its invitation: a number for the ambassador, never which family.
            share::count($ambassador, $centre, 'arrived', 'familia');
        }
        // A family already known: whoever filled the form may not be that family, so its page is not shown here and
        // the link goes only to the email it had.
        $to = $existed ? (string) ($before[0] ?? '') : null;
        // The sign-up is kept already: if the mail of the site fails or says something, the family still gets its
        // page.
        ob_start();
        try {
            if ($to !== '' && !empty($event->tailored)) {
                familymail::send('request', $household, $centre, null, $lang, $link, $to);
            } else if ($to !== '' && ($signup = familymail::signup($household, (int) $event->id))) {
                familymail::send('confirmation', $household, $centre, $signup, $lang, $link, $to);
            }
        } catch (\Throwable $e) {
            // The page of the family is what matters now; the mail of the site says itself what went wrong.
            $sent = false;
        } finally {
            ob_end_clean();
        }
        if ($desk) {
            $signup = empty($event->tailored) ? familymail::signup($household, (int) $event->id) : null;
            $signupid = (int) $DB->get_field(events::SIGNUPS, 'id', ['eventid' => $event->id, 'householdid' => $household->id]);
            if ($signupid && userdate((int) $event->timestart, '%Y-%m-%d', 99, false) === userdate(time(), '%Y-%m-%d', 99, false)) {
                // Signing up at the act, on its day: they are here.
                events::attend($event, $signupid, true);
            }
            // What they wrote and their visit, and the form again for the next family; never their page.
            publicpage::output(
                $centre,
                $lang,
                publiclang::str('desk_done_title', $lang),
                'public_known',
                ['text' => publiclang::str('desk_note', $lang), 'desk' => $where !== '' ? false : [
                    'next' => (new moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token,
                        'pk' => share::signature('puesto', 0, (int) $event->id), 'idioma' => $lang]))->out(false),
                    'nexttext' => publiclang::str('desk_next', $lang),
                ]] + knownpage::context($centre, $lang, $data['children'], $signup, $to !== ''),
                $url
            );
        }
        if ($brief) {
            // Two lines in the same place; the page of a family already known is never offered here.
            brief::output($centre, $lang, publiclang::str('pub_breve_sent', $lang), brief::sent(
                $centre,
                $lang,
                $existed ? null : ($event->kind === 'other' && !events::slots((int) $event->id) ? new moodle_url(
                    '/local/oksigeniaeguahe/j.php',
                    ['t' => $event->token, 'k' => $link, 'idioma' => $lang]
                ) : new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $link, 'new' => 1, 'idioma' => $lang])),
                $event
            ));
        }
        if ($existed) {
            // The wall with what they just wrote and their visit; never their page (public_known).
            $signup = empty($event->tailored) ? familymail::signup($household, (int) $event->id) : null;
            publicpage::output(
                $centre,
                $lang,
                publiclang::str('pub_known_title', $lang),
                'public_known',
                knownpage::context($centre, $lang, $data['children'], $signup, $to !== ''),
                $url
            );
        }
        $page = new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $link, 'new' => 1, 'idioma' => $lang]);
        if ($event->kind === 'other' && !events::slots((int) $event->id)) {
            // A talk or a party: the page of the act, where they say they do not come after all and how they are written to.
            $page = new moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token, 'k' => $link, 'idioma' => $lang]);
        }
        if ($embedded) {
            // Inside the web of the centre: done, and their page, with their visit, opens apart.
            publicpage::message(
                $centre,
                $lang,
                $event->kind === 'other' ? publiclang::str('pub_breve_signed', $lang) :
                    publiclang::str('pub_event_sent_title', $lang),
                $event->kind === 'other' ? \local_oksigeniaeguahe\local\actmail::saved($event, $lang) :
                    publiclang::str('pub_event_sent_embedded', $lang),
                $url,
                false,
                ['url' => $page->out(false), 'label' => publiclang::str('pub_sent_open', $lang)]
            );
        }
        redirect($page);
    }
}

// Its preview, wherever it is shared; passed on by a family, the seal of Eguahe at its foot.
if (share::act_open($event)) {
    $when = publicpage::date_parts((int) $event->timestart, $lang);
    publicpage::$head = share::meta(
        format_string($event->name),
        $when['long'] . (!empty($event->place) ? ' · ' . format_string($event->place) : '') .
            (trim((string) ($event->about ?? '')) !== '' ? '. ' . core_text::substr(trim($event->about), 0, 160) : ''),
        events::url($event),
        new moodle_url('/local/oksigeniaeguahe/og.php', ['p' => 'acto', 't' => $event->token, 'idioma' => $lang]),
        $lang,
        format_string($centre->name)
    );
}
if ($sharer) {
    publicpage::$seal = share::seal('acto', $lang);
}
if ($brief) {
    brief::output($centre, $lang, format_string($event->name), brief::context(
        $event,
        $centre,
        $lang,
        $data,
        $errors,
        new moodle_url($url, ['idioma' => $lang]),
        events::mark($event),
        $question
    ), $button);
}
$self = publiclang::$self;
if ($known) {
    publiclang::speak_to($family);
}
$context = joinpage::context(
    $event,
    $centre,
    $lang,
    $data,
    $errors,
    $url,
    events::mark($event),
    $known ? joinpage::known($event, $centre, $family, $lang, $url, events::mark($event)) : false
);
publiclang::$self = $self;
if (optional_param('llena', 0, PARAM_BOOL) && $context['known']) {
    $context['known']['full'] = publiclang::str('pub_err_full', $lang);
}
if ($desk) {
    // A computer many families use: the browser does not offer what the one before wrote.
    foreach ($context['form']['fields'] as $i => $field) {
        $context['form']['fields'][$i]['autocomplete'] = 'off';
    }
}
publicpage::output($centre, $lang, format_string($event->name), 'public_join', $context, $url);
