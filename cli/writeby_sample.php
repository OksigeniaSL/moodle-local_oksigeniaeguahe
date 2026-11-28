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
 * A made-up family for someone of the centre to see, as a family would, how it chooses the way it is written to: a real
 * mail to their address, with the buttons; then its page, the choice and its thanks. Kept out of the campaigns (made
 * with a date long past), never sent to Family. Then, if wanted, its child marked enrolled to see the question asked
 * again; and removed without a trace when done.
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
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;

[$options, $unrecognised] = cli_get_params(
    ['centre' => 0, 'email' => '', 'name' => '', 'phone' => '', 'child' => 'Leo', 'course' => '', 'lang' => '', 'event' => 0,
        'mail' => 'received', 'plain' => false, 'enrol' => 0, 'remove' => 0, 'whatsapp' => 0, 'dry' => false,
        'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || (!$options['centre'] && !$options['enrol'] && !$options['remove'] && !$options['whatsapp'])) {
    echo "A made-up family to see how a family chooses the way it is written to.

Make it and send it a real mail:
  --centre=ID       The centre.
  --email=ADDRESS   Where the mail goes (the person who will look at it).
  --name=NAME       The first name of the adult.
  --phone=+34...    A phone for the adult, to join WhatsApp or Telegram from its page (optional).
  --child=Leo       The name of the child.
  --course=TEXT     The course it asks for: the first of the offer whose name has this text (the first one if not given).
  --lang=es         The language of the family (that of the centre if not given).
  --mail=received   The mail: received (we got your message), or openinvite with --event=ID (the letter of an act).
  --plain           The letter as text only.

Then:
  --enrol=ID        Its child enrolled now, without passing to Family: to see the question asked again.
  --whatsapp=ID --event=ID [--dry]
                    The letter of the act, also by WhatsApp or Telegram, to this family only, by the same way as the
                    others (Marandu, its approval and its template). It needs the letter by mail first (--mail=openinvite).
                    With --dry, what it would be, and nothing goes.
  --remove=ID       Remove it without a trace, when done.

Example:
  php local/oksigeniaeguahe/cli/writeby_sample.php --centre=1 --email=someone@example.com --name=Ana
";
    exit(0);
}

$sample = fn(\stdClass $h) => str_ends_with((string) $DB->get_field(
    households::ADULTS,
    'lastname',
    ['householdid' => $h->id],
    IGNORE_MULTIPLE
), '(muestra)');

if ($options['remove']) {
    $household = $DB->get_record(households::T, ['id' => (int) $options['remove']]);
    if (!$household || !$sample($household)) {
        cli_error('No sample family ' . (int) $options['remove']);
    }
    // Erased like any family, and then without the line that counts it among the erased ones: it never was one.
    $last = (int) $DB->get_field_sql('SELECT MAX(id) FROM {' . households::ERASED . '}');
    households::erase($household);
    $DB->delete_records_select(households::ERASED, 'id > ? AND centreid = ?', [$last, $household->centreid]);
    cli_writeln('Removed the sample family ' . $household->id . '.');
    exit(0);
}

if ($options['whatsapp']) {
    $household = $DB->get_record(households::T, ['id' => (int) $options['whatsapp']]);
    if (!$household || !$sample($household)) {
        cli_error('No sample family ' . (int) $options['whatsapp']);
    }
    $centre = centres::get((int) $household->centreid);
    $event = $DB->get_record(events::T, ['id' => (int) $options['event'], 'centreid' => $household->centreid]);
    if (!$event) {
        cli_error('An open act of the centre is needed: --event=ID.');
    }
    if (!$DB->record_exists(invites::T, ['eventid' => $event->id, 'householdid' => $household->id])) {
        cli_error('It has not had the letter of that act by mail: make it with --mail=openinvite --event=' . $event->id . ' first.');
    }
    $too = invites::phone_too($event, $centre, [(int) $household->id]);
    [, $values] = invites::phone_params($event, $centre, (string) $household->lang ?: 'es');
    cli_writeln('Phones possible on this site: ' . (invites::by_phone($centre) ? 'yes' : 'no') . '; to numbers that did not join: ' .
        (invites::numbers_ready() ? 'yes' : 'no') . '.');
    cli_writeln('Its number: ' . ((invites::numbers((int) $centre->id)[(int) $household->id] ?? '') ?: '(none)') . '.');
    cli_writeln('It goes to it: ' . ($too['households'] ? 'yes' : 'no') . ' ' . json_encode(array_diff_key($too, ['households' => 1,
        'numbers' => 1])) . '.');
    cli_writeln('The values: {{1}} ' . $values[0] . ' · {{2}} ' . $values[1] . ' · {{3}} ' . $values[2]);
    cli_writeln('What it costs: ' . json_encode(invites::phone_quote($centre, $too), JSON_UNESCAPED_UNICODE));
    if ($options['dry'] || !$too['households']) {
        cli_writeln($options['dry'] ? 'Dry: nothing went.' : 'Nothing went.');
        exit(0);
    }
    $out = invites::queue_phone_too($event, $centre, (int) get_admin()->id, time(), [(int) $household->id]);
    cli_writeln('Sent: ' . json_encode($out, JSON_UNESCAPED_UNICODE));
    cli_writeln('The act: ' . (new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false));
    exit(0);
}

if ($options['enrol']) {
    $household = $DB->get_record(households::T, ['id' => (int) $options['enrol']]);
    if (!$household || !$sample($household)) {
        cli_error('No sample family ' . (int) $options['enrol']);
    }
    // Straight in the table: households::set_stage() would pass it to the common family record.
    $DB->set_field(households::CHILDREN, 'stage', 'enrolled', ['householdid' => $household->id]);
    $DB->set_field(households::CHILDREN, 'stagetime', time(), ['householdid' => $household->id]);
    cli_writeln('Its child is enrolled now: the file and its page ask again how to write to them.');
    exit(0);
}

$centre = centres::get((int) $options['centre']);
if (!$centre) {
    cli_error('No centre ' . (int) $options['centre']);
}
$email = households::email((string) $options['email']);
if (!$email || trim((string) $options['name']) === '') {
    cli_error('An --email and a --name are needed.');
}
$lang = (string) $options['lang'] ?: ((string) $centre->lang ?: 'es');
$id = households::create((int) $centre->id, (object) ['lang' => $lang, 'firstchannel' => 'email',
    'guest' => households::SAMPLE]);
// Marked as made up: out of the inbox, the acts, the numbers, the reports and the downloads. And a date long past.
$DB->update_record(households::T, (object) ['id' => $id, 'timecreated' => 946684800, 'lastcontact' => time()]);
$household = households::get($id, (int) $centre->id);
$adult = households::add_adult($household, (object) ['firstname' => trim((string) $options['name']),
    'lastname' => '(muestra)', 'email' => $email, 'phone' => trim((string) $options['phone']) ?: null]);
households::consent($household, $adult, 'email', true, 'team', $lang);
$offers = array_values(centres::offers((int) $centre->id));
$course = \core_text::strtolower(trim((string) $options['course']));
$offer = $course !== '' ? (array_values(array_filter($offers, fn($o) => str_contains(\core_text::strtolower(
    format_string($o->name)
), $course)))[0] ?? null) : null;
$offer = $offer ?? ($offers[0] ?? null);
households::add_child($household, trim((string) $options['child']) ?: 'Leo', $offer ? (int) $offer->id : 0);
$household = $DB->get_record(households::T, ['id' => $id]);

$token = households::new_link($household, 'sample');
$event = $options['event'] ? $DB->get_record(events::T, ['id' => (int) $options['event'], 'centreid' => $centre->id]) : null;
if ($options['mail'] === 'openinvite') {
    if (!$event) {
        cli_error('An open act of the centre is needed: --event=ID.');
    }
    // To compare how the letter arrives: as text only.
    $CFG->forced_plugin_settings['local_oksigeniaeguahe']['letterplain'] = (int) !empty($options['plain']);
    $sent = familymail::send(
        'openinvite',
        $household,
        $centre,
        invites::of_letter($event),
        $lang,
        $token,
        null,
        (string) ($event->invitewords ?? '')
    );
    if ($sent && !$DB->record_exists(invites::T, ['eventid' => $event->id, 'householdid' => $id])) {
        // Invited as the others were, so that it can have it by phone too; a sample never counts among them.
        $DB->insert_record(invites::T, (object) ['centreid' => $centre->id, 'eventid' => $event->id, 'householdid' => $id,
            'userid' => (int) get_admin()->id, 'channel' => 'email', 'bulkid' => 0, 'mailsent' => time(), 'timecreated' => time()]);
    }
} else {
    $sent = familymail::send('received', $household, $centre, null, $lang, $token);
}
cli_writeln('Sample family ' . $id . ($sent ? ', mail sent to ' . $email : ', the mail could NOT be sent') . '.');
cli_writeln('Its page: ' . (new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang]))->out(false));
cli_writeln('Its child enrolled: --enrol=' . $id . '. Remove it: --remove=' . $id . '.');
if ($options['mail'] === 'openinvite') {
    cli_writeln('By WhatsApp too: --whatsapp=' . $id . ' --event=' . $event->id . ' (first with --dry).');
}
