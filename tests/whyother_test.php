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
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\whyother;

/**
 * What families tell when they go elsewhere, and how long they wait (review of November 2026): why they chose another
 * centre, asked on their page in one tap and added up without names; and the first answer by where and when they wrote.
 * Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\whyother
 */
#[\PHPUnit\Framework\Attributes\CoversClass(whyother::class)]
final class whyother_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $offer;

    /**
     * A centre with a course, with example data.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        $this->offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1, 'sortorder' => 0]);
    }

    /**
     * A family with a child, and its way closed for a reason.
     *
     * @param string $reason
     * @return \stdClass The household.
     */
    protected function closed(string $reason): \stdClass {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es']);
        $household = households::get($id, (int) $this->centre->id);
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => '', 'email' => 'marta@example.com',
            'phone' => '']);
        households::add_child($household, 'Leo', $this->offer);
        $children = households::full($household)->children;
        $child = reset($children);
        households::set_stage($child, 'closed', $reason);
        return households::get($id, (int) $this->centre->id);
    }

    /**
     * Asked only when every child is closed and one chose another centre; kept once; told in the file, the download
     * and Cifras, never which family.
     */
    public function test_why_they_chose_another_centre(): void {
        $now = time();
        $moved = $this->closed('moved');
        $this->assertNull(whyother::since($moved, array_values(households::full($moved)->children), $now));

        $household = $this->closed('othercentre');
        $children = array_values(households::full($household)->children);
        $this->assertNotNull(whyother::since($household, $children, $now));
        $this->assertNull(whyother::since($household, $children, $now + 61 * DAYSECS), 'Only for a while.');

        // Its page asks it as the thing of now, and the file proposes a goodbye.
        $context = familypage::context($household, $this->centre, 'es', 'token', false);
        $this->assertSame('¿Qué les hizo decidirse por otro centro?', $context['nowcard']['whyother']['title']);
        $proposal = panel_household::chose_proposal($household, households::full($household), $now);
        $this->assertStringContainsString(get_string('panel_chose_message', 'local_oksigeniaeguahe'), $proposal['text']);

        // Told: kept, in its conversation without waiting for an answer, and asked no more.
        $this->assertFalse(whyother::save($household, $this->centre, [], '  ', $now));
        $this->assertTrue(whyother::save($household, $this->centre, ['cost', 'hours', 'nonsense'], 'Nos pillaba de camino.', $now));
        $household = households::get((int) $household->id, (int) $this->centre->id);
        $this->assertSame('cost,hours', $household->whyother);
        $this->assertSame(0, (int) $household->waitingsince);
        // In words of the team (the language of the site in the tests).
        $this->assertSame('the cost and the hours', whyother::words($household, 'en'));
        $this->assertNull(whyother::since($household, $children, $now));
        $this->assertFalse(panel_household::chose_proposal($household, households::full($household), $now));
        $contacts = households::full($household)->contacts;
        $body = end($contacts)->body;
        $this->assertStringContainsString(whyother::words($household, 'es'), $body);
        $this->assertStringContainsString('«Nos pillaba de camino.»', $body);
        $this->assertSame('cost,hours', households::export($household)['household']['why_you_chose_another_centre']);

        $counts = whyother::counts((int) $this->centre->id, 0);
        $this->assertSame(1, $counts['told']);
        $this->assertSame(['cost' => 1, 'hours' => 1], $counts['reasons']);
    }

    /**
     * The first answer, by where they wrote first and by the part of the week they wrote in.
     */
    public function test_response_by_channel_and_band(): void {
        global $DB;
        $zone = \core_date::get_server_timezone();
        $monday = (new \DateTime('2026-11-09 10:00', new \DateTimeZone($zone)))->getTimestamp();
        $saturday = (new \DateTime('2026-11-14 18:00', new \DateTimeZone($zone)))->getTimestamp();
        $this->assertSame('weekmorning', metrics::band($monday, $zone));
        $this->assertSame('weekafternoon', metrics::band($monday + 7 * HOURSECS, $zone));
        $this->assertSame('night', metrics::band($monday + 12 * HOURSECS, $zone));
        $this->assertSame('weekend', metrics::band($saturday, $zone));

        $user = $this->getDataGenerator()->create_user();
        foreach ([['email', $monday, 2], ['whatsapp', $saturday, 40]] as [$channel, $when, $hours]) {
            $id = households::create((int) $this->centre->id, (object) ['lang' => 'es']);
            $household = households::get($id, (int) $this->centre->id);
            households::received($household, $channel, '¿Hay comedor?', true, $when);
            households::answered($household, (int) $user->id, 'email', 'Sí.');
            $where = 'householdid = ? AND direction = ?';
            $DB->set_field_select(households::CONTACTS, 'timecreated', $when + $hours * HOURSECS, $where, [$id, 'out']);
        }
        $r = metrics::response((int) $this->centre->id);
        $this->assertSame(2, $r['answered']);
        $this->assertSame(['median' => 2.0, 'count' => 1], $r['bychannel']['email']);
        $this->assertSame(['median' => 40.0, 'count' => 1], $r['bychannel']['whatsapp']);
        $this->assertSame(['weekmorning', 'weekend'], array_keys($r['byband']));
    }
}
