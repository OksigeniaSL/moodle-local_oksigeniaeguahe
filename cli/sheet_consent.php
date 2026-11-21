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
 * The families that came in by the sheets of the team (the ads, the sheet of the office) may be written to by email, as
 * the centre decided: each adult with an email gets that permission, kept as coming from the sheet. Never a family that
 * asked not to be written to, nor an adult who already said something about email. Without --run, it only counts.
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
use local_oksigeniaeguahe\local\teamnotes;
use local_oksigeniaeguahe\local\writeby;

[$options, $unrecognised] = cli_get_params(['centre' => 0, 'run' => false, 'help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['centre']) {
    echo "Lets the centre write by email to the families that came in by the sheets of the team.

Options:
  --centre=ID   The centre.
  --run         Do it; without it, only how many there are.
  -h, --help    This help.
";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
$ids = $DB->get_fieldset_sql('SELECT DISTINCT householdid FROM {' . teamnotes::T . '} WHERE centreid = ?', [$centre->id]);
$families = 0;
$adults = 0;
foreach ($ids as $id) {
    $household = $DB->get_record(households::T, ['id' => $id]);
    if (!$household || writeby::silent($household)) {
        continue;
    }
    $said = households::permissions((int) $household->id);
    $some = 0;
    foreach (households::full($household)->adults as $adult) {
        if (trim((string) $adult->email) === '' || array_key_exists('email', $said[$adult->id] ?? [])) {
            continue;
        }
        if ($options['run']) {
            households::consent($household, (int) $adult->id, 'email', true, 'sheet', (string) ($household->lang ?: $centre->lang));
        }
        $some++;
    }
    if ($some) {
        $families++;
        $adults += $some;
    }
}
cli_writeln(($options['run'] ? 'Done: ' : 'Would be: ') . $families . ' families, ' . $adults . ' adults with their email.');
