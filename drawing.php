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
 * The drawing of a child: taken with the phone at the door, cut to the sheet and straightened, and kept for the child.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\drawings;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_drawing;

require_login(null, false);

$childid = required_param('child', PARAM_INT);
$eventid = optional_param('event', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

$child = $DB->get_record(households::CHILDREN, ['id' => $childid], '*', MUST_EXIST);
$centre = centres::require_work((int) $child->centreid);
$canact = centres::can_act((int) $USER->id);
$url = new moodle_url('/local/oksigeniaeguahe/drawing.php', ['child' => $child->id] + ($eventid ? ['event' => $eventid] : []));
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('panel_drawing', $c));

if (data_submitted()) {
    require_sesskey();
    centres::require_act((int) $centre->id);
    $action = optional_param('action', '', PARAM_ALPHA);
    $saved = false;
    if ($action === 'save') {
        $sent = $_FILES['photo'] ?? null;
        $saved = $sent && (int) ($sent['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($sent['tmp_name']) &&
            drawings::save($child, (string) file_get_contents($sent['tmp_name']));
    } else if ($action === 'delete') {
        drawings::delete((int) $child->id);
        $saved = true;
    }
    if (optional_param('fetch', 0, PARAM_BOOL)) {
        // From the cut made in the browser: it says so, and the page goes back by itself.
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['saved' => $saved, 'back' => panel_drawing::back($child, $eventid)->out(false)]);
        die();
    }
    redirect(
        $saved ? panel_drawing::back($child, $eventid) : $url,
        get_string(
            $saved ? ($action === 'delete' ? 'panel_drawing_deleted' : 'panel_drawing_saved') : 'panel_drawing_not_taken',
            $c
        ),
        null,
        $saved ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

panel::output(
    $centre,
    $eventid ? 'events' : 'inbox',
    get_string('panel_drawing', $c),
    'panel_drawing',
    panel_drawing::context($child, $eventid, $canact)
);
