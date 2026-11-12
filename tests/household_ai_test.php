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

use local_oksigeniaeguahe\local\ai;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\household_ai;
use local_oksigeniaeguahe\local\households;

/**
 * The AI of the team for each family (0.58.0): a draft that a person reads and sends, where the family is in facts,
 * and what each message asks in a line; never with contact data, never a judgement, and nothing without the AI of the
 * centre on. With example data and an AI that answers in the tests.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(household_ai::class)]
final class household_ai_test extends \advanced_testcase {
    /**
     * Forgets the AI of the tests.
     */
    protected function tearDown(): void {
        ai::$answer = null;
        parent::tearDown();
    }

    /**
     * A centre with its AI on and a family that wrote, with an email and a phone in what it wrote.
     *
     * @return array [centre, household]
     */
    protected function family(): array {
        global $DB;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $DB->set_field(centres::T, 'aion', 1, ['id' => $id]);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com']);
        households::add_child($household, 'Leonardo', $offer);
        $DB->insert_record(households::CONTACTS, (object) ['centreid' => $id, 'householdid' => $household->id,
            'direction' => 'in', 'channel' => 'email', 'body' => '¿Hay comedor en Infantil? Escríbanme a ana@example.com ' .
            'o al 600 123 456.', 'timecreated' => time() - HOURSECS]);
        return [centres::get($id), households::get((int) $household->id, $id)];
    }

    /**
     * The draft and the summary: what the AI reads has no email or phone, the summary is kept for the file, and the
     * rules say never to judge the family.
     */
    public function test_draft(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $household] = $this->family();
        $asked = '';
        ai::$answer = function (string $prompt) use (&$asked) {
            $asked = $prompt;
            return '{"draft": "Sí, hay comedor en Infantil.", "summary": "Leo, para Infantil 3 años. Preguntan por el comedor."}';
        };
        $result = household_ai::draft($household, $centre, 2, '', time());
        $this->assertSame('Sí, hay comedor en Infantil.', $result['draft']);
        $this->assertStringContainsString('¿Hay comedor en Infantil?', $asked);
        $this->assertStringNotContainsString('ana@example.com', $asked);
        $this->assertStringNotContainsString('600 123 456', $asked);
        $this->assertStringContainsString('never guess what they think or whether they will enrol', $asked);
        $this->assertStringContainsString('never «vosotros»', $asked);
        $kept = $DB->get_record(households::T, ['id' => $household->id]);
        $this->assertSame('Leo, para Infantil 3 años. Preguntan por el comedor.', $kept->aisummary);
        $this->assertGreaterThan(0, (int) $kept->aisummarytime);

        // What the person began to write goes with it, to be finished.
        household_ai::draft($household, $centre, 2, 'Hola, Ana: les cuento que', time());
        $this->assertStringContainsString('les cuento que', $asked);

        // Without the AI of the centre, nothing is asked.
        $DB->set_field(centres::T, 'aion', 0, ['id' => $centre->id]);
        $asked = '';
        $this->assertNull(household_ai::draft($household, centres::get((int) $centre->id), 2, '', time()));
        $this->assertSame('', $asked);
    }

    /**
     * What each new message of a family asks, in a line, once; «-» is nothing to say.
     */
    public function test_read_messages(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $household] = $this->family();
        $calls = 0;
        ai::$answer = function (string $prompt) use (&$calls) {
            $calls++;
            return str_contains($prompt, 'Gracias') ? '-' : 'Pregunta por el comedor de Infantil';
        };
        $DB->insert_record(households::CONTACTS, (object) ['centreid' => $centre->id, 'householdid' => $household->id,
            'direction' => 'in', 'channel' => 'email', 'body' => 'Gracias.', 'timecreated' => time()]);
        $this->assertSame(2, household_ai::read_messages(time()));
        $lines = $DB->get_fieldset_select(households::CONTACTS, 'asks', 'householdid = ? ORDER BY id', [$household->id]);
        $this->assertSame(['Pregunta por el comedor de Infantil', ''], $lines);
        $this->assertSame(0, household_ai::read_messages(time()), 'Each message once.');
        $this->assertSame(2, $calls);
    }
}
