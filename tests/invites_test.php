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
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\settled;

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
        $this->assertStringStartsWith("Hola, Ana:\n\nComo se interesaron por el Colegio de ejemplo", $mail[2]);
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
}
