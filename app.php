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
 * From a card of Eguahe on the home of the app of Oksigenia Aty to the page of the family, in the browser of the phone,
 * where the person is not signed in: the link is signed and lasts a day (atycards::url()). It opens the page with a
 * private link of its own, which goes after two days (task\erase_inactive).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- The browser of the phone has no session: the signed link lets them in.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\atycards;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;

$householdid = optional_param('h', 0, PARAM_INT);
$userid = optional_param('u', 0, PARAM_INT);
$until = optional_param('t', 0, PARAM_INT);
$anchor = optional_param('a', '', PARAM_ALPHANUMEXT);
$signature = optional_param('s', '', PARAM_ALPHANUM);
$url = new moodle_url('/local/oksigeniaeguahe/app.php', ['h' => $householdid, 'u' => $userid, 't' => $until,
    'a' => $anchor, 's' => $signature]);
publicpage::setup($url);

$record = $DB->get_record(households::T, ['id' => $householdid]);
$centre = $record ? centres::get((int) $record->centreid) : null;
if (!$record || !$centre || !atycards::valid($householdid, $userid, $until, $signature, time())) {
    $lang = $record->lang ?? (publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en');
    publicpage::message(
        $centre,
        $lang,
        publiclang::str('pub_app_gone_title', $lang),
        publiclang::str('pub_app_gone', $lang),
        $url
    );
}
$household = households::get($householdid, (int) $centre->id);
$token = households::new_link($household, 'app');
redirect(new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $household->lang], $anchor ?: null));
