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
 * The page of Eguahe in the Moodle app and in Oksigenia Aty: whether each child goes on next school year, answered with
 * a tap by an adult of the family with an account (classes/output/mobile.php). In «More», only while there is something
 * to answer; the cards of the home open it too.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'local_oksigeniaeguahe' => [
        'handlers' => [
            'renewal' => [
                'delegate' => 'CoreMainMenuDelegate',
                'method' => 'mobile_renewal_view',
                'init' => 'mobile_init',
                'displaydata' => [
                    'title' => 'app_renewal_title',
                    'icon' => 'fas-school',
                ],
                'priority' => 390,
            ],
        ],
        'lang' => [
            ['app_renewal_title', 'local_oksigeniaeguahe'],
        ],
    ],
];
