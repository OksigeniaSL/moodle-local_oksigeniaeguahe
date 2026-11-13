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
 * The web services of Eguahe: what an adult of a family answers from the app.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_oksigeniaeguahe_renewal_answer' => [
        'classname' => \local_oksigeniaeguahe\external\renewal_answer::class,
        'description' => 'An adult of a family says from the app whether a child goes on next school year.',
        'type' => 'write',
        'ajax' => false,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
