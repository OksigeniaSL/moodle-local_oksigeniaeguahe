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
 * The rows of a sheet (an export of the ads, a list the team keeps) as families that come in through an entry, as
 * things sent before: nothing is sent to any family. What the team wrote in the sheet becomes the history of each
 * family. Sending the same sheet again adds only what is new. Prints only counts, never a family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\sheets;

[$options, $unrecognised] = cli_get_params(
    ['entry' => 0, 'file' => '', 'tab' => '', 'event' => 0, 'dry-run' => false, 'help' => false],
    ['h' => 'help', 'n' => 'dry-run']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['entry'] || $options['file'] === '') {
    echo "The rows of a sheet as families that come in through an entry, without sending anything to them.

Options:
  --entry=ID      The entry of the centre they come through.
  --file=PATH     A CSV, XLSX or ODS file; the first row with names of columns is the header.
  --tab=NAME      In a book, the sheet to read (the first with families, if not said).
  --event=ID      Also sign them up for this act of the centre, without any reminder or message.
  -n, --dry-run   Read and count everything, and keep nothing.
  -h, --help      This help.

Example:
  php local/oksigeniaeguahe/cli/entry_rows.php --entry=1 --file=/tmp/hoja.csv --dry-run
";
    exit(0);
}

$entry = $DB->get_record(entries::T, ['id' => (int) $options['entry']]);
if (!$entry) {
    cli_error('No entry ' . (int) $options['entry']);
}
$event = null;
if ($options['event']) {
    $event = $DB->get_record(events::T, ['id' => (int) $options['event'], 'centreid' => $entry->centreid]);
    if (!$event) {
        cli_error('No act ' . (int) $options['event'] . ' in the centre of the entry');
    }
}
$path = (string) $options['file'];
if (!is_readable($path)) {
    cli_error('Cannot read ' . $path);
}
$sheets = sheets::read($path, basename($path));
if ($options['tab'] !== '') {
    $sheets = array_values(array_filter($sheets, fn($s) => trim((string) $s['name']) === trim((string) $options['tab'])));
    if (!$sheets) {
        cli_error('No sheet named ' . $options['tab']);
    }
}
[$rows, $headers] = entries::rows_of($sheets);
cli_writeln('Rows: ' . count($rows) . ' · columns: ' . count($headers));
$roles = entries::roles($entry, $headers);
$seen = [];
foreach ($roles as $header => $role) {
    $seen[] = $header . ' → ' . ($role ?? '-');
}
cli_writeln('What each column is: ' . implode(' | ', $seen));

$counts = ['new' => 0, 'already' => 0, 'nobody' => 0, 'signedup' => 0];
$noted = [];
$households = [];
$people = [];
$transaction = $DB->start_delegated_transaction();
foreach ($rows as $row) {
    [$household, $new] = entries::receive($entry, $row, '', null, true);
    $counts[$household ? ($new ? 'new' : 'already') : 'nobody']++;
    foreach (entries::$noted as $kind => $n) {
        $noted[$kind] = ($noted[$kind] ?? 0) + $n;
    }
    if ($household) {
        $households[(int) $household->id] = $household;
        $people[(int) $household->id] = max($people[(int) $household->id] ?? 1, (int) (entries::$lastread->people ?? 0));
    }
}
if ($event) {
    $now = time();
    foreach ($households as $household) {
        $was = $DB->get_record(events::SIGNUPS, ['eventid' => $event->id, 'householdid' => $household->id]);
        if ($was) {
            // Signed up before: how many will come, the larger of what each sheet says.
            if ($people[$household->id] > (int) $was->people) {
                $DB->set_field(events::SIGNUPS, 'people', $people[$household->id], ['id' => $was->id]);
            }
            continue;
        }
        // Signed up as the team: the reminder of the day before counts as looked at, so nothing is sent.
        $DB->insert_record(events::SIGNUPS, (object) ['centreid' => $entry->centreid, 'eventid' => $event->id,
            'householdid' => $household->id, 'slotid' => 0, 'people' => $people[$household->id], 'reminded' => $now,
            'attended' => 0,
            'thanked' => 0, 'timecreated' => $now]);
        households::note($household, 0, get_string('cli_signedup', 'local_oksigeniaeguahe', format_string($event->name)));
        $counts['signedup']++;
    }
}
$households = count($households);
ksort($noted);
cli_writeln('Families: ' . $households . ' · new ' . $counts['new'] . ' · already there ' . $counts['already'] .
    ' · rows without anyone to answer ' . $counts['nobody'] . ($event ? ' · signed up ' . $counts['signedup'] : ''));
cli_writeln('What the team wrote: ' . ($noted ? implode(', ', array_map(
    fn($k, $n) => "$k $n",
    array_keys($noted),
    $noted
)) : 'nothing'));
if ($options['dry-run']) {
    try {
        $transaction->rollback(new \moodle_exception('dryrun', 'error', '', null, 'Dry run'));
    } catch (\moodle_exception $e) {
        cli_writeln('Dry run: nothing was kept.');
    }
} else {
    $transaction->allow_commit();
    cli_writeln('Done.');
}
