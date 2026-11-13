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
 * The drawing of a child, sent by its family to someone (the grandparents): that drawing and nothing else of the
 * family, by a signed link that works while the drawing is there. With its preview, the gift of the centre, the
 * invitation when the centre has it on, and the seal of Eguahe.
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
use local_oksigeniaeguahe\local\share;
use local_oksigeniaeguahe\local\story;

$householdid = optional_param('h', 0, PARAM_INT);
$childid = optional_param('n', 0, PARAM_INT);
$signature = optional_param('s', '', PARAM_ALPHANUM);
$params = ['h' => $householdid, 'n' => $childid, 's' => $signature];
$url = new moodle_url('/local/oksigeniaeguahe/dibujo.php', $params);
publicpage::setup($url);

$found = share::signed('dibujo', $householdid, $childid, $signature) ? story::from_ids($householdid, $childid) : null;
$file = $found ? drawings::file($childid) : null;
if (!$found || !$file) {
    $centre = $found[2] ?? null;
    $lang = $centre ? publiclang::choose($centre) : (publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))
        ?? 'en');
    publicpage::message(
        $centre,
        $lang,
        publiclang::str('share_gone_title', $lang),
        publiclang::str('share_gone', $lang),
        $url
    );
}
[$household, $child, $centre] = $found;
$lang = publiclang::choose($centre);
$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'imagen') {
    send_stored_file($file, HOURSECS, 0, false, ['cacheability' => 'private']);
}
if ($action === 'trazos') {
    $strokes = drawings::strokes_file($childid);
    if (!$strokes) {
        send_header_404();
        die();
    }
    send_stored_file($strokes, HOURSECS, 0, false, ['cacheability' => 'private']);
}
if ($action === 'invitacion') {
    // The invitation followed, counted for the family that shared it; then on to the centre.
    $target = share::invite_target($centre, 'dibujo');
    if ($target && !empty($centre->shareinvite)) {
        share::count($household, $centre, 'invite', 'dibujo');
        redirect($target);
    }
    redirect($url);
}
if ($action === 'mandado' || $action === 'guardado') {
    // Sent on, or kept, by whoever received it.
    if (confirm_sesskey()) {
        share::count($household, $centre, $action === 'mandado' ? 'sent' : 'saved', 'dibujo');
    }
    publicpage::headers();
    @header('Cache-Control: no-store');
    http_response_code(204);
    die();
}

share::count($household, $centre, 'opened', 'dibujo');
$name = familypage::label_name($child) ?: (string) $child->firstname;
$here = new moodle_url($url, ['idioma' => $lang]);
$strokes = drawings::strokes_file($childid);
$title = publiclang::str('pub_drawing_title', $lang, $name);
publicpage::$head = share::meta(
    $title,
    share::gift($centre, $lang),
    $here,
    new moodle_url('/local/oksigeniaeguahe/og.php', $params + ['p' => 'dibujo', 'idioma' => $lang]),
    $lang,
    format_string($centre->name)
);
publicpage::$seal = share::seal('dibujo', $lang);
publicpage::output($centre, $lang, $title, 'public_shared_drawing', [
    'kicker' => publiclang::str('share_drawing_kicker', $lang),
    'name' => $name,
    'img' => (new moodle_url($url, ['action' => 'imagen', 't' => $file->get_timemodified()]))->out(false),
    'strokes' => $strokes ? (new moodle_url($url, ['action' => 'trazos', 't' => $strokes->get_timemodified()]))->out(false) :
        false,
    'alt' => $title,
    'gift' => share::gift($centre, $lang),
    'share' => ['label' => publiclang::str('share_send_on', $lang), 'url' => $here->out(false), 'title' => $title,
        'text' => publiclang::str('share_drawing_text', $lang, $name), 'copied' => publiclang::str('share_copied', $lang),
        'measure' => (new moodle_url($url, ['action' => 'mandado', 'sesskey' => sesskey()]))->out(false),
        'measuresave' => (new moodle_url($url, ['action' => 'guardado', 'sesskey' => sesskey()]))->out(false),
        'save' => publiclang::str('share_video_save', $lang),
        // The drawing as a video to keep, whoever received it (pub/dibujo-video.js).
        'video' => share::video(
            $household,
            $child,
            $centre,
            $lang,
            (new moodle_url($url, ['action' => 'imagen', 't' => $file->get_timemodified()]))->out(false),
            $strokes ? (new moodle_url($url, ['action' => 'trazos', 't' => $strokes->get_timemodified()]))->out(false) : ''
        )],
    'invitation' => share::invitation($centre, 'dibujo', $lang, $url),
], $here);
