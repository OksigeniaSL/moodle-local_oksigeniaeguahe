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
        $DB->set_field(events::SIGNUPS, 'attended', 1, ['householdid' => $twice]);
        $back = $family(400, [[$next, 'inquiry']]);
        $other = $family(30, [[$before, 'closed'], [$next, 'inquiry']]);
        $late = $family(200, [[$started, 'enrolled']]);
        $families = [];
        foreach ([$quiet, $twice, $back, $other, $late] as $id) {
            $h = $DB->get_record(households::T, ['id' => $id]);
            $kids = $DB->get_records(households::CHILDREN, ['householdid' => $id]);
            $enrolled = array_filter($kids, fn($k) => $k->stage === 'enrolled');
            $families[] = ['id' => $id, 'first' => (int) $h->timecreated, 'step' => $enrolled ? 5 : 1, 'who' => 'new',
                'enrolledat' => $enrolled ? max(array_column($enrolled, 'stagetime')) : 0,
                'voices' => ['channel' => '']];
        }
        $facts = decide::facts($centre, $families, $now - 300 * DAYSECS);
        $this->assertSame(0, $facts[$quiet]['visits']);
        $this->assertSame(2, $facts[$twice]['visits']);
        $this->assertSame(10, $facts[$twice]['tovisit']);
        $this->assertTrue($facts[$back]['returning']);
        $this->assertFalse($facts[$quiet]['returning']);
        $this->assertTrue($facts[$other]['otherchild']);
        $this->assertFalse($facts[$twice]['otherchild']);
        $this->assertTrue($facts[$late]['latejoin']);

        $sum = decide::add($families, $facts, $now);
        $this->assertSame(5, $sum['n']);
        $this->assertSame(5, $sum['quiet']);
        $this->assertSame(3, $sum['stayed'], 'The quiet one, the one back with a word and the other child.');
        $this->assertSame([1, 1], [$sum['multi'], $sum['multienrolled']]);
        $lines = decide::lines($sum);
        $c = 'local_oksigeniaeguahe';
        $this->assertSame(
            get_string('decide_stayed', $c, (object) ['n' => 3, 'of' => 5, 'pct' => '60 %', 'days' => 21]),
            $lines[0]
        );
        $this->assertNull(decide::middle([3, 4]), 'Too few to say a median.');
        $this->assertSame(['median' => 5, 'low' => 3, 'high' => 7], decide::middle([1, 3, 5, 7, 9]));
        $summary = decide::summary($centre, $families, $now - 300 * DAYSECS, $now);
        $this->assertNotEmpty($summary['rows']);
        $this->assertSame(get_string('who_new', $c), $summary['rows'][array_key_last($summary['rows'])]['name']);
    }
}
