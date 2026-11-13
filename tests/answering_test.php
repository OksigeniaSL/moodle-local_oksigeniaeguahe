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
use local_oksigeniaeguahe\local\emailcheck;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\questions;
use local_oksigeniaeguahe\local\signup;

/**
 * Answering a family well (review of November 2026): who will answer, with their face; what they asked, to see the
 * answer says it all; an email that looks mistyped or cannot receive mail; and an entry tried with the test email.
 * Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\questions
 * @covers     \local_oksigeniaeguahe\local\emailcheck
 */
#[\PHPUnit\Framework\Attributes\CoversClass(questions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(emailcheck::class)]
final class answering_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /**
     * A centre, with example data.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1, 'sortorder' => 0]);
    }

    /**
     * Resets what the tests forced.
     */
    protected function tearDown(): void {
        emailcheck::$testreachable = null;
        parent::tearDown();
    }

    /**
     * A person of the team, shown to families or not.
     *
     * @param string $first
     * @param bool $public
     * @return int The user.
     */
    protected function person(string $first, bool $public): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['firstname' => $first, 'lastname' => 'Medina']);
        centres::add_member((int) $this->centre->id, (int) $user->id);
        $DB->set_field(centres::MEMBERS, 'public', $public ? 1 : 0, ['centreid' => $this->centre->id, 'userid' => $user->id]);
        $DB->set_field(centres::MEMBERS, 'rolename', 'Secretaría', ['centreid' => $this->centre->id, 'userid' => $user->id]);
        return (int) $user->id;
    }

    /**
     * The questions of a message, in Spanish, English and Portuguese, with the words that tell they are answered.
     */
    public function test_questions(): void {
        $asked = questions::of("Hola, buenas tardes.\nQuería saber si hay comedor en Infantil. ¿A qué hora entran los " .
            "pequeños? Gracias. ¿Hay ruta?");
        $texts = array_column($asked, 'text');
        $this->assertSame(['Quería saber si hay comedor en Infantil', '¿A qué hora entran los pequeños?', '¿Hay ruta?'], $texts);
        $this->assertSame(['comed', 'infan'], $asked[0]['words']);
        $this->assertSame(['hora', 'entra', 'peque'], $asked[1]['words']);
        $this->assertSame(['ruta'], $asked[2]['words']);
        $this->assertSame(['Do you have a canteen?'], array_column(questions::of('Hi! Do you have a canteen? Thanks.'), 'text'));
        $this->assertSame(['cante'], questions::of('Do you have a canteen?')[0]['words']);
        $texts = array_column(questions::of('Olá. Gostaria de saber se há transporte.'), 'text');
        $this->assertSame(['Gostaria de saber se há transporte'], $texts);
        $this->assertSame([], questions::of('Gracias por todo, nos vemos el sábado.'));
    }

    /**
     * Only what came in since the last answer is asked.
     */
    public function test_asked_since_the_answer(): void {
        $contacts = [
            (object) ['direction' => 'in', 'body' => '¿Hay comedor?'],
            (object) ['direction' => 'out', 'body' => 'Sí, hay comedor.'],
            (object) ['direction' => 'note', 'body' => '¿Les llamo yo?'],
            (object) ['direction' => 'in', 'body' => '¿Y cuánto cuesta el uniforme?'],
        ];
        $this->assertSame(['¿Y cuánto cuesta el uniforme?'], array_column(questions::asked($contacts), 'text'));
        $contacts[] = (object) ['direction' => 'out', 'body' => 'Son 40 euros.'];
        $this->assertSame([], questions::asked($contacts));
    }

    /**
     * An email that looks like a slip of a common domain, and one that cannot receive mail.
     */
    public function test_emails(): void {
        $this->assertSame('marta@gmail.com', emailcheck::suggest('marta@gmial.com'));
        $this->assertSame('marta@hotmail.com', emailcheck::suggest('marta@hotmail.con'));
        $this->assertSame('ana@yahoo.es', emailcheck::suggest('ana@yaho.es'));
        $this->assertNull(emailcheck::suggest('marta@gmail.com'));
        $this->assertNull(emailcheck::suggest('direccion@colegiolosarces.es'), 'A domain of its own is never «corrected».');
        $this->assertSame('', emailcheck::warning('marta@gmail.com'));
        $this->assertStringContainsString('marta@gmail.com', emailcheck::warning('marta@gmial.com'));

        // A domain without mail: refused when a family writes it, said to the team in the file.
        emailcheck::$testreachable = false;
        $this->assertSame(get_string('panel_email_unreachable', 'local_oksigeniaeguahe'), emailcheck::warning('marta@gmali.cm'));
        $event = (object) ['id' => 0, 'kind' => 'form', 'capacity' => 0, 'formid' => 0];
        $data = ['adults' => [['firstname' => 'Marta', 'lastname' => '', 'email' => 'marta@sindominio.example',
            'phone' => '']], 'children' => [['firstname' => 'Leo', 'offerid' => 0, 'birthdate' => '']], 'people' => 1,
            'answers' => []];
        $errors = signup::check($data, $event, $this->centre, 'es');
        $this->assertSame('Ese correo no puede recibir mensajes: revisen lo que va después de la @.', $errors['a0email']);
    }

    /**
     * Who will answer: the person who attends the family, those who answered lately, or the only one shown; never a
     * guess among many, nobody away and nobody hidden.
     */
    public function test_who_answers(): void {
        global $DB;
        $lucia = $this->person('Lucía', true);
        $pablo = $this->person('Pablo', true);
        $hidden = $this->person('Ana', false);
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es']);
        $household = households::get($id, (int) $this->centre->id);
        // Two shown and no answers yet: no guess.
        $this->assertSame([], centres::answerers($this->centre, $household));
        // Pablo answered families lately: he usually answers.
        households::answered($household, $pablo, 'email', 'Hola: sí.');
        $this->assertSame(['Pablo'], array_map(fn($h) => $h->first, centres::answerers($this->centre)));
        // Lucía attends this family: she will answer it.
        $DB->set_field(households::T, 'ownerid', $lucia, ['id' => $household->id]);
        $household->ownerid = $lucia;
        $this->assertSame(['Lucía'], array_map(fn($h) => $h->first, centres::answerers($this->centre, $household)));
        // Away, she is not named; neither is a person families do not see.
        $DB->set_field(centres::MEMBERS, 'awayuntil', time() + DAYSECS, ['userid' => $lucia]);
        $this->assertSame(['Pablo'], array_map(fn($h) => $h->first, centres::answerers($this->centre, $household)));
        $DB->set_field(households::T, 'ownerid', $hidden, ['id' => $household->id]);
        $household->ownerid = $hidden;
        $this->assertSame(['Pablo'], array_map(fn($h) => $h->first, centres::answerers($this->centre, $household)));

        // The mail of «received» says it, with the face below.
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => '', 'email' => 'marta@example.com',
            'phone' => '']);
        $token = households::new_link($household, 'test');
        [, $html, $text] = familymail::build('received', $household, $this->centre, null, 'es', $token);
        $this->assertStringContainsString('Pablo les contesta normalmente en 2 días laborables.', $text);
        $this->assertStringContainsString('Quién les contesta', $html);
        $this->assertStringContainsString('Pablo Medina', $html);
    }

    /**
     * An entry tried with the test email: it works, and no family comes in.
     */
    public function test_tried_entries(): void {
        global $DB;
        $id = entries::create((int) $this->centre->id, 'google', 'Formulario de Google');
        $entry = entries::get($id, (int) $this->centre->id);
        [$household, $new] = entries::receive($entry, ['Nombre' => 'Prueba', 'Correo electrónico' => 'Prueba@Eguahe.test',
            'Nombre del alumno' => 'Leo'], 'resp-1');
        $this->assertNull($household);
        $this->assertFalse($new);
        $this->assertGreaterThan(0, (int) $DB->get_field(entries::T, 'testedat', ['id' => $entry->id]));
        $this->assertSame(0, (int) $DB->get_field(entries::T, 'received', ['id' => $entry->id]));
        $this->assertSame(0, $DB->count_records(households::T, ['centreid' => $this->centre->id]));

        $mailbox = entries::get(entries::create((int) $this->centre->id, 'email', 'Buzón'), (int) $this->centre->id);
        $raw = "From: Secretaría <secretaria@example.com>\r\nTo: admisiones@example.com\r\nSubject: Prueba\r\n" .
            "Message-ID: <p1@example.com>\r\n\r\nEsto es una prueba: prueba@eguahe.test\r\n";
        [$household] = entries::from_email($mailbox, $raw);
        $this->assertNull($household);
        $this->assertGreaterThan(0, (int) $DB->get_field(entries::T, 'testedat', ['id' => $mailbox->id]));
        $this->assertSame(0, $DB->count_records(households::T, ['centreid' => $this->centre->id]));
    }
}
