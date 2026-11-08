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
 * Importing what already happened, in the panel: the file as it comes; what Eguahe makes of each sheet before anything
 * comes in; and what was imported before, to take out.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_import;
use local_oksigeniaeguahe\local\recognise;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$draft = optional_param('draft', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_act($centreid);
$url = new moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]);
$PAGE->set_context(context_system::instance());
panel::setup(new moodle_url($url, $draft ? ['draft' => $draft] : []), get_string('panel_import_title', $c));
$file = panel::draft_file($draft);
$action = data_submitted() ? optional_param('action', '', PARAM_ALPHA) : '';
if ($action !== '') {
    require_sesskey();
}

switch ($action) {
    case 'upload':
        $draft = panel::keep_upload(panel_import::ACCEPT);
        redirect(
            $draft ? new moodle_url($url, ['draft' => $draft]) : $url,
            $draft ? null : get_string('panel_sheet_not_taken', $c),
            null,
            $draft ? null : \core\output\notification::NOTIFY_WARNING
        );
        break;
    case 'delete':
        $import = $DB->get_record(
            imports::T,
            ['id' => required_param('import', PARAM_INT), 'centreid' => $centre->id],
            '*',
            MUST_EXIST
        );
        imports::delete((int) $centre->id, (int) $import->id);
        redirect($url, get_string('import_deleted', $c), null, \core\output\notification::NOTIFY_SUCCESS);
        break;
    case 'cancel':
        panel::forget_draft($draft);
        redirect($url, get_string('import_cancelled', $c), null, \core\output\notification::NOTIFY_INFO);
        break;
    case 'import':
        if (!$file) {
            redirect($url);
        }
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
        panel::forget_draft($draft);
        redirect(
            new moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id]),
            get_string('import_done_file', $c, (object) ['sheets' => $made, 'rows' => $rows, 'skipped' => $skipped]),
            null,
            $made ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
        break;
}

// The file given, with what Eguahe makes of each sheet; when looking again, with what was chosen for each.
$preview = null;
$path = '';
if ($file) {
    $path = $file->copy_content_to_temp('local_oksigeniaeguahe');
    $again = $action === 'refresh';
    $preview = (object) ['filename' => $file->get_filename(), 'path' => $path, 'draft' => $draft,
        'kinds' => $again ? optional_param_array('kind', [], PARAM_ALPHA) : [],
        'labels' => $again ? optional_param_array('label', [], PARAM_TEXT) : [],
        'years' => $again ? optional_param_array('schoolyear', [], PARAM_TEXT) : []];
}
try {
    $context = panel_import::context($centre, $preview);
} finally {
    if ($path !== '') {
        @unlink($path);
    }
}
panel::output($centre, 'metrics', get_string('panel_import_title', $c), 'panel_import', $context);
