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
 * The poster of an act as a whole page, to print it or make a PDF of it elsewhere: the one that invites, or the one of
 * the door, where families sign in when they arrive. The same page the panel opens.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\poster;

[$options, $unrecognised] = cli_get_params(
    ['event' => 0, 'paper' => 'A4', 'door' => false, 'where' => 'puerta', 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['event']) {
    echo "The poster of an act as a whole HTML page, on the standard output.

Options:
  --event=ID      The act.
  --paper=A4      A4, A3 or A5.
  --door          The poster of the door: families sign in with their phone when they arrive.
  --where=puerta  With --door, where it is put (puerta, mesa…): each place has its own link, and the numbers say it.
  -h, --help      This help.

Example:
  php local/oksigeniaeguahe/cli/poster.php --event=1 --door --where=mesa --paper=A5 > mesa-a5.html
";
    exit(0);
}
$event = $DB->get_record(events::T, ['id' => (int) $options['event']]);
$centre = $event ? centres::get((int) $event->centreid) : null;
if (!$event || !$centre) {
    cli_error('No act ' . (int) $options['event']);
}
echo poster::html($event, $centre, strtoupper((string) $options['paper']), (bool) $options['door'], (string) $options['where']);
