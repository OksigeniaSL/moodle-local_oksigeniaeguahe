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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\mail;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
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
$lang = publiclang::choose($centre);
if (!$event->open || $event->timeend < time()) {
    publicpage::message($centre, $lang, format_string($event->name), publiclang::str('pub_closed', $lang), $url);
}

$data = signup::read();
$errors = [];
if (data_submitted()) {
    if (!confirm_sesskey()) {
        // A page left open for hours: the session ended. Nothing is lost, it is sent again.
        $errors['form'] = publiclang::str('pub_err_session', $lang);
    } else {
        $errors = signup::check($data, $event, $centre, $lang);
    }
    if (!$errors) {
        [$household, $link] = signup::save($data, $event, $centre, $lang);
        $first = $data['adults'][0];
        $email = households::email($first['email']);
        if ($email) {
            // The sign-up is kept already: if the mail of the site fails or says something, the family still gets
            // its page.
            ob_start();
            try {
                mail::signed_up(
                    $centre,
                    $event,
                    $email,
                    $first['firstname'],
                    $link,
                    $lang,
                    events::when($event, (int) ($data['slot'] ?? 0))
                );
            } catch (\Throwable $e) {
                $email = null;
            } finally {
                ob_end_clean();
            }
        }
        redirect(new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $link, 'new' => 1, 'lang' => $lang]));
    }
}

publicpage::output(
    $centre,
    $lang,
    format_string($event->name),
    'public_signup',
    signuppage::context($event, $centre, $lang, $data, $errors, $url),
    $url
);
