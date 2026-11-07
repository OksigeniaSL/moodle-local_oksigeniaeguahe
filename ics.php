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
 * The file of an event for the calendar of a family, from its private link.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Families have no account: their private link lets them in.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;

$token = optional_param('k', '', PARAM_ALPHANUMEXT);
$eventid = optional_param('e', 0, PARAM_INT);
publicpage::setup(new moodle_url('/local/oksigeniaeguahe/ics.php'));

$household = households::by_link($token);
$event = $household ? events::get($eventid, (int) $household->centreid) : null;
$signup = $event ? $DB->get_record('local_oksigeniaeguahe_signup', ['eventid' => $event->id,
    'householdid' => $household->id]) : null;
if (!$signup) {
    send_header_404();
    die();
}
$centre = centres::get((int) $event->centreid);
$lang = publiclang::choose($centre);
$description = publiclang::str('ics_description', $lang, (object) ['centre' => format_string($centre->name),
    'link' => (new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]))->out(false)]);
publicpage::headers();
@header('Content-Type: text/calendar; charset=utf-8');
@header('Content-Disposition: attachment; filename="' . clean_filename(format_string($event->name)) . '.ics"');
echo events::ics($event, $centre, $description, events::when($event, (int) $signup->slotid));
