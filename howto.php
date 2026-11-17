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
 * «How it is done»: the whole guide of the team's tasks, with a search on top. The sheet of the bar asks it the same
 * question with ajax=1 and gets the answers as JSON, while someone types; a question sent (not typed) that finds nothing
 * is kept, without names, to tune the guide.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\howto;
use local_oksigeniaeguahe\local\panel;

require_login(null, false);

$centre = centres::require_work(required_param('c', PARAM_INT));
$query = core_text::substr(trim(optional_param('q', '', PARAM_TEXT)), 0, 200);
$ajax = optional_param('ajax', 0, PARAM_BOOL);
$sent = optional_param('sent', 0, PARAM_BOOL);
$askai = optional_param('ai', 0, PARAM_BOOL);
$manage = centres::can_manage((int) $centre->id);
$c = 'local_oksigeniaeguahe';

$answers = $query !== '' ? howto::answers($query, $centre, $manage) : null;
// Only what someone sends, not every piece of a word they type, says what the guide lacks.
if ($answers && !$answers['answers'] && ($sent || !$ajax)) {
    howto::missed($query, $centre, time());
}

// Asked to the AI of the centre, by a person of the team: only when they press for it, never while typing.
$ai = $askai && $query !== '' ? howto::ask($query, $centre, $manage, (int) $USER->id) : null;
if ($askai && $ajax) {
    panel::headers();
    @header('Content-Type: application/json; charset=utf-8');
    $said = $ai ? ['aitext' => $ai['text'], 'answers' => $ai['answers'], 'by' => get_string('howto_ai_by', $c)] :
        ['aitext' => '', 'answers' => [], 'none' => get_string('howto_ai_none', $c)];
    echo json_encode($said, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

if ($ajax) {
    panel::headers();
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode($answers ?? howto::answers('', $centre, $manage), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

$url = new moodle_url('/local/oksigeniaeguahe/howto.php', ['c' => $centre->id]);
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('howto_title', $c));

panel::output($centre, 'inbox', get_string('howto_title', $c), 'panel_howto', [
    'head' => panel::head(['title' => get_string('howto_title', $c), 'sub' => get_string('howto_sub', $c),
        'what' => get_string('howto_what', $c)]),
    'action' => $url->out(false),
    'centreid' => (int) $centre->id,
    'q' => $query,
    'asked' => $query !== '',
    'found' => $answers ? $answers['answers'] : [],
    'hasfound' => !empty($answers['answers']),
    'none' => $answers ? $answers['none'] : '',
    'ask' => $answers && !$ai ? $answers['ask'] : false,
    'aitext' => $ai ? $ai['text'] : '',
    'aianswers' => $ai ? $ai['answers'] : [],
    'aiby' => get_string('howto_ai_by', $c),
    'ainone' => $askai && !$ai ? get_string('howto_ai_none', $c) : '',
    'groups' => howto::guide($centre, $manage),
    'guides' => howto::guides(),
]);
