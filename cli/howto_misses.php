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
 * The questions that «How is it done?» could not answer in a centre, the most asked first: only their words, never who
 * asked nor anybody's name. To add the words that are missing to the tasks of the guide.
 *
 * php local/oksigeniaeguahe/cli/howto_misses.php [--centre=1] [--limit=50]
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\howto;

[$options] = cli_get_params(['centre' => 0, 'limit' => 50, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "The questions «How is it done?» found nothing for, the most asked first.\n\n" .
        "  --centre=1   only this centre (all of them without it)\n" .
        "  --limit=50   how many per centre\n";
    exit(0);
}
$centres = $options['centre'] ? [(int) $options['centre']] :
    array_keys($DB->get_records_menu('local_oksigeniaeguahe_centre', null, 'id', 'id, name'));
foreach ($centres as $centreid) {
    $misses = array_slice(howto::misses((int) $centreid), 0, (int) $options['limit']);
    if (!$misses) {
        continue;
    }
    echo "== centre $centreid\n";
    foreach ($misses as $miss) {
        echo str_pad((string) $miss['times'], 4, ' ', STR_PAD_LEFT) . '  ' . userdate($miss['last'], '%Y-%m-%d') . '  ' .
            $miss['question'] . "\n";
    }
}
