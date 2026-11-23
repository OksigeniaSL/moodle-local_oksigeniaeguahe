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
use local_oksigeniaeguahe\local\chatcontext;
use local_oksigeniaeguahe\local\households;

/**
 * What admissions tells the chat of Marandu about whoever writes, for the reply it proposes: facts of the centres of
 * the person who asks, without emails, phones or links. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(chatcontext::class)]
final class chatcontext_test extends \advanced_testcase {
    /**
     * A family that writes from its phone: who they are, the child and its course, how far it went and that it asks;
     * nothing for a phone nobody has, for a person of no centre, or for a practice centre.
     */
    public function test_lines(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offerid = centres::save_offer($centreid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1]);
        $household = households::get(
            households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform', 'heardfrom' => 'search']),
            $centreid
        );
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com', 'phone' => '600 111 222']);
        $child = households::add_child($household, 'Leo', $offerid);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'visit');

        $lines = chatcontext::lines('34600111222', (int) get_admin()->id);
        $text = implode("\n", $lines);
        $this->assertStringContainsString('Ana Ejemplo', $text);
        $this->assertStringContainsString('Leo', $text);
        $this->assertStringContainsString('Infantil 3 años (2027-28)', $text);
        $this->assertStringContainsString(get_string('stage_visit', 'local_oksigeniaeguahe'), $text);
        $this->assertContains(get_string('chatctx_asking', 'local_oksigeniaeguahe'), $lines);
        $this->assertStringContainsString(get_string('heard_search', 'local_oksigeniaeguahe'), $text);
        $this->assertStringNotContainsString('@', $text);
        $this->assertStringNotContainsString('600', $text);
        $this->assertLessThanOrEqual(chatcontext::LINES, count($lines));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(chatcontext::LENGTH, \core_text::strlen($line));
        }

        // A phone nobody has, and a person of no centre.
        $this->assertSame([], chatcontext::lines('34699999999', (int) get_admin()->id));
        $this->assertSame([], chatcontext::lines('34', (int) get_admin()->id));
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame([], chatcontext::lines('34600111222', (int) $user->id));

        // A practice centre tells nothing, even with the same phone.
        $DB->set_field('local_oksigeniaeguahe_centre', 'practice', 1, ['id' => $centreid]);
        $this->assertSame([], chatcontext::lines('34600111222', (int) get_admin()->id));
    }
}
