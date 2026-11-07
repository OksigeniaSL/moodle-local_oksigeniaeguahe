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
 * What already happened, imported as it comes: the centre gives a file (a spreadsheet with its sheets, an export of the
 * adverts, the answers of a form, a CSV), sees what Eguahe makes of each sheet, what stays and what is removed, and
 * imports it. The file is not kept. Nobody becomes a household: it is for the numbers.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\form\import_form;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\recognise;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$draft = optional_param('draft', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
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

// The file the centre gave, in its own draft area: only the one who gave it sees it.
$fs = get_file_storage();
$usercontext = context_user::instance($USER->id);
$file = null;
if ($draft) {
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draft, 'id DESC', false);
    $file = reset($files) ?: null;
}

if ($file && $action !== '' && confirm_sesskey()) {
    if ($action === 'cancel') {
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $draft);
        redirect($url, get_string('import_cancelled', $c), null, \core\output\notification::NOTIFY_INFO);
    }
    if ($action === 'import') {
        $kinds = optional_param_array('kind', [], PARAM_ALPHA);
        $labels = optional_param_array('label', [], PARAM_TEXT);
        $years = optional_param_array('schoolyear', [], PARAM_TEXT);
        $choices = [];
        foreach ($kinds as $i => $kind) {
            if (in_array($kind, recognise::KINDS, true)) {
                $year = trim((string) ($years[$i] ?? ''));
                $choices[(int) $i] = ['kind' => $kind, 'label' => (string) ($labels[$i] ?? ''),
                    'schoolyear' => recognise::schoolyear($year) ?? (preg_match('/^\d{4}$/', $year) ? $year : null)];
            }
        }
        $path = $file->copy_content_to_temp('local_oksigeniaeguahe');
        try {
            [$made, $rows, $skipped] = imports::import_file(
                (int) $centre->id,
                $path,
                $file->get_filename(),
                $choices,
                (int) $USER->id
            );
        } finally {
            @unlink($path);
        }
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $draft);
        redirect(
            new moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id]),
            get_string('import_done_file', $c, (object) ['sheets' => $made, 'rows' => $rows, 'skipped' => $skipped]),
            null,
            $made ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
}

$form = new import_form($url);
$form->set_data(['c' => $centre->id]);
if ($data = $form->get_data()) {
    redirect(new moodle_url($url, ['draft' => $data->file]));
}

$list = [];
foreach (imports::for_centre((int) $centre->id) as $import) {
    $list[] = [
        'label' => s($import->label),
        'kind' => get_string('import_kind_' . $import->kind, $c),
        'rows' => (int) $import->rowcount,
        'file' => s(trim((string) $import->filename . ($import->sheet ? ' · ' . $import->sheet : ''))),
        'when' => userdate((int) $import->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        'deleteurl' => (new moodle_url($url, ['delete' => $import->id]))->out(false),
    ];
}

$sheets = [];
if ($file) {
    // What Eguahe makes of each sheet, with the kinds the centre chose when it looks again.
    $chosen = $action === 'refresh' ? optional_param_array('kind', [], PARAM_ALPHA) : [];
    $chosen = array_filter($chosen, fn($k) => $k === 'none' || in_array($k, recognise::KINDS, true));
    $labels = $action === 'refresh' ? optional_param_array('label', [], PARAM_TEXT) : [];
    $years = $action === 'refresh' ? optional_param_array('schoolyear', [], PARAM_TEXT) : [];
    $path = $file->copy_content_to_temp('local_oksigeniaeguahe');
    try {
        $preview = imports::preview($path, $file->get_filename(), $chosen);
    } finally {
        @unlink($path);
    }
    foreach ($preview as $sheet) {
        $kind = $sheet['kind'] ?? 'none';
        $sheets[] = [
            'index' => $sheet['index'],
            'name' => $sheet['name'],
            'rows' => $sheet['rows'],
            'none' => $kind === 'none',
            'kinds' => array_map(fn($k) => ['value' => $k, 'name' => get_string('import_kind_' . $k, $c),
                'selected' => $k === $kind], array_merge(recognise::KINDS, ['none'])),
            'label' => $labels[$sheet['index']] ?? $sheet['label'],
            'schoolyear' => $years[$sheet['index']] ?? (string) $sheet['schoolyear'],
            'kept' => implode(' · ', $sheet['kept']),
            'keys' => implode(' · ', $sheet['keys']),
            'removed' => implode(' · ', $sheet['removed']),
            'haskept' => (bool) $sheet['kept'],
            'haskeys' => (bool) $sheet['keys'],
            'hasremoved' => (bool) $sheet['removed'],
            'health' => $sheet['health'],
        ];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/import', ['tabs' => staff::tabs($centre, 'metrics'),
    'imports' => $list, 'hasimports' => (bool) $list, 'preview' => (bool) $file, 'sheets' => $sheets,
    'filename' => $file ? s($file->get_filename()) : '', 'draft' => $draft, 'sesskey' => sesskey(),
    'action' => $url->out(false)]);
if (!$file) {
    $form->display();
}
echo $OUTPUT->footer();
