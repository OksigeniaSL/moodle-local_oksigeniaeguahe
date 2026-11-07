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
use local_oksigeniaeguahe\local\households;

/**
 * Who works for which centre: nobody of one sees the families of another.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\centres
 */
#[\PHPUnit\Framework\Attributes\CoversClass(centres::class)]
final class centres_test extends \advanced_testcase {
    /**
     * Team members work for their centres only; whoever sets them up sees them all.
     */
    public function test_teams(): void {
        global $DB;
        $this->resetAfterTest();
        $one = centres::create((object) ['name' => 'Uno']);
        $two = centres::create((object) ['name' => 'Dos']);
        $worker = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniaeguahe:work', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $worker->id, \context_system::instance());
        $this->assertSame([], centres::for_user($worker->id));

        centres::add_member($one, $worker->id);
        centres::add_member($one, $worker->id);
        $this->assertSame(1, $DB->count_records(centres::MEMBERS));
        $this->assertTrue(centres::can_work($one, $worker->id));
        $this->assertFalse(centres::can_work($two, $worker->id));

        $this->setAdminUser();
        $this->assertCount(2, centres::for_user(get_admin()->id));

        centres::remove_member($one, $worker->id);
        $this->assertFalse(centres::can_work($one, $worker->id));
    }

    /**
     * Whoever only sees, in the team of a centre, sees it but may not act; whoever works may.
     */
    public function test_view_only(): void {
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Uno']);
        $system = \context_system::instance();
        $roles = [];
        foreach (['view', 'work'] as $cap) {
            $user = $this->getDataGenerator()->create_user();
            $role = $this->getDataGenerator()->create_role();
            assign_capability('local/oksigeniaeguahe:' . $cap, CAP_ALLOW, $role, $system);
            role_assign($role, $user->id, $system);
            centres::add_member($centreid, $user->id);
            $roles[$cap] = $user;
        }
        $this->assertTrue(centres::can_work($centreid, $roles['view']->id));
        $this->assertFalse(centres::can_act($roles['view']->id));
        $this->assertTrue(centres::can_act($roles['work']->id));
        $this->setUser($roles['view']);
        $this->expectException(\required_capability_exception::class);
        centres::require_act($centreid);
    }

    /**
     * A family is found again only in its own centre.
     */
    public function test_find_by_centre(): void {
        $this->resetAfterTest();
        $one = centres::create((object) ['name' => 'Uno']);
        $two = centres::create((object) ['name' => 'Dos']);
        $household = households::get(households::create($one, (object) ['lang' => 'es']), $one);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'phone' => '+34600111222']);
        $this->assertEquals($household->id, households::find($one, null, '+34600111222'));
        $this->assertNull(households::find($two, null, '+34600111222'));
        $this->assertNull(households::get($household->id, $two));
    }

    /**
     * The area of a centre, with and without codes.
     */
    public function test_municipalities(): void {
        $list = centres::municipalities((object) ['municipalities' => "28065;Getafe\nPinto\n\n"]);
        $this->assertSame('Getafe', $list['28065']);
        $this->assertContains('Pinto', $list);
        $this->assertCount(2, $list);
    }
}
