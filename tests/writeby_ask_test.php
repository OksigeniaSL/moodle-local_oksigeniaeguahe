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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\joinpage;
use local_oksigeniaeguahe\local\writeby;

/**
 * Marking WhatsApp on a page of the family: it is asked there, once, with one touch. With Oksigenia Marandu and example data;
 * nothing goes to Meta here.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\writeby
 */
#[\PHPUnit\Framework\Attributes\CoversClass(writeby::class)]
final class writeby_ask_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $household;

    /**
     * A centre with WhatsApp and a family that gave its phone, with example data.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        if (!method_exists('\local_oksigeniamarandu\api', 'ask_to_join')) {
            $this->markTestSkipped('Oksigenia Marandu 0.65 is not installed.');
        }
        foreach (
            ['phonenumberid' => '100000000000001', 'accesstoken' => 'x', 'displayphone' => '+34 600 000 000',
                'telegramtoken' => 'x', 'telegrambot' => 'example_bot'] as $name => $value
        ) {
            set_config($name, $value, 'local_oksigeniamarandu');
        }
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        $this->household = households::get(
            households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']),
            $id
        );
        $adult = households::add_adult($this->household, (object) ['firstname' => 'Ana', 'email' => 'ana@example.com',
            'phone' => '+34600111222']);
        households::consent($this->household, $adult, 'email', true, 'form', 'es');
    }

    /**
     * The question, as Marandu keeps it.
     *
     * @param string $status asked, yes, no or failed.
     */
    protected function question(string $status): void {
        global $DB;
        $DB->insert_record('local_oksigeniamarandu_joinask', (object) ['component' => 'local_oksigeniaeguahe', 'area' => '',
            'itemid' => (int) $this->household->id, 'phone' => '34600111222', 'code' => 'EJEMPLO1', 'status' => $status,
            'consentsource' => 'eguahe:family', 'consentat' => time(), 'timeasked' => time(), 'timeanswered' => time(),
            'timemodified' => time()]);
    }

    /**
     * What the page of a letter says is left to be written to by the phones.
     *
     * @return array
     */
    protected function left(): array {
        $method = new \ReflectionMethod(joinpage::class, 'missing');
        $method->setAccessible(true);
        return $method->invoke(null, $this->household, $this->centre, 'es', writeby::now($this->household, $this->centre));
    }

    /**
     * Only a family that marks WhatsApp itself, right then, with a phone and not asked before.
     */
    public function test_who_is_asked(): void {
        $this->assertFalse(writeby::askable($this->household, $this->centre), 'It has marked nothing.');
        writeby::set($this->household, ['email'], 'family');
        $this->assertFalse(writeby::askable($this->household, $this->centre), 'Only email.');
        writeby::set($this->household, ['email', 'whatsapp'], 'team');
        $this->assertFalse(writeby::askable($this->household, $this->centre), 'The team noted it.');
        writeby::set($this->household, ['email'], 'family');
        writeby::set($this->household, ['email', 'whatsapp'], 'family');
        $this->assertTrue(writeby::askable($this->household, $this->centre));
        $later = time() + writeby::JUST + 5;
        $this->assertFalse(writeby::askable($this->household, $this->centre, $later), 'Opening the page later asks nothing.');
        $practice = clone($this->centre);
        $practice->practice = 1;
        $this->assertFalse(writeby::askable($this->household, $practice));
        $this->question('asked');
        $this->assertFalse(writeby::askable($this->household, $this->centre), 'Asked already.');
    }

    /**
     * Without a phone there is nobody to ask, and its button is there as always.
     */
    public function test_without_a_phone(): void {
        global $DB;
        $DB->set_field(households::ADULTS, 'phone', '', ['householdid' => $this->household->id]);
        $household = households::get((int) $this->household->id, (int) $this->centre->id);
        writeby::set($household, ['email', 'whatsapp'], 'family');
        $this->assertFalse(writeby::askable($household, $this->centre));
        $this->household = $household;
        $this->assertSame('Un paso más para WhatsApp', $this->left()['rows'][0]['label']);
    }

    /**
     * When WhatsApp cannot take it (its template is not approved here), the step is the one of always, and it is not tried at
     * each touch.
     */
    public function test_when_it_cannot_go(): void {
        writeby::set($this->household, ['email', 'whatsapp'], 'family');
        $this->assertSame('notready', writeby::ask($this->household, $this->centre));
        $this->assertSame('', writeby::ask($this->household, $this->centre));
        $row = $this->left()['rows'][0];
        $this->assertFalse($row['asked']);
        $this->assertSame('Un paso más para WhatsApp', $row['label']);
        $this->assertNotSame('', $row['url']);
    }

    /**
     * Asked: what to touch, on which phone, and the button for whoever does not get it; their page and the team see it too.
     */
    public function test_asked(): void {
        writeby::set($this->household, ['email', 'whatsapp', 'telegram'], 'family');
        $this->question('asked');
        $left = $this->left();
        $rows = array_column($left['rows'], null, 'open');
        $row = $rows['Abrir WhatsApp'];
        $this->assertTrue($row['asked']);
        $this->assertSame(['Miren su WhatsApp', get_string('yes')], [$row['label'], $row['yes']], 'The message goes in the language of the site.');
        $this->assertStringContainsString('222', $row['text']);
        $this->assertStringContainsString('¿No les ha llegado?', $row['planb']);
        $this->assertFalse($rows['Abrir Telegram']['asked']);
        $this->assertNotSame('', writeby::team_context($this->household, $this->centre, '')['asked']);
        $start = make_timestamp(2030, 12, 4, 15, 0);
        $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'other', 'word' => 'talk',
            'name' => 'Respira', 'timestart' => $start, 'timeend' => $start + HOURSECS, 'capacity' => 0, 'open' => 1,
            'free' => 2]), (int) $this->centre->id);
        $page = joinpage::known($event, $this->centre, $this->household, 'es', new \moodle_url('/'), 'm');
        $this->assertSame('¿Vienen?', $page['ask']);
        $this->assertFalse($page['askwhatsapp']);
    }

    /**
     * «No» to the question is a no to WhatsApp, not to being written to: email goes on, and the team reads that they said so.
     */
    public function test_no(): void {
        global $DB;
        writeby::set($this->household, ['whatsapp'], 'family');
        $this->question('no');
        writeby::from_messaging((int) $this->household->id, 'whatsapp', true, 'asked');
        $household = households::get((int) $this->household->id, (int) $this->centre->id);
        $this->assertSame(['email'], writeby::now($household, $this->centre));
        $this->assertFalse(writeby::silent($household));
        $this->assertFalse(writeby::askable($household, $this->centre));
        $notes = $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ?', [$household->id]);
        $this->assertNotEmpty(preg_grep('/WhatsApp/', $notes));
        // Marking it again asks nothing: it answered no to that phone.
        \cache::make('local_oksigeniaeguahe', 'writebyconfirm')->delete('ask_' . $household->id);
        writeby::set($household, ['email', 'whatsapp'], 'family');
        $this->assertFalse(writeby::askable($household, $this->centre));
    }
}
