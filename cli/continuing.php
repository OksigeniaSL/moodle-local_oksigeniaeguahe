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
 * The continuing children of a centre: those already marked, and those the list of the centre would mark by itself (a
 * child of the list in a course whose next one, the school year after, is what its family asks for). Dry by default:
 * with --run it marks them, as the task does each morning.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\continuing;

[$options] = cli_get_params(['centre' => 0, 'run' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || !(int) $options['centre']) {
    echo "Continuing children of a centre.\n\n--centre=N  The centre.\n--run       Mark those the list says (without it, only what it would do).\n";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No such centre.');
}
$families = continuing::families((int) $centre->id);
echo 'Already continuing: ' . array_sum(array_map('count', $families)) . ' children of ' . count($families) . " families.\n";
$reserved = continuing::reserved((int) $centre->id);
echo 'Places kept for those who have not said yet: ' . array_sum($reserved) . ".\n";
$would = continuing::from_lists($centre, (bool) $options['run']);
echo ($options['run'] ? 'Marked' : 'Would mark') . ' by the list: ' . count($would['marked']) . ' children' .
    ($would['marked'] ? ' (' . implode(', ', $would['marked']) . ')' : '') . ".\n";
echo ($options['run'] ? 'Confirmed' : 'Would confirm') . ' by the list of the new school year (they went on): ' .
    count($would['confirmed']) . ' children' . ($would['confirmed'] ? ' (' . implode(', ', $would['confirmed']) . ')' : '') . ".\n";
echo 'Not clear, for a person to say: ' . count($would['doubt']) . ' families' .
    ($would['doubt'] ? ' (' . implode(', ', $would['doubt']) . ')' : '') . ".\n";
if ($options['run']) {
    echo 'Places kept now: ' . continuing::sync_all((int) $centre->id) . " more.\n";
}
