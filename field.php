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
 * A question of a form of a centre: new, or to change.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\form\field_form;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\staff;

$entryid = required_param('e', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
$entry = $DB->get_record(entries::T, ['id' => $entryid, 'kind' => 'form'], '*', MUST_EXIST);
$centre = centres::require_work((int) $entry->centreid);
centres::require_act((int) $centre->id);
$field = $id ? forms::field($id, (int) $entry->id) : null;
$url = new moodle_url('/local/oksigeniaeguahe/field.php', ['e' => $entry->id] + ($field ? ['id' => $field->id] : []));
$back = new moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id], 'preguntas');
staff::setup($url, get_string($field ? 'field_edit' : 'field_add', $c), $centre);
$lang = $centre->lang ?: 'es';

// The earlier questions it may wait for: those with answers to choose.
$earlier = [];
foreach (forms::fields((int) $entry->id) as $other) {
    if ($field && (int) $other->id === (int) $field->id) {
        break;
    }
    if (in_array($other->type, ['choice', 'multichoice', 'yesno'], true)) {
        $texts = forms::texts($other, $lang, $lang);
        $earlier[(int) $other->id] = $texts['label'] . ($other->type === 'yesno' ? ' (' . get_string('yes') . ' / ' .
            get_string('no') . ')' : ($texts['options'] ? ' (' . implode(' / ', $texts['options']) . ')' : ''));
    }
}

$form = new field_form($url, ['lang' => $lang, 'earlier' => $earlier]);
$defaults = ['e' => $entry->id, 'id' => $field->id ?? 0, 'type' => $field->type ?? 'choice',
    'required' => $field->required ?? 0, 'showif' => $field->showif ?? 0, 'showvalue' => $field->showvalue ?? ''];
$texts = $field ? (json_decode((string) $field->texts, true) ?: []) : [];
foreach (lists::LANGS as $each) {
    $defaults['label_' . $each] = $texts[$each]['label'] ?? '';
    $defaults['help_' . $each] = $texts[$each]['help'] ?? '';
    $defaults['options_' . $each] = implode("\n", $texts[$each]['options'] ?? []);
}
$form->set_data($defaults);
if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    forms::save_field($entry, $data);
    redirect($back, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($field ? 'field_edit' : 'field_add', $c) . ' · ' . format_string($entry->name), 3);
$form->display();
echo $OUTPUT->footer();
