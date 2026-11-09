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
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\seen;
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

if (optional_param('action', '', PARAM_ALPHA) === 'seen') {
    // What is done with the story, counted for the family it belongs to; only that.
    $counted = data_submitted() && confirm_sesskey() && !seen::from_team($centre);
    if ($counted && optional_param('kind', '', PARAM_ALPHA) === 'story') {
        seen::record($household, 'story', optional_param('what', '', PARAM_ALPHAEXT), optional_param('via', '', PARAM_ALPHANUM));
    }
    publicpage::headers();
    @header('Cache-Control: no-store');
    http_response_code(204);
    die();
}

story::output(story::context($household, $child, $centre, $lang, [
    'drawing' => (new moodle_url($url, ['dibujo' => 1]))->out(false),
    'share' => $url->out(false),
    'measure' => seen::from_team($centre) ? null : ['url' => (new moodle_url($url, ['action' => 'seen']))->out(false),
        'sesskey' => sesskey()],
]));
