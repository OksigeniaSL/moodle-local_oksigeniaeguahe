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
use local_oksigeniaeguahe\local\costs;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_household;

/**
 * What it costs, when each centre decides; the waiting list said plainly, with an answer even without a place; and the
 * page of a family whose every way is closed (review of November 2026). Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\costs
 */
#[\PHPUnit\Framework\Attributes\CoversClass(costs::class)]
final class costs_test extends \advanced_testcase {
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
     * A family with a child, with example data.
     *
     * @return \stdClass
     */
    protected function family(): \stdClass {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es']);
        $household = households::get($id, (int) $this->centre->id);
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => '', 'email' => 'marta@example.com',
            'phone' => '']);
        households::add_child($household, 'Leo', $this->offer);
        return households::get($id, (int) $this->centre->id);
    }

    /**
     * The lines as a receipt, and when families see them: after the visit by default, from the start, or never.
     */
    public function test_what_it_costs(): void {
        $this->assertSame([], costs::rows($this->centre));
        $this->assertFalse(costs::shown($this->centre, true), 'Nothing written, nothing shown.');
        centres::update((int) $this->centre->id, (object) ['costs' => "Cuota mensual: 320 €\n\nComedor: 6 € al día\n" .
            "Uniforme aparte", 'costswhen' => 'nonsense']);
        $centre = centres::get((int) $this->centre->id);
        $this->assertSame('after', $centre->costswhen);
        // A change of a few fields keeps the rest of the centre.
        $this->assertSame('Colegio de ejemplo', $centre->name);
        $this->assertSame('es', $centre->lang);
        $this->assertSame('ES', $centre->country);
        $this->assertSame([['what' => 'Cuota mensual', 'amount' => '320 €'], ['what' => 'Comedor', 'amount' => '6 € al día'],
            ['what' => 'Uniforme aparte', 'amount' => '']], costs::rows($centre));
        $this->assertFalse(costs::shown($centre, false));
        $this->assertTrue(costs::shown($centre, true));

        // On the page: not before the visit; from the start when the centre says so; never, never.
        $household = $this->family();
        $context = familypage::context($household, $centre, 'es', 'token', false);
        $this->assertSame([], array_filter(array_column($context['more'], 'costs')));
        centres::update((int) $centre->id, (object) ['costs' => $centre->costs, 'costswhen' => 'start']);
        $centre = centres::get((int) $centre->id);
        $context = familypage::context($household, $centre, 'es', 'token', false);
        $costs = array_values(array_filter(array_column($context['more'], 'costs')));
        $this->assertSame('Lo que cuesta', $costs[0]['title']);
        $this->assertCount(3, $costs[0]['rows']);
        centres::update((int) $centre->id, (object) ['costs' => $centre->costs, 'costswhen' => 'never']);
        $this->assertFalse(costs::shown(centres::get((int) $centre->id), true));
    }

    /**
     * A child on the waiting list: the page says it with nothing of the places, and the file proposes an answer.
     */
    public function test_waiting_list(): void {
        $household = $this->family();
        $children = households::full($household)->children;
        $child = reset($children);
        households::set_stage($child, 'waitlist');
        $household = households::get((int) $household->id, (int) $this->centre->id);
        $context = familypage::context($household, $this->centre, 'es', 'token', false);
        $this->assertSame('En lista de espera', $context['nowcard']['waitlist']['title']);
        $this->assertSame(['Leo, para Infantil 3 años · curso 2027-28'], $context['nowcard']['waitlist']['lines']);
        $proposal = panel_household::waitlist_proposal($household, households::full($household), time());
        $this->assertNotFalse($proposal);
        $user = $this->getDataGenerator()->create_user();
        households::answered($household, (int) $user->id, 'email', 'Hola: …');
        $this->assertFalse(panel_household::waitlist_proposal($household, households::full($household), time()));
    }

    /**
     * Every way closed: thanked for getting to know the centre, what hung resting, and nothing more asked.
     */
    public function test_closed_family(): void {
        $household = $this->family();
        $children = households::full($household)->children;
        households::set_stage(reset($children), 'closed', 'moved');
        $household = households::get((int) $household->id, (int) $this->centre->id);
        $context = familypage::context($household, $this->centre, 'es', 'token', false);
        $this->assertSame('Gracias por conocernos,', $context['greeting']);
        $this->assertSame('Si algún día cambian de idea, aquí seguimos.', $context['lead']);
        $this->assertTrue($context['pegs']['resting']);
        $this->assertTrue($context['quiet']);
        $this->assertFalse($context['nowband'], 'Nothing of the next visit or of when admissions open.');
    }

    /**
     * After the visit, the mail of thanks says what it costs.
     */
    public function test_thanks_mail(): void {
        centres::update((int) $this->centre->id, (object) ['costs' => 'Cuota mensual: 320 €', 'costswhen' => 'after']);
        $centre = centres::get((int) $this->centre->id);
        $household = $this->family();
        $start = time() - DAYSECS;
        $eventid = events::save((int) $centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'place' => '',
            'timestart' => $start, 'timeend' => $start + 2 * HOURSECS, 'capacity' => 0, 'open' => 1, 'hosts' => []]);
        $signup = (object) ['eventid' => $eventid, 'timestart' => $start, 'timeend' => $start + 2 * HOURSECS,
            'place' => '', 'hosts' => '', 'eventname' => 'Jornada', 'people' => 2];
        [, $html, $text] = familymail::build('thanks', $household, $centre, $signup, 'es', 'token');
        $this->assertStringContainsString('Cuota mensual: <strong>320 €</strong>', $html);
        $this->assertStringContainsString('Cuota mensual: 320 €', $text);
    }
}
