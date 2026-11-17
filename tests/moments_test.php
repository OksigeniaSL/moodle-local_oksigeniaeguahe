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
use local_oksigeniaeguahe\local\family;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\kit;
use local_oksigeniaeguahe\local\moments;

/**
 * Each function at its moment: said in the inbox when its time comes, and gone once done or known.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\moments
 */
#[\PHPUnit\Framework\Attributes\CoversClass(moments::class)]
final class moments_test extends \advanced_testcase {
    /**
     * The keys of the moments of a centre now.
     *
     * @param int $id
     * @param int $now
     * @return string[]
     */
    protected function keys(int $id, int $now): array {
        return array_column(moments::due(centres::get($id), $now), 'key');
    }

    /**
     * An open act in the coming weeks offers its kit until it is opened; a later one, not yet; known, never again.
     */
    public function test_kit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $now = time();
        $act = fn(int $days) => events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'place' => 'Patio',
            'timestart' => $now + $days * DAYSECS, 'timeend' => $now + $days * DAYSECS + 3 * HOURSECS, 'capacity' => 40,
            'open' => 1]);
        $near = $act(10);
        $act(60);
        $this->assertSame(['kit-' . $near], $this->keys($id, $now));
        kit::seen(events::get($near, $id));
        $this->assertSame([], $this->keys($id, $now));
        $other = $act(12);
        $this->assertSame(['kit-' . $other], $this->keys($id, $now));
        moments::know($id, 'kit-' . $other);
        $this->assertSame([], $this->keys($id, $now));
        // A key that is not one of a moment is not kept.
        moments::know($id, 'kit-1,family');
        $this->assertSame('kit-' . $other, get_config('local_oksigeniaeguahe', moments::KNOWN . $id));
    }

    /**
     * The first family with a place asks who makes the common record, where there is one; before the next school year,
     * its offer, when the families with a place have no course to go on to.
     */
    public function test_family_and_offer(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $three = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1, 'sortorder' => 0]);
        $family = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($family, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz']);
        $leo = households::add_child($family, 'Leo', $three);
        $before = imports::year_start('2028-29') - 30 * DAYSECS;
        $this->assertSame([], $this->keys($id, $before), 'Nobody with a place yet.');
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $leo]), 'enrolled');
        $keys = $this->keys($id, $before);
        $this->assertContains('offer-2028-29', $keys);
        $this->assertSame(family::available(), in_array('family', $keys, true));
        // Months before, not yet; once the school year began, no longer.
        $this->assertNotContains('offer-2028-29', $this->keys($id, imports::year_start('2028-29') - 200 * DAYSECS));
        $this->assertNotContains('offer-2028-29', $this->keys($id, imports::year_start('2028-29') + DAYSECS));
        // With the offer made, the families have where to go on: it is no longer asked.
        centres::save_offer($id, (object) ['schoolyear' => '2028-29', 'name' => 'Infantil 4 años', 'places' => 25,
            'active' => 1, 'sortorder' => 0]);
        $this->assertNotContains('offer-2028-29', $this->keys($id, $before));
        moments::know($id, 'family');
        $this->assertSame([], $this->keys($id, $before));
    }
}
