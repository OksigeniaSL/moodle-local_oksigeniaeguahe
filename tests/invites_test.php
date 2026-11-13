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
        $this->assertSame('Colegio de ejemplo les invita: Festival de Infantil', $mail[0]);
        $this->assertStringContainsString('j.php?t=' . $event->token, $mail[1]);
        $this->assertStringContainsString('Inscribirse', $mail[2]);

        $sink = $this->redirectEmails();
        $this->assertSame(1, invites::send($event, $centre, (int) get_admin()->id, time()));
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame(0, invites::send($event, $centre, (int) get_admin()->id, time()), 'Once per act.');
        $this->assertSame(1, invites::count($eventid));
        $this->assertContains(
            get_string('invite_note', 'local_oksigeniaeguahe', 'Festival de Infantil'),
            $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ?', [$ana->id])
        );

        // A visit, or an act already past, is no open act to invite to.
        $event->kind = 'openday';
        $this->assertFalse(invites::fits($event, time()));
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
