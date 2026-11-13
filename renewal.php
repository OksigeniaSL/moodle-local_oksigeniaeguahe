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
 * Who goes on: the centre asks its families, for a school year, whether each child goes on, and sees it course by course.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\firstdays;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_renewal;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\renewal;
use local_oksigeniaeguahe\local\roster;

require_login(null, false);

$centre = centres::require_work(required_param('c', PARAM_INT));
$c = 'local_oksigeniaeguahe';
$url = new moodle_url('/local/oksigeniaeguahe/renewal.php', ['c' => $centre->id]);
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('panel_renewal', $c));

if (data_submitted()) {
    require_sesskey();
    centres::require_act((int) $centre->id);
    $do = optional_param('do', '', PARAM_ALPHA);
    $now = time();
    $year = trim((string) ($centre->renewalyear ?? ''));
    $anchor = 'pregunta';
    if ($do === 'start') {
        // Asking about a school year, from now and without an end until someone writes one.
        $asked = optional_param('year', '', PARAM_NOTAGS);
        $years = array_column(renewal::candidates($centre), 'ready', 'year');
        if (!empty($years[$asked])) {
            renewal::set($centre, $asked, $now, 0);
        }
    } else if ($do === 'reopen' && $year !== '') {
        renewal::set($centre, $year, (int) $centre->renewalfrom, 0);
    } else if ($do === 'stop' && $year !== '') {
        renewal::set($centre, $year, (int) $centre->renewalfrom, $now);
    } else if ($do === 'until' && $year !== '') {
        // The last day the families can answer; the question closes at the start of the next one.
        $day = firstdays::parse(publicpage::date_param('until'));
        if ($day) {
            renewal::set($centre, $year, (int) $centre->renewalfrom, $day + DAYSECS);
        }
    } else if ($do === 'noend' && $year !== '') {
        renewal::set($centre, $year, (int) $centre->renewalfrom, 0);
    } else if ($do === 'tell' && renewal::open($centre, $now)) {
        // Hundreds of mails do not fit in a page: they go in the background, and the page counts them as they go.
        $task = new \local_oksigeniaeguahe\task\tell_renewal();
        $task->set_custom_data(['centreid' => (int) $centre->id, 'userid' => (int) $USER->id]);
        \core\task\manager::queue_adhoc_task($task, true);
    } else if (in_array($do, ['rosterkind', 'rosteryear', 'rosteroff'], true)) {
        // The families already at the centre: where the course of each student comes from, and the school year now.
        $source = roster::source($centre);
        $kind = $do === 'rosterkind' ? optional_param('kind', '', PARAM_ALPHA) : ($source[0] ?? '');
        $arg = $do === 'rosterkind' ? optional_param('arg', '', PARAM_ALPHANUMEXT) : ($source[1] ?? '');
        $rosteryear = $do === 'rosteryear' ? optional_param('year', '', PARAM_NOTAGS) : (string) ($centre->rosteryear ?? '');
        roster::set_source($centre, $do === 'rosteroff' ? '' : $kind, $arg, $rosteryear);
        $anchor = 'padron';
    } else if ($do === 'rostermap') {
        roster::set_map($centre, optional_param('value', '', PARAM_TEXT), optional_param('grade', '', PARAM_TEXT));
        $anchor = 'padron';
    } else if ($do === 'rosteraccept') {
        // What each value most likely is, taken as it is for all those nobody said yet.
        foreach (roster::overview($centre)['values'] as $row) {
            if ($row['grade'] === null && $row['guess'] !== '') {
                roster::set_map($centre, $row['value'], $row['guess']);
            }
        }
        $anchor = 'padron';
    } else if ($do === 'rostersync') {
        roster::sync($centre, $now);
        $anchor = 'padron';
    } else if ($do === 'answer') {
        // What a family told a person, from the list.
        $child = required_param('child', PARAM_INT);
        $householdid = (int) $DB->get_field(households::CHILDREN, 'householdid', ['id' => $child]);
        $household = households::get($householdid, (int) $centre->id);
        if ($household) {
            renewal::from_team(
                $centre,
                $household,
                $child,
                optional_param('answer', '', PARAM_ALPHA),
                '',
                (int) $USER->id,
                $now
            );
        }
        $anchor = 'n' . $child;
    }
    // The families already at the centre are set up in the settings: their gestures go back there.
    if (str_starts_with($do, 'roster') && optional_param('volver', '', PARAM_ALPHA) === 'ajustes') {
        redirect(new moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id], $anchor));
    }
    redirect(new moodle_url($url, [], $anchor));
}

panel::output(
    $centre,
    'inbox',
    get_string('panel_renewal', $c),
    'panel_renewal',
    panel_renewal::context(
        $centre,
        centres::can_act((int) $USER->id),
        time(),
        optional_param('cohorte', '', PARAM_ALPHANUM),
        optional_param('curso', 0, PARAM_INT)
    )
);
