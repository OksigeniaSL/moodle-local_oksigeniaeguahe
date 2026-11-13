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

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\kit;
use local_oksigeniaeguahe\local\knownpage;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\seen;
use local_oksigeniaeguahe\local\share;
use local_oksigeniaeguahe\local\signup;
use local_oksigeniaeguahe\local\signuppage;

$token = optional_param('t', '', PARAM_ALPHANUM);
$url = new moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $token]);
publicpage::setup($url);

$event = events::by_token($token);
$centre = $event ? centres::get((int) $event->centreid) : null;
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
// From the invitation to an open act in a mail: the family that pressed it, counted.
$invited = optional_param('k', '', PARAM_ALPHANUMEXT);
$from = households::by_link($invited);
if ($from && (int) $from->centreid === (int) $centre->id && data_submitted() === false && !seen::from_team($centre)) {
    seen::record($from, 'touch', 'open_act', optional_param('de', '', PARAM_ALPHA));
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
    publicpage::message(
        $centre,
        $lang,
        publiclang::str('pub_closed_title', $lang),
        publiclang::str('pub_closed', $lang),
        $url
    );
}

$data = signup::read();
$data['answers'] = forms::read((int) $event->formid);
$errors = [];
if (data_submitted()) {
    if (!confirm_sesskey()) {
        // A page left open for hours: the session ended. Nothing is lost, it is sent again.
        $errors['form'] = publiclang::str('pub_err_session', $lang);
    } else {
        $errors = signup::check($data, $event, $centre, $lang);
    }
    $lock = null;
    if (!$errors) {
        // The room again, now under the lock of the act: two families sending at once never take its last place twice.
        $lock = events::lock($event);
        $errors = signup::fits($data, $event, $centre, $lang);
        if ($errors && $lock) {
            $lock->release();
            $lock = null;
        }
    }
    if (!$errors) {
        $origin = $piece !== '' ? publiclang::str('share_origin_' . $piece, (string) $centre->lang) : $network;
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
        redirect(new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $link, 'new' => 1, 'lang' => $lang]));
    }
}

// Its preview, wherever it is shared; passed on by a family, the seal of Eguahe at its foot.
if (share::act_open($event)) {
    $when = publicpage::date_parts((int) $event->timestart, $lang);
    publicpage::$head = share::meta(
        format_string($event->name),
        $when['long'] . (!empty($event->place) ? ' · ' . format_string($event->place) : ''),
        events::url($event),
        new moodle_url('/local/oksigeniaeguahe/og.php', ['p' => 'acto', 't' => $event->token, 'lang' => $lang]),
        $lang,
        format_string($centre->name)
    );
}
if ($sharer) {
    publicpage::$seal = share::seal('acto', $lang);
}
publicpage::output(
    $centre,
    $lang,
    format_string($event->name),
    'public_signup',
    signuppage::context($event, $centre, $lang, $data, $errors, $url),
    $url
);
