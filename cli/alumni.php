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
 * Finds the families of former students of a centre in what it already has: the notes of its sheets that say so
 * («antiguo alumno», «exalumna»…), and every adult looked for in its list of former students, when it has one. Without
 * --run, it only counts.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\alumni;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\teamnotes;

[$options, $unrecognised] = cli_get_params(['centre' => 0, 'run' => false, 'help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['centre']) {
    echo "Finds the families of former students of a centre in its notes and in its list of former students.

Options:
  --centre=ID   The centre.
  --run         Write what was found; without it, only how many there are.
  -h, --help    This help.
";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
// The notes: each line of the history of a family that says someone of it studied at the centre.
$found = [];
$rows = $DB->get_recordset_select(
    households::CONTACTS,
    "centreid = :centreid AND direction = 'note'",
    ['centreid' => $centre->id],
    '',
    'id, householdid, body'
);
foreach ($rows as $row) {
    $gestures = teamnotes::read('', (string) $row->body, time(), true) ?? [];
    $kinds = array_column($gestures, 'kind');
    if (in_array('alumni', $kinds, true) && !in_array('notalumni', $kinds, true)) {
        $found[(int) $row->householdid] = true;
    }
}
$rows->close();
$already = alumni::families((int) $centre->id);
$new = array_diff_key($found, $already);
echo 'Families whose notes say so: ' . count($found) . ' (' . count($new) . " not known yet)\n";
echo 'Names on the list of former students: ' . alumni::list_size((int) $centre->id) . "\n";
if (!$options['run']) {
    exit(0);
}
foreach (array_keys($new) as $id) {
    $household = households::get($id, (int) $centre->id);
    if ($household) {
        alumni::set($household, 0, alumni::YES, 'sheet', 0, '', false);
    }
}
[$yes, $maybe] = alumni::match_centre((int) $centre->id);
echo 'Done: ' . count($new) . " from the notes; from the list, $yes found and $maybe for a person to say.\n";
echo 'Families of former students now: ' . count(alumni::families((int) $centre->id)) . "\n";
