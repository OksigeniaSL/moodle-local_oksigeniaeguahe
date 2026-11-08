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
 * An entry of a centre, in the panel: a form of its own, edited as families see it, or a bridge with what the centre
 * already uses (Google, Meta, the mailbox or any other tool). Every button posts here and comes back; a question of a
 * form saves by itself.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_bridge;
use local_oksigeniaeguahe\local\panel_entry;

$id = required_param('id', PARAM_INT);

require_login(null, false);
$entry = $DB->get_record(entries::T, ['id' => $id], '*', MUST_EXIST);
$centre = centres::require_work((int) $entry->centreid);
$PAGE->set_context(context_system::instance());
$canact = centres::can_act((int) $USER->id);

if ($entry->kind === 'form') {
    $lang = panel_entry::lang($centre);
    $open = optional_param('q', 0, PARAM_INT);
    $here = panel_entry::url($entry, $lang, $open);
    panel::setup($here, format_string($entry->name));
    if ($canact && data_submitted()) {
        require_sesskey();
        centres::require_act((int) $centre->id);
        [$to, $message] = panel_entry::act($entry, $centre, $lang, $here);
        if ($to === null) {
            // The save that happens by itself, a moment after the last change.
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['saved' => true]);
            die();
        }
        redirect($to, $message ?: null, null, $message ? \core\output\notification::NOTIFY_SUCCESS : null);
    }
    panel::output(
        $centre,
        'entries',
        format_string($entry->name),
        'panel_entry',
        panel_entry::context($entry, $centre, $lang, $open)
    );
}

$draft = optional_param('draft', 0, PARAM_INT);
panel::setup(
    new moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id] + ($draft ? ['draft' => $draft] : [])),
    format_string($entry->name)
);
if ($canact && data_submitted()) {
    require_sesskey();
    centres::require_act((int) $centre->id);
    [$to, $message, $kind] = panel_bridge::act($entry, $centre);
    redirect($to, $message ?: null, null, $message ? $kind : null);
}
panel::output(
    $centre,
    'entries',
    format_string($entry->name),
    'panel_bridge',
    panel_bridge::context($entry, $centre, $canact, $draft)
);
