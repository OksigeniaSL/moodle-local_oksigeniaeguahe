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
 * «A new family» from the command line: who of the team of a centre hears of each family that comes on its own, and,
 * once, the families that came since a time, for those who did not hear of them yet. Without --run, it only says.
 *
 *   php cli/notify_team.php --centre=1 --users=12,34 [--since="2026-11-23 16:30"] [--run]
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\teamnotice;

[$options, $unrecognised] = cli_get_params(
    ['centre' => 0, 'users' => '', 'since' => '', 'run' => false, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
$centre = centres::get((int) $options['centre']);
if ($options['help'] || !$centre) {
    cli_writeln('php cli/notify_team.php --centre=N [--users=ID,ID] [--since="YYYY-MM-DD HH:MM"] [--run]');
    exit($centre ? 0 : 1);
}
$members = centres::members((int) $centre->id);
if ($options['users'] !== '') {
    $want = array_values(array_filter(array_map('intval', explode(',', (string) $options['users']))));
    $missing = array_diff($want, array_keys($members));
    if ($missing) {
        cli_error('Not in the team of the centre: ' . implode(', ', $missing));
    }
    if ($options['run']) {
        set_config('notifynew_' . $centre->id, implode(',', $want), 'local_oksigeniaeguahe');
    }
    cli_writeln(($options['run'] ? 'Chosen: ' : 'Would choose: ') . implode(', ', array_map(
        fn($id) => $id . ' ' . fullname($members[$id]),
        $want
    )));
}
cli_writeln('Hear of each new family now: ' . implode(', ', array_map(
    fn($u) => $u->id . ' ' . fullname($u),
    teamnotice::recipients($centre)
)));
if ($options['since'] !== '') {
    $since = strtotime((string) $options['since']);
    if (!$since) {
        cli_error('A time it understands, such as "2026-11-23 16:30".');
    }
    $to = (int) get_config('local_oksigeniaeguahe', 'notifiedto_' . $centre->id) ?: time();
    $came = teamnotice::arrived((int) $centre->id, $since, $to);
    cli_writeln(count($came) . ' families came on their own since ' . userdate($since) . ' and before the task took over.');
    if ($came && $options['run']) {
        cli_writeln(teamnotice::send($centre, $came) . ' notices sent.');
    }
}
