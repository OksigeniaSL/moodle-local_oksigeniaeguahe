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
use local_oksigeniaeguahe\local\guide;

/**
 * The guide of a centre: where it stands on the miniature, which way it looks, and what it says.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\guide
 */
#[\PHPUnit\Framework\Attributes\CoversClass(guide::class)]
final class guide_test extends \advanced_testcase {
    /** @var string A miniature with its way, going up and to the left. */
    const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000"><image href="data:image/jpeg;base64,AAAA"/>' .
        '<path class="ruta" d="M871.0 636.8 903.3 576.0 726.4 337.0" fill="none"/></svg>';

    /**
     * The way read from the picture: where it starts and where it ends, as parts of the picture.
     */
    public function test_read_way(): void {
        $way = guide::read_way(self::SVG);
        $this->assertEqualsWithDelta(0.5444, $way['x'], 0.0001);
        $this->assertEqualsWithDelta(0.6368, $way['y'], 0.0001);
        $this->assertEqualsWithDelta(0.454, $way['endx'], 0.0001);
        $this->assertSame([], guide::read_way('<svg viewBox="0 0 10 10"><path d="M1 1 2 2"/></svg>'), 'No way, no guide.');
        $this->assertSame([], guide::read_way('<svg><path class="ruta" d="M1 1 2 2"/></svg>'), 'No size, no guide.');
    }

    /**
     * On the page of a family: only with the guide on; at the entrance, looking where the way goes; and its words.
     */
    public function test_on_the_page(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'local_oksigeniaeguahe', 'filearea' => 'miniature', 'itemid' => $id, 'filepath' => '/',
            'filename' => 'camino.svg'], self::SVG);
        $this->assertFalse(guide::on_map(centres::get($id), 'miniature', false, 'es'), 'Without the guide, nothing.');
        $this->assertFalse(guide::hello(centres::get($id), 'es'));

        centres::update($id, (object) ['guide' => 'perro', 'guidewhere' => ' Secretaría ']);
        $centre = centres::get($id);
        $map = guide::on_map($centre, 'miniature', false, 'en');
        $spot = '--x:54.44%;--y:63.68%;--giro:-1;--alto:-4%;';
        $this->assertStringContainsString($spot, $map['style'], 'Looking left, below the way.');
        $this->assertSame(get_string('pub_guide_to', 'local_oksigeniaeguahe', 'Secretaría'), $map['bubble']);
        $this->assertStringContainsString('/pub/guia/perro-senala-256.webp', $map['img']['src']);
        $this->assertStringContainsString('perro-senala-512.webp 2x', $map['img']['srcset']);
        $this->assertSame('', $map['avoid'], 'This miniature has no labels.');
        $marks = guide::read_way(self::SVG . '<rect x="558" y="579.4" width="229.6" height="73"/>' .
            '<g transform="translate(726.9 304.0) scale(5.80)"><path d="M0 0"/></g>')['marks'];
        $this->assertCount(2, $marks, 'A label and the pin.');
        $this->assertEqualsWithDelta(0.345, $marks[0][0], 0.001);

        // By the greeting: the words of Eguahe, or those of the centre.
        $this->assertSame(get_string('pub_guide_hello', 'local_oksigeniaeguahe'), guide::hello($centre, 'en')['bubble']);
        centres::update($id, (object) ['guidehello' => '¡Bienvenidos!']);
        $this->assertSame('¡Bienvenidos!', guide::hello(centres::get($id), 'es')['bubble']);

        // By who receives: where the first of them waits for them, or that they open the door.
        $hosts = [['first' => 'Marta', 'name' => 'Marta Ruiz'], ['first' => 'Javier', 'name' => 'Javier Pérez']];
        $waits = get_string('pub_guide_waits', 'local_oksigeniaeguahe', (object) ['name' => 'Marta', 'where' => 'Secretaría']);
        $this->assertSame($waits, guide::presents($centre, $hosts, 'en')['bubble']);
        centres::update($id, (object) ['guidewhere' => '']);
        $opens = guide::presents(centres::get($id), $hosts, 'en');
        $this->assertSame(get_string('pub_guide_opens', 'local_oksigeniaeguahe', 'Marta'), $opens['bubble']);
        $this->assertFalse(guide::presents($centre, [], 'es'), 'Nobody to introduce.');

        // Off again.
        centres::update($id, (object) ['guide' => 'no']);
        $this->assertSame('', guide::of(centres::get($id)));
    }
}
