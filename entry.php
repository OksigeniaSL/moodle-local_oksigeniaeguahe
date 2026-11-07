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
 * An entry of a centre. A form: its questions, the common ones it asks, where it is shared from (each place with its
 * link and its QR), the code for the web of the centre and how it looks. A bridge: the script to paste in Google, the
 * file of Meta to bring, or the address for any other tool, and what each question that came is.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\form\entry_form;
use local_oksigeniaeguahe\form\upload_form;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\sheets;
use local_oksigeniaeguahe\local\staff;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
$entry = $DB->get_record(entries::T, ['id' => $id], '*', MUST_EXIST);
$centre = centres::require_work((int) $entry->centreid);
$url = new moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id]);
$PAGE->set_context(context_system::instance());
staff::setup($url, format_string($entry->name), $centre);
$canact = centres::can_act((int) $USER->id);
$isform = $entry->kind === 'form';
$lang = $centre->lang ?: 'es';

// The settings, with their own form.
$settings = new entry_form($url, ['isform' => $isform]);
$defaults = ['id' => $entry->id, 'name' => $entry->name, 'title' => $entry->title, 'lead' => $entry->lead,
    'acknowledge' => $entry->acknowledge, 'active' => $entry->active];
foreach (forms::BLOCKS as $block) {
    $defaults['block_' . $block] = in_array($block, forms::blocks($entry), true) ? 1 : 0;
}
$settings->set_data($defaults);
if ($canact && ($data = $settings->get_data())) {
    centres::require_act((int) $centre->id);
    entries::update($entry, $data);
    if ($isform) {
        $blocks = array_values(array_filter(forms::BLOCKS, fn($b) => !empty($data->{'block_' . $b})));
        $DB->update_record(entries::T, (object) ['id' => $entry->id,
            'title' => \core_text::substr(trim((string) $data->title), 0, 255) ?: null,
            'lead' => trim((string) $data->lead) ?: null, 'blocks' => implode(',', $blocks)]);
    }
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// The file of contacts of a bridge of Meta, in the draft area of whoever gives it.
$fs = get_file_storage();
$usercontext = context_user::instance($USER->id);
$draft = optional_param('draft', 0, PARAM_INT);
$file = null;
if ($draft) {
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draft, 'id DESC', false);
    $file = reset($files) ?: null;
}
$upload = new upload_form($url);
$upload->set_data(['id' => $entry->id]);
if ($canact && $entry->kind === 'meta' && ($data = $upload->get_data())) {
    redirect(new moodle_url($url, ['draft' => $data->file]));
}

if ($canact && $action !== '' && confirm_sesskey()) {
    centres::require_act((int) $centre->id);
    switch ($action) {
        case 'moveup':
        case 'movedown':
            forms::move($entry, required_param('field', PARAM_INT), $action === 'moveup' ? -1 : 1);
            redirect(new moodle_url($url, [], 'preguntas'));
            break;
        case 'deletefield':
            forms::delete_field($entry, required_param('field', PARAM_INT));
            redirect(new moodle_url($url, [], 'preguntas'), get_string('field_deleted', $c));
            break;
        case 'addorigin':
            $name = trim(optional_param('name', '', PARAM_TEXT));
            if ($name !== '') {
                forms::add_origin($entry, $name);
            }
            redirect(new moodle_url($url, [], 'compartir'));
            break;
        case 'removeorigin':
            forms::remove_origin($entry, required_param('key', PARAM_ALPHANUM));
            redirect(new moodle_url($url, [], 'compartir'));
            break;
        case 'roles':
            $questions = entries::questions($entry);
            $roles = [];
            foreach (optional_param_array('role', [], PARAM_ALPHA) as $i => $role) {
                if (isset($questions[(int) $i])) {
                    $roles[$questions[(int) $i]] = $role;
                }
            }
            entries::set_roles($entry, $roles);
            redirect(new moodle_url($url, [], 'preguntas'), get_string('changessaved'));
            break;
        case 'renew':
            entries::renew_secret($entry);
            redirect($url, get_string('entry_renewed', $c), null, \core\output\notification::NOTIFY_WARNING);
            break;
        case 'bring':
            if ($file && $entry->kind === 'meta') {
                $path = $file->copy_content_to_temp('local_oksigeniaeguahe');
                try {
                    [$rows] = entries::rows_of(sheets::read($path, $file->get_filename()));
                } finally {
                    @unlink($path);
                }
                $counts = entries::from_rows($entry, $rows);
                $fs->delete_area_files($usercontext->id, 'user', 'draft', $draft);
                redirect(
                    $url,
                    get_string('entry_brought', $c, (object) $counts),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            }
            break;
        case 'cancel':
            $fs->delete_area_files($usercontext->id, 'user', 'draft', $draft);
            redirect($url);
            break;
        case 'delete':
            entries::delete($entry);
            redirect(new moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]), get_string('entry_deleted', $c));
            break;
    }
}

// The questions of a form.
$fields = [];
$all = forms::fields((int) $entry->id);
$labels = [];
foreach ($all as $field) {
    $labels[$field->id] = forms::texts($field, $lang, $lang)['label'];
}
$position = 0;
foreach ($all as $field) {
    $texts = forms::texts($field, $lang, $lang);
    $fields[] = [
        'id' => $field->id,
        'label' => $texts['label'],
        'type' => get_string('field_type_' . $field->type, $c),
        'required' => (bool) $field->required,
        'options' => implode(' · ', $texts['options']),
        'condition' => $field->showif ? get_string('field_condition', $c, (object) ['question' => $labels[$field->showif] ?? '',
            'answer' => (string) $field->showvalue]) : '',
        'issection' => $field->type === 'section',
        'editurl' => (new moodle_url('/local/oksigeniaeguahe/field.php', ['e' => $entry->id, 'id' => $field->id]))->out(false),
        'first' => $position === 0,
        'last' => $position === count($all) - 1,
    ];
    $position++;
}

// Where it is shared from, each place with its link and its QR.
$shared = [];
if ($isform) {
    $main = entries::form_url($entry)->out(false);
    $shared[] = ['name' => get_string('entry_origin_none', $c), 'url' => $main, 'qr' => events::qr_of($main), 'key' => ''];
    foreach (forms::origins($entry) as $key => $name) {
        $link = (new moodle_url(entries::form_url($entry), ['d' => $key]))->out(false);
        $shared[] = ['name' => $name, 'url' => $link, 'qr' => events::qr_of($link), 'key' => $key];
    }
}

// The questions that came through a bridge, and what each one is.
$seen = [];
if (!$isform) {
    $roles = entries::roles($entry, entries::questions($entry));
    foreach (entries::questions($entry) as $i => $question) {
        $options = [['value' => '', 'name' => get_string('entry_role_auto', $c), 'selected' => false]];
        foreach (entries::ROLES as $role) {
            $options[] = ['value' => $role, 'name' => get_string('entry_role_' . $role, $c),
                'selected' => ($roles[$question] ?? '') === $role];
        }
        $seen[] = ['i' => $i, 'question' => $question, 'options' => $options,
            'now' => $roles[$question] ? get_string('entry_role_' . (in_array($roles[$question], entries::ROLES, true) ?
                $roles[$question] : 'ignore'), $c) : get_string('entry_role_none', $c)];
    }
}

// The file of Meta, before bringing it: how many rows and what each column is.
$preview = null;
if ($file && $entry->kind === 'meta') {
    $path = $file->copy_content_to_temp('local_oksigeniaeguahe');
    try {
        [$rows, $headers] = entries::rows_of(sheets::read($path, $file->get_filename()));
    } finally {
        @unlink($path);
    }
    $roles = entries::roles($entry, $headers);
    $columns = [];
    foreach ($headers as $header) {
        $role = $roles[$header] ?? null;
        $columns[] = ['header' => $header, 'role' => $role && in_array($role, entries::ROLES, true) ?
            get_string('entry_role_' . $role, $c) : get_string(in_array($role, entries::DROPPED, true) ? 'entry_role_dropped' :
            'entry_role_none', $c)];
    }
    $preview = ['filename' => $file->get_filename(), 'rows' => count($rows), 'columns' => $columns, 'draft' => $draft,
        'hasrows' => (bool) $rows];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/entry', [
    'tabs' => staff::tabs($centre, 'entries'),
    'name' => format_string($entry->name),
    'kind' => get_string('entry_kind_' . $entry->kind, $c),
    'counts' => get_string('entry_counts', $c, (object) ['received' => (int) $entry->received,
        'last' => $entry->lastreceived ? userdate((int) $entry->lastreceived, get_string('strftimedatetimeshort', 'langconfig')) :
            get_string('entry_never', $c)]),
    'active' => (bool) $entry->active,
    'isform' => $isform,
    'isgoogle' => $entry->kind === 'google',
    'ismeta' => $entry->kind === 'meta',
    'ishook' => $entry->kind === 'hook',
    'fields' => $fields,
    'hasfields' => (bool) $fields,
    'addurl' => (new moodle_url('/local/oksigeniaeguahe/field.php', ['e' => $entry->id]))->out(false),
    'previewurl' => $isform ? (new moodle_url(entries::form_url($entry), ['preview' => 1]))->out(false) : '',
    'previewframe' => $isform ? (new moodle_url(entries::form_url($entry, true), ['preview' => 1]))->out(false) : '',
    'shared' => $shared,
    'embed' => $isform ? entries::embed_code($entry, format_string($entry->title ?: $entry->name)) : '',
    'scriptform' => $entry->kind === 'google' ? entries::apps_script($entry, 'form') : '',
    'scriptsheet' => $entry->kind === 'google' ? entries::apps_script($entry, 'sheet') : '',
    'hookurl' => entries::hook_url($entry)->out(false),
    'secret' => $canact && in_array($entry->kind, ['hook', 'google'], true) ? $entry->secret : '',
    'seen' => $seen,
    'hasseen' => (bool) $seen,
    'preview' => $preview,
    'uploadform' => $canact && $entry->kind === 'meta' && !$preview ? $upload->render() : '',
    'settings' => $canact ? $settings->render() : '',
    'canact' => $canact,
    'action' => $url->out(false),
    'sesskey' => sesskey(),
    'entriesurl' => (new moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]))->out(false),
]);
echo $OUTPUT->footer();
