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
use local_oksigeniaeguahe\local\demo;
use local_oksigeniaeguahe\local\practice;

/**
 * Trying Eguahe without an account: a centre of one's own, apart from every other visitor, gone after a day.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\demo
 */
#[\PHPUnit\Framework\Attributes\CoversClass(demo::class)]
final class demo_test extends \advanced_testcase {
    /**
     * Each visitor gets a centre of their own, logged in, and sees nobody else's; the helps happen in it.
     */
    public function test_visitors_apart(): void {
        $this->resetAfterTest();
        set_config('onlyeguahe', 1, 'local_oksigeniaeguahe');
        set_config('demo', 1, 'local_oksigeniaeguahe');
        $this->assertTrue(demo::on());

        [$first, $firstuser] = demo::make('en');
        $this->setUser($firstuser);
        $this->assertSame(demo::KIND, (int) $first->practice);
        $this->assertTrue(demo::is_visitor($firstuser));
        $this->assertTrue(centres::can_work((int) $first->id, $firstuser));
        $this->assertTrue(centres::can_manage((int) $first->id, $firstuser));
        $this->assertSame((int) $first->id, (int) practice::centre()->id);
        $this->assertGreaterThan(0, practice::answer((int) $first->id));

        [$second, $seconduser] = demo::make('en');
        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->assertFalse(centres::can_work((int) $first->id, $seconduser));
        $this->assertFalse(centres::can_work((int) $second->id, $firstuser));

        // The practice centre of the helps is not for visitors, and a visitor's centre is in no list.
        $this->setAdminUser();
        $shared = practice::centre(time());
        $this->assertNotSame((int) $shared->id, (int) $second->id);
        $this->assertFalse(centres::can_work((int) $shared->id, $seconduser));
        $this->assertTrue(centres::is_practice((int) $first->id));
    }

    /**
     * After a day, the centre and its passing account go, and the help's family of it too.
     */
    public function test_gone_after_a_day(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('onlyeguahe', 1, 'local_oksigeniaeguahe');
        set_config('demo', 1, 'local_oksigeniaeguahe');
        [$centre, $userid] = demo::make('en');
        $this->assertSame(0, demo::tidy(time()));
        $this->assertSame(1, demo::tidy(time() + demo::KEEP + 60));
        $this->assertFalse($DB->record_exists(centres::T, ['id' => $centre->id]));
        $this->assertFalse($DB->record_exists('local_oksigeniaeguahe_household', ['centreid' => $centre->id]));
        $this->assertSame(1, (int) $DB->get_field('user', 'deleted', ['id' => $userid]));
        $this->assertSame(0, practice::answer((int) $centre->id));
    }

    /**
     * Not without the site saying so, and only so many from one address in an hour.
     */
    public function test_allowance(): void {
        $this->resetAfterTest();
        $this->assertFalse(demo::on());
        set_config('onlyeguahe', 1, 'local_oksigeniaeguahe');
        $this->assertFalse(demo::on());
        for ($i = 0; $i < demo::PERHOUR; $i++) {
            $this->assertTrue(demo::allowed_from('192.0.2.10'));
        }
        $this->assertFalse(demo::allowed_from('192.0.2.10'));
        $this->assertTrue(demo::allowed_from('192.0.2.11'));
    }

    /**
     * Where a visitor sees the plans: eguahe.com in their language, the site's own page, or nowhere.
     */
    public function test_plans(): void {
        $this->resetAfterTest();
        set_config('demoplans', demo::PLANS, 'local_oksigeniaeguahe');
        $this->assertSame('https://eguahe.com/#precio', demo::plans_url('es'));
        $this->assertSame('https://eguahe.com/en/#precio', demo::plans_url('en'));
        $this->assertSame('https://eguahe.com/pt/#precio', demo::plans_url('pt'));
        $this->assertSame('https://eguahe.com/pt-br/#precio', demo::plans_url('pt_br'));
        $this->assertSame('https://eguahe.com/#precio', demo::plans_url('ar'));
        set_config('demoplans', 'https://example.com/plans', 'local_oksigeniaeguahe');
        $this->assertSame('https://example.com/plans', demo::plans_url('en'));
        set_config('demoplans', '', 'local_oksigeniaeguahe');
        $this->assertNull(demo::plans_url('en'));
    }
}
