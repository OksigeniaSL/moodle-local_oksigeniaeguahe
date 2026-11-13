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
 * How a centre treats the data of the families who come to it: the second layer of the information, in the language
 * of the family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Read by families, who have no account.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\ai;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\messaging;
use local_oksigeniaeguahe\local\namevoice_service;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\share;

$centreid = optional_param('c', 0, PARAM_INT);
// Signed, so the number of a centre alone opens nothing: nobody goes through the centres of a site one by one.
$signature = optional_param('k', '', PARAM_ALPHANUM);
$url = new moodle_url('/local/oksigeniaeguahe/privacy.php', ['c' => $centreid, 'k' => $signature]);
publicpage::setup($url);
$centre = share::signed('privacy', 0, $centreid, $signature) ? centres::get($centreid) : null;
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
$a = (object) ['centre' => format_string($centre->controller ?: $centre->name), 'months' => (int) $centre->retention,
    'contact' => s((string) $centre->rightscontact), 'dpo' => s((string) $centre->dpocontact)];
// What each centre has turned on, said as it is: the artificial intelligence of the team, WhatsApp and Telegram, the
// voices of the story.
$aion = ai::ready($centre);
$extra = [
    'whom' => array_filter([
        $aion ? publiclang::str('privacy_whom_ai', $lang) : '',
        messaging::mode($centre) !== 'off' ? publiclang::str('privacy_whom_messaging', $lang) : '',
        namevoice_service::settings() ? publiclang::str('privacy_whom_voices', $lang) : '',
    ]),
    'ai' => $aion && ai::name($centre) !== '' ? [publiclang::str('privacy_ai_provider', $lang, format_string(ai::name($centre)))] :
        [],
];
$sections = [];
foreach (['who', 'why', 'what', 'basis', 'whom', 'keep', 'measure', 'rights', 'ai'] as $part) {
    $text = publiclang::str('privacy_' . $part . ($part === 'ai' && $aion ? '_on' : ''), $lang, $a);
    foreach ($extra[$part] ?? [] as $more) {
        $text .= ' ' . $more;
    }
    if ($part === 'who' && $a->dpo !== '') {
        $text .= ' ' . publiclang::str('privacy_who_dpo', $lang, $a);
    }
    if ($part === 'rights' && $a->contact !== '') {
        $text .= ' ' . publiclang::str('info_rights_contact', $lang, $a);
    }
    $sections[] = ['title' => publiclang::str('privacy_' . $part . '_title', $lang), 'text' => $text];
}
publicpage::output($centre, $lang, publiclang::str('pub_privacy_link', $lang), 'public_privacy', [
    'title' => publiclang::str('pub_privacy_link', $lang),
    'lead' => publiclang::str('pub_privacy_lead', $lang, $a->centre),
    'sections' => $sections,
], $url);
