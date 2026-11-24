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
 * A form of a centre, made in Eguahe: on its own page, by its link or its QR, or inside the web of the centre. The
 * link may say where it was shared from («Instagram», «Cartel de la puerta»), for the numbers.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Families have no account: the link of the form lets them in.
define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\brief;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\knownpage;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\pulse;
use local_oksigeniaeguahe\local\share;
use local_oksigeniaeguahe\local\signup;
use local_oksigeniaeguahe\local\signuppage;

$token = optional_param('e', '', PARAM_ALPHANUM);
$embedded = optional_param('embed', 0, PARAM_BOOL);
$from = optional_param('d', '', PARAM_ALPHANUM);
$preview = optional_param('preview', 0, PARAM_BOOL);
// All of it in sight, for the window the web of the centre opens (brief).
$brief = $embedded && optional_param('breve', 0, PARAM_BOOL);
$button = $brief ? optional_param('boton', '', PARAM_ALPHANUM) : '';
// Invited by an ambassador family of the centre, from its invitation (invita.php): checked once the centre is known.
$invited = optional_param('p', '', PARAM_ALPHA) === 'familia' ? ['f' => optional_param('f', 0, PARAM_INT),
    'c' => optional_param('c', '', PARAM_ALPHANUM), 'p' => 'familia'] : [];
$url = new moodle_url('/local/oksigeniaeguahe/w.php', ['e' => $token] + ($embedded ? ['embed' => 1] : []) +
    ($from !== '' ? ['d' => $from] : []) + ($preview ? ['preview' => 1] : []) + ($brief ? ['breve' => 1] : []) +
    ($button !== '' ? ['boton' => $button] : []) + $invited);
publicpage::setup($url);
publicpage::$embedded = (bool) $embedded;

$entry = entries::by_token($token);
$centre = $entry && $entry->kind === 'form' ? centres::get((int) $entry->centreid) : null;
publicpage::practice_gate($centre, $url);
if (!$centre) {
    $lang = publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en';
    publicpage::message(
        null,
        $lang,
        publiclang::str('pub_notfound_title', $lang),
        publiclang::str('pub_notfound', $lang),
        $url
    );
}
$lang = publiclang::choose($centre);
$event = forms::as_event($entry);
$origins = forms::origins($entry);
$origin = $origins[$from] ?? '';
$ambassador = $invited ? share::invited_by($centre, (int) $invited['f'], (string) $invited['c']) : null;

$data = signup::read($centre);
// A student signing up for themselves is spoken to as one person from here on (what is wrong, the wall afterwards).
publiclang::$self = !empty($data['self']);
$data['answers'] = forms::read((int) $entry->id);
$question = null;
if ($brief) {
    $question = brief::question((int) $entry->id, (string) ($centre->lang ?: 'es'));
    if ($question && !data_submitted()) {
        // The web says which of the options was pressed there.
        $data['answers'][$question->id] = brief::option(
            $question,
            optional_param('o', '', PARAM_ALPHANUMEXT),
            (string) ($centre->lang ?: 'es')
        );
    }
    $data = brief::data($data, $event, $question);
}
$errors = [];
if (!$preview && !data_submitted()) {
    pulse::opened($entry);
}
if (data_submitted() && !$preview) {
    if (!entries::mark_ok($entry, optional_param('sesskey', '', PARAM_RAW_TRIMMED))) {
        // A page left open for a day: nothing is lost, it is sent again.
        $errors['form'] = publiclang::str('pub_err_session', $lang);
    } else {
        $errors = signup::check($data, $event, $centre, $lang);
        if ($brief) {
            $errors = brief::errors($errors, $question, $data);
            if (
                !$errors && !brief::person(
                    optional_param('sesskey', '', PARAM_RAW_TRIMMED),
                    optional_param(brief::TRAP, '', PARAM_RAW_TRIMMED),
                    (int) $entry->id,
                    getremoteaddr(),
                    time()
                )
            ) {
                $errors['form'] = publiclang::str('pub_err_toomany', $lang);
            }
        }
    }
    $lock = null;
    if (!$errors && array_filter($data['adults'], fn($a) => entries::is_test($a['email']))) {
        // A test of the form, from the website of the centre or its link: it works, and no family is kept.
        entries::tested($entry);
        if ($brief) {
            brief::output($centre, $lang, publiclang::str('pub_test_title', $lang), ['sent' => [
                'title' => publiclang::str('pub_test_title', $lang), 'text' => publiclang::str('pub_test_text', $lang),
                'url' => '', 'more' => '']]);
        }
        publicpage::message(
            $centre,
            $lang,
            publiclang::str('pub_test_title', $lang),
            publiclang::str('pub_test_text', $lang),
            $url
        );
    }
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
        $data = $ambassador ? share::told_by($data, $ambassador, $lang) : $data;
        [$household, $link, $before, $existed] = signup::save($data, $event, $centre, $lang, $entry, $origin);
        if ($lock) {
            $lock->release();
            $lock = null;
        }
        entries::counted($entry);
        if ($ambassador && !$existed) {
            // One more family that came by its invitation: a number for the ambassador, never which family.
            share::count($ambassador, $centre, 'arrived', 'familia');
        }
        // A family already known: whoever filled the form may not be that family, so its page is not shown here and
        // the link goes only to the email it had.
        $to = $existed ? (string) ($before[0] ?? '') : null;
        ob_start();
        try {
            if ($to !== '' && !empty($entry->acknowledge)) {
                familymail::send('received', $household, $centre, null, $lang, $link, $to);
            }
        } catch (\Throwable $e) {
            // The family is in the inbox: the team answers it anyway.
            $link = $existed ? null : $link;
        } finally {
            ob_end_clean();
        }
        if ($brief) {
            // Two lines in the same place; the page of a family already known is never offered here.
            brief::output($centre, $lang, publiclang::str('pub_breve_sent', $lang), brief::sent(
                $centre,
                $lang,
                $existed ? null : new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $link, 'new' => 1, 'idioma' => $lang])
            ));
        }
        if ($existed) {
            // The wall with what they just wrote; never their page (public_known).
            publicpage::output(
                $centre,
                $lang,
                publiclang::str('pub_known_title', $lang),
                'public_known',
                knownpage::context($centre, $lang, $data['children'], null, $to !== ''),
                $url
            );
        }
        $page = new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $link, 'new' => 1, 'idioma' => $lang]);
        if ($embedded) {
            // Inside the web of the centre: done, and their page opens apart.
            publicpage::message(
                $centre,
                $lang,
                publiclang::str('pub_sent_title', $lang),
                publiclang::str('pub_sent_embedded', $lang),
                $url,
                false,
                ['url' => $page->out(false), 'label' => publiclang::str('pub_sent_open', $lang)]
            );
        }
        redirect($page);
    }
}

if ($brief) {
    brief::output($centre, $lang, format_string($entry->title ?: $entry->name), brief::context(
        $event,
        $centre,
        $lang,
        $data,
        $errors,
        new moodle_url($url, ['idioma' => $lang]),
        entries::mark($entry),
        $question
    ), $button);
}
$context = signuppage::context($event, $centre, $lang, $data, $errors, $url);
$context['sesskey'] = entries::mark($entry);
$context['action'] = (new moodle_url($url, ['idioma' => $lang]))->out(false);
$context['preview'] = (bool) $preview;
$context['previewnote'] = publiclang::str('pub_preview', $lang);
publicpage::output(
    $centre,
    $lang,
    $context['headline'],
    'public_signup',
    $context,
    $url
);
