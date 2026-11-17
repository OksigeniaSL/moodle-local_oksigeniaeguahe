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
 * A guided help, «How it works: …»: the real screen in a frame, on the practice centre (where nothing leaves the site),
 * with a pointer, a caption for each step, its voice and a music under it when the art package brings them, and a step
 * where the person tries it. The steps are in pub/ayuda-piezas.js; the engine, in pub/ayuda.js.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\helps;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\practice;

require_login(null, false);

$centre = centres::require_work(required_param('c', PARAM_INT));
$piece = optional_param('piece', 'inbox', PARAM_ALPHA);
if (!isset(helps::PIECES[$piece])) {
    $piece = 'inbox';
}
$c = 'local_oksigeniaeguahe';
$practice = practice::centre();
// The practice centre wears the skin of the centre the help is opened from.
set_user_preference('local_oksigeniaeguahe_helpskin', panel::skin($centre));
$sound = helps::sound($piece, current_language());

$url = new moodle_url('/local/oksigeniaeguahe/help.php', ['c' => $centre->id, 'piece' => $piece]);
$PAGE->set_context(context_system::instance());
$title = get_string('help_title', $c, get_string('help_' . $piece, $c));
panel::setup($url, $title);

panel::output($centre, 'inbox', $title, 'panel_help', [
    'head' => panel::head(['title' => $title, 'sub' => get_string('help_sub', $c),
        'back' => ['url' => (new moodle_url('/local/oksigeniaeguahe/howto.php', ['c' => $centre->id]))->out(false),
            'name' => get_string('howto_title', $c)]]),
    'config' => json_encode([
        'piece' => $piece,
        'frame' => helps::start($piece, $practice),
        'steps' => helps::steps($piece),
        'voices' => $sound['voices'],
        'music' => $sound['music'],
        'now' => get_string('help_now', $c),
        'stuck' => get_string('help_stuck', $c),
        'auto' => optional_param('auto', 0, PARAM_BOOL),
        'texts' => [
            'answer' => get_string('help_text_answer', $c),
            'door' => get_string('help_text_door', $c),
            'event' => get_string('help_text_event', $c),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'stepcount' => count(helps::steps($piece)),
    'hasvoice' => (bool) $sound['voices'],
    'hasmusic' => $sound['music'] !== '',
    'howtourl' => (new moodle_url('/local/oksigeniaeguahe/howto.php', ['c' => $centre->id]))->out(false),
    'scripts' => array_map(fn($f) => ['src' => (new moodle_url(
        '/local/oksigeniaeguahe/pub/' . $f,
        ['v' => (int) get_config($c, 'version')]
    ))->out(false)], ['ayuda-piezas.js', 'ayuda.js']),
]);
