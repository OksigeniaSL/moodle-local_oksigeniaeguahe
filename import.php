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
 * What already happened, imported pseudonymised: the contacts of a past campaign, the students of a school year and the
 * totals of past years. Nobody becomes a household: it is for the numbers.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\form\import_form;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_act($centreid);
$url = new moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]);
staff::setup($url, get_string('import', $c), $centre);

if ($delete) {
    $import = $DB->get_record(imports::T, ['id' => $delete, 'centreid' => $centre->id], '*', MUST_EXIST);
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        imports::delete((int) $centre->id, $delete);
        redirect($url, get_string('import_deleted', $c), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    // What it removes, in the question and in its button.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('import_delete_confirm', $c, (object) ['label' => s($import->label), 'rows' => (int) $import->rowcount]),
        new single_button(
            new moodle_url($url, ['delete' => $delete, 'confirm' => 1, 'sesskey' => sesskey()]),
            get_string('import_delete_yes', $c),
            'post',
            single_button::BUTTON_DANGER
        ),
        new single_button($url, get_string('import_delete_no', $c), 'get')
    );
    echo $OUTPUT->footer();
    die();
}

$form = new import_form($url);
$form->set_data(['c' => $centre->id]);
$errors = [];
if ($data = $form->get_data()) {
    [$id, $rows, $errors] = imports::import(
        (int) $centre->id,
        $data->kind,
        $data->label,
        (string) $form->get_new_filename('file'),
        (string) $form->get_file_content('file'),
        (int) $USER->id
    );
    if ($id) {
        redirect(
            new moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id]),
            get_string('import_done', $c, $rows),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$list = [];
foreach (imports::for_centre((int) $centre->id) as $import) {
    $list[] = [
        'label' => s($import->label),
        'kind' => get_string('import_kind_' . $import->kind, $c),
        'rows' => (int) $import->rowcount,
        'file' => s((string) $import->filename),
        'when' => userdate((int) $import->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        'deleteurl' => (new moodle_url($url, ['delete' => $import->id]))->out(false),
    ];
}
$errorlist = [];
foreach ($errors as $line => $message) {
    $errorlist[] = ['line' => $line, 'message' => $message];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/import', ['tabs' => staff::tabs($centre, 'metrics'),
    'imports' => $list, 'hasimports' => (bool) $list, 'errors' => $errorlist, 'haserrors' => (bool) $errorlist]);
$form->display();
echo $OUTPUT->footer();
