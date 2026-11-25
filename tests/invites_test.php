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

namespace local_oksigeniaeguahe;

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\joinpage;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\settled;
use local_oksigeniaeguahe\local\writeby;
use local_oksigeniaeguahe\task\send_confirmation;

/**
 * What is left after «Enrolled»: the open acts of the centre for the families that are not of it yet, sent once by a
 * person; the thanks to the family that told them about the centre; and those who offered to tell another family how
 * it went. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(invites::class)]
final class invites_test extends \advanced_testcase {
    /**
     * A centre with an offer, and a family with a child at a stage, with or without permission to write by email.
     *
     * @param int $centreid
     * @param int $offerid
     * @param string $name
     * @param string $stage
     * @param bool $email Whether they let the centre write by email.
     * @return \stdClass
     */
    protected function family(int $centreid, int $offerid, string $name, string $stage, bool $email): \stdClass {
        global $DB;
        $household = households::get(
            households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']),
            $centreid
        );
        $adult = households::add_adult($household, (object) ['firstname' => $name, 'lastname' => 'Ejemplo',
            'email' => \core_text::strtolower($name) . '@example.com']);
        households::consent($household, $adult, 'email', $email, 'form', 'es');
        $child = households::add_child($household, 'Hijo de ' . $name, $offerid);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), $stage);
        return households::get((int) $household->id, $centreid);
    }

    /**
     * The open act: who can be invited, the invitation sent once with its mail and a line in the history.
     */
    public function test_open_act(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $ana = $this->family($id, $offer, 'Ana', 'visit', true);
        $this->family($id, $offer, 'Berta', 'enrolled', true);
        $this->family($id, $offer, 'Carlos', 'visit', false);
        $this->family($id, $offer, 'Diego', 'closed', true);
        $eva = $this->family($id, $offer, 'Eva', 'inquiry', true);
        $eventid = events::save($id, (object) ['kind' => 'other', 'name' => 'Festival de Infantil',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]);
        $event = events::get($eventid, $id);
        events::sign_up($event, (int) $eva->id, 2);
        $this->assertTrue(invites::fits($event, time()));
        $this->assertSame([(int) $ana->id], array_keys(invites::eligible($event)));

        $mail = familymail::build('openinvite', $ana, $centre, (object) ['eventid' => $eventid, 'eventname' => $event->name,
            'timestart' => $event->timestart, 'timeend' => $event->timeend, 'place' => '', 'hosts' => '', 'people' => 0,
            'token' => $event->token], 'es', 'clave');
        // A plain letter: one link, no pictures, and how to say no in words.
        $this->assertStringStartsWith('Festival de Infantil, el ', $mail[0]);
        $this->assertStringContainsString('j.php?t=' . $event->token, $mail[2]);
        $this->assertMatchesRegularExpression('/^Hola, Ana:\n\nEl .+ tenemos «Festival de Infantil» en el colegio\./u', $mail[2]);
        $this->assertStringContainsString('Les escribimos porque nos dejaron su correo al preguntar por el colegio.', $mail[2]);
        $this->assertSame(1, substr_count($mail[1], '<a '));
        $this->assertStringNotContainsString('<img', $mail[1]);
        $this->assertStringNotContainsString('<table', $mail[1]);
        $this->assertSame([], $mail[3]);

        $sink = $this->redirectEmails();
        $ids = array_keys(invites::eligible($event));
        $this->assertSame(1, invites::send($event, $centre, (int) get_admin()->id, time(), $ids, 'Habrá chocolate.'));
        $this->assertCount(1, $sink->get_messages());
        $sent = quoted_printable_decode($sink->get_messages()[0]->body);
        $this->assertStringContainsString('Habrá chocolate.', $sent);
        $this->assertStringNotContainsString('List-Unsubscribe', $sink->get_messages()[0]->header);
        $sink->clear();
        // To see how it arrives: to whoever sends it, and it invites nobody.
        $this->assertTrue(invites::try_on($event, $centre, get_admin()));
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame(1, invites::count($eventid));
        $this->assertSame(0, invites::send($event, $centre, (int) get_admin()->id, time(), $ids), 'Once per act.');
        // Through the page: the mails go in the background, none by phone without Marandu.
        $this->assertSame(
            ['email' => 0, 'phone' => 0, 'status' => '', 'said' => ''],
            invites::queue($event, $centre, (int) get_admin()->id, time())
        );
        $this->assertSame(1, invites::count($eventid));
        $this->assertContains(
            get_string('invite_note', 'local_oksigeniaeguahe', 'Festival de Infantil'),
            $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ?', [$ana->id])
        );

        // An open day too; a visit, or an act already past, is none to invite to.
        $event->kind = 'openday';
        $this->assertTrue(invites::fits($event, time()));
        $this->assertFalse(invites::fits($event, (int) $event->timestart + 1));
        $event->kind = 'visit';
        $this->assertFalse(invites::fits($event, time()));
    }

    /**
     * The letter waits in a queue: a family that signed up, was enrolled, closed or became of the centre meanwhile gets none;
     * one of the centre that a person chose does; and two files of the same people, one letter.
     */
    public function test_as_they_are_when_it_goes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $f = [];
        foreach (['Ana', 'Berta', 'Carla', 'Dora', 'Elsa', 'Flor', 'Gema'] as $name) {
            $f[$name] = $this->family($id, $offer, $name, 'visit', true);
        }
        $event = events::get(events::save($id, (object) ['kind' => 'other', 'name' => 'Charla', 'timestart' => time() + 5 * DAYSECS,
            'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), $id);
        $DB->set_field(households::T, 'incentre', 1, ['id' => $f['Gema']->id]);
        $sink = $this->redirectEmails();
        $ids = array_map(fn($h) => (int) $h->id, array_values($f));
        $said = invites::queue($event, $centre, 2, time(), '', $ids);
        $this->assertSame(7, $said['email']);
        $task = array_values(\core\task\manager::get_adhoc_tasks('\local_oksigeniaeguahe\task\send_invites'))[0];
        $this->assertSame([(int) $f['Gema']->id], array_map('intval', $task->get_custom_data()->centre));
        // While it waits.
        events::sign_up($event, (int) $f['Berta']->id, 1);
        $child = fn(string $name) => $DB->get_record(households::CHILDREN, ['householdid' => $f[$name]->id]);
        households::set_stage($child('Carla'), 'enrolled');
        households::set_stage($child('Dora'), 'closed', 'moved');
        $DB->set_field(households::T, 'incentre', 1, ['id' => $f['Elsa']->id]);
        $DB->set_field(households::T, 'noinvites', 1, ['id' => $f['Flor']->id]);
        $this->runAdhocTasks('\local_oksigeniaeguahe\task\send_invites');
        $to = array_map(fn($m) => $m->to, $sink->get_messages());
        sort($to);
        $this->assertSame(['ana@example.com', 'gema@example.com'], $to);
        $this->assertSame('letter', $DB->get_field(households::LINKS, 'reason', ['householdid' => $f['Ana']->id]));
        // The same people in two files: the older one, once; and a no of either is of both.
        $sink->clear();
        $hugo = $this->family($id, $offer, 'Hugo', 'visit', true);
        $again = $this->family($id, $offer, 'Hugo', 'visit', true);
        $this->assertSame([(int) $hugo->id], array_map('intval', array_keys(invites::eligible($event))));
        $DB->set_field(households::T, 'noinvites', 1, ['id' => $again->id]);
        $this->assertSame([], invites::eligible($event));
        $sink->close();
    }

    /**
     * A family that does not want invitations to events, by the box of its page or noted by the team: out of the ones to
     * invite, also when its letter was already waiting to go; the ways it is written to stay; a line in its history; and
     * back when it wants them again.
     */
    public function test_not_invited_to_events(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $ana = $this->family($id, $offer, 'Ana', 'visit', true);
        $eva = $this->family($id, $offer, 'Eva', 'inquiry', true);
        $event = events::get(events::save($id, (object) ['kind' => 'other', 'name' => 'Festival de Infantil',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + 2 * HOURSECS, 'capacity' => 0,
            'open' => 1]), $id);
        $this->assertEqualsCanonicalizing([(int) $ana->id, (int) $eva->id], array_keys(invites::eligible($event)));
        $before = $DB->count_records(households::CONTACTS, ['householdid' => $ana->id]);
        $ways = writeby::now($ana, $centre);
        $this->assertTrue(invites::wanted($ana, false, 'family'));
        $this->assertFalse(invites::wanted($ana, false, 'family'), 'Said once.');
        $this->assertSame($before + 1, $DB->count_records(households::CONTACTS, ['householdid' => $ana->id]));
        $ana = households::get((int) $ana->id, $id);
        $this->assertNotEmpty(households::export($ana)['household']['no_invitations_to_events_since']);
        $this->assertSame($ways, writeby::now($ana, $centre), 'How they are written to for the rest stays.');
        $this->assertSame([(int) $eva->id], array_keys(invites::eligible($event)));
        // Its letter was already in the queue: it does not go.
        $sink = $this->redirectEmails();
        $this->assertSame(1, invites::send($event, $centre, 2, time(), [(int) $ana->id, (int) $eva->id]));
        $this->assertSame('eva@example.com', $sink->get_messages()[0]->to);
        $sink->close();
        // From the page of the letter, the box is apart from the ways: taken out, and marked with no way left.
        $_POST = ['evt' => '1'];
        joinpage::said($event, $centre, $ana);
        $this->assertSame(0, (int) $DB->get_field(households::T, 'noinvites', ['id' => $ana->id]));
        $_POST = ['evt' => '1', 'noinvites' => '1', 'ways' => '1'];
        $this->assertTrue(joinpage::last_way($centre, $ana), 'No way marked: the last one stays, and it is said.');
        joinpage::said($event, $centre, $ana);
        $_POST = [];
        $ana = households::get((int) $ana->id, $id);
        $this->assertGreaterThan(0, (int) $ana->noinvites);
        $this->assertSame($ways, writeby::now($ana, $centre));
        // It marks only WhatsApp and has not joined it yet: until then by email, the invitations too, once it wants them.
        writeby::set($ana, ['whatsapp'], 'family');
        $this->assertSame([], array_keys(invites::eligible($event)));
        invites::wanted($ana, true, 'team', 2);
        $this->assertFalse(familymail::may_email($ana));
        $this->assertSame([(int) $ana->id], array_keys(invites::eligible($event)));
        $sink = $this->redirectEmails();
        $this->assertSame(1, invites::send($event, $centre, 2, time(), [(int) $ana->id]));
        $this->assertSame('ana@example.com', $sink->get_messages()[0]->to);
        $sink->close();
    }

    /**
     * The confirmation of a family that says it comes with one touch goes after its page has answered; once, and not if it
     * has said by then that it does not come.
     */
    public function test_confirmation_afterwards(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $ana = $this->family($id, $offer, 'Ana', 'visit', true);
        $eva = $this->family($id, $offer, 'Eva', 'inquiry', true);
        $event = events::get(events::save($id, (object) ['kind' => 'other', 'name' => 'Charla', 'timestart' => time() + 5 * DAYSECS,
            'timeend' => time() + 5 * DAYSECS + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]), $id);
        $sink = $this->redirectEmails();
        foreach ([$ana, $eva] as $family) {
            $this->assertTrue(invites::reply($event, $family, 'yes', 1));
            send_confirmation::queue($family, $event, 'es');
            send_confirmation::queue($family, $event, 'es');
        }
        $this->assertCount(0, $sink->get_messages());
        invites::reply($event, $eva, 'no', 0);
        ob_start();
        $this->runAdhocTasks(send_confirmation::class);
        ob_end_clean();
        $this->assertSame(['ana@example.com'], array_column($sink->get_messages(), 'to'));
        $sink->close();
    }

    /**
     * Whom it goes to: by the course they ask for, by whether they signed up to another act, and only those left ticked.
     */
    public function test_groups(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $three = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $first = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => '1.º de Primaria', 'places' => 25,
            'active' => 1]);
        $ana = (int) $this->family($id, $three, 'Ana', 'visit', true)->id;
        $berta = (int) $this->family($id, $first, 'Berta', 'inquiry', true)->id;
        $carlos = (int) $this->family($id, 0, 'Carlos', 'inquiry', true)->id;
        $past = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => time() - 3 * DAYSECS,
            'timeend' => time() - 3 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]);
        events::sign_up(events::get($past, $id), $berta, 2);
        $event = events::get(events::save($id, (object) ['kind' => 'other', 'name' => 'Charla',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), $id);
        $groups = invites::groups($event, invites::eligible($event));
        $of = [];
        foreach ($groups as $key => $group) {
            sort($group['ids']);
            $of[$group['kind'] . ' ' . preg_replace('/,.*/', '', $group['name'])] = $group['ids'];
        }
        $this->assertSame([$ana], $of['course Infantil 3 años']);
        $this->assertSame([$berta], $of['course 1.º de Primaria']);
        $this->assertSame([$carlos], $of['course ' . get_string('panel_invite_nocourse', 'local_oksigeniaeguahe')]);
        $this->assertSame([$berta], $groups['acto-' . $past]['ids']);
        $this->assertEqualsCanonicalizing([$ana, $carlos], $groups['sinacto-' . $past]['ids']);

        // Only those of the group, and of them only those left ticked; a group that is not there invites nobody.
        $user = (int) get_admin()->id;
        $this->assertSame(0, invites::queue($event, $centre, $user, time(), 'curso-nada')['email']);
        $this->assertSame(0, invites::queue($event, $centre, $user, time(), '', [])['email']);
        $this->assertSame(1, invites::queue($event, $centre, $user, time(), 'sinacto-' . $past, [$ana, $berta])['email']);
        $this->assertSame([$ana => true], invites::queued((int) $event->id));
        $this->assertEqualsCanonicalizing([$berta, $carlos], array_keys(invites::eligible($event)));
        // Whoever is unticked stays so in any group, and only who is shown changes.
        invites::tick((int) $event->id, [$ana, $berta, $carlos], [$ana]);
        $this->assertEqualsCanonicalizing([$berta, $carlos], invites::left_out((int) $event->id));
        invites::tick((int) $event->id, [$carlos], [$carlos]);
        $this->assertSame([$berta], invites::left_out((int) $event->id));
        // Those already of the centre that came through Eguahe: only when they are chosen, and never those of its roll.
        global $DB;
        $dora = (int) $this->family($id, $three, 'Dora', 'visit', true)->id;
        $elsa = (int) $this->family($id, $three, 'Elsa', 'enrolled', true)->id;
        $fara = (int) $this->family($id, $three, 'Fara', 'visit', true)->id;
        $DB->set_field(households::T, 'incentre', 1, ['id' => $dora]);
        $DB->update_record(households::T, (object) ['id' => $fara, 'incentre' => 1, 'roster' => time()]);
        $this->assertEqualsCanonicalizing([$berta, $carlos], array_keys(invites::eligible($event)));
        $this->assertEqualsCanonicalizing([$dora, $elsa], array_keys(invites::of_centre($event)));
        $groups = invites::groups($event, invites::eligible($event), invites::of_centre($event));
        $this->assertEqualsCanonicalizing([$dora, $elsa], $groups[invites::OFCENTRE]['ids']);
        $mail = familymail::build('openinvite', households::get($dora, $id), $centre, invites::of_letter($event), 'es', 'clave');
        $this->assertStringContainsString('tenemos «Charla» en el colegio.', $mail[2]);
        $this->assertStringNotContainsString('porque nos dejaron su correo', $mail[2]);
        $this->assertSame(1, invites::queue($event, $centre, $user, time(), '', [$dora, $fara])['email']);
        $this->assertSame(2, invites::stop((int) $event->id));
        invites::queue($event, $centre, $user, time(), '', [$ana]);
        // Stopped, it can be invited again.
        $this->assertSame(1, invites::stop((int) $event->id));
        $this->assertCount(3, invites::eligible($event));
    }

    /**
     * Many letters go out a few at a time, by day.
     */
    public function test_little_by_little(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        for ($i = 0; $i < invites::BATCH + 2; $i++) {
            $this->family($id, 0, 'Familia' . chr(97 + $i), 'inquiry', true);
        }
        $event = events::get(events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), $id);
        $sink = $this->redirectEmails();
        invites::queue($event, $centre, (int) get_admin()->id, time());
        $this->assertSame(invites::BATCH + 2, invites::on_the_way((int) $event->id)[0]);
        $run = function () use ($event): void {
            global $DB;
            foreach ($DB->get_records('task_adhoc', ['classname' => '\\local_oksigeniaeguahe\\task\\send_invites']) as $row) {
                $task = \core\task\manager::adhoc_task_from_record($row);
                $DB->delete_records('task_adhoc', ['id' => $row->id]);
                $task->execute();
            }
        };
        $run();
        $this->assertSame(invites::BATCH, $sink->count());
        [$left, $next] = invites::on_the_way((int) $event->id);
        $this->assertSame(2, $left);
        $this->assertGreaterThanOrEqual(time() + invites::PAUSE - 5, $next);
        $run();
        $this->assertSame(invites::BATCH + 2, $sink->count());
        $this->assertSame(0, invites::on_the_way((int) $event->id)[0]);

        // By day only: at night they wait for the morning.
        $zone = \core_date::get_server_timezone_object();
        $time = fn(string $s) => (new \DateTimeImmutable($s, $zone))->getTimestamp();
        $this->assertSame($time('2026-11-24 12:00'), invites::daytime($time('2026-11-24 12:00')));
        $this->assertSame($time('2026-11-24 09:00'), invites::daytime($time('2026-11-24 07:30')));
        $this->assertSame($time('2026-11-25 09:00'), invites::daytime($time('2026-11-24 20:00')));
        $this->assertSame($time('2026-11-25 09:25'), invites::last_at(45, $time('2026-11-24 19:40')));
    }

    /**
     * The thanks to the family that told them, once a child of theirs is enrolled; and those who offered to receive,
     * alone in the inbox.
     */
    public function test_thanks_and_volunteers(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform',
            'heardfrom' => 'centrefamilies', 'heardwho' => 'Los padres de Leo']), $id);
        $this->assertSame('Los padres de Leo', $household->heardwho);
        $other = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform',
            'heardfrom' => 'social', 'heardwho' => 'Nadie']), $id);
        $this->assertNull($other->heardwho, 'Only when another family of the centre told them.');
        households::add_adult($household, (object) ['firstname' => 'Inés', 'lastname' => 'Pardo', 'email' => 'ines@example.com']);
        $child = households::add_child($household, 'Mateo', $offer);

        $context = panel_household::context($household, $centre, true);
        $this->assertFalse($context['thanks'], 'Not before a child is enrolled.');
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'enrolled');
        $context = panel_household::context(households::get((int) $household->id, $id), $centre, true);
        $this->assertStringContainsString('Los padres de Leo', $context['thanks']['sentence']);
        $DB->set_field(households::T, 'thanked', time(), ['id' => $household->id]);
        $this->assertFalse(panel_household::context(households::get((int) $household->id, $id), $centre, true)['thanks']);

        settled::volunteer(households::get((int) $household->id, $id), $centre, time());
        $context = panel_inbox::context($centre, false, (int) get_admin()->id, true, ['volunteers' => 1]);
        $ids = array_merge(...array_map(fn($d) => array_column($d['rows'], 'id'), $context['days']));
        $this->assertSame([(string) $household->id], $ids);
    }

    /**
     * An act that changes or is called off: those that have its letter are said, and told only when a person says so; not those
     * that said no, nor signed up, nor asked for no more invitations.
     */
    public function test_letters_of_before(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->setAdminUser();
        $centre = centres::get(centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']));
        $centreid = (int) $centre->id;
        $event = events::get(events::save($centreid, (object) ['kind' => 'other', 'name' => 'Charla', 'timestart' => time() + 5 * DAYSECS,
            'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), $centreid);
        $families = [];
        foreach (['Ana', 'Eva', 'Luis', 'Pau', 'Rosa'] as $name) {
            $id = households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $household = households::get($id, $centreid);
            $adult = households::add_adult($household, (object) ['firstname' => $name, 'email' => \core_text::strtolower($name) . '@example.org']);
            households::consent($household, $adult, 'email', true, 'form', 'es');
            $DB->insert_record(invites::T, (object) ['centreid' => $centreid, 'eventid' => $event->id, 'householdid' => $id, 'userid' => 2,
                'channel' => 'email', 'bulkid' => 0, 'timecreated' => time()]);
            $families[$name] = $household;
        }
        $DB->set_field(invites::T, 'reply', 'no', ['householdid' => $families['Eva']->id]);
        events::sign_up($event, (int) $families['Luis']->id, 1);
        $DB->set_field(households::T, 'noinvites', 5, ['id' => $families['Pau']->id]);
        $this->assertSame([(int) $families['Ana']->id, (int) $families['Rosa']->id], array_map('intval', array_keys(invites::with_letter($event))));
        // Said before saving a change of day, also when nobody is signed up.
        $old = \local_oksigeniaeguahe\local\panel_eventform::values($event);
        $new = ['day' => userdate(time() + 6 * DAYSECS, '%Y-%m-%d', 99, false)] + $old;
        [$changes, $reached] = \local_oksigeniaeguahe\local\panel_eventform::changes($centre, $old, $new);
        $this->assertNotEmpty($changes);
        $this->assertSame([(int) $families['Luis']->id], $reached);
        $notice = \local_oksigeniaeguahe\local\panel_eventform::notice($centre, $changes, $reached, $new);
        $this->assertStringContainsString('2', $notice['letters']);
        $sink = $this->redirectEmails();
        \local_oksigeniaeguahe\task\tell_invited::queue($event, 'change', '', 2, [(int) $families['Ana']->id, (int) $families['Pau']->id]);
        $this->runAdhocTasks(\local_oksigeniaeguahe\task\tell_invited::class);
        $to = array_map(fn($m) => $m->to, $sink->get_messages());
        $this->assertSame(['ana@example.org'], $to);
        $this->assertStringContainsString('Charla', quoted_printable_decode($sink->get_messages()[0]->body));
        $sink->close();
    }

    /**
     * What is said before a change counts only those it will reach.
     */
    public function test_change_counted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $centre = centres::get(centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']));
        $centreid = (int) $centre->id;
        $event = events::get(events::save($centreid, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => time() + 5 * DAYSECS,
            'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), $centreid);
        $ids = [];
        foreach (['ana', 'eva'] as $name) {
            $id = households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            households::add_adult(households::get($id, $centreid), (object) (['firstname' => $name] + ($name === 'ana' ?
                ['email' => 'ana@example.org'] : ['phone' => '+34600111222'])));
            events::sign_up($event, $id, 1);
            $ids[] = $id;
        }
        $values = \local_oksigeniaeguahe\local\panel_eventform::values($event);
        $notice = \local_oksigeniaeguahe\local\panel_eventform::notice($centre, [], $ids, $values);
        $this->assertStringContainsString(get_string('panel_notice_by_email', 'local_oksigeniaeguahe', 1), $notice['who']);
        $this->assertStringContainsString(get_string('panel_notice_neither_one', 'local_oksigeniaeguahe', 1), $notice['who']);
    }

    /**
     * A send by phone asks, as each one goes, whether it still does: not of an act called off, not to whoever signed up or
     * does not want invitations, and with the day as it is now.
     */
    public function test_still(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $adult = households::add_adult($household, (object) ['firstname' => 'Ana', 'phone' => '+34600111501']);
        households::consent($household, $adult, 'whatsapp', true, 'family', 'es');
        $DB->set_field(households::T, 'writeby', 'whatsapp', ['id' => $household->id]);
        $start = time() + 9 * DAYSECS;
        $event = events::get(events::save($id, (object) ['kind' => 'other', 'name' => 'Festival', 'timestart' => $start,
            'timeend' => $start + HOURSECS, 'capacity' => 0, 'open' => 1]), $id);
        $wording = new \ReflectionMethod(invites::class, 'wording');
        $row = $DB->insert_record(invites::T, (object) ['centreid' => $id, 'eventid' => $event->id, 'householdid' => $household->id,
            'userid' => 0, 'channel' => 'phone', 'bulkid' => 77, 'said' => $wording->invoke(null, $event), 'timecreated' => time()]);
        $still = fn() => invites::still((int) $household->id, 77);
        if (\local_oksigeniaeguahe\local\writeby::phone_ok(households::get((int) $household->id, $id), 'openinvite')) {
            $this->assertTrue($still());
            $DB->update_record(events::T, (object) ['id' => $event->id, 'timestart' => $start + DAYSECS, 'timeend' => $start + DAYSECS + HOURSECS]);
            $this->assertIsArray($still());
            $this->assertStringContainsString('Festival', implode(' ', $still()['params']));
        }
        $this->assertFalse(invites::still((int) $household->id, 78));
        events::sign_up($event, (int) $household->id, 1);
        $this->assertFalse($still(), 'It has said it comes.');
        $DB->delete_records(events::SIGNUPS, ['eventid' => $event->id]);
        $DB->set_field(events::T, 'cancelled', time(), ['id' => $event->id]);
        $this->assertFalse($still(), 'Called off.');
        $DB->set_field(events::T, 'cancelled', 0, ['id' => $event->id]);
        $DB->set_field(households::T, 'noinvites', 1, ['id' => $household->id]);
        $this->assertFalse($still());
        $this->assertTrue($DB->record_exists(invites::T, ['id' => $row]));
    }
}
