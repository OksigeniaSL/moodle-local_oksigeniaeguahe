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
use local_oksigeniaeguahe\local\familypage;

/**
 * The miniature of a centre on the page of a family: the way in by default, the cut for phones beside it, and where to
 * park for a big act.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\familypage
 */
#[\PHPUnit\Framework\Attributes\CoversClass(familypage::class)]
final class miniature_test extends \advanced_testcase {
    /**
     * Stores a picture of the miniature of a centre.
     *
     * @param int $centreid
     * @param string $area
     */
    protected function picture(int $centreid, string $area): void {
        get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'local_oksigeniaeguahe', 'filearea' => $area, 'itemid' => $centreid, 'filepath' => '/',
            'filename' => $area . '.svg'], '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 10"></svg>');
    }

    /**
     * Which picture a family sees with its visit.
     */
    public function test_which_picture(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $day = time() + 5 * DAYSECS;
        $small = events::save($id, (object) ['name' => 'Visita', 'kind' => 'visit', 'timestart' => $day,
            'timeend' => $day + HOURSECS]);
        $big = events::save($id, (object) ['name' => 'Jornada', 'kind' => 'openday', 'timestart' => $day,
            'timeend' => $day + HOURSECS, 'parking' => 1]);
        $visit = (object) ['eventid' => $small];
        $openday = (object) ['eventid' => $big];

        // Without a miniature, and without a visit, nothing.
        $this->assertFalse(familypage::miniature_context($centre, $visit, 'es'));
        $this->picture($id, 'miniatureparking');
        $this->assertFalse(familypage::miniature_context($centre, null, 'es'));
        $this->assertFalse(familypage::miniature_context($centre, $visit, 'es'));

        // The way in, and the cut for phones beside it.
        $this->picture($id, 'miniature');
        $this->picture($id, 'miniaturenarrow');
        $way = familypage::miniature_context($centre, $visit, 'es');
        $this->assertStringContainsString('/miniature/' . $id . '/miniature.svg', $way['src']);
        $this->assertStringContainsString('/miniaturenarrow/', $way['narrow']);
        $this->assertNotEmpty($way['here']);
        $this->assertNotEmpty($way['way']);

        // A big act: where to park, the whole, with no cut for phones.
        $park = familypage::miniature_context($centre, $openday, 'es');
        $this->assertStringContainsString('/miniatureparking/', $park['src']);
        $this->assertSame('', $park['narrow']);
        $this->assertNotSame($way['caption'], $park['caption']);

        // Only the pictures of the miniature, by their area.
        $this->assertNull(centres::miniature($id, 'logo'));
    }
}
