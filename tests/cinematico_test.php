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
use local_oksigeniaeguahe\local\cinematico;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\places;

/**
 * The cinematic skin (0.42.0): the river of the inbox, the constellation of towns, and where the towns are, found in
 * any country with a source of the test, the nearest to the centre when a name is in several places. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cinematico::class)]
final class cinematico_test extends \advanced_testcase {
    /**
     * Back to the source of always.
     */
    protected function tearDown(): void {
        places::$testanswer = null;
        parent::tearDown();
    }

    /**
     * A centre with the cinematic skin.
     *
     * @return \stdClass
     */
    protected function centre(): \stdClass {
        global $DB;
        $id = centres::create((object) ['name' => 'Example College', 'lang' => 'en', 'country' => 'AU',
            'address' => '1 Example Street, Example Town']);
        $DB->set_field(centres::T, 'skin', 'cinematico', ['id' => $id]);
        return centres::get($id);
    }

    /**
     * The river: the six that have waited longest, each placed by its wait and in a lane, the days along it; and the
     * river empty when nobody waits. What hangs follows the ladder of ages, as in every skin.
     */
    public function test_river(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $centre = $this->centre();
        $now = time();
        $this->assertTrue(cinematico::river($centre, [], 2, $now)['empty']);
        foreach ([40 * MINSECS, 5 * HOURSECS, 26 * HOURSECS, 3 * DAYSECS, 50 * MINSECS, 2 * HOURSECS, 9 * HOURSECS] as $wait) {
            $h = households::get(
                households::create((int) $centre->id, (object) ['lang' => 'en', 'firstchannel' => 'webform']),
                (int) $centre->id
            );
            households::add_adult($h, (object) ['firstname' => 'Sam', 'lastname' => 'Example', 'email' => 'sam' . $h->id .
                '@example.com']);
            $DB->set_field(households::T, 'waitingsince', $now - $wait, ['id' => $h->id]);
        }
        $river = cinematico::river($centre, households::inbox((int) $centre->id, true, 60), 2, $now);
        $this->assertFalse($river['empty']);
        $this->assertCount(cinematico::LANTERNS, $river['lanterns']);
        $xs = array_column($river['lanterns'], 'x');
        $sorted = $xs;
        rsort($sorted);
        $this->assertSame($sorted, $xs, 'In the order of the queue: the longest first.');
        $this->assertGreaterThan(0.9, $xs[0], 'The longest wait, near the counter.');
        $this->assertLessThan(1, max($xs));
        foreach ($river['lanterns'] as $lantern) {
            $this->assertContains($lantern['lane'], [0, 1, 2]);
        }
        $this->assertSame(get_string('cine_today', 'local_oksigeniaeguahe'), $river['days'][0]['name']);
        $this->assertNotEmpty($river['cuts']);
        $paper = clone $centre;
        $paper->skin = 'mostrador';
        foreach (['', 'Year 1', 'Year 5', 'Year 9', 'Year 12', 'English B2'] as $course) {
            $this->assertSame(panel::hanging_object($course, $paper), panel::hanging_object($course, $centre), $course);
        }
        $this->assertSame('mochila', panel::hanging_object('', $centre));
        $this->assertSame('credencial', panel::hanging_object('Year 12', $centre));
    }

    /**
     * Where the towns are: the centre by its address, each town by its name in the country of the centre, the nearest
     * to the centre when a name is in several places; not found, not asked again for a month.
     */
    public function test_locate(): void {
        $this->resetAfterTest();
        $centre = $this->centre();
        centres::update((int) $centre->id, (object) ['name' => 'Example College', 'lang' => 'en', 'country' => 'AU',
            'address' => '1 Example Street, Example Town', 'municipalities' => "1;Northside\n2;Riverbend\n3;Nowhere"]);
        $centre = centres::get((int) $centre->id);
        $asked = [];
        places::$testanswer = function (string $url) use (&$asked) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $asked[] = $q['q'] . '|' . ($q['countrycodes'] ?? '');
            return [
                '1 Example Street, Example Town' => [['lat' => '-33.0', 'lon' => '151.0']],
                // Two towns of that name: the one near the centre goes.
                'Northside' => [['lat' => '-12.0', 'lon' => '130.0'], ['lat' => '-32.9', 'lon' => '151.1']],
                'Riverbend' => [['lat' => '-33.2', 'lon' => '150.8']],
            ][$q['q']] ?? [];
        };
        $now = time();
        $this->assertSame(4, places::locate($centre, 10, $now));
        $this->assertContains('Northside|au', $asked, 'In the country of the centre.');
        $known = places::known((int) $centre->id);
        $this->assertSame([-33.0, 151.0], $known[places::CENTRE]);
        $this->assertSame([-32.9, 151.1], $known[places::key('Northside')]);
        $this->assertArrayNotHasKey(places::key('Nowhere'), $known);
        $this->assertSame(0, places::locate($centre, 10, $now + DAYSECS), 'Nothing new to ask.');
        $this->assertSame(1, places::locate($centre, 10, $now + places::RETRY + DAYSECS), 'Not found, asked again a month later.');

        // The constellation: each town at its bearing, the centre in the glow; the one without a place below, by its count.
        $stars = cinematico::constellation(
            $centre,
            [['Northside', 8, null], ['Riverbend', 3, null], ['Nowhere', 2, null]],
            'Each light is a town.',
            false
        );
        $this->assertCount(2, $stars['stars']);
        $north = $stars['stars'][0];
        $this->assertLessThan(0.5, $north['y'], 'North is up.');
        $this->assertGreaterThan(0.33, $north['x'], 'East is right.');
        $this->assertEquals(1.0, $north['t']);
        $this->assertSame('Nowhere', $stars['unplaced'][0]['name']);
        $this->assertStringContainsString('OpenStreetMap', $stars['foot'], 'Its source, cited.');
        $this->assertNotFalse($stars['centre'], 'No town where the centre is: its own light.');
        // A town where the centre is: one light, the centre's, with both names.
        places::keep((int) $centre->id, 'Example Town', [-33.005, 151.004], 'nominatim', $now);
        $merged = cinematico::constellation($centre, [['Example Town', 6, null], ['Northside', 8, null]], 'Each light.', false);
        $this->assertFalse($merged['centre']);
        $home = array_column($merged['stars'], null, 'name')['Example Town'];
        $this->assertTrue($home['iscentre']);
        $this->assertSame('Example College', $home['centrename']);
        $this->assertFalse(array_column($merged['stars'], null, 'name')['Northside']['iscentre']);
        foreach ($stars['stars'] as $star) {
            foreach (['x', 'y', 'xm', 'ym'] as $axis) {
                $this->assertGreaterThanOrEqual(0.09, $star[$axis]);
                $this->assertLessThanOrEqual(0.91, $star[$axis]);
            }
        }
    }
}
