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
 * One question of a form, alone, in the panel: its editor and the phone, for whoever comes without JavaScript or from a
 * link. A new question is made in the form, which opens it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_entry;
use local_oksigeniaeguahe\local\surveys;

$entryid = required_param('e', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);

require_login(null, false);
$entry = $DB->get_record(entries::T, ['id' => $entryid], '*', MUST_EXIST);
if ($entry->kind !== 'form' && $entry->kind !== surveys::KIND) {
    throw new moodle_exception('invalidrecord', 'error');
}
$centre = centres::require_work((int) $entry->centreid);
centres::require_act((int) $centre->id);
$lang = panel_entry::lang($centre);
$field = $id ? forms::field($id, (int) $entry->id) : null;
if (!$field) {
    redirect(panel_entry::url($entry, $lang, 0, 'preguntas'));
}
$PAGE->set_context(context_system::instance());
$here = new moodle_url('/local/oksigeniaeguahe/field.php', ['e' => $entry->id, 'id' => $field->id, 'idioma' => $lang]);
panel::setup($here, format_string($entry->name));
if (data_submitted()) {
    require_sesskey();
    [$to, $message] = panel_entry::act($entry, $centre, $lang, $here);
    if ($to === null) {
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
    'panel_field',
    panel_entry::field_context($entry, $centre, $field, $lang)
);
