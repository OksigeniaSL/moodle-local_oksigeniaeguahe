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

use local_oksigeniaeguahe\local\atycards;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\renewal;

/**
 * The cards of Eguahe on the home of the app of Oksigenia Aty: for an adult of a family of the centre, whether each
 * child goes on next year, with a link to their page that lasts a day. Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\atycards
 */
#[\PHPUnit\Framework\Attributes\CoversClass(atycards::class)]
final class atycards_test extends \advanced_testcase {
    /**
     * Back to what the site says.
     */
    protected function tearDown(): void {
        atycards::$testfamilies = null;
        parent::tearDown();
    }

    /**
     * A family of the centre asked whether its child goes on: a card with that question, urgent, to its page; nothing
     * for a person of no family; and the link from the app, good for a day and only signed.
     */
    public function test_cards(): void {
        global $DB;
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES', 'system' => 'es']);
        $now = time();
        $year = (int) date('Y', $now);
        $before = $year . '-' . substr((string) ($year + 1), 2);
        $after = ($year + 1) . '-' . substr((string) ($year + 2), 2);
        $three = centres::save_offer($id, (object) ['schoolyear' => $before, 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1, 'sortorder' => 0]);
        centres::save_offer($id, (object) ['schoolyear' => $after, 'name' => 'Infantil 4 años', 'places' => 25,
            'active' => 1, 'sortorder' => 1]);
        renewal::set(centres::get($id), $after, $now - DAYSECS, $now + 20 * DAYSECS);
        $household = households::get(households::create($id, (object) ['lang' => 'es']), $id);
        $child = households::add_child($household, 'Leo', $three);
        $DB->set_field(households::CHILDREN, 'stage', 'enrolled', ['id' => $child]);

        $user = $this->getDataGenerator()->create_user();
        $this->assertSame([], atycards::home_cards((int) $user->id), 'Without Family saying it, nothing.');
        atycards::$testfamilies = [(int) $user->id => [['familyid' => 9, 'source' => 'eguahe',
            'sourcekey' => 'eguahe:' . $household->id]]];
        $cards = atycards::home_cards((int) $user->id, $now);
        $this->assertCount(1, $cards);
        $this->assertSame('Colegio de ejemplo', $cards[0]['heading']);
        $row = $cards[0]['items'][0];
        $this->assertSame('¿Sigue Leo en ' . $after . '?', $row['title']);
        $this->assertTrue($row['urgent']);
        $this->assertSame('due', $row['kind']);
        $link = new \moodle_url($row['url']);
        $this->assertSame('rematricula-' . $child, $link->get_param('a'));
        $this->assertSame([], atycards::home_cards((int) $user->id + 1, $now), 'Nothing for someone of no family.');

        // The link: good for a day, signed for that household and person only.
        [$h, $u, $t, $s] = [(int) $link->get_param('h'), (int) $link->get_param('u'), (int) $link->get_param('t'),
            (string) $link->get_param('s')];
        $this->assertTrue(atycards::valid($h, $u, $t, $s, $now));
        $this->assertFalse(atycards::valid($h, $u, $t, $s, $t + 1), 'Past its day.');
        $this->assertFalse(atycards::valid($h + 1, $u, $t, $s, $now));
        $this->assertFalse(atycards::valid($h, $u, $t + 60, $s, $now));

        // Answered, the question goes.
        renewal::answer(centres::get($id), households::get((int) $household->id, $id), $child, 'stay', '', '', 0, $now);
        $this->assertSame([], atycards::home_cards((int) $user->id, $now));

        // The links opened from the app go after two days.
        households::new_link(households::get((int) $household->id, $id), 'app');
        $DB->set_field(households::LINKS, 'timecreated', $now - 3 * DAYSECS, ['reason' => 'app']);
        households::tidy($now);
        $this->assertSame(0, $DB->count_records(households::LINKS, ['reason' => 'app']));
    }
}
