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
 * The same child written down twice in one file («Dafne» and «Dafne Cano»), put together as one line: what the file proposes,
 * for all the files of a centre at once. Without --run it only says how many and in which files; names are never printed.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\lookalike;

[$options, $unrecognised] = cli_get_params(['centre' => 0, 'user' => 0, 'run' => false, 'help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['centre']) {
    echo "Puts together the same child written down twice in one file, as each file proposes.

Options:
  --centre=ID    The centre.
  --user=ID      Who says so, for the line in each history (else Eguahe).
  --run          Do it; without it, only how many there are and in which files.
  -h, --help     This help.

Example:
  php local/oksigeniaeguahe/cli/same_children.php --centre=1
";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
$ids = $DB->get_fieldset_sql(
    'SELECT householdid FROM {' . households::CHILDREN . '} WHERE centreid = ? GROUP BY householdid HAVING COUNT(1) > 1',
    [$centre->id]
);
$files = 0;
$pairs = 0;
foreach ($ids as $id) {
    $household = households::get((int) $id, (int) $centre->id);
    if (!$household) {
        continue;
    }
    $found = lookalike::twice($household);
    if (!$found) {
        continue;
    }
    $files++;
    $lines = [];
    // Each joining can make the next pair clear: a file with the same child three times is put together twice.
    for ($round = 0; $found && $round < 10; $round++) {
        [$a, $b] = $found[0];
        $pairs++;
        $lines[] = $a->id . '+' . $b->id;
        if (!$options['run']) {
            $lines = array_merge($lines, array_map(fn($p) => $p[0]->id . '+' . $p[1]->id, array_slice($found, 1)));
            $pairs += count($found) - 1;
            break;
        }
        lookalike::join_twice($household, (int) $a->id, (int) $b->id, (int) $options['user']);
        $found = lookalike::twice($household);
    }
    cli_writeln('File ' . $household->id . ': ' . implode(', ', $lines));
}
cli_writeln('Files with the same child twice: ' . $files . '; pairs: ' . $pairs . '.');
cli_writeln($options['run'] ? 'Done.' : 'Nothing was written: with --run, it is.');
