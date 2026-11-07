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
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\metrics;

/**
 * The families that came through Eguahe, by where they came from: each once, as far as it went; those moving to the
 * area apart, and those already of the centre only counted. Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\metrics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(metrics::class)]
final class live_test extends \advanced_testcase {
    /**
     * Three families of a form shared on Instagram, one that moves and one of the centre.
     */
    public function test_by_where_they_came(): void {
        global $DB;
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1, 'sortorder' => 0]);
        $form = entries::get(entries::create($id, 'form', 'Pidan información'), $id);
        $make = function (array $about, string $stage, bool $fromform) use ($id, $offer, $form, $DB) {
            $household = households::get(households::create($id, (object) ($about + ['lang' => 'es',
                'firstchannel' => $fromform ? 'webform' : 'phone'])), $id);
            $child = households::add_child($household, 'Leo', $offer);
            households::received(
                $household,
                $fromform ? 'webform' : 'phone',
                'Datos de ejemplo.',
                true,
                null,
                $fromform ? (int) $form->id : 0,
                $fromform ? 'Instagram' : ''
            );
            households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), $stage);
            return $household;
        };
        $make([], 'inquiry', true);
        $make([], 'visit', true);
        $make([], 'enrolled', true);
        $make([], 'closed', false);
        $make(['moving' => 1], 'visit', true);
        $make(['incentre' => 1], 'enrolled', true);

        $live = metrics::live($id);
        $this->assertSame(1, $live['incentre']);
        $byname = array_column($live['rows'], null, 'name');
        $instagram = $byname['Pidan información · Instagram'];
        $this->assertSame([3, 2, 1, 1, 1, 0], [$instagram['households'], $instagram['visited'], $instagram['applied'],
            $instagram['admitted'], $instagram['enrolled'], $instagram['closed']]);
        $phone = $byname[get_string('channel_phone', 'local_oksigeniaeguahe')];
        $this->assertSame(1, $phone['closed']);
        $this->assertSame(4, $live['total']['households']);
        $this->assertSame(1, $live['moving']['households'], 'Moving families go apart.');
        $this->assertSame(1, $live['moving']['visited']);
        $this->assertSame('Pidan información · Instagram', $live['rows'][0]['name'], 'The one that enrolls more, first.');
    }
}
