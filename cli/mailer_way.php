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
 * How the letters of a centre go out, put and tried from the console, as Settings does it («How the letters go out»): a way
 * is used only once its letter of trial goes. The secrets come by the standard input, never in the command: for Mailjet, the
 * API key and the secret key, one per line (a line «NAME=value» gives its value); for SMTP, the password.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\mailer;

[$options, $unrecognised] = cli_get_params(
    ['centre' => 0, 'kind' => '', 'from' => '', 'fromname' => '', 'to' => '', 'host' => '', 'port' => 587, 'secure' => 'tls',
        'user' => '', 'secrets-stdin' => false, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['centre'] || !in_array($options['kind'], mailer::KINDS, true)) {
    echo "How the letters of a centre go out: put, tried with a letter to an address, and the address of the events of Mailjet.

  --centre=ID         The centre.
  --kind=KIND         site (the mail of the site), smtp or mailjet.
  --from=ADDRESS      The address they go from.
  --fromname=NAME     The name they go with (the name of the centre if not given).
  --to=ADDRESS        Where the letter of trial goes.
  --secrets-stdin     The secrets by the standard input: for mailjet, the API key and the secret key, one per line; for
                      smtp, the password. Empty keeps the ones kept.
  --host, --port, --secure (tls, ssl or none), --user   For smtp.

Example:
  printf '%s\\n%s\\n' \"\$KEY\" \"\$SECRET\" | php local/oksigeniaeguahe/cli/mailer_way.php --centre=1 --kind=mailjet \\
      --from=admissions@example.org --to=someone@example.org --secrets-stdin
";
    exit(0);
}
$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
mailer::choose($centre, (string) $options['kind']);
if ($options['kind'] === 'site') {
    cli_writeln('The letters go by the mail of the site.');
    exit(0);
}
$secrets = [];
if ($options['secrets-stdin']) {
    foreach (preg_split('/\r?\n/', (string) stream_get_contents(STDIN)) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $secrets[] = trim(strpos($line, '=') !== false ? substr($line, strpos($line, '=') + 1) : $line, " \"'");
        }
    }
}
$in = ['from' => (string) $options['from'], 'fromname' => (string) $options['fromname']];
if ($options['kind'] === 'mailjet') {
    $in += ['key' => (string) ($secrets[0] ?? ''), 'secret' => (string) ($secrets[1] ?? '')];
} else {
    $in += ['host' => (string) $options['host'], 'port' => (int) $options['port'], 'secure' => (string) $options['secure'],
        'user' => (string) $options['user'], 'pass' => (string) ($secrets[0] ?? '')];
}
// What is not given keeps what was kept.
$in = array_filter($in, fn($value) => $value !== '' && $value !== 0);
// The letter of trial goes to the address given, as Settings sends it to whoever saves.
$to = (object) ['email' => (string) $options['to']];
$said = mailer::save($centre, $in, $to);
cli_writeln($said !== '' ? $said : 'The letter of trial went: the letters go by ' . $options['kind'] . ' from ' . $in['from'] . '.');
if ($options['kind'] === 'mailjet') {
    $hook = mailer::hook_url($centre);
    cli_writeln('Events of Mailjet (bounce, blocked, spam) to: ' . ($hook !== '' ? $hook : '(none yet)'));
}
exit($said === '' ? 0 : 1);
