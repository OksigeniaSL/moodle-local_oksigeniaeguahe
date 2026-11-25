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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\teamcalendar;

/**
 * The calendar of the team: the visits and acts of a centre with their time, by a private link that a new one stops.
 * With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(teamcalendar::class)]
final class teamcalendar_test extends \advanced_testcase {
    /**
     * An open day with two families, a visit of one family, a cancelled act and the act where families ask for a visit:
     * the first two are in the calendar, with how many come and who; the others are not. The link changes with a new one.
     */
    public function test_items_and_link(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($centreid);
        $start = time() + 5 * DAYSECS;
        $act = function (string $name, array $more = []) use ($centreid, $start): \stdClass {
            $data = (object) ($more + ['kind' => 'openday', 'name' => $name, 'timestart' => $start, 'timeend' => $start + HOURSECS,
                'capacity' => 0, 'open' => 1]);
            return events::get(events::save($centreid, $data), $centreid);
        };
        $family = function (string $first) use ($centreid): \stdClass {
            $household = households::get(households::create($centreid, (object) ['lang' => 'es']), $centreid);
            households::add_adult($household, (object) ['firstname' => $first, 'lastname' => 'Ejemplo']);
            households::add_child($household, 'Leo', 0);
            return $household;
        };
        $openday = $act('Jornada de puertas abiertas');
        events::sign_up($openday, (int) $family('Ana')->id, 2);
        events::sign_up($openday, (int) $family('Luis')->id, 1);
        $own = $act('Visita de una familia', ['kind' => 'visit']);
        $marta = (int) $family('Marta')->id;
        $DB->set_field('local_oksigeniaeguahe_event', 'householdid', $marta, ['id' => $own->id]);
        events::sign_up($own, $marta, 1);
        $cancelled = $act('Charla anulada');
        $DB->set_field('local_oksigeniaeguahe_event', 'cancelled', 1, ['id' => $cancelled->id]);
        $act('Pidan su visita', ['kind' => 'visit', 'tailored' => 1]);

        $items = teamcalendar::items($centre);
        $titles = array_column($items, 'title');
        $this->assertCount(2, $items, implode(' | ', $titles));
        $c = 'local_oksigeniaeguahe';
        $this->assertContains(get_string('cal_act_title', $c, (object) ['name' => 'Jornada de puertas abiertas',
            'n' => get_string('cal_families', $c, 2)]), $titles);
        $this->assertContains(get_string('cal_visit_title', $c, 'Marta Ejemplo'), $titles);
        $this->assertSame($start, $items[0]['start']);

        $ics = teamcalendar::ics($centre, $items);
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
        $data = teamcalendar::data($centre, $items);
        $this->assertSame(2, count($data['items']));
        $this->assertStringContainsString('UrlFetchApp', teamcalendar::script($centre));

        $key = teamcalendar::key($centreid);
        $this->assertTrue(teamcalendar::key_ok($centreid, $key));
        $this->assertFalse(teamcalendar::key_ok($centreid, ''));
        $new = teamcalendar::key($centreid, true);
        $this->assertNotSame($key, $new);
        $this->assertFalse(teamcalendar::key_ok($centreid, $key));
        $this->assertTrue(teamcalendar::key_ok($centreid, $new));
    }
}
