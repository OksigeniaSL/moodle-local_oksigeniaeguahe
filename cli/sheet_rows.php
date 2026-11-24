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
 * Puts right what came from the sheets a team keeps before Eguahe told the team from the family: the rows of the team
 * kept as messages of a family become notes, and each family waits only for what it truly wrote. Without --run, it only
 * counts. It writes figures, never a name.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\sheetrows;

[$options, $unrecognised] = cli_get_params(['centre' => 0, 'run' => false, 'help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['centre']) {
    echo "Puts right the rows of the sheets of the team of a centre that were kept as messages of a family.

Options:
  --centre=ID   The centre.
  --run         Write it; without it, only how many there are.
  -h, --help    This help.

Example:
  php local/oksigeniaeguahe/cli/sheet_rows.php --centre=1
";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
$n = sheetrows::fix($centre, (bool) $options['run']);
cli_writeln('Sheets of the team: ' . $n['sheets'] . ' (' . $n['unclear'] . ' a form fills too, left as they are)');
cli_writeln('Rows of the team kept as messages: ' . $n['lines'] . ', of ' . $n['families'] . ' families; contacts of ads, left ' .
    'as they are: ' . $n['ads']);
cli_writeln('Families waiting before: ' . $n['waiting'] . '; that stop waiting: ' . $n['free'] .
    '; that wait since what they truly wrote: ' . $n['moved'] . '; waiting then: ' . $n['after']);
cli_writeln($options['run'] ? 'Done.' : 'Nothing was written: with --run, it is.');
