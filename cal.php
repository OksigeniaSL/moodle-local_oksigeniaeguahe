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
 * The calendar of the team of a centre (local\teamcalendar), by its private link: a calendar file that any calendar can
 * subscribe to, or, with f=json, the data the script of Google copies into a calendar of Google.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Calendars read it without an account: its private link lets them.
define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\teamcalendar;

$centreid = optional_param('c', 0, PARAM_INT);
$key = optional_param('k', '', PARAM_ALPHANUM);
$format = optional_param('f', '', PARAM_ALPHA);
$centre = $centreid ? centres::get($centreid) : null;
if (!$centre || !empty($centre->practice) || !teamcalendar::key_ok($centreid, $key)) {
    http_response_code(404);
    die();
}
$items = teamcalendar::items($centre);
@header('Cache-Control: no-store');
@header('X-Robots-Tag: noindex, nofollow');
if ($format === 'json') {
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode(teamcalendar::data($centre, $items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}
@header('Content-Type: text/calendar; charset=utf-8');
@header('Content-Disposition: inline; filename="admisiones.ics"');
echo teamcalendar::ics($centre, $items);
