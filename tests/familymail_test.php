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

use local_oksigeniaeguahe\local\antispam;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\poster;
use local_oksigeniaeguahe\local\signup;
use local_oksigeniaeguahe\local\sticker;
use local_oksigeniaeguahe\task\send_reminders;

/**
 * What is sent to the families (DESIGN.md of the sendings): the label with the name as a PNG, the mails with their
 * images inside, the reminder of the day before, the calendar file and the poster. Never the brand of Eguahe. Example
 * data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\familymail
 * @covers     \local_oksigeniaeguahe\local\sticker
 * @covers     \local_oksigeniaeguahe\local\poster
 * @covers     \local_oksigeniaeguahe\task\send_reminders
 */
#[\PHPUnit\Framework\Attributes\CoversClass(familymail::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sticker::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(poster::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(send_reminders::class)]
final class familymail_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $event;

    /**
     * A centre with someone who receives the families and an open day tomorrow at ten, with example data.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        antispam::$testpass = true;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'colour' => '#1f5a4e', 'address' => 'Calle de ejemplo 1', 'phone' => '+34 900 000 014',
            'email' => 'admisiones@example.com']);
        $this->centre = centres::get($id);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Lucía', 'lastname' => 'Medina']);
        centres::add_member($id, (int) $user->id);
        $member = $DB->get_record(centres::MEMBERS, ['centreid' => $id, 'userid' => $user->id]);
        $DB->update_record(centres::MEMBERS, (object) ['id' => $member->id, 'public' => 1, 'rolename' => 'Tutora']);
        $start = make_timestamp((int) date('Y'), (int) date('n'), (int) date('j') + 1, 10, 0);
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'place' => 'Entrada principal', 'timestart' => $start, 'timeend' => $start + 3 * HOURSECS, 'capacity' => 0,
            'open' => 1, 'hosts' => [$member->id]]);
        $this->event = events::get($eventid, $id);
    }

    /**
     * Resets the widget against spam and the clock of the reminders.
     */
    protected function tearDown(): void {
        antispam::$testpass = null;
        send_reminders::$testnow = null;
        parent::tearDown();
    }

    /**
     * A family signed up, with two children and the email allowed or not.
     *
     * @param bool $email
     * @return array [household, token]
     */
    protected function family(bool $email = true): array {
        return signup::save([
            'adults' => [['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@example.com', 'phone' => '600111222'],
                ['firstname' => '', 'lastname' => '', 'email' => '', 'phone' => '']],
            'children' => [['firstname' => 'Leonardo', 'tagname' => 'Leo', 'offerid' => 0],
                ['firstname' => 'Íñigo', 'tagname' => '', 'offerid' => 0]],
            'people' => 3, 'slot' => 0, 'incentre' => '', 'municipality' => '', 'municipalityother' => '', 'heardfrom' => '',
            'message' => '', 'consents' => ['email' => $email ? 1 : 0, 'whatsapp' => 1, 'phone' => 0], 'altcha' => 'x',
        ], $this->event, $this->centre, 'es');
    }

    /**
     * The names under the labels read as a phrase, with «e» before the sound «i».
     */
    public function test_alt_names(): void {
        $this->assertSame(['Leo', 'y Noa'], familymail::alt_names(['Leo', 'Noa'], 'es'));
        $this->assertSame(['María José', 'e Íñigo'], familymail::alt_names(['María José', 'Íñigo'], 'es'));
        $this->assertSame(['Leo,', 'Noa', 'y Mateo'], familymail::alt_names(['Leo', 'Noa', 'Mateo'], 'es'));
        $this->assertSame(['Leo', 'y Hierro'], familymail::alt_names(['Leo', 'Hierro'], 'es'));
        $this->assertSame(['Leo', 'and Noa'], familymail::alt_names(['Leo', 'Noa'], 'en'));
    }

    /**
     * The label as a PNG: its size, the same bytes for the same name, smaller writing for a long name or an accent.
     */
    public function test_sticker(): void {
        if (!sticker::available()) {
            $this->markTestSkipped('GD with FreeType is not here.');
        }
        $png = sticker::png('Leo', '#1f5a4e');
        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame([480, 392], array_slice(getimagesizefromstring($png), 0, 2));
        $this->assertSame($png, sticker::png('Leo', '#1f5a4e'));
        $this->assertNotSame($png, sticker::png('Leo', '#f2b705'));
        $this->assertEquals(13.0, sticker::size_for('Leo'));
        $this->assertLessThan(13.0, sticker::size_for('Íñigo'));
        $this->assertLessThan(sticker::size_for('Ana Sofía'), sticker::size_for('María de los Ángeles'));
        $this->assertGreaterThanOrEqual(5.5, sticker::size_for('María de los Ángeles Fernández'));
    }

    /**
     * The confirmation, with the images inside the message and the name of the centre, never Eguahe.
     */
    public function test_confirmation(): void {
        [$household, $token] = $this->family();
        $signup = familymail::signup($household, (int) $this->event->id);
        [$subject, $html, $text, $images] = familymail::build('confirmation', $household, $this->centre, $signup, 'es', $token);
        $this->assertStringStartsWith('Los esperamos el ', $subject);
        $this->assertStringContainsString('Abrir su página', $html);
        $this->assertStringContainsString('Lucía', $html);
        $this->assertStringContainsString('Leo y Íñigo', $text);
        $this->assertStringNotContainsStringIgnoringCase('eguahe.', $html);
        // Who makes Eguahe, at the end, small (Enrique, 8 November 2026).
        $this->assertStringContainsString('Enviado con <a class="eg-enlace"', $html);
        $this->assertStringContainsString('Enviado con Eguahe, de Oksigenia: https://oksigenia.com/', $text);
        if (sticker::available()) {
            $this->assertCount(2, array_filter($images, fn($i) => $i['mime'] === 'image/png'));
            $this->assertStringContainsString('alt="e Íñigo"', $html);
        }
        $sink = $this->redirectEmails();
        $this->assertTrue(familymail::send('confirmation', $household, $this->centre, $signup, 'es', $token));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('marta@example.com', $messages[0]->to);
        $this->assertSame($subject, $messages[0]->subject);
        if (sticker::available()) {
            $this->assertGreaterThanOrEqual(2, substr_count($messages[0]->body, 'Content-ID'));
        }
    }

    /**
     * The reminder goes once, the day before, only to families who let the centre write to them by email.
     */
    public function test_reminders(): void {
        [$withemail] = $this->family(true);
        $sink = $this->redirectEmails();
        send_reminders::$testnow = make_timestamp((int) date('Y'), (int) date('n'), (int) date('j'), 12, 0);
        (new send_reminders())->execute();
        $this->assertCount(1, $sink->get_messages());
        $this->assertStringStartsWith('Mañana ', $sink->get_messages()[0]->subject);
        (new send_reminders())->execute();
        $this->assertCount(1, $sink->get_messages(), 'Once.');

        // Without the email allowed, no reminder.
        $sink->clear();
        $other = signup::save([
            'adults' => [['firstname' => 'Pedro', 'lastname' => '', 'email' => 'pedro@example.com', 'phone' => '600333444'],
                ['firstname' => '', 'lastname' => '', 'email' => '', 'phone' => '']],
            'children' => [['firstname' => 'Noa', 'tagname' => '', 'offerid' => 0], ['firstname' => '', 'offerid' => 0]],
            'people' => 2, 'slot' => 0, 'incentre' => '', 'municipality' => '', 'municipalityother' => '', 'heardfrom' => '',
            'message' => '', 'consents' => ['email' => 0, 'whatsapp' => 1, 'phone' => 0], 'altcha' => 'x',
        ], $this->event, $this->centre, 'es');
        (new send_reminders())->execute();
        $this->assertCount(0, $sink->get_messages());
        $this->assertNotEmpty($withemail);
        $this->assertNotEmpty($other);
    }

    /**
     * The calendar file of a family: fixed per family and visit, with its alarm, in the name of the centre.
     */
    public function test_ics(): void {
        [$household, $token] = $this->family();
        $signup = familymail::signup($household, (int) $this->event->id);
        $ics = familymail::ics($household, $this->centre, $this->event, $signup, 'es', $token);
        $this->assertStringContainsString('UID:visita-' . $this->event->id . '-familia-' . $household->id . '@', $ics);
        $this->assertStringContainsString("TRIGGER:-PT2H\r\n", $ics);
        $this->assertStringContainsString('PRODID:-//Colegio de ejemplo//Visitas//ES', $ics);
        $this->assertStringNotContainsString('Eguahe', $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    /**
     * The poster, with its QR and the question of the name.
     */
    public function test_poster(): void {
        $html = poster::html($this->event, $this->centre, 'A3');
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Vengan a<br>conocernos', $html);
        $this->assertStringContainsString('size: A3 portrait', $html);
        $this->assertStringContainsString('O llámennos al +34 900 000 014', $html);
        $this->assertStringContainsString('<p class="firma">Eguahe, de Oksigenia</p>', $html);
    }

    /**
     * The QR of an event: the one the shortener draws, in the common style and kept with the event; else one drawn here.
     */
    public function test_designed_qr(): void {
        global $DB;
        // Without a shortener: the one drawn here, of the long link.
        $this->assertStringStartsWith('<svg', events::qr_svg($this->event));
        set_config('shortenerapi', 'https://short.example/yourls-api.php', 'local_oksigeniaeguahe');
        set_config('shortenerkey', 'k3y', 'local_oksigeniaeguahe');
        // The answers come out last first: the shortener answers, then the picture is brought.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="1"/></svg>';
        \curl::mock_response($svg);
        \curl::mock_response(json_encode(['status' => 'success', 'shorturl' => 'https://short.example/visita-2111',
            'url' => ['keyword' => 'visita-2111'], 'qrsvg' => 'https://short.example/svg/visita-2111']));
        $html = events::qr_svg($this->event);
        $this->assertSame('<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" alt="" />', $html);
        $this->assertSame($svg, $DB->get_field(events::T, 'shortqr', ['id' => $this->event->id]));
        // Kept: no more asking.
        $this->assertSame($html, events::qr_svg(events::get((int) $this->event->id, (int) $this->centre->id)));
        $this->assertStringContainsString($html, poster::html($this->event, $this->centre));

        // A short link made before, with a shortener that draws none (a plain YOURLS): asked once, and the one drawn here.
        $DB->set_field(events::T, 'shortqr', null, ['id' => $this->event->id]);
        \curl::mock_response(json_encode(['status' => 'fail', 'code' => 'error:url',
            'shorturl' => 'https://short.example/visita-2111']));
        $event = events::get((int) $this->event->id, (int) $this->centre->id);
        $this->assertStringStartsWith('<svg', events::qr_svg($event));
        $this->assertSame('', $DB->get_field(events::T, 'shortqr', ['id' => $this->event->id]));
    }
}
