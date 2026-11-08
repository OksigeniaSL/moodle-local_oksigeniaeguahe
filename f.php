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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
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

if ($action === 'download') {
    publicpage::headers();
    @header('Content-Type: application/json; charset=utf-8');
    @header('Content-Disposition: attachment; filename="' . clean_filename(publiclang::str('pub_data_file', $lang)) .
        '.json"');
    echo json_encode(households::export($household), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
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
        'saved' => optional_param('saved', 0, PARAM_BOOL)]),
    $url
);
