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
 * «Your first day», sent by the family to someone (the grandparents): the story of one child, its drawing and nothing
 * else of the family. The link is signed; it works while the child has the story.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Whoever the family sent it to has no account: the signed link lets them in.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\drawings;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\seen;
use local_oksigeniaeguahe\local\share;
use local_oksigeniaeguahe\local\story;

$householdid = optional_param('h', 0, PARAM_INT);
$childid = optional_param('n', 0, PARAM_INT);
$signature = optional_param('s', '', PARAM_ALPHANUM);
$lang = optional_param('lang', '', PARAM_ALPHANUMEXT);
$lang = in_array($lang, story::LANGS, true) ? $lang : story::LANGS[0];
$params = ['h' => $householdid, 'n' => $childid, 's' => $signature, 'lang' => $lang];
$url = new moodle_url('/local/oksigeniaeguahe/cuento.php', $params);
publicpage::setup($url);

$found = story::from_link($householdid, $childid, $signature);
$child = $found ? (story::children($found[0], $found[2], $lang, time())[$childid] ?? null) : null;
if (!$child) {
    publicpage::message(
        $found[2] ?? null,
        $lang,
        publiclang::str('story_gone_title', $lang),
        publiclang::str('story_gone', $lang),
        $url
    );
}
[$household, , $centre] = $found;

if (optional_param('dibujo', 0, PARAM_BOOL)) {
    // The drawing of the child, for the wall of the class in the story.
    $file = drawings::file((int) $child->id);
    if (!$file) {
        send_header_404();
        die();
    }
    send_stored_file($file, DAYSECS, 0, false, ['cacheability' => 'private']);
}

if (optional_param('action', '', PARAM_ALPHA) === 'invitacion') {
    // The invitation followed by whoever received the story, counted for the family that shared it; then to the centre.
    $target = share::invite_target($centre, 'cuento');
    if ($target && !empty($centre->shareinvite)) {
        share::count($household, $centre, 'invite', 'cuento');
        redirect($target);
    }
    redirect($url);
}

if (optional_param('action', '', PARAM_ALPHA) === 'seen') {
    // Opened by whoever received it, and sent on: counted as what was shared, for the family it belongs to. What is done
    // inside the book is the family's reading, not this.
    $what = optional_param('what', '', PARAM_ALPHAEXT);
    if (data_submitted() && confirm_sesskey() && optional_param('kind', '', PARAM_ALPHA) === 'story') {
        if ($what === 'opened' || $what === 'sent') {
            share::count($household, $centre, $what, 'cuento');
        }
    }
    publicpage::headers();
    @header('Cache-Control: no-store');
    http_response_code(204);
    die();
}

$context = story::context($household, $child, $centre, $lang, [
    'drawing' => (new moodle_url($url, ['dibujo' => 1]))->out(false),
    'share' => $url->out(false),
    'measure' => seen::from_team($centre) ? null : ['url' => (new moodle_url($url, ['action' => 'seen']))->out(false),
        'sesskey' => sesskey()],
]);
// Shared: its preview, the gift of the centre, the invitation and the seal of Eguahe.
$name = familypage::label_name($child) ?: (string) $child->firstname;
$context['meta'] = share::meta(
    publiclang::str('pub_story_kicker', $lang, (object) ['name' => $name]),
    share::gift($centre, $lang),
    $url,
    new moodle_url('/local/oksigeniaeguahe/og.php', ['p' => 'cuento', 'h' => $householdid, 'n' => $childid,
        's' => $signature, 'lang' => $lang]),
    $lang,
    format_string($centre->name)
);
$context['credit'] = share::seal('cuento', $lang);
$context['gift'] = share::gift($centre, $lang);
$context['invitation'] = share::invitation($centre, 'cuento', $lang, $url);
story::output($context);
