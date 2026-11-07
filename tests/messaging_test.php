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
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\messaging;

/**
 * Telegram and WhatsApp with Oksigenia Marandu: without it nothing shows and nothing fails; with it, «notices only»
 * answers where to talk to the centre and «notices and conversation» brings what the family writes to its record.
 * Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\messaging
 */
#[\PHPUnit\Framework\Attributes\CoversClass(messaging::class)]
final class messaging_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $household;

    /**
     * A centre and a family, with example data.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'phone' => '+34 900 000 000', 'email' => 'admisiones@example.com']);
        $this->centre = centres::get($id);
        $this->household = households::get(
            households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']),
            $id
        );
        households::add_adult($this->household, (object) ['firstname' => 'Marta', 'email' => 'marta@example.com']);
    }

    /**
     * Without Marandu, or with the centre that does not want it: nothing to join, nothing sent, nothing on the page.
     */
    public function test_without_marandu_or_off(): void {
        global $DB;
        if (messaging::available()) {
            $DB->set_field(centres::T, 'messaging', 'off', ['id' => $this->centre->id]);
            $this->centre = centres::get((int) $this->centre->id);
        }
        $this->assertSame('off', messaging::mode($this->centre));
        $this->assertSame([[], false], messaging::join($this->household, $this->centre));
        $this->assertFalse(messaging::joined($this->household, $this->centre));
        $this->assertSame(0, messaging::notify('confirmation', $this->household, $this->centre, null, 'es'));
        $this->assertNull(messaging::reply($this->household, $this->centre, 'Hola'));
        $page = familypage::context($this->household, $this->centre, 'es', 'x', false);
        $this->assertFalse($page['hasjoin']);
        $this->assertFalse($page['joined']);
    }

    /**
     * With Marandu: «notices only» answers where to talk to the centre, and nobody has to read it.
     */
    public function test_notices_only(): void {
        global $DB;
        if (!messaging::available()) {
            $this->markTestSkipped('Oksigenia Marandu is not installed.');
        }
        $this->assertSame('notices', messaging::mode($this->centre));
        $hook = $this->message('¿A qué hora es la jornada?');
        messaging::message_received($hook);
        $this->assertStringContainsString('+34 900 000 000', (string) $hook->get_reply());
        $this->assertStringContainsString('admisiones@example.com', (string) $hook->get_reply());
        $this->assertSame(0, $DB->count_records(households::CONTACTS, ['householdid' => $this->household->id]));
        // A message for another plugin is not ours.
        $other = new \local_oksigeniamarandu\hook\message_received(
            'local_oksigeniameals',
            (int) $this->household->id,
            null,
            'telegram',
            'tg:1',
            'text',
            'Hola',
            time(),
            'tg.1.1',
            null
        );
        messaging::message_received($other);
        $this->assertNull($other->get_reply());
    }

    /**
     * With Marandu and «notices and conversation»: what the family writes comes into its record and waits for a person.
     */
    public function test_conversation(): void {
        global $DB;
        if (!messaging::available()) {
            $this->markTestSkipped('Oksigenia Marandu is not installed.');
        }
        $DB->set_field(centres::T, 'messaging', 'conversation', ['id' => $this->centre->id]);
        $hook = $this->message('¿Hay plazas en 1.º de Primaria?');
        messaging::message_received($hook);
        $this->assertTrue($hook->is_handled());
        $this->assertNull($hook->get_reply());
        $contact = $DB->get_record(households::CONTACTS, ['householdid' => $this->household->id]);
        $this->assertSame('telegram', $contact->channel);
        $this->assertSame('¿Hay plazas en 1.º de Primaria?', $contact->body);
        $this->assertGreaterThan(0, (int) households::get((int) $this->household->id, (int) $this->centre->id)->waitingsince);
        // A photo without words still says what came.
        $photo = new \local_oksigeniamarandu\hook\message_received(
            'local_oksigeniaeguahe',
            (int) $this->household->id,
            null,
            'whatsapp',
            '+34600111222',
            'image',
            '',
            time(),
            'wamid.1',
            'Marta'
        );
        messaging::message_received($photo);
        $this->assertSame(1, $DB->count_records(households::CONTACTS, ['householdid' => $this->household->id,
            'channel' => 'whatsapp']));
    }

    /**
     * A message of the family to the code of its household, on Telegram.
     *
     * @param string $text
     * @return \local_oksigeniamarandu\hook\message_received
     */
    protected function message(string $text) {
        return new \local_oksigeniamarandu\hook\message_received(
            'local_oksigeniaeguahe',
            (int) $this->household->id,
            null,
            'telegram',
            'tg:12345',
            'text',
            $text,
            time(),
            'tg.12345.1',
            'Marta'
        );
    }
}
