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

use local_oksigeniaeguahe\local\brief;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\signup;
use local_oksigeniaeguahe\local\workdays;

/**
 * Tests for the brief form inside the web of a centre.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\brief
 */
#[\PHPUnit\Framework\Attributes\CoversClass(brief::class)]
final class brief_test extends \advanced_testcase {
    /**
     * A centre with a form: a long question that must be answered, and one of buttons.
     *
     * @return array [centre, entry]
     */
    protected function form(): array {
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES', 'replydays' => 2]);
        centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1]);
        $entry = entries::get(entries::create($id, 'form', 'La web'), $id);
        forms::save_field($entry, (object) ['type' => 'longtext', 'required' => 1, 'label_es' => 'Cuéntennos']);
        forms::save_field($entry, (object) ['type' => 'choice', 'required' => 1, 'label_es' => '¿Cómo quieren conocernos?',
            'options_es' => "Jornada de puertas abiertas\nUn día de clase\nPor videollamada", 'label_en' => 'How?',
            'options_en' => "Open day\nA day in class\nBy video call"]);
        return [centres::get($id), $entry];
    }

    /**
     * What a family writes.
     *
     * @param array $adult
     * @param string $child
     * @return array As signup::read() gives it.
     */
    protected function sent(array $adult, string $child): array {
        $data = signup::read();
        $data['adults'][0] = $adult + $data['adults'][0];
        $data['children'][0]['firstname'] = $child;
        return $data;
    }

    /**
     * The question of buttons, and the option the web asks for by a word.
     */
    public function test_question(): void {
        $this->resetAfterTest();
        [, $entry] = $this->form();
        $question = brief::question((int) $entry->id, 'es');
        $this->assertSame('¿Cómo quieren conocernos?', forms::texts($question, 'es', 'es')['label']);
        $this->assertSame('Un día de clase', brief::option($question, 'un-dia', 'es'));
        $this->assertSame('Un día de clase', brief::option($question, 'a-day-in-class', 'es'));
        $this->assertSame('Por videollamada', brief::option($question, 'Por Videollamada', 'es'));
        $this->assertSame('Por videollamada', brief::option($question, 'videollamada', 'es'));
        $this->assertSame('', brief::option($question, 'llamada', 'es'));
        $this->assertSame('', brief::option($question, 'otra-cosa', 'es'));
        $this->assertSame('', brief::option($question, '', 'es'));
        $this->assertNull(brief::question(0, 'es'));
    }

    /**
     * Nothing that is not in sight is asked for, no permission is written, and the family is in the inbox.
     */
    public function test_form(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $entry] = $this->form();
        $event = forms::as_event($entry);
        $question = brief::question((int) $entry->id, 'es');

        // Empty, everything that is missing is said at once, and only of what is in sight.
        $data = brief::data($this->sent([], ''), $event, $question);
        $errors = brief::errors(signup::check($data, $event, $centre, 'es'), $question, $data);
        $this->assertEqualsCanonicalizing(['a0first', 'a0phone', 'f' . $question->id], array_keys($errors));
        $context = brief::context(
            $event,
            $centre,
            'es',
            $data,
            $errors,
            new \moodle_url('/local/oksigeniaeguahe/w.php'),
            entries::mark($entry),
            $question
        );
        $this->assertSame(['a0first', 'c0name', 'a0phone', 'a0email', 'c0offer', 'message'], array_column(
            $context['fields'],
            'name'
        ));
        $this->assertSame('Teléfono o correo', $context['fields'][2]['error']);
        $this->assertSame('Falta elegir una', $context['ask']['error']);

        // With a phone, it is enough; the long question that is not in sight stops nobody.
        $data = $this->sent(['firstname' => 'Marta', 'phone' => '600 111 222'], 'Leo');
        $data['answers'] = [$question->id => 'Un día de clase'];
        $data = brief::data($data, $event, $question);
        $this->assertSame([], brief::errors(signup::check($data, $event, $centre, 'es'), $question, $data));
        [$household, , , $existed] = signup::save($data, $event, $centre, 'es', $entry, '');
        $this->assertFalse($existed);
        $this->assertSame([], households::permissions((int) $household->id));
        $body = $DB->get_field(households::CONTACTS, 'body', ['householdid' => $household->id]);
        $this->assertStringContainsString('¿Cómo quieren conocernos?: Un día de clase', $body);
        $this->assertNotEmpty($DB->get_field(households::T, 'waitingsince', ['id' => $household->id]));
        $this->assertSame(
            'La respuesta llega ' . workdays::promise($centre, time(), 'es') . '.',
            brief::sent($centre, 'es', null)['sent']['text']
        );
    }

    /**
     * An act: no robot and no ways to write are asked; the confirmation goes by what they gave.
     */
    public function test_act(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre] = $this->form();
        $start = time() + 5 * DAYSECS;
        $event = events::get(events::save((int) $centre->id, (object) ['kind' => 'other', 'name' => 'Charla',
            'timestart' => $start, 'timeend' => $start + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $centre->id);
        $data = brief::data($this->sent(['firstname' => 'Ana', 'email' => 'ana@example.com'], ''), $event, null);
        $this->assertSame([], brief::errors(signup::check($data, $event, $centre, 'es', true), null, $data));
        [$household] = signup::save($data, $event, $centre, 'es');
        $said = array_values(households::permissions((int) $household->id))[0];
        $this->assertSame([true, false], [!empty($said['email']), !empty($said['phone'])]);
        $this->assertSame('Inscripción hecha. Gracias.', brief::sent($centre, 'es', null, $event)['sent']['title']);
    }

    /**
     * Too soon, the trap filled or by the dozen: not of a person.
     */
    public function test_person(): void {
        $this->resetAfterTest();
        $now = time();
        $mark = ($now - 10) . '-abc';
        $this->assertTrue(brief::person($mark, '', 7, '192.0.2.1', $now));
        $this->assertFalse(brief::person($now . '-abc', '', 7, '192.0.2.1', $now));
        $this->assertFalse(brief::person($mark, 'http://spam.example', 7, '192.0.2.1', $now));
        for ($i = 1; $i < brief::PERHOUR; $i++) {
            $this->assertTrue(brief::person($mark, '', 7, '192.0.2.1', $now));
        }
        $this->assertFalse(brief::person($mark, '', 7, '192.0.2.1', $now));
        $this->assertTrue(brief::person($mark, '', 7, '192.0.2.2', $now));
        $this->assertTrue(brief::person($mark, '', 8, '192.0.2.1', $now));
    }

    /**
     * Only the name of the adult and a way to answer; the name of the student is for whoever wants to say it, and they may be
     * two or more.
     */
    public function test_name_and_a_way(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $entry] = $this->form();
        $event = forms::as_event($entry);
        $data = brief::data($this->sent(['firstname' => 'Marta', 'email' => 'marta@example.com'], ''), $event, null);
        $this->assertSame([], brief::errors(signup::check($data, $event, $centre, 'es', true), null, $data), 'Its question was not asked.');
        $url = new \moodle_url('/local/oksigeniaeguahe/w.php');
        $context = brief::context($event, $centre, 'es', $data, [], $url, entries::mark($entry), null);
        $this->assertFalse($context['ask'], 'The web had its own buttons (sinpregunta).');
        $this->assertSame('Basta con tu nombre y un teléfono o un correo.', $context['hint']);
        $fields = array_column($context['fields'], null, 'name');
        $this->assertSame('Tu nombre', $fields['a0first']['label']);
        $this->assertSame('', $fields['c0name']['need']);
        $this->assertCount(signup::CHILDREN - 1, $context['others']);
        $this->assertFalse($context['others'][0]['open']);
        $this->assertSame(['c1name', 'c1offer'], array_column($context['others'][0]['fields'], 'name'));
        $data['children'][1]['firstname'] = 'Luis';
        $context = brief::context($event, $centre, 'es', $data, [], $url, entries::mark($entry), null);
        $this->assertTrue($context['others'][0]['open']);
        $this->assertStringContainsString('Prueben en un minuto', $context['unsent']);

        // Of an act, how many come is asked there too.
        $start = time() + 5 * DAYSECS;
        $act = events::get(events::save((int) $centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada',
            'timestart' => $start, 'timeend' => $start + HOURSECS, 'capacity' => 3, 'open' => 1]), (int) $centre->id);
        $context = brief::context($act, $centre, 'es', $data, [], $url, events::mark($act), null);
        $this->assertSame('people', $context['people']['name']);
        // A person of the team is asked whether it is a test.
        $context = brief::context($act, $centre, 'es', $data, ['team' => '¿Es una prueba?'], $url, events::mark($act), null);
        $this->assertSame('Sí, es una prueba', $context['team']['yes']);
        $this->assertTrue($context['hinterror']);
    }

    /**
     * The options by the words the web may name them with, the same the server looks at.
     */
    public function test_words(): void {
        $this->resetAfterTest();
        [, $entry] = $this->form();
        $words = brief::words(brief::question((int) $entry->id, 'es'), 'es');
        $this->assertContains(['jornada-de-puertas-abiertas', 'un-dia-de-clase', 'por-videollamada'], $words);
        $this->assertContains(['open-day', 'a-day-in-class', 'by-video-call'], $words);
    }

    /**
     * An hour is an hour: what is refused does not make it longer.
     */
    public function test_an_hour(): void {
        $this->resetAfterTest();
        $now = time();
        $mark = fn(int $at) => ($at - 10) . '-abc';
        for ($i = 0; $i < brief::PERHOUR; $i++) {
            $this->assertTrue(brief::person($mark($now), '', 7, '192.0.2.9', $now));
        }
        $this->assertFalse(brief::person($mark($now + 50 * MINSECS), '', 7, '192.0.2.9', $now + 50 * MINSECS));
        $this->assertTrue(brief::person($mark($now + 61 * MINSECS), '', 7, '192.0.2.9', $now + 61 * MINSECS));
    }

    /**
     * Where what nobody sees was filled, pressing again does nothing: how to reach the centre is said.
     */
    public function test_refused(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES', 'phone' => '+34 900 000 014']);
        $centre = centres::get($id);
        $now = time();
        $this->assertStringContainsString('+34 900 000 014', brief::refused(($now - 10) . '-a', 'x', $now, $centre, 'es'));
        $this->assertStringContainsString('pulsar otra vez', brief::refused($now . '-a', '', $now, $centre, 'es'));
        $this->assertStringContainsString('muchas inscripciones', brief::refused(($now - 10) . '-a', '', $now, $centre, 'es'));
        $none = clone $centre;
        $none->phone = '';
        $none->email = '';
        $this->assertSame('', brief::reach_words($none, 'es'));
    }
}
