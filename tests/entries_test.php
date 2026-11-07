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
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\sheets;
use local_oksigeniaeguahe\local\signup;

/**
 * The entries of a centre: its own forms with their questions and conditions, the answers of a Google form, the
 * contacts of Meta ads, the signature of what comes in, and nothing coming in twice or after being erased. Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\entries
 * @covers     \local_oksigeniaeguahe\local\forms
 */
#[\PHPUnit\Framework\Attributes\CoversClass(entries::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(forms::class)]
final class entries_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $infantil;

    /**
     * A centre with two courses, with example data.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        antispam::$testpass = true;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'municipalities' => "28065;Getafe\n28074;Leganés"]);
        $this->centre = centres::get($id);
        $this->infantil = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1, 'sortorder' => 0]);
        centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => '1.º de Primaria', 'places' => 4,
            'active' => 1, 'sortorder' => 1]);
    }

    /**
     * Resets the widget against spam.
     */
    protected function tearDown(): void {
        antispam::$testpass = null;
        parent::tearDown();
    }

    /**
     * A form of the centre with its own questions: one waits for a yes, and the answers stay with the family.
     */
    public function test_form_with_questions(): void {
        global $DB;
        $entry = entries::get(entries::create((int) $this->centre->id, 'form', 'Plaza para hermanos'), (int) $this->centre->id);
        $siblings = forms::save_field($entry, (object) ['type' => 'yesno', 'required' => 1,
            'label_es' => '¿Tiene hermanos en el centro?', 'label_en' => 'Any siblings at the school?']);
        $course = forms::save_field($entry, (object) ['type' => 'text', 'required' => 1, 'showif' => $siblings,
            'showvalue' => 'Sí', 'label_es' => '¿En qué curso están?']);
        $lunch = forms::save_field($entry, (object) ['type' => 'multichoice', 'label_es' => '¿Qué servicios les interesan?',
            'options_es' => "Comedor\nAula matinal\nExtraescolares"]);
        $origin = forms::add_origin($entry, 'Cartel de la puerta');
        $this->assertSame('carteldelapuerta', $origin);
        $this->assertSame('health', forms::forbidden('¿Tiene alguna alergia?'));
        $this->assertNull(forms::forbidden('¿Qué servicios les interesan?'));

        // In English, the question written in English; the others, in the language of the centre.
        $context = forms::context($entry, 'en', 'es', [], []);
        $this->assertSame('Any siblings at the school?', $context[0]['label']);
        $this->assertSame('¿En qué curso están?', $context[1]['label']);
        $this->assertTrue($context[1]['hidden']);
        $this->assertSame('yes', $context[1]['showvalue']);

        // Without siblings, the question about their course is not asked.
        $event = forms::as_event($entry);
        $data = $this->data(['answers' => [$siblings => 'no', $course => '', $lunch => ['Comedor', 'Aula matinal']]]);
        $this->assertSame([], signup::check($data, $event, $this->centre, 'es'));
        $data['answers'][$siblings] = 'yes';
        $this->assertArrayHasKey('f' . $course, signup::check($data, $event, $this->centre, 'es'));
        $data['answers'][$course] = '2.º de Primaria';
        $data['answers'][$lunch] = ['Piscina'];
        $this->assertArrayHasKey('f' . $lunch, signup::check($data, $event, $this->centre, 'es'), 'Only its own answers.');
        $data['answers'][$lunch] = ['Comedor'];
        $this->assertSame([], signup::check($data, $event, $this->centre, 'es'));

        [$household, $token] = signup::save($data, $event, $this->centre, 'es', $entry, 'Cartel de la puerta');
        $this->assertSame(0, $DB->count_records('local_oksigeniaeguahe_signup'), 'A form signs up for nothing.');
        $contact = $DB->get_record(households::CONTACTS, ['householdid' => $household->id]);
        $this->assertSame('webform', $contact->channel);
        $this->assertSame((int) $entry->id, (int) $contact->entryid);
        $this->assertSame('Cartel de la puerta', $contact->detail);
        $this->assertStringContainsString('¿En qué curso están?: 2.º de Primaria', $contact->body);
        $this->assertGreaterThan(0, (int) households::get((int) $household->id, (int) $this->centre->id)->waitingsince);
        $answers = forms::answers_of((int) $household->id);
        $this->assertCount(3, $answers);
        $page = familypage::context($household, $this->centre, 'es', $token, true);
        $this->assertTrue($page['hasanswers']);
        $this->assertSame(['label' => '¿Tiene hermanos en el centro?', 'value' => get_string('yes')], $page['answers'][0]);
        $this->assertCount(3, households::export($household)['form_answers']);
        households::erase($household);
        $this->assertSame(0, $DB->count_records(forms::ANSWERS));
    }

    /**
     * The answers of a Google form: each question is recognised by its words, the family is found again by its email,
     * nothing comes in twice, and what was erased does not come back.
     */
    public function test_google_answers(): void {
        global $DB;
        $entry = entries::get(entries::create((int) $this->centre->id, 'google', 'Formulario de Google'), (int) $this->centre->id);
        $answers = [
            'Marca temporal' => '2026-11-20 18:04:00',
            'Nombre y apellidos del padre, madre o tutor' => 'Marta Ruiz Cabrera',
            'Correo electrónico' => 'Marta.Ruiz@Example.com',
            'Teléfono móvil' => '611 222 333',
            'Nombre del alumno' => 'Pablo Ruiz',
            'Curso que solicita' => 'Infantil 3 años',
            '¿Cómo nos conociste?' => 'Por Instagram',
            'Municipio' => 'Leganés',
            'Acepto recibir información por WhatsApp' => 'Sí',
            '¿Alguna alergia?' => 'Ninguna',
            '¿Qué es lo que más os importa?' => 'Que sea cerca de casa',
        ];
        [$household, $new, $token] = entries::receive($entry, $answers, 'resp-1');
        $this->assertTrue($new);
        $this->assertNotNull($token);
        $full = households::full($household);
        $adult = reset($full->adults);
        $this->assertSame('Marta', $adult->firstname);
        $this->assertSame('Ruiz Cabrera', $adult->lastname);
        $this->assertSame('marta.ruiz@example.com', $adult->email);
        $child = reset($full->children);
        $this->assertSame('Pablo', $child->firstname, 'Only the first name of a child.');
        $this->assertSame($this->infantil, (int) $child->offerid);
        $this->assertSame('social', $full->heardfrom);
        $this->assertSame('28074', (string) $full->municipality);
        $this->assertSame(['whatsapp' => true], array_map('boolval', households::permissions((int) $household->id)[$adult->id]));
        $body = $DB->get_field(households::CONTACTS, 'body', ['householdid' => $household->id]);
        $this->assertStringContainsString('¿Qué es lo que más os importa?: Que sea cerca de casa', $body);
        $this->assertStringNotContainsString('alergia', $body, 'Health is not kept.');
        $this->assertSame('googleforms', $full->firstchannel);

        // The same answer again: nothing new. The same family through another answer: the same household.
        [, $again] = entries::receive($entry, $answers, 'resp-1');
        $this->assertFalse($again);
        [$same, $new2] = entries::receive(
            $entry,
            ['Email' => 'marta.ruiz@example.com', 'Pregunta' => 'Y el horario?'],
            'resp-2'
        );
        $this->assertTrue($new2);
        $this->assertSame((int) $household->id, (int) $same->id);
        $this->assertSame(1, $DB->count_records(households::T));

        // An answer from long ago comes in without waiting for an answer.
        [$old] = entries::receive(
            $entry,
            ['Email' => 'ana@example.com', 'Nombre' => 'Ana'],
            'resp-0',
            time() - 60 * DAYSECS,
            true
        );
        $this->assertSame(0, (int) $old->waitingsince);

        // Nobody to answer: nothing comes in.
        [$nobody] = entries::receive($entry, ['Comentario' => 'Hola'], 'resp-3');
        $this->assertNull($nobody);

        // Erased, it stays erased even if the old answers are sent again.
        households::erase($household);
        [$back] = entries::receive($entry, $answers, 'resp-1');
        $this->assertNull($back);
        $this->assertSame(
            ['Marca temporal', 'Nombre y apellidos del padre, madre o tutor'],
            array_slice(entries::questions(entries::get((int) $entry->id, (int) $this->centre->id)), 0, 2)
        );
    }

    /**
     * What the team says a question is wins over its words; the script carries the address and the key.
     */
    public function test_roles_and_script(): void {
        $entry = entries::get(entries::create((int) $this->centre->id, 'google', 'Hoja de la agencia'), (int) $this->centre->id);
        $this->assertSame('notes', entries::roles($entry, ['Observaciones'])['Observaciones']);
        entries::set_roles($entry, ['Observaciones' => 'ignore']);
        $this->assertSame('ignore', entries::roles($entry, ['Observaciones'])['Observaciones']);
        $script = entries::apps_script($entry, 'sheet');
        $this->assertStringContainsString(json_encode((string) $entry->secret), $script);
        $this->assertStringContainsString('hook.php?e=' . $entry->token, $script);
        $this->assertStringContainsString('function eguaheCheck()', $script);
        $this->assertStringNotContainsString('FormApp', $script);
        $this->assertStringContainsString('FormApp', entries::apps_script($entry, 'form'));

        // Signed with its key, and only with it.
        $body = '{"answers":{"Email":"a@example.com"}}';
        $this->assertTrue(entries::signed($entry, $body, 'sha256=' . hash_hmac('sha256', $body, $entry->secret)));
        $this->assertFalse(entries::signed($entry, $body, 'sha256=' . hash_hmac('sha256', $body, 'otra')));
        $this->assertFalse(entries::signed($entry, $body, ''));
        entries::renew_secret($entry);
        $this->assertFalse(entries::signed($entry, $body, 'sha256=' . hash_hmac('sha256', $body, 'x')));

        // The mark of a page of a form, instead of a session.
        $form = entries::get(entries::create((int) $this->centre->id, 'form', 'Web'), (int) $this->centre->id);
        $this->assertTrue(entries::mark_ok($form, entries::mark($form)));
        $this->assertFalse(entries::mark_ok($form, entries::mark($form, time() - 2 * DAYSECS)));
        $this->assertFalse(entries::mark_ok($form, 'nada'));
    }

    /**
     * The contacts of Meta ads, as they are downloaded: UTF-16 with tabs, each lead once, with its campaign.
     */
    public function test_meta_export(): void {
        global $DB;
        $entry = entries::get(entries::create((int) $this->centre->id, 'meta', 'Anuncios'), (int) $this->centre->id);
        $tsv = "id\tcreated_time\tad_name\tcampaign_name\tplatform\t¿en_qué_etapa_estaría_tu_hijo?\tfull_name\temail\t" .
            "phone_number\tlead_status\n" .
            "l:111\t2026-11-01T10:00:00+01:00\tAnuncio jornada\tCaptación 27/28\tig\tInfantil 3 años\tLuis Pérez Gil\t" .
            "luis@example.com\tp:+34600111222\tcreated\n" .
            "l:112\t2026-11-02T10:00:00+01:00\tAnuncio jornada\tCaptación 27/28\tfb\tPrimaria\tSara Díaz\t\t" .
            "p:+34600333444\tcreated\n";
        $content = "\xFF\xFE" . mb_convert_encoding($tsv, 'UTF-16LE', 'UTF-8');
        $sheets = [['name' => 'leads', 'rows' => sheets::csv($content)]];
        [$rows, $headers] = entries::rows_of($sheets);
        $this->assertCount(2, $rows);
        $this->assertContains('full_name', $headers);
        $this->assertSame(['new' => 2, 'already' => 0, 'nobody' => 0], entries::from_rows($entry, $rows));
        $this->assertSame(['new' => 0, 'already' => 2, 'nobody' => 0], entries::from_rows($entry, $rows));
        $contact = $DB->get_record_sql('SELECT c.* FROM {' . households::CONTACTS . '} c JOIN {' . households::ADULTS .
            '} a ON a.householdid = c.householdid WHERE a.email = ?', ['luis@example.com']);
        $this->assertSame('meta', $contact->channel);
        $this->assertSame('Instagram · Captación 27/28 · Anuncio jornada', $contact->detail);
        $this->assertSame('+34600333444', $DB->get_field(households::ADULTS, 'phone', ['firstname' => 'Sara']));
    }

    /**
     * The mail of receipt, and the answer a person of the team writes, as it was written.
     */
    public function test_received_and_reply_mails(): void {
        $entry = entries::get(entries::create((int) $this->centre->id, 'hook', 'Web a medida'), (int) $this->centre->id);
        [$household, , $token] = entries::receive($entry, ['Nombre' => 'Marta', 'Email' => 'marta@example.com'], 'x1');
        [$subject] = familymail::build('received', $household, $this->centre, null, 'es', $token);
        $this->assertSame('Hemos recibido su mensaje', $subject);
        [$subject, $html, $text] = familymail::build(
            'reply',
            $household,
            $this->centre,
            null,
            'es',
            $token,
            "Hola, Marta:\n\nSí hay comedor.\nY aula matinal desde las 7:30."
        );
        $this->assertSame('Respuesta de Colegio de ejemplo', $subject);
        $this->assertStringContainsString('Sí hay comedor.<br>', $html);
        $this->assertStringContainsString('Y aula matinal desde las 7:30.', $text);
    }

    /**
     * The data of a sign-up, as signup::read() gives it.
     *
     * @param array $changes
     * @return array
     */
    protected function data(array $changes = []): array {
        return $changes + [
            'adults' => [['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@example.com', 'phone' => ''],
                ['firstname' => '', 'lastname' => '', 'email' => '', 'phone' => '']],
            'children' => [['firstname' => 'Leonardo', 'tagname' => 'Leo', 'offerid' => 0],
                ['firstname' => '', 'tagname' => '', 'offerid' => 0]],
            'people' => 2, 'slot' => 0, 'incentre' => '', 'municipality' => '', 'municipalityother' => '', 'heardfrom' => '',
            'message' => '', 'consents' => ['email' => 1, 'whatsapp' => 0, 'phone' => 0], 'altcha' => 'x',
            'days' => [], 'periods' => [], 'answers' => [],
        ];
    }
}
