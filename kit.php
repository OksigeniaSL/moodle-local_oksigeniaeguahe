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
 * The kit of an open act for the networks of the centre: when to post, a link for each network, the pictures without
 * a QR, the texts and a script to film; the texts by the AI of the centre when it has it on. A person posts them.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\kit;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_kit;

$id = required_param('id', PARAM_INT);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
$event = $DB->get_record(events::T, ['id' => $id], '*', MUST_EXIST);
$centre = centres::require_work((int) $event->centreid);
$url = new moodle_url('/local/oksigeniaeguahe/kit.php', ['id' => $event->id]);
$back = new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]);
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('kit_title', $c));
if (!kit::has($event)) {
    redirect($back);
}

$shape = optional_param('img', '', PARAM_ALPHA);
if ($shape !== '') {
    $png = kit::picture($event, $centre, $shape);
    if ($png === '') {
        send_header_404();
        die();
    }
    @header('Content-Type: image/png');
    @header('Content-Length: ' . strlen($png));
    @header('Cache-Control: private, max-age=600');
    if (optional_param('bajar', 0, PARAM_BOOL)) {
        $name = clean_filename(core_text::strtolower(format_string($event->name)) . '-' . $shape . '.png');
        @header('Content-Disposition: attachment; filename="' . str_replace(' ', '-', $name) . '"');
    }
    echo $png;
    die();
}

if (data_submitted() && confirm_sesskey()) {
    centres::require_act((int) $centre->id);
    $action = optional_param('action', '', PARAM_ALPHA);
    if ($action === 'write') {
        $done = kit::write($event, $centre, (int) $USER->id);
        redirect(new moodle_url($url, [], 'textos'), $done ? null : get_string('kit_write_failed', $c));
    }
    if ($action === 'forget') {
        kit::forget($event);
        redirect(new moodle_url($url, [], 'textos'));
    }
}

panel::output(
    $centre,
    'events',
    get_string('kit_title', $c),
    'panel_kit',
    panel_kit::context($event, $centre, centres::can_act((int) $USER->id), time())
);
