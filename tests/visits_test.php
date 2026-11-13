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
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\signup;
use local_oksigeniaeguahe\local\visits;

/**
 * Visits of their own: a family asks saying when it suits them, the team proposes the day, the hour and who guides
 * them, and the invitation carries a link of its own without breaking the ones sent before. Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\visits
 */
#[\PHPUnit\Framework\Attributes\CoversClass(visits::class)]
final class visits_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $event;

    /** @var \stdClass */
    protected $guide;

    /** @var int */
    protected $memberid;

    /**
     * A centre with a guide and an event of asking for a visit, with example data.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        antispam::$testpass = true;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'address' => 'Calle de ejemplo 1']);
        $this->centre = centres::get($id);
        $this->guide = $this->getDataGenerator()->create_user(['firstname' => 'Lucía', 'lastname' => 'Medina']);
        centres::add_member($id, (int) $this->guide->id);
        $this->memberid = (int) $DB->get_field(centres::MEMBERS, 'id', ['centreid' => $id, 'userid' => $this->guide->id]);
        $DB->update_record(centres::MEMBERS, (object) ['id' => $this->memberid, 'public' => 1, 'rolename' => 'Tutora']);
        $eventid = events::save($id, (object) ['kind' => 'visit', 'name' => 'Pidan una visita', 'tailored' => 1,
            'timestart' => time(), 'timeend' => time() + 90 * DAYSECS, 'capacity' => 0, 'open' => 1]);
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
     * A family asks, waits with what suits it, and gets a visit with its guide and its invitation.
     */
    public function test_ask_and_propose(): void {
        global $DB;
        $this->assertSame(1, (int) $this->event->tailored);
        $data = [
            'adults' => [['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@example.com', 'phone' => ''],
                ['firstname' => '', 'lastname' => '', 'email' => '', 'phone' => '']],
            'children' => [['firstname' => 'Leonardo', 'tagname' => 'Leo', 'offerid' => 0],
                ['firstname' => '', 'tagname' => '', 'offerid' => 0]],
            'people' => 3, 'slot' => 0, 'incentre' => '', 'municipality' => '', 'municipalityother' => '', 'heardfrom' => '',
            'message' => '', 'consents' => ['email' => 1, 'whatsapp' => 0, 'phone' => 0], 'altcha' => 'x',
            'days' => [1, 3], 'periods' => ['morning'],
        ];
        $this->assertSame([], signup::check($data, $this->event, $this->centre, 'es'));
        [$household, $first] = signup::save($data, $this->event, $this->centre, 'es');
        $request = visits::pending((int) $household->id);
        $this->assertSame('1,3', $request->days);
        $this->assertSame('lunes y miércoles, por la mañana', visits::suits($request, 'es'));
        $this->assertSame(0, $DB->count_records(events::SIGNUPS, ['householdid' => $household->id]));
        $this->assertGreaterThan(0, (int) households::get((int) $household->id, (int) $this->centre->id)->waitingsince);
        $page = familypage::context($household, $this->centre, 'es', $first, true);
        $this->assertFalse($page['hasnext']);
        $this->assertSame('Nos dijeron que les viene bien lunes y miércoles, por la mañana.', $page['novisit']);
        [$subject] = familymail::build('request', $household, $this->centre, null, 'es', $first);
        $this->assertSame('Hemos recibido su petición de visita', $subject);

        // The team proposes the day, the hour and the guide; the invitation carries a link of its own.
        $start = time() + 3 * DAYSECS;
        $visit = visits::propose($household, $start, 45, $this->memberid, 'Recepción', 3);
        $this->assertSame((int) $household->id, (int) $visit->householdid);
        $this->assertSame(0, (int) $visit->open);
        $this->assertSame('Visita con Lucía', $visit->name);
        $this->assertSame('scheduled', $DB->get_field(visits::REQUESTS, 'status', ['householdid' => $household->id]));
        $this->assertNull(visits::pending((int) $household->id));
        $second = households::new_link($household, 'invitation');
        $this->assertSame((int) $household->id, (int) households::by_link($first)->id, 'The first link still works.');
        $this->assertSame((int) $household->id, (int) households::by_link($second)->id);
        $signup = familymail::signup($household, (int) $visit->id);
        [$subject, $html] = familymail::build('invitation', $household, $this->centre, $signup, 'es', $second);
        $this->assertStringContainsString('Lucía Medina', $html);
        $this->assertStringStartsWith('Su visita al Colegio de ejemplo', $subject);
        $page = familypage::context($household, $this->centre, 'es', $second, false);
        $this->assertTrue($page['hasnext']);
        $this->assertSame('Visita con Lucía', $page['first']['name']);
        // The visit is the one thing of now, in its band, right under the greeting.
        $this->assertTrue($page['nowband']['band']['isvisit']);

        // Its guide sees it among the visits to come.
        $mine = visits::mine((int) $this->centre->id, (int) $this->guide->id);
        $this->assertSame([(int) $visit->id], array_map(fn($e) => (int) $e->id, array_values($mine)));

        // What they asked for goes in the download of their data.
        $export = households::export($household);
        $this->assertSame([1, 3], $export['visit_requests'][0]['days']);
        $this->assertSame(['morning'], $export['visit_requests'][0]['parts_of_the_day']);

        // Renewing the links stops all the ones before; erasing the family takes its visit, request and links.
        $third = households::renew_links($household);
        $this->assertNull(households::by_link($first));
        $this->assertNull(households::by_link($second));
        $this->assertNotNull(households::by_link($third));
        households::erase($household);
        $this->assertSame(0, $DB->count_records(households::LINKS, ['householdid' => $household->id]));
        $this->assertSame(0, $DB->count_records(visits::REQUESTS, ['householdid' => $household->id]));
        $this->assertFalse($DB->record_exists(events::T, ['id' => $visit->id]));
    }
}
