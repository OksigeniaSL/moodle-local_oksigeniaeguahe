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
 * Takes out what a sheet of the team repeated in the history of its families because a column was renamed, or its rows copied
 * to another tab, and puts back in the queue whoever stopped waiting because of it. Without --run it only says how many.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\teamnotes;

[$options, $unrecognised] = cli_get_params(
    ['centre' => 0, 'title' => '', 'day' => '', 'run' => false, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
$day = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $options['day'], $m) ?
    make_timestamp((int) $m[1], (int) $m[2], (int) $m[3]) : 0;
if ($options['help'] || !$options['centre'] || !$day) {
    echo "Takes out the lines a sheet of the team repeated under another column.

Options:
  --centre=ID    The centre.
  --title=TEXT   The column that was read as new, as it is written; without it, any.
  --day=DATE     The day it was read (2026-11-25).
  --run          Do it; without it, only how many there are and of whom.
  -h, --help     This help.

Example:
  php local/oksigeniaeguahe/cli/sheet_repeated.php --centre=1 --day=2026-11-25
";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
$out = teamnotes::repeated($centre, (string) $options['title'], $day, $day + DAYSECS, (bool) $options['run']);
$titles = array_map(fn($title, $n) => "$title $n", array_keys($out['titles']), $out['titles']);
cli_writeln('Lines repeated: ' . count($out['lines']) . ($titles ? ' (' . implode(', ', $titles) . ')' : ''));
cli_writeln('Families that wait again: ' . count($out['waiting']) . ($out['waiting'] ? ' (' . implode(', ', array_keys($out['waiting'])) . ')' : ''));
cli_writeln($options['run'] ? 'Done.' : 'Nothing was written: with --run, it is.');
