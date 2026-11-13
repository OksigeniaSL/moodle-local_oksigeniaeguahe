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
 * The invitation of a family that is an ambassador of the centre, passed on to another family: who invites them, the
 * centre in a few lines, its next open acts to sign up for, and asking for information. By a signed link that works
 * while the family is an ambassador; nothing else of the family. With its preview, and the seal of Eguahe. What it
 * brings is counted for the family that passed it on, as a number: never which family came.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Whoever the family invited has no account: the signed link lets them in.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\share;

$householdid = optional_param('h', 0, PARAM_INT);
$signature = optional_param('s', '', PARAM_ALPHANUM);
$params = ['h' => $householdid, 's' => $signature];
$url = new moodle_url('/local/oksigeniaeguahe/invita.php', $params);
publicpage::setup($url);

$found = share::ambassador($householdid, $signature);
if (!$found) {
    $lang = publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en';
    publicpage::message(null, $lang, publiclang::str('share_gone_title', $lang), publiclang::str('share_gone', $lang), $url);
}
[$household, $centre] = $found;
$lang = publiclang::choose($centre);
// Whoever comes by here signs up saying which family invited them (j.php and w.php check the signature).
$from = ['f' => $household->id, 'c' => $signature, 'p' => 'familia'];

if (optional_param('action', '', PARAM_ALPHA) === 'pedir') {
    // Asking for information: the form the centre chose, with its place for these invitations.
    $target = share::invite_target($centre, 'familia');
    if ($target) {
        share::count($household, $centre, 'invite', 'familia');
        redirect(new moodle_url($target, $from));
    }
    redirect($url);
}

// The next open acts, the soonest first: an open day, a visit by turns, a talk.
$acts = [];
foreach (events::for_centre((int) $centre->id) as $event) {
    if (count($acts) >= 3 || !share::act_open($event) || !empty($event->tailored)) {
        continue;
    }
    $parts = publicpage::date_parts((int) $event->timestart, $lang);
    $acts[] = [
        'name' => format_string($event->name),
        'weekday' => $parts['weekday'],
        'daymonth' => $parts['daymonth'],
        'hours' => familypage::hours((int) $event->timestart, (int) $event->timeend, $lang),
        'when' => core_text::strtoupper(core_text::substr($parts['long'], 0, 1)) . core_text::substr($parts['long'], 1) . ' · ' .
            familypage::hours((int) $event->timestart, (int) $event->timeend, $lang),
        'place' => $event->place ? format_string($event->place) : '',
        'url' => (new moodle_url(events::url($event), $from))->out(false),
    ];
}

share::count($household, $centre, 'opened', 'familia');
$sign = share::ambassador_sign($household, $lang);
$phrase = share::phrase($centre, $lang);
$title = publiclang::str('share_family_title', $lang, $phrase);
$here = new moodle_url($url, ['idioma' => $lang]);
publicpage::$head = share::meta(
    $title,
    publiclang::str('share_family_from', $lang, $sign),
    $here,
    new moodle_url('/local/oksigeniaeguahe/og.php', $params + ['p' => 'familia', 'idioma' => $lang]),
    $lang,
    format_string($centre->name)
);
publicpage::$seal = share::seal('familia', $lang);
$ask = share::invite_target($centre, 'familia');
publicpage::output($centre, $lang, $title, 'public_invitation', [
    'kicker' => publiclang::str('share_family_from', $lang, $sign),
    'card' => share::emblem($centre) + ['from' => share::from_html($sign, $lang)],
    'title' => $title,
    'welcome' => trim((string) ($centre->welcome ?? '')),
    'acts' => $acts,
    'hasacts' => (bool) $acts,
    'signup' => publiclang::str('share_family_signup', $lang),
    'ask' => $ask ? ['text' => publiclang::str($acts ? 'share_family_ask_text' : 'share_family_ask_only', $lang),
        'button' => publiclang::str('share_family_ask', $lang),
        'url' => (new moodle_url($url, ['action' => 'pedir']))->out(false)] : false,
], $here);
