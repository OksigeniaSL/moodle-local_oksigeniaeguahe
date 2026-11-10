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
 * Puts in the bank the reading of «Tu primer día» that says a name, from a folder with its pages, habla.json and,
 * if there is one, cuento-con-cancion.mp3; or takes it out.
 *
 * php local/oksigeniaeguahe/cli/namevoice.php --name=Martina --from=/path/to/folder [--lang=es] [--reading=nombre]
 * php local/oksigeniaeguahe/cli/namevoice.php --name=Martina --delete
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\namevoices;

[$options] = cli_get_params(['name' => '', 'from' => '', 'lang' => 'es', 'reading' => namevoices::READING,
    'delete' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || $options['name'] === '' || (!$options['delete'] && $options['from'] === '')) {
    echo "The voice of «Tu primer día» saying a name, in the bank of Oksigenia Eguahe.\n\n" .
        "  --name=Name      the name as the book writes it\n" .
        "  --from=folder    the pages (00-portada.mp3 to 08-vuelta.mp3), habla.json and cuento-con-cancion.mp3\n" .
        "  --lang=es        the language of the book (es, en, pt-PT, pt-BR, ar)\n" .
        "  --reading=nombre the reading\n" .
        "  --delete         takes it out\n";
    exit(0);
}
if ($options['delete']) {
    namevoices::delete($options['lang'], $options['reading'], $options['name']);
    echo "Out: " . namevoices::key($options['name']) . "\n";
    exit(0);
}
$kept = namevoices::store($options['lang'], $options['reading'], $options['name'], rtrim($options['from'], '/'));
$found = namevoices::find($options['lang'], $options['reading'], $options['name']);
$whole = $found ? ', whole' : ', not whole: the book will not read it';
echo "Kept {$kept} files as " . namevoices::key($options['name']) . $whole . "\n";
