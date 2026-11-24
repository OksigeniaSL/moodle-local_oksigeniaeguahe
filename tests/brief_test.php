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
        $this->assertEqualsCanonicalizing(['a0first', 'a0phone', 'c0name', 'f' . $question->id], array_keys($errors));
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
        $this->assertTrue($context['ask']['error']);

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
        $this->assertSame('Les contestamos en 2 días laborables.', brief::sent($centre, 'es', null)['sent']['text']);
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
}
