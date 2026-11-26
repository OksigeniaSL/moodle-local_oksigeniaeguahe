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
use local_oksigeniaeguahe\local\panel_centre;

/**
 * What is still to be set in a centre, at the top of its settings: it says only what is missing, and goes as it is set.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\panel_centre
 */
#[\PHPUnit\Framework\Attributes\CoversClass(panel_centre::class)]
final class settings_missing_test extends \advanced_testcase {
    /**
     * A new centre misses everything that shows; set, it goes; a centre of practice says nothing.
     */
    public function test_missing(): void {
        global $DB;
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 0, 'active' => 1]);
        $anchors = fn() => array_map(
            fn($item) => parse_url($item['url'], PHP_URL_FRAGMENT),
            (panel_centre::missing(centres::get($id)) ?: ['items' => []])['items']
        );
        $all = ['aj-reciben', 'aj-contacto', 'aj-miniatura', 'aj-datos', 'aj-oferta', 'aj-zona', 'aj-despues', 'inicios'];
        $this->assertSame($all, $anchors());
        $DB->update_record(centres::T, (object) ['id' => $id, 'arrive' => 'Por la puerta del patio', 'municipalities' => 'La Laguna',
            'controller' => 'Colegio de ejemplo', 'rightscontact' => 'privacidad@example.com', 'handoverplace' => 'Secretaría',
            'campaignstart' => time() - 30 * DAYSECS]);
        $DB->set_field(centres::OFFERS, 'places', 25, ['centreid' => $id]);
        $this->assertSame(['aj-reciben', 'aj-miniatura'], $anchors());
        $DB->set_field(centres::T, 'practice', 1, ['id' => $id]);
        $this->assertFalse(panel_centre::missing(centres::get($id)));
    }
}
