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
 * The mailbox the families of a centre answer to, put and tried from the console, as Settings does it («How the letters go
 * out», «The answers by mail»): read only if it opens. The password comes by the standard input, never in the command.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\replies;

[$options, $unrecognised] = cli_get_params(
    ['centre' => 0, 'host' => 'imap.gmail.com', 'port' => 993, 'user' => '', 'pass-stdin' => false, 'read' => false, 'off' => false,
        'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['centre']) {
    echo "The mailbox the families of a centre answer to: put, tried, and read now if wanted.

  --centre=ID       The centre.
  --host=HOST       The IMAP server (imap.gmail.com if not given).
  --port=993        Its port.
  --user=ADDRESS    The mailbox.
  --pass-stdin      The password (an app password), read from the standard input; empty keeps the one kept.
  --read            Read it now, once it opens.
  --off             Stop reading it.

Example:
  cat password.txt | php local/oksigeniaeguahe/cli/replies_mailbox.php --centre=1 --user=admissions@example.com --pass-stdin --read
";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
$pass = $options['pass-stdin'] ? trim((string) stream_get_contents(STDIN)) : '';
$said = replies::save($centre, ['host' => $options['off'] ? '' : (string) $options['host'], 'port' => (int) $options['port'],
    'user' => $options['off'] ? '' : (string) $options['user'], 'pass' => $pass]);
$on = !empty(replies::settings((int) $centre->id)['on']);
cli_writeln($said !== '' ? $said : ($on ? 'The mailbox opens: it is read every quarter of an hour.' : 'No mailbox: it is not read.'));
if ($on && $options['read']) {
    $out = replies::read($centre, time());
    cli_writeln('Read: ' . $out['read'] . '; mail of families taken in: ' . $out['kept'] . '; answered from the mailbox: ' .
        $out['answered'] . ($out['error'] !== '' ? '; failed: ' . $out['error'] : ''));
}
exit($said === '' ? 0 : 1);
