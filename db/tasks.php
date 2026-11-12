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
 * Scheduled tasks of local_oksigeniaeguahe.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\\local_oksigeniaeguahe\\task\\send_thanks',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\\local_oksigeniaeguahe\\task\\send_reminders',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\local_oksigeniaeguahe\task\fetch_opendata',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '4',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '1',
    ],
    [
        'classname' => '\local_oksigeniaeguahe\task\erase_inactive',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\\local_oksigeniaeguahe\\task\\make_reports',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\\local_oksigeniaeguahe\\task\\locate_places',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\\local_oksigeniaeguahe\\task\\fetch_name_voices',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\\local_oksigeniaeguahe\\task\\read_messages',
        'blocking' => 0,
        'minute' => '*/10',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\\local_oksigeniaeguahe\\task\\sync_roster',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '5',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
