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
 * The families that wrote and, since then, were only called with nobody answering, back among those that wait. Without --run it
 * only says how many and which.
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

[$options, $unrecognised] = cli_get_params(['centre' => 0, 'run' => false, 'help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['centre']) {
    echo "Puts back among those that wait the families that were only called with nobody answering.

Options:
  --centre=ID    The centre.
  --run          Do it; without it, only how many there are and which.
  -h, --help     This help.

Example:
  php local/oksigeniaeguahe/cli/unreached.php --centre=1
";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
$out = teamnotes::unreached($centre, (bool) $options['run']);
cli_writeln('Families only called, nobody answered: ' . count($out) . ($out ? ' (' . implode(', ', array_keys($out)) . ')' : ''));
cli_writeln($options['run'] ? 'Done.' : 'Nothing was written: with --run, it is.');
