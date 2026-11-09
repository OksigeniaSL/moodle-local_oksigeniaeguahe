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
 * The page of a family, opened with its private link: what the centre keeps about it, its next visit with the file
 * for its calendar, and its data, to take with it or to erase, whenever it wants.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Families have no account: their private link lets them in.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\hobbies;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\seen;
use local_oksigeniaeguahe\local\settled;
use local_oksigeniaeguahe\local\siblings;
use local_oksigeniaeguahe\local\story;
use local_oksigeniaeguahe\local\surveys;

$token = optional_param('k', '', PARAM_ALPHANUMEXT);
$new = optional_param('new', 0, PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);
$url = new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]);
publicpage::setup($url);

$household = households::by_link($token);
$centre = $household ? centres::get((int) $household->centreid) : null;
if (!$household || !$centre) {
    $lang = publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en';
    publicpage::message(
        null,
        $lang,
        publiclang::str('pub_link_gone_title', $lang),
        publiclang::str('pub_link_gone', $lang),
        $url
    );
}
$lang = publiclang::choose($centre);
// What the family does here is counted, never when someone of the team looks at their page.
$note = function (string $what) use ($household, $centre): void {
    if (!seen::from_team($centre)) {
        seen::record($household, 'touch', $what);
    }
};

$drawing = optional_param('dibujo', 0, PARAM_INT);
if ($drawing) {
    // The drawing of one of their children, for the hall of their page.
    $children = households::full($household)->children;
    $file = isset($children[$drawing]) ? local_oksigeniaeguahe\local\drawings::file($drawing) : null;
    if (!$file) {
        send_header_404();
        die();
    }
    send_stored_file($file, DAYSECS, 0, false, ['cacheability' => 'private']);
}

$strokes = optional_param('trazos', 0, PARAM_INT);
if ($strokes) {
    // The strokes of that drawing, for it to draw itself on the table of their page.
    $children = households::full($household)->children;
    $file = isset($children[$strokes]) ? local_oksigeniaeguahe\local\drawings::strokes_file($strokes) : null;
    if (!$file) {
        send_header_404();
        die();
    }
    send_stored_file($file, DAYSECS, 0, false, ['cacheability' => 'private']);
}

if ($action === 'drawing') {
    // The drawing of one of their children, sent by the family: the photo, cut and straightened in the browser.
    $children = households::full($household)->children;
    $child = $children[optional_param('nino', 0, PARAM_INT)] ?? null;
    $back = new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang], 'quien-vendria');
    if (!$child) {
        redirect($back);
    }
    if (data_submitted() && confirm_sesskey()) {
        $sent = $_FILES['photo'] ?? null;
        $bytes = '';
        if ($sent && (int) ($sent['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($sent['tmp_name'])) {
            $bytes = (string) file_get_contents($sent['tmp_name']);
        }
        $saved = familypage::drawing_sent(
            $household,
            $centre,
            $child,
            optional_param('quitar', 0, PARAM_BOOL) ? null : $bytes,
            optional_param('strokes', '', PARAM_RAW)
        );
        if (optional_param('fetch', 0, PARAM_BOOL)) {
            if ($saved && !optional_param('quitar', 0, PARAM_BOOL)) {
                $note('drawing_sent');
            }
            publicpage::headers();
            @header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['saved' => $saved, 'back' => $back->out(false)]);
            die();
        }
        if ($saved && !optional_param('quitar', 0, PARAM_BOOL)) {
            $note('drawing_sent');
        }
        redirect($saved ? $back : new moodle_url($url, ['action' => 'drawing', 'nino' => $child->id, 'lang' => $lang, 'mal' => 1]));
    }
    publicpage::output(
        $centre,
        $lang,
        publiclang::str('pub_drawing_title', $lang, familypage::label_name($child) ?: $child->firstname),
        'public_drawing',
        familypage::drawing_context($child, $lang, $token, optional_param('mal', 0, PARAM_BOOL)),
        $url
    );
}

if ($action === 'download') {
    $note('download');
    publicpage::headers();
    @header('Content-Type: application/json; charset=utf-8');
    @header('Content-Disposition: attachment; filename="' . clean_filename(publiclang::str('pub_data_file', $lang)) .
        '.json"');
    echo json_encode(households::export($household), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

if (($action === 'settledask' || $action === 'goneask') && data_submitted() && confirm_sesskey()) {
    // Once enrolled: what they were left to ask before the first day; once gone, what could have been better.
    $kids = households::full($household)->children;
    $question = optional_param('question', '', PARAM_TEXT);
    $asked = $action === 'goneask' ? settled::gone($kids) && settled::ask($household, $question, settled::GONEASKED) :
        settled::in_centre($kids) && settled::ask($household, $question);
    if ($asked) {
        $note('question');
    }
    if (optional_param('fetch', 0, PARAM_BOOL)) {
        publicpage::headers();
        @header('Content-Type: application/json; charset=utf-8');
        @header('Cache-Control: no-store');
        echo json_encode(['saved' => $asked]);
        die();
    }
    redirect(new moodle_url(
        '/local/oksigeniaeguahe/f.php',
        ['k' => $token, 'lang' => $lang] + ($asked ? ['asked' => 1] : []),
        'pregunta'
    ));
}

if ($action === 'volunteer' && data_submitted() && confirm_sesskey()) {
    // Once enrolled: yes to telling another family how it went, next year.
    if (settled::in_centre(households::full($household)->children)) {
        settled::volunteer($household, $centre, time());
        $note('volunteer');
    }
    redirect(new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang], 'ofrecimiento'));
}

if (($action === 'siblingnotify' || $action === 'siblingconfirm') && data_submitted() && confirm_sesskey()) {
    // A brother or sister: tell us when their turn comes, or keep them a place now that it has.
    $sibling = optional_param('hermano', 0, PARAM_INT);
    if ($action === 'siblingnotify') {
        siblings::notify($household, $centre, $sibling, time());
    } else {
        siblings::confirm($household, $centre, $sibling, time());
    }
    redirect(new moodle_url(
        '/local/oksigeniaeguahe/f.php',
        ['k' => $token, 'lang' => $lang],
        $action === 'siblingnotify' ? 'avisar-' . $sibling : 'quien-vendria'
    ));
}

if (($action === 'ask' || $action === 'survey') && data_submitted() && confirm_sesskey()) {
    // After the visit: what they were left to ask, and the answers of the survey, one when touched or all at once.
    $last = surveys::last_visit($household, time());
    $fetch = optional_param('fetch', 0, PARAM_BOOL);
    $one = optional_param('q', -1, PARAM_INT);
    $saved = false;
    if ($last) {
        $question = optional_param('question', '', PARAM_TEXT);
        if (trim($question) !== '' && ($one === -1 || $one === 0)) {
            surveys::ask($household, $last, $question);
            $note('question');
        }
        $form = $action === 'survey' && surveys::is_open($last, time()) ? surveys::form($last) : null;
        foreach ($form ? forms::fields((int) $form->id) : [] as $field) {
            if ($field->type === 'section' || ($one !== -1 && $one !== (int) $field->id)) {
                continue;
            }
            $name = 'f' . $field->id;
            $answer = $field->type === 'multichoice' ? optional_param_array($name, [], PARAM_TEXT) :
                optional_param($name, '', PARAM_TEXT);
            $saved = surveys::save($household, $last, (int) $field->id, $answer, (string) ($centre->lang ?: 'es')) || $saved;
        }
    }
    if ($fetch) {
        publicpage::headers();
        @header('Content-Type: application/json; charset=utf-8');
        @header('Cache-Control: no-store');
        echo json_encode(['saved' => $saved || ($last !== null && $one === 0)]);
        die();
    }
    $asked = trim(optional_param('question', '', PARAM_TEXT)) !== '';
    redirect(new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang] + ($asked ? ['asked' => 1] : []) +
        ($action === 'survey' ? ['saved' => 1] : []), $action === 'survey' ? 'como-fue' : 'pregunta'));
}

if ($action === 'cuento') {
    // The story «Your first day»: the one of one of their children who starts at the centre.
    $child = story::children($household, $centre, $lang, time())[optional_param('nino', 0, PARAM_INT)] ?? null;
    if (!$child) {
        redirect(new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]));
    }
    $here = fn(array $params) => (new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token] + $params))->out(false);
    story::output(story::context($household, $child, $centre, $lang, [
        'drawing' => $here(['dibujo' => $child->id]),
        'share' => story::share_url($household, $child, $lang)->out(false),
        'measure' => seen::from_team($centre) ? null : ['url' => $here(['action' => 'seen']), 'sesskey' => sesskey()],
    ]));
}

if ($action === 'seen') {
    // What the page tells about itself, as it happens: opened, a part reached, something touched.
    if (data_submitted() && confirm_sesskey() && !seen::from_team($centre)) {
        seen::record(
            $household,
            optional_param('kind', '', PARAM_ALPHA),
            optional_param('what', '', PARAM_ALPHAEXT),
            optional_param('via', '', PARAM_ALPHANUM)
        );
    }
    publicpage::headers();
    @header('Cache-Control: no-store');
    http_response_code(204);
    die();
}

if ($action === 'hobbies' && data_submitted() && confirm_sesskey()) {
    // What each adult likes doing: one when touched, or all of them with «Save».
    $one = optional_param('adult', 0, PARAM_INT);
    $saved = false;
    foreach (households::full($household)->adults as $adult) {
        if ($one && $one !== (int) $adult->id) {
            continue;
        }
        $saved = hobbies::save(
            $household,
            (int) $adult->id,
            optional_param_array('h' . $adult->id, [], PARAM_ALPHANUMEXT),
            optional_param('o' . $adult->id, '', PARAM_TEXT),
            time()
        ) || $saved;
    }
    if ($saved) {
        $note('hobbies');
    }
    if (optional_param('fetch', 0, PARAM_BOOL)) {
        publicpage::headers();
        @header('Content-Type: application/json; charset=utf-8');
        @header('Cache-Control: no-store');
        echo json_encode(['saved' => $saved]);
        die();
    }
    redirect(new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang, 'gustos' => 1], 'aficiones'));
}

if ($action === 'erase') {
    if (optional_param('confirm', 0, PARAM_BOOL) && data_submitted() && confirm_sesskey()) {
        households::erase($household);
        publicpage::message(
            $centre,
            $lang,
            publiclang::str('pub_erased_title', $lang),
            publiclang::str('pub_erased', $lang),
            $url,
            false
        );
    }
    publicpage::output(
        $centre,
        $lang,
        publiclang::str('pub_erase_title', $lang),
        'public_erase',
        familypage::erase_context($household, $centre, $lang, $token),
        $url
    );
}

publicpage::output(
    $centre,
    $lang,
    publiclang::str('pub_family_title', $lang),
    'public_family',
    familypage::context($household, $centre, $lang, $token, $new, ['asked' => optional_param('asked', 0, PARAM_BOOL),
        'saved' => optional_param('saved', 0, PARAM_BOOL), 'hobbies' => optional_param('gustos', 0, PARAM_BOOL),
        'de' => optional_param('de', '', PARAM_ALPHA)]),
    $url
);
