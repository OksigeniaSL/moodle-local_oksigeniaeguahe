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
use local_oksigeniaeguahe\local\writeby;

/**
 * How a family wants to be written to: one choice, kept in every mail and invitation, the question at sight in each
 * mail, «do not write to us any more» and what lifts it, and the question asked again once a child is enrolled. With
 * example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(writeby::class)]
final class writeby_test extends \advanced_testcase {
    /** @var int */
    protected $centreid;

    /** @var int */
    protected $offerid;

    /**
     * A centre with an offer, and a family with its email allowed and a child on its way.
     *
     * @return \stdClass
     */
    protected function family(): \stdClass {
        global $DB;
        $this->centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->offerid = centres::save_offer($this->centreid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1]);
        $household = households::get(
            households::create($this->centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']),
            $this->centreid
        );
        $adult = households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com', 'phone' => '+34600111222']);
        households::consent($household, $adult, 'email', true, 'form', 'es');
        $child = households::add_child($household, 'Leo', $this->offerid);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'visit');
        return households::get((int) $household->id, $this->centreid);
    }

    /**
     * The notes of the team about a family.
     *
     * @param \stdClass $household
     * @return string[]
     */
    protected function notes(\stdClass $household): array {
        global $DB;
        return array_values($DB->get_fieldset_select(
            households::CONTACTS,
            'body',
            'householdid = ? AND direction = ?',
            [$household->id, 'note']
        ));
    }

    /**
     * «Do not write to us any more»: nothing goes, not a mail, not an invitation; the answer to what the family writes
     * after it does, and writing again lifts it by that way.
     */
    public function test_none_and_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $household = $this->family();
        $centre = centres::get($this->centreid);
        $this->assertNull(writeby::chosen($household));
        $this->assertSame(['email'], writeby::now($household, $centre));

        $this->assertTrue(writeby::set($household, 'none', 'family', 0, time() - 2 * DAYSECS));
        $this->assertFalse(writeby::set($household, 'none', 'family'));
        $this->assertTrue(writeby::silent($household));
        $this->assertGreaterThan(0, (int) $household->writebynone);
        $this->assertFalse(familymail::may_email($household));
        $this->assertContains(get_string('writeby_noted_family_none', 'local_oksigeniaeguahe'), $this->notes($household));

        // Nothing goes: the reminder, the thanks, the answer of the team.
        $sink = $this->redirectEmails();
        $this->assertFalse(familymail::send('reminder', $household, $centre, null, 'es', 'clave'));
        $this->assertFalse(familymail::send('reply', $household, $centre, null, 'es', 'clave', null, 'Hola'));
        $this->assertCount(0, $sink->get_messages());

        // An open act: not invited.
        $eventid = events::save($this->centreid, (object) ['kind' => 'other', 'name' => 'Festival',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]);
        $this->assertSame([], invites::eligible(events::get($eventid, $this->centreid)));

        // A person may write anyway, once told.
        writeby::anyway((int) $household->id);
        $this->assertTrue(writeby::may_send($household, 'reply'));
        $this->assertFalse(writeby::may_send($household, 'reminder'));

        // The family writes again by email, days later: it accepts to be written to by that way.
        households::received($household, 'email', 'Una pregunta', true);
        $household = households::get((int) $household->id, $this->centreid);
        $this->assertSame(['email'], writeby::chosen($household));
        $this->assertContains(get_string('writeby_noted_back', 'local_oksigeniaeguahe', 'por correo'), $this->notes($household));
        $this->assertTrue(familymail::send('received', $household, $centre, null, 'es', 'clave'));
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * The «thank you» answered the same day to the confirmation does not undo it: it is answered, and the word stays.
     */
    public function test_same_day(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $household = $this->family();
        writeby::set($household, [], 'family');
        households::received($household, 'email', 'Gracias', false);
        $household = households::get((int) $household->id, $this->centreid);
        $this->assertTrue(writeby::silent($household));
        $this->assertTrue(writeby::may_send($household, 'reply'));
        $this->assertFalse(writeby::may_send($household, 'reminder'));
    }

    /**
     * Every mail asks at sight how they want to be written to, with a button for each way, the one of now marked; the
     * page of the buttons changes nothing until one touch chooses.
     */
    public function test_mail_and_page(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $household = $this->family();
        $centre = centres::get($this->centreid);
        [, $html, $text] = familymail::build('received', $household, $centre, null, 'es', 'clave');
        $this->assertStringContainsString('¿Por dónde les escribimos?', $html);
        $this->assertStringContainsString('Ahora les escribimos por correo. ¿Prefieren que no les escribamos más?', $html);
        $this->assertStringContainsString('&#10003;&nbsp;Correo', $html);
        $this->assertStringContainsString('action=escribir', $html);
        $this->assertStringContainsString('por=none', $html);
        $this->assertStringContainsString('No escribirnos más', $text);
        // The mail that confirms the choice does not ask again.
        [$subject, $html] = familymail::build('writeby', $household, $centre, null, 'es', 'clave');
        $this->assertStringStartsWith('Les escribiremos por correo', $subject);
        $this->assertStringNotContainsString('¿Por dónde les escribimos?', $html);

        // Pressed «Do not write to us any more» in a mail: asked with its one button, nothing changed yet.
        $page = writeby::page_context($household, $centre, 'es', 'clave', 'none', '');
        $this->assertSame('¿Dejamos de escribirles?', $page['title']);
        $this->assertStringContainsString('Leo', $page['none']['means']);
        $this->assertNull(writeby::chosen($household));
        // From their page: the boxes as they are.
        $page = writeby::page_context($household, $centre, 'es', 'clave', '', '');
        $this->assertSame('¿Cómo prefieren que les escribamos?', $page['title']);
        $this->assertSame([true], array_column($page['choosing']['boxes'], 'checked'));
        // Pressing email in a mail, which they have: it is offered taken out.
        $page = writeby::page_context($household, $centre, 'es', 'clave', 'email', '');
        $this->assertSame('¿Dejamos de escribirles por correo?', $page['title']);
        $this->assertSame([false], array_column($page['choosing']['boxes'], 'checked'));
        $this->assertSame('No escribirnos más', $page['choosing']['button']);

        writeby::set($household, [], 'family');
        $done = writeby::page_context($household, $centre, 'es', 'clave', '', 'none');
        $this->assertSame('Hecho. No les escribiremos más', $done['title']);
        $this->assertSame('¡Gracias por decírnoslo!', $done['done']['note']);
        writeby::set($household, ['email'], 'family');
        $done = writeby::page_context($household, $centre, 'es', 'clave', '', 'email');
        $this->assertSame('Les escribiremos por correo', $done['title']);
        $this->assertSame('Por correo', $done['done']['note']);
    }

    /**
     * Several ways at once: email goes while no phone they marked is joined; email taken out and a phone joined, only
     * what the phone does not carry; all taken out is «do not write to us any more».
     */
    public function test_several_ways(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $household = $this->family();
        $centre = centres::get($this->centreid);
        $this->assertTrue(writeby::set($household, ['whatsapp', 'email'], 'family'));
        $this->assertSame('email,whatsapp', $household->writeby);
        $this->assertSame(['email', 'whatsapp'], writeby::chosen($household));
        $this->assertTrue(writeby::email_ok($household, $centre, 'reminder'));
        $this->assertTrue(writeby::phone_ok($household, 'reminder'));
        $this->assertSame('por correo y por WhatsApp', writeby::words(writeby::chosen($household), 'es'));
        // Only WhatsApp, not joined yet: email still goes, until they join it.
        writeby::set($household, ['whatsapp'], 'family');
        $this->assertTrue(writeby::email_ok($household, $centre, 'reminder'));
        $this->assertFalse(familymail::may_email($household));
        // Only email: nothing to the phones.
        writeby::set($household, ['email'], 'team');
        $this->assertFalse(writeby::phone_ok($household, 'reminder'));
        // None marked is «do not write to us any more».
        writeby::set($household, [], 'family');
        $this->assertTrue(writeby::silent($household));
        $this->assertFalse(writeby::email_ok($household, $centre, 'reminder'));
    }

    /**
     * The question: Jujol's sentence with several ways, Enrique's with email only.
     */
    public function test_question(): void {
        $this->resetAfterTest();
        $this->assertSame('¿Prefieren que no les escribamos más? Elíjanlo aquí.', writeby::question(['email'], 'es', ['email']));
        $this->assertSame(
            'Toquen un canal para añadirlo o quitarlo, o pídannos que no les escribamos más.',
            writeby::question(['email', 'whatsapp', 'telegram'], 'es', ['email'])
        );
    }

    /**
     * Asked as a family on its way not to be written to, and then enrolled: the word was for the admissions, and the file
     * and the page ask again, with the trail of days.
     */
    public function test_enrolled_after(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $household = $this->family();
        $centre = centres::get($this->centreid);
        writeby::set($household, 'none', 'family', 0, time() - DAYSECS);
        $this->assertSame(0, writeby::enrolled_since($household));
        $DB->set_field(households::CHILDREN, 'stage', 'enrolled', ['householdid' => $household->id]);
        $DB->set_field(households::CHILDREN, 'stagetime', time(), ['householdid' => $household->id]);
        $this->assertGreaterThan(0, writeby::enrolled_since($household));
        $team = writeby::team_context($household, $centre, '#');
        $this->assertNotSame('', $team['askagain']);
        $this->assertCount(2, $team['trail']);
        // They choose again: the trail keeps the three days.
        writeby::set($household, 'email', 'family', 0, time() + 5);
        $team = writeby::team_context($household, $centre, '#');
        $this->assertSame('', $team['askagain']);
        $this->assertCount(3, $team['trail']);
    }
}
