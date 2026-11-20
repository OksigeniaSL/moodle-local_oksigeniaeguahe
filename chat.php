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
 * The family beside a conversation of the chat of the centre: the page the chat shows, and the search it asks for.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\chatpanel;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniaeguahe/chat.php'));

if (optional_param('action', '', PARAM_ALPHA) === 'find') {
    // Who writes: asked by the page with the email and the phone the chat gave it, never in the address.
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');
    if (!isloggedin() || isguestuser() || !confirm_sesskey()) {
        echo json_encode(['html' => '', 'error' => 'login']);
        die();
    }
    if (!centres::for_user((int) $USER->id)) {
        echo json_encode(['html' => '', 'error' => 'team']);
        die();
    }
    $found = chatpanel::find(
        (int) $USER->id,
        optional_param('email', '', PARAM_RAW_TRIMMED),
        optional_param('phone', '', PARAM_RAW_TRIMMED)
    );
    echo json_encode(['html' => $OUTPUT->render_from_template('local_oksigeniaeguahe/chat_family', $found)]);
    die();
}

chatpanel::page();
