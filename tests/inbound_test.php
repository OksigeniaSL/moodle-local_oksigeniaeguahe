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
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\inbound;

/**
 * Emails that reach an entry: a family that writes, a message forwarded by hand and the notice of a form of a web;
 * the attachments are not read. Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\inbound
 */
#[\PHPUnit\Framework\Attributes\CoversClass(inbound::class)]
final class inbound_test extends \advanced_testcase {
    /**
     * A family writes, with a PDF attached: who, its phone and child from the text, and the rest as its message.
     */
    public function test_family_writes(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $raw = file_get_contents($CFG->dirroot . '/local/oksigeniaeguahe/email-worker/sample.eml');
        $mail = inbound::parse($raw);
        $this->assertSame('lucia.ortega@example.com', $mail['from']);
        $this->assertSame('Información para Infantil 3 años', $mail['subject']);
        $this->assertSame('prueba-eguahe-0001@example.com', $mail['messageid']);
        $this->assertStringContainsString('Nos gustaría saber si quedan plazas', $mail['text']);
        $this->assertStringNotContainsString('JVBER', $mail['text'], 'Attachments are not read.');
        $answers = inbound::answers($mail);
        $this->assertSame('Mateo', $answers['Nombre del alumno']);
        $this->assertSame('611 444 555', $answers['Teléfono']);
        $this->assertSame('lucia.ortega@example.com', $answers['Email']);
        $this->assertSame('Lucia Ortega', $answers['Nombre y apellidos']);

        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $entry = entries::get(entries::create($id, 'email', 'Buzón de admisiones'), $id);
        $this->assertSame(0, (int) $entry->acknowledge, 'Emails are not answered by a machine.');
        [$household, $new] = entries::from_email($entry, $raw);
        $this->assertTrue($new);
        $full = households::full($household);
        $this->assertSame('Lucia', reset($full->adults)->firstname);
        $this->assertSame('+34611444555', reset($full->adults)->phone);
        $this->assertSame('Mateo', reset($full->children)->firstname);
        $this->assertSame('email', $full->firstchannel);
        $body = $DB->get_field(households::CONTACTS, 'body', ['householdid' => $household->id]);
        $this->assertStringContainsString('cuándo es la próxima jornada', $body);
        [, $again] = entries::from_email($entry, $raw);
        $this->assertFalse($again, 'The same email comes in once.');
    }

    /**
     * A message forwarded by hand: who wrote it first, not who forwarded it.
     */
    public function test_forwarded_by_hand(): void {
        $raw = "From: Secretaría <secretaria@colegio.example>\r\nSubject: Fwd: Plaza\r\nContent-Type: text/plain; " .
            "charset=ISO-8859-1\r\nContent-Transfer-Encoding: 8bit\r\n\r\nOs lo paso.\r\n\r\n" .
            "---------- Forwarded message ---------\r\nDe: Jorge Medina <jorge.medina@example.com>\r\n" .
            "Date: vie, 6 nov 2026\r\nSubject: Plaza\r\nTo: <secretaria@colegio.example>\r\n\r\n" .
            mb_convert_encoding("¿Tienen plaza en 1.º de ESO? Somos de Leganés.", 'ISO-8859-1', 'UTF-8');
        $answers = inbound::answers(inbound::parse($raw));
        $this->assertSame('jorge.medina@example.com', $answers['Email']);
        $this->assertSame('Jorge Medina', $answers['Nombre y apellidos']);
        $this->assertStringContainsString('¿Tienen plaza en 1.º de ESO? Somos de Leganés.', $answers['Mensaje del correo']);
        $this->assertStringNotContainsString('Os lo paso', $answers['Mensaje del correo']);
    }

    /**
     * The notice of a form of a web: the family is in its fields, not in the sender, which is a machine.
     */
    public function test_web_form_notice(): void {
        $raw = "From: WordPress <wordpress@colegio.example>\r\nSubject: Nuevo mensaje del formulario\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n\r\n<p>Nombre: Ana Gil</p><p>Email: ana.gil@example.com</p>" .
            "<p>Curso: 1.º de Primaria</p><p>Mensaje: ¿Hay comedor?</p>";
        $answers = inbound::answers(inbound::parse($raw));
        $this->assertSame('ana.gil@example.com', $answers['Email']);
        $this->assertSame('Ana Gil', $answers['Nombre']);
        $this->assertSame('1.º de Primaria', $answers['Curso']);
        $this->assertArrayNotHasKey('Nombre y apellidos', $answers);
        $this->assertSame('¿Hay comedor?', $answers['Mensaje']);
    }

    /**
     * Anyone can write anything in the text of an email: a forwarded message or the fields of a form say who wrote only
     * when the team, the address of the centre or its own domain send it.
     */
    public function test_trusted_senders(): void {
        $raw = "From: Alguien <alguien@example.net>\r\nSubject: Plaza\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" .
            "Hola\r\n---------- Forwarded message ---------\r\nFrom: Marta Ruiz <marta@example.com>\r\n\r\n" .
            "Email: marta@example.com\r\nQueremos plaza.\r\n";
        $mail = inbound::parse($raw);
        $this->assertSame('alguien@example.net', inbound::answers($mail, ['secretaria@colegio.example'])['Email']);
        $this->assertSame('marta@example.com', inbound::answers($mail, ['alguien@example.net'])['Email']);
        $this->assertSame('marta@example.com', inbound::answers($mail, ['@example.net'])['Email'], 'Its own domain.');
    }
}
