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
use local_oksigeniaeguahe\local\decide;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\facts;
use local_oksigeniaeguahe\local\households;

/**
 * How the families of a campaign decide, all together: those that stay in their first word, how long they take, those
 * that come more than once, those that come back.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\decide
 */
#[\PHPUnit\Framework\Attributes\CoversClass(decide::class)]
final class decide_test extends \advanced_testcase {
    /**
     * Five families, each with what it did, and what is said of them together.
     */
    public function test_how_they_decide(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($cid);
        $year = (int) date('Y', $now);
        $before = centres::save_offer($cid, (object) ['schoolyear' => ($year - 2) . '-' . substr((string) ($year - 1), 2),
            'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1]);
        $started = centres::save_offer($cid, (object) ['schoolyear' => ($year - 1) . '-' . substr((string) $year, 2),
            'name' => '1.º de Primaria', 'places' => 25, 'active' => 1]);
        $DB->set_field(centres::OFFERS, 'firstday', $now - 60 * DAYSECS, ['id' => $started]);
        $next = centres::save_offer($cid, (object) ['schoolyear' => $year . '-' . substr((string) ($year + 1), 2),
            'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1]);
        $act = function (int $daysago) use ($cid): \stdClass {
            return events::get(events::save($cid, (object) ['kind' => 'openday', 'name' => 'Jornada',
                'timestart' => time() - $daysago * DAYSECS, 'timeend' => time() - $daysago * DAYSECS + HOURSECS,
                'capacity' => 0, 'open' => 1]), $cid);
        };
        $family = function (int $daysago, array $children) use ($DB, $cid): int {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $DB->set_field(households::T, 'timecreated', time() - $daysago * DAYSECS, ['id' => $id]);
            $DB->insert_record(households::CONTACTS, (object) ['centreid' => $cid, 'householdid' => $id, 'direction' => 'in',
                'channel' => 'webform', 'body' => 'Hola', 'entryid' => 0, 'userid' => 0,
                'timecreated' => time() - $daysago * DAYSECS]);
            $household = households::get($id, $cid);
            foreach ($children as $i => $child) {
                households::add_child($household, ['Leo', 'Ana'][$i], $child[0]);
            }
            $i = 0;
            foreach (households::full(households::get($id, $cid))->children as $child) {
                households::set_stage($child, $children[$i++][1]);
            }
            return $id;
        };
        $quiet = $family(60, [[$next, 'inquiry']]);
        $twice = $family(40, [[$next, 'enrolled']]);
        foreach ([30, 10] as $daysago) {
            events::sign_up($act($daysago), $twice, 2);
        }
        $DB->set_field(events::SIGNUPS, 'attended', $now, ['householdid' => $twice]);
        $back = $family(400, [[$next, 'inquiry']]);
        $other = $family(30, [[$before, 'closed'], [$next, 'inquiry']]);
        $late = $family(200, [[$started, 'enrolled']]);
        // The child of two years ago asked then, before this campaign.
        $first = $DB->get_records(households::CHILDREN, ['householdid' => $other], 'id ASC', 'id', 0, 1);
        $DB->set_field(households::CHILDREN, 'timecreated', $now - 700 * DAYSECS, ['id' => key($first)]);
        $families = array_values(facts::prospects($centre, $now));
        $gone = $family(90, [[$next, 'closed']]);
        $asks = $family(25, [[$next, 'inquiry'], [$started, 'inquiry']]);
        $facts = decide::facts($centre, $families, $now - 300 * DAYSECS);
        $this->assertSame(0, $facts[$quiet]['visits']);
        $this->assertSame(2, $facts[$twice]['visits']);
        $this->assertSame(10, $facts[$twice]['tovisit']);
        $this->assertTrue($facts[$back]['returning']);
        $this->assertFalse($facts[$quiet]['returning']);
        $this->assertTrue($facts[$other]['otherchild']);
        $this->assertFalse($facts[$twice]['otherchild']);
        // Two children at the same time, each for its year, are not «another child»; and one closed with its reason did not
        // stay in its first word.
        $two = decide::facts($centre, array_values(facts::prospects($centre, $now)), $now - 300 * DAYSECS);
        $this->assertFalse($two[$asks]['otherchild']);
        $closed = array_filter(facts::prospects($centre, $now), fn($f) => $f['id'] === $gone);
        $this->assertSame(0, decide::add($closed, decide::facts($centre, $closed, $now - 300 * DAYSECS), $now)['quiet']);
        $this->assertTrue($facts[$late]['latejoin']);

        $sum = decide::add($families, $facts, $now);
        $this->assertSame(5, $sum['n']);
        $this->assertSame(5, $sum['quiet']);
        $this->assertSame([3, 3], [$sum['stayed'], $sum['unanswered']], 'The quiet one, the one back with a word and the other child, and nobody answered them.');
        $this->assertSame([1, 1], [$sum['multi'], $sum['multienrolled']]);
        $lines = decide::lines($sum);
        $c = 'local_oksigeniaeguahe';
        $this->assertSame(
            get_string('decide_stayed', $c, (object) ['n' => 3, 'of' => 5, 'days' => 21]) . ' ' .
                get_string('decide_stayed_split', $c, (object) ['without' => 3, 'with' => 0]),
            $lines[0]
        );
        $this->assertNull(decide::middle([3, 4]), 'Too few to say a median.');
        $this->assertNull(decide::middle([1, 3, 5, 7, 9]), 'Nine or fewer are not said as a median.');
        $this->assertSame(['median' => 6, 'low' => 3, 'high' => 8], decide::middle([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]));
        $summary = decide::summary($centre, $families, $now - 300 * DAYSECS, $now);
        $this->assertNotEmpty($summary['rows']);
        $this->assertSame(get_string('who_new', $c), $summary['rows'][array_key_last($summary['rows'])]['name']);
    }

    /**
     * A family that came, though the day is not known, came once and did not stay at its first contact.
     */
    public function test_day_not_known(): void {
        $sum = decide::add([['id' => 7, 'came' => true, 'enrolled' => false, 'closed' => false]], [7 => ['first' => 1, 'words' => 1, 'visits' => 1,
            'answered' => true, 'tovisit' => null, 'toenrol' => null, 'returning' => false, 'otherchild' => false, 'latejoin' => false]], time());
        $this->assertSame([1, 0], [$sum['once'], $sum['stayed']]);
        $this->assertStringContainsString(': 1.', implode(' ', decide::lines($sum)));
    }

    /**
     * Each fact as a figure and what it means; the days to the first visit and to enrolling on one ruler, from 0 to a
     * round number past the longest middle half.
     */
    public function test_figures(): void {
        $c = 'local_oksigeniaeguahe';
        $sum = ['n' => 10, 'quiet' => 8, 'stayed' => 2, 'multi' => 0, 'multienrolled' => 0, 'once' => 0, 'onceenrolled' => 0,
            'tovisit' => [1, 2, 3, 4, 6, 6, 8, 9, 10, 11], 'toenrol' => [5, 10, 15, 20, 30, 30, 40, 40, 50, 60], 'returning' => 3, 'returningenrolled' => 1,
            'otherchild' => 0, 'otherchildenrolled' => 0, 'latejoin' => 0];
        $figures = decide::figures($sum);
        $this->assertSame(
            [get_string('panel_resp_of', $c, (object) ['n' => 2, 'of' => 8]), get_string('decide_days', $c, 6), get_string('decide_days', $c, 30), 3],
            array_column($figures, 'fig')
        );
        $this->assertFalse($figures[0]['ruler']);
        // Both on the same ruler: to 40 days, so the median of 30 stands at three quarters.
        $this->assertEquals(
            ['lo' => 0.4, 'hi' => 1.0, 'mid' => 0.75, 'max' => get_string('decide_days', $c, 40)],
            $figures[2]['ruler']
        );
        $this->assertSame(get_string('decide_days', $c, 40), $figures[1]['ruler']['max']);
        $this->assertSame(get_string('decide_fig_returning', $c, 1), $figures[3]['text']);
    }
}
