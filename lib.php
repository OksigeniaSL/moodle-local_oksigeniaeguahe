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
 * Library functions of local_oksigeniaeguahe.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serves the logo of a centre, which families see without an account on their pages.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool False when there is nothing to serve.
 */
function local_oksigeniaeguahe_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;
    if ($context->contextlevel != CONTEXT_SYSTEM || !in_array($filearea, ['logo', 'hostphoto'], true)) {
        return false;
    }
    $itemid = (int) array_shift($args);
    // The photo of someone of the team, only while the centre shows that person to the families.
    if ($filearea === 'hostphoto' && !$DB->record_exists('local_oksigeniaeguahe_member', ['id' => $itemid, 'public' => 1])) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_oksigeniaeguahe', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, false, $options);
}

/**
 * What admissions can tell a family on the phone, with Oksigenia Marandu: their texts are in the language packs.
 *
 * @return array By notice: values for its examples and how many a week, to estimate the cost.
 */
function local_oksigeniaeguahe_oksigeniamarandu_notices(): array {
    return [
        'visit_confirmed' => ['examples' => ['Los Almendros School', 'Saturday 21 November, 10:00'], 'perweek' => 0.2],
        'visit_reminder' => ['examples' => ['Los Almendros School', 'Saturday 21 November, 10:00'], 'perweek' => 0.2],
        'visit_invitation' => ['examples' => ['Los Almendros School', 'Tuesday 10 November, 10:30', 'Lucía Medina'],
            'perweek' => 0.1],
        'request_received' => ['examples' => ['Los Almendros School'], 'perweek' => 0.1],
        'message_received' => ['examples' => ['Los Almendros School'], 'perweek' => 0.1],
        'answer_waiting' => ['examples' => ['Los Almendros School', 'Yes, there is a canteen with its own kitchen.'],
            'perweek' => 0.2],
        'visit_changed' => ['examples' => ['Los Almendros School', 'Saturday 21 November, from 11:00 to 13:00, in the hall'],
            'perweek' => 0.05],
    ];
}
