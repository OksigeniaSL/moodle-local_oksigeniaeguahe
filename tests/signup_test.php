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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\signup;

/**
 * A family signs up for an open day from its phone: the household, found again when it signs up twice; its children
 * and permissions; the room of the event; its private link; and its data, taken and erased.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\signup
 * @covers     \local_oksigeniaeguahe\local\households
 * @covers     \local_oksigeniaeguahe\local\events
 * @covers     \local_oksigeniaeguahe\task\erase_inactive
 */
#[\PHPUnit\Framework\Attributes\CoversClass(signup::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(households::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(events::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(task\erase_inactive::class)]
final class signup_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $event;

    /** @var int */
    protected $offerid;

    /**
     * A centre with one offer and an open day, with example data.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        antispam::$testpass = true;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'municipalities' => "28065;Getafe\n28074;Leganés"]);
        $this->centre = centres::get($id);
        $this->offerid = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1]);
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'place' => 'Entrada principal', 'timestart' => time() + DAYSECS, 'timeend' => time() + DAYSECS + 3 * HOURSECS,
            'capacity' => 10, 'open' => 1]);
        $this->event = events::get($eventid, $id);
    }

    /**
     * Resets the widget against spam.
     */
    protected function tearDown(): void {
        antispam::$testpass = null;
        parent::tearDown();
    }

    /**
     * What the form sends, with example data.
     *
     * @param array $changes
     * @return array
     */
    protected function data(array $changes = []): array {
        $data = [
            'adults' => [
                ['firstname' => 'Lucas', 'lastname' => 'Pérez Gil', 'email' => 'Lucas@Example.com', 'phone' => '600 111 222'],
                ['firstname' => '', 'lastname' => '', 'email' => '', 'phone' => ''],
            ],
            'children' => [
                ['firstname' => 'Daniel', 'tagname' => 'Dani', 'offerid' => $this->offerid],
                ['firstname' => '', 'offerid' => 0],
            ],
            'people' => 3,
            'slot' => 0,
            'incentre' => '',
            'municipality' => '28065',
            'municipalityother' => '',
            'heardfrom' => 'family',
            'message' => '',
            'consents' => ['email' => 1, 'whatsapp' => 1, 'phone' => 0],
            'altcha' => 'x',
        ];
        return array_replace($data, $changes);
    }

    /**
     * A first sign-up: the household with its adult, child, place, permissions, event and private link.
     */
    public function test_first_signup(): void {
        global $DB;
        $data = $this->data();
        $this->assertSame([], signup::check($data, $this->event, $this->centre, 'es'));
        [$household, $token] = signup::save($data, $this->event, $this->centre, 'es');

        $adult = $DB->get_record(households::ADULTS, ['householdid' => $household->id]);
        $this->assertSame('lucas@example.com', $adult->email);
        $this->assertSame('+34600111222', $adult->phone);
        $child = $DB->get_record(households::CHILDREN, ['householdid' => $household->id]);
        $this->assertSame('Daniel', $child->firstname);
        $this->assertSame('Dani', $child->tagname);
        $this->assertSame('inquiry', $child->stage);
        $this->assertEquals($this->offerid, $child->offerid);
        $household = households::get($household->id, $this->centre->id);
        $this->assertSame('28065', $household->municipality);
        $this->assertSame('Getafe', $household->municipalityname);
        $this->assertSame('family', $household->heardfrom);
        $this->assertSame('openday', $household->firstchannel);
        $this->assertEquals(0, $household->waitingsince, 'A sign-up without a question waits for nobody.');
        $this->assertSame(
            ['email' => true, 'whatsapp' => true, 'phone' => false],
            households::permissions($household->id)[$adult->id]
        );
        $this->assertSame(3, events::people($this->event->id));
        $this->assertEquals($household->id, households::by_link($token)->id);
    }

    /**
     * The same family signs up again with its phone written another way: one household, nothing duplicated, the new
     * child added, its question waiting, and the old link replaced.
     */
    public function test_same_family_again(): void {
        global $DB;
        [$first, $oldtoken] = signup::save($this->data(), $this->event, $this->centre, 'es');
        $again = $this->data(['message' => '¿Hay comedor?', 'people' => 4]);
        $again['adults'][0]['email'] = '';
        $again['adults'][0]['phone'] = '+34 600 11 12 22';
        $again['children'][1] = ['firstname' => 'Sara', 'offerid' => 0];
        [$second, $newtoken, $before, $existed] = signup::save($again, $this->event, $this->centre, 'es');
        $this->assertTrue($existed);
        $this->assertSame(['lucas@example.com'], $before, 'The link of a family known goes only to its email.');

        $this->assertEquals($first->id, $second->id);
        $this->assertSame(1, $DB->count_records(households::T));
        $this->assertSame(1, $DB->count_records(households::ADULTS));
        $this->assertSame(2, $DB->count_records(households::CHILDREN));
        $this->assertSame(4, events::people($this->event->id), 'The people of a household count once.');
        $this->assertGreaterThan(0, households::get($first->id, $this->centre->id)->waitingsince);
        $this->assertNotNull(households::by_link($oldtoken), 'The links sent before keep working.');
        $this->assertNotNull(households::by_link($newtoken));
    }

    /**
     * What is missing or wrong, by field; and an event without room.
     */
    public function test_check(): void {
        $data = $this->data();
        $data['adults'][0] = ['firstname' => '', 'lastname' => '', 'email' => 'no-es-un-correo', 'phone' => ''];
        $data['children'][0]['firstname'] = '';
        $errors = signup::check($data, $this->event, $this->centre, 'es');
        $this->assertArrayHasKey('a0first', $errors);
        $this->assertArrayHasKey('a0email', $errors);
        $this->assertArrayHasKey('c0name', $errors);

        $this->assertArrayHasKey('people', signup::check($this->data(['people' => 11]), $this->event, $this->centre, 'es'));
        antispam::$testpass = false;
        $this->assertArrayHasKey('altcha', signup::check($this->data(), $this->event, $this->centre, 'es'));
    }

    /**
     * Coming to the open day moves the way to the visit; never back.
     */
    public function test_attend(): void {
        global $DB;
        [$household] = signup::save($this->data(), $this->event, $this->centre, 'es');
        $signup = $DB->get_record('local_oksigeniaeguahe_signup', ['householdid' => $household->id]);
        events::attend($this->event, $signup->id, true);
        $this->assertSame('visit', $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $household->id]));

        $child = $DB->get_record(households::CHILDREN, ['householdid' => $household->id]);
        households::set_stage($child, 'enrolled');
        households::advance($household->id, 'visit');
        $this->assertSame('enrolled', $DB->get_field(households::CHILDREN, 'stage', ['id' => $child->id]));
    }

    /**
     * The family takes its data and erases it: nothing about it is left but an anonymous count.
     */
    public function test_export_and_erase(): void {
        global $DB;
        [$household, $token] = signup::save($this->data(['message' => '¿Hay comedor?']), $this->event, $this->centre, 'es');
        $export = households::export($household);
        $this->assertSame('Lucas', $export['adults'][0]['first_name']);
        $this->assertSame('Infantil 3 años', $export['children'][0]['asks_for']);
        $this->assertCount(1, $export['events']);

        households::erase($household);
        foreach (
            [households::T, households::ADULTS, households::CHILDREN, households::CONTACTS, households::CONSENTS,
                households::SIGNUPS] as $table
        ) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        $this->assertNull(households::by_link($token));
        $left = $DB->get_records(households::ERASED);
        $this->assertCount(1, $left);
        $this->assertSame('inquiry', reset($left)->stage);
    }

    /**
     * A household with no activity for longer than its centre keeps data is erased; an enrolled one stays.
     */
    public function test_erase_inactive(): void {
        global $DB;
        [$old] = signup::save($this->data(), $this->event, $this->centre, 'es');
        $other = $this->data();
        $other['adults'][0] = ['firstname' => 'Ana', 'lastname' => 'Gil', 'email' => 'ana@example.com', 'phone' => ''];
        [$enrolled] = signup::save($other, $this->event, $this->centre, 'es');
        $DB->set_field(households::T, 'lastcontact', time() - 25 * 31 * DAYSECS);
        $child = $DB->get_record(households::CHILDREN, ['householdid' => $enrolled->id]);
        households::set_stage($child, 'enrolled');

        (new task\erase_inactive())->execute();
        $this->assertNull(households::get($old->id, $this->centre->id));
        $this->assertNotNull(households::get($enrolled->id, $this->centre->id));
        $this->assertSame(1, $DB->count_records(households::ERASED));
    }

    /**
     * An event by turns: each turn with its room, the family chooses one, and the calendar has its hours.
     */
    public function test_turns(): void {
        global $DB;
        $start = time() + 2 * DAYSECS;
        $id = events::save($this->centre->id, (object) ['kind' => 'visit', 'name' => 'Conócenos', 'timestart' => $start,
            'timeend' => $start, 'capacity' => 0, 'open' => 1, 'slots' => [
                ['timestart' => $start, 'timeend' => $start + HOURSECS, 'capacity' => 4],
                ['timestart' => $start + HOURSECS, 'timeend' => $start + 2 * HOURSECS, 'capacity' => 4],
            ]]);
        $event = events::get($id, $this->centre->id);
        $this->assertEquals($start + 2 * HOURSECS, $event->timeend, 'The event spans its turns.');
        $slots = array_values(events::slots($id));
        $this->assertCount(2, $slots);

        $this->assertArrayHasKey('slot', signup::check($this->data(), $event, $this->centre, 'es'));
        $data = $this->data(['slot' => $slots[0]->id, 'people' => 3]);
        $this->assertSame([], signup::check($data, $event, $this->centre, 'es'));
        [$household, $token] = signup::save($data, $event, $this->centre, 'es');
        $this->assertEquals($slots[0]->id, $DB->get_field(events::SIGNUPS, 'slotid', ['householdid' => $household->id]));
        $this->assertSame(3, (int) array_values(events::slots($id))[0]->people);

        $more = $this->data(['slot' => $slots[0]->id, 'people' => 2]);
        $more['adults'][0] = ['firstname' => 'Ana', 'lastname' => '', 'email' => 'ana@example.com', 'phone' => ''];
        $this->assertArrayHasKey('slot', signup::check($more, $event, $this->centre, 'es'), 'The first turn is full.');
        $more['slot'] = $slots[1]->id;
        $this->assertSame([], signup::check($more, $event, $this->centre, 'es'));

        $when = events::when($event, (int) $slots[1]->id);
        $this->assertEquals($start + HOURSECS, $when->timestart);
        $ics = events::ics($event, $this->centre, '', $when);
        $this->assertStringContainsString('DTSTART:' . gmdate('Ymd\THis\Z', $start + HOURSECS), $ics);

        // A turn nobody signed up for goes; one with families stays.
        events::save_slots($event, [['id' => $slots[0]->id, 'timestart' => 0], ['id' => $slots[1]->id, 'timestart' => 0]]);
        $this->assertCount(1, events::slots($id));
    }

    /**
     * Another event (a party, a talk): who would study is not asked for, coming does not make a visit, and families
     * already of the centre stay out of the numbers.
     */
    public function test_other_event(): void {
        global $DB;
        $id = events::save($this->centre->id, (object) ['kind' => 'other', 'name' => 'Charla', 'timestart' => time() + DAYSECS,
            'timeend' => time() + DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]);
        $event = events::get($id, $this->centre->id);
        $nochild = $this->data(['incentre' => 'yes']);
        $nochild['children'][0]['firstname'] = '';
        $this->assertSame([], signup::check($nochild, $event, $this->centre, 'es'));
        [$ours] = signup::save($this->data(['incentre' => 'yes']), $event, $this->centre, 'es');
        $this->assertEquals(1, households::get($ours->id, $this->centre->id)->incentre);
        $this->assertSame('event', households::get($ours->id, $this->centre->id)->firstchannel);

        $signup = $DB->get_record(events::SIGNUPS, ['householdid' => $ours->id]);
        events::attend($event, $signup->id, true);
        $this->assertSame('inquiry', $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $ours->id]));
        $this->assertSame([], households::offer_counts($this->centre->id), 'Families of the centre are not counted.');
    }

    /**
     * The calendar file of the event.
     */
    public function test_ics(): void {
        $ics = events::ics($this->event, $this->centre, 'Su página: https://example.com/f');
        $this->assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        $this->assertStringContainsString('LOCATION:Colegio de ejemplo\\, Entrada principal', $ics);
        $this->assertStringNotContainsString('Eguahe', $ics);
        $this->assertStringContainsString('DTSTART:' . gmdate('Ymd\THis\Z', (int) $this->event->timestart), $ics);
    }
}
