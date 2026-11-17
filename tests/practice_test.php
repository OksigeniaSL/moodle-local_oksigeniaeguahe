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
use local_oksigeniaeguahe\local\helps;
use local_oksigeniaeguahe\local\mail;
use local_oksigeniaeguahe\local\practice;

/**
 * The practice centre of the guided helps: made with its families and its open day of today, in no list, open to whoever
 * works for a centre, and nothing it does leaves the site.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\practice
 */
#[\PHPUnit\Framework\Attributes\CoversClass(practice::class)]
final class practice_test extends \advanced_testcase {
    /**
     * Made once a day, with what the helps need; in no list; and only for whoever works for some centre.
     */
    public function test_made_and_hidden(): void {
        global $DB;
        $this->resetAfterTest();
        $own = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $practice = practice::centre();
        $this->assertTrue(centres::is_practice((int) $practice->id));
        $this->assertSame((int) $practice->id, (int) practice::centre()->id, 'The same one, the same day.');
        $this->assertSame(5, $DB->count_records('local_oksigeniaeguahe_household', ['centreid' => $practice->id]));
        $this->assertArrayNotHasKey((int) $practice->id, centres::all());
        $this->assertArrayHasKey($own, centres::all());
        // Every help finds where it starts.
        foreach (array_keys(helps::PIECES) as $piece) {
            $this->assertStringNotContainsString('id=0', helps::start($piece, $practice), $piece);
            $this->assertCount(helps::PIECES[$piece][0], helps::steps($piece));
        }
        $member = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniaeguahe:work', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $member->id, \context_system::instance());
        centres::add_member($own, (int) $member->id);
        $this->assertTrue(centres::can_work((int) $practice->id, (int) $member->id));
        $this->assertFalse(centres::can_work((int) $practice->id, (int) $stranger->id));
        // Another day, it starts again: the same centre, made anew.
        $made = practice::centre(time() + DAYSECS);
        $this->assertSame((int) $practice->id, (int) $made->id);
        $this->assertSame(5, $DB->count_records('local_oksigeniaeguahe_household', ['centreid' => $practice->id]));
    }

    /**
     * Nothing leaves: a mail of the practice centre counts as sent and goes nowhere.
     */
    public function test_nothing_leaves(): void {
        $this->resetAfterTest();
        $practice = practice::centre();
        $sink = $this->redirectEmails();
        $this->assertTrue(mail::send_rich($practice, 'someone@example.com', 'Hola', '<p>Hola</p>', 'Hola'));
        $this->assertSame(0, $sink->count());
        $sink->close();
    }
}
