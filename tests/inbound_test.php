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

    /**
     * An email, as it comes.
     *
     * @param string $from
     * @param string $subject
     * @param string $body
     * @param string $more Other headers.
     * @return array As inbound::parse() gives it.
     */
    protected function mail(string $from, string $subject, string $body, string $more = ''): array {
        return inbound::parse("From: $from\r\nSubject: $subject\r\n$more" . "Content-Type: text/plain; charset=utf-8\r\n\r\n$body");
    }

    /**
     * Forwarded from Outlook, on the web (a line of underscores) and on the desk (no line at all): who wrote it first, and
     * never two families in one.
     */
    public function test_forwarded_from_outlook(): void {
        $this->resetAfterTest();
        $trusted = ['@colegio.example'];
        $headers = "De: Marta Ruiz <marta@example.com>\r\nEnviado: lunes, 23 de noviembre de 2026 10:00\r\n" .
            "Para: Conserjería <conserjeria@colegio.example>\r\nAsunto: Plaza\r\n\r\nQueremos plaza en 1.º de Primaria.\r\n" .
            "Para: 1.º de Primaria\r\n";
        foreach (["Os lo paso.\r\n\r\n________________________________\r\n" . $headers, "Os lo paso.\r\n\r\n" . $headers] as $body) {
            $answers = inbound::answers($this->mail('Conserjería <conserjeria@colegio.example>', 'RV: Plaza', $body), $trusted);
            $this->assertSame('marta@example.com', $answers['Email']);
            $this->assertSame('Marta Ruiz', $answers['Nombre y apellidos']);
            $this->assertStringContainsString('Queremos plaza en 1.º de Primaria.', $answers['Mensaje del correo']);
            $this->assertStringContainsString('Para: 1.º de Primaria', $answers['Mensaje del correo'], 'Only its headers go.');
            $this->assertStringNotContainsString('Enviado:', $answers['Mensaje del correo']);
            $this->assertStringNotContainsString('Os lo paso', $answers['Mensaje del correo']);
        }
    }

    /**
     * Sent on by somebody of the centre with nothing that says whose it is: no family of the doorkeeper's, no test, and the
     * team hears of it once.
     */
    public function test_forwarded_and_nobody(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $trusted = ['conserjeria@colegio.example'];
        $mail = $this->mail('Conserjería <conserjeria@colegio.example>', 'RV', "Han llamado, quieren plaza.\r\n");
        $this->assertSame([], inbound::answers($mail, $trusted));
        $with = $this->mail('Conserjería <conserjeria@colegio.example>', 'RV', "Nombre: Ana Gil\r\nTeléfono: 600 111 222\r\n");
        $answers = inbound::answers($with, $trusted);
        $this->assertSame('Ana Gil', $answers['Nombre']);
        $this->assertArrayNotHasKey('Email', $answers, 'Never the address of the doorkeeper.');

        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'email' => 'admisiones@colegio.example']);
        $user = $this->getDataGenerator()->create_user();
        centres::add_member($id, (int) $user->id);
        $DB->set_field(centres::MEMBERS, 'admin', 1, ['centreid' => $id, 'userid' => $user->id]);
        $entry = entries::get(entries::create($id, 'email', 'Buzón'), $id);
        $raw = "From: Conserjería <conserjeria@colegio.example>\r\nMessage-ID: <uno@colegio.example>\r\nSubject: RV\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n\r\nHan llamado, quieren plaza.\r\n";
        $sink = $this->redirectMessages();
        $this->assertSame([null, false, null], entries::from_email($entry, $raw));
        $this->assertSame([null, false, null], entries::from_email($entry, $raw));
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame(0, $DB->count_records(households::T, ['centreid' => $id]));
        $this->assertSame(0, (int) $DB->get_field(entries::T, 'testedat', ['id' => $entry->id]), 'It is not a test.');
    }

    /**
     * The notice of a form of a web, as it comes of its own: whom to answer is in its headers and in its first line.
     */
    public function test_notice_with_reply_to(): void {
        $this->resetAfterTest();
        $body = "De: Ana Gil <ana.gil@example.com>\r\nAsunto: Plazas\r\n\r\nCuerpo del mensaje:\r\n¿Hay comedor?\r\n\r\n-- \r\n";
        foreach (['@colegio.example', '@otro.example'] as $trust) {
            $mail = $this->mail('WordPress <wordpress@colegio.example>', 'Plazas', $body, "Reply-To: ana.gil@example.com\r\n");
            $answers = inbound::answers($mail, [$trust]);
            $this->assertSame('ana.gil@example.com', $answers['Email']);
            $this->assertSame('Ana Gil', $answers['Nombre y apellidos']);
            $this->assertStringContainsString('¿Hay comedor?', $answers['Mensaje del correo']);
        }
    }

    /**
     * What a family quotes under its answer is of the centre: neither its words nor its phone.
     */
    public function test_quoted(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES', 'phone' => '900 000 014',
            'email' => 'admisiones@colegio.example']);
        $trusted = ['admisiones@colegio.example'];
        $body = "Nos viene bien el jueves.\r\nFecha: podemos ir el 3 de diciembre\r\n\r\nDe: Admisiones <admisiones@colegio.example>\r\n" .
            "Enviado: lunes\r\nPara: Olga\r\nAsunto: Su visita\r\n\r\nHola, Olga. Los esperamos.\r\nTel.: 900 000 014\r\n";
        $answers = inbound::answers($this->mail('Olga <olga@example.com>', 'RE: Su visita', $body), $trusted, centres::get($id));
        $this->assertSame('olga@example.com', $answers['Email']);
        $said = implode("\n", $answers);
        $this->assertStringContainsString('Nos viene bien el jueves.', $said);
        $this->assertStringContainsString('podemos ir el 3 de diciembre', $said);
        $this->assertStringNotContainsString('Los esperamos', $said);
        $this->assertStringNotContainsString('900 000 014', $said);
        $signed = inbound::answers(
            $this->mail('Olga <olga@example.com>', 'Hola', "El lunes, 23 de noviembre de 2026, Admisiones escribió:\r\n> Hola\r\nGracias.\r\nTel.: 900000014"),
            $trusted,
            centres::get($id)
        );
        $this->assertStringNotContainsString('escribió', implode("\n", $signed));
        $this->assertArrayNotHasKey('Tel.', $signed, 'The phone of the centre is of no family.');
    }

    /**
     * Nobody wrote the answer of a mailbox nor a newsletter; the name of who writes keeps its accents.
     */
    public function test_nobody_wrote(): void {
        $auto = $this->mail('Eva <eva@example.com>', 'Fuera', 'Vuelvo el lunes.', "Auto-Submitted: auto-replied\r\n");
        $this->assertTrue(inbound::nobody_wrote($auto, []));
        $list = $this->mail('Editorial <hola@editorial.example>', 'Novedades', 'Libros', "List-Unsubscribe: <https://editorial.example>\r\n");
        $this->assertTrue(inbound::nobody_wrote($list, []));
        $this->assertFalse(inbound::nobody_wrote($list, ['@editorial.example']));
        $this->assertFalse(inbound::nobody_wrote($this->mail('Eva <eva@example.com>', 'Plaza', 'Hola'), []));
        $this->assertSame('Conserjería', $this->mail('Conserjería <c@example.com>', 'Información', 'Hola')['name']);
        $this->assertSame('Información', $this->mail('Conserjería <c@example.com>', 'Información', 'Hola')['subject']);
    }
}
