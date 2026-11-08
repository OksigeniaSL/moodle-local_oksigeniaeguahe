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

use local_oksigeniaeguahe\local\arabic;
use local_oksigeniaeguahe\local\sticker;

/**
 * Names in Arabic script on the labels drawn with GD: each letter joined and in visual order, as the art director
 * checked them with the real GD (the fourteen names of her test); other names untouched; and the labels drawn.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(arabic::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sticker::class)]
final class arabic_test extends \advanced_testcase {
    /**
     * The fourteen names of the test of the art director, joined and in visual order as GD needs them.
     */
    public function test_for_gd(): void {
        $cases = [
            ['ليلى', 'ﻰﻠﻴﻟ'],
            ['يوسف', 'ﻒﺳﻮﻳ'],
            ['مريم', 'ﻢﻳﺮﻣ'],
            ['عمر', 'ﺮﻤﻋ'],
            ['آدم', 'مدآ'],
            ['حمزة', 'ةﺰﻤﺣ'],
            ['نور', 'رﻮﻧ'],
            ['عبد الله', 'ﻪﻠﻟا ﺪﺒﻋ'],
            ['سلمى', 'ﻰﻤﻠﺳ'],
            ['خديجة', 'ﺔﺠﻳﺪﺧ'],
            ['إلياس', 'سﺎﻴﻟإ'],
            ['محمد أمين', 'ﻦﻴﻣأ ﺪﻤﺤﻣ'],
            ['ياسمين Yasmin', 'Yasmin ﻦﻴﻤﺳﺎﻳ'],
            ['Yasmin ياسمين', 'Yasmin ﻦﻴﻤﺳﺎﻳ'],
        ];
        foreach ($cases as [$name, $gd]) {
            $this->assertTrue(arabic::has($name), $name);
            $this->assertSame($gd, arabic::for_gd($name), $name);
        }
        foreach (['Leo', 'Олена', 'Diego', '2B'] as $name) {
            $this->assertFalse(arabic::has($name));
            $this->assertSame($name, arabic::for_gd($name));
        }
    }

    /**
     * The label and the sticker of the bottle, with a name in Arabic and one with a tail, are drawn.
     */
    public function test_stickers(): void {
        $this->resetAfterTest();
        if (!sticker::available()) {
            $this->markTestSkipped('No GD with FreeType here.');
        }
        foreach (['نور', 'Diego', 'Yasmin ياسمين'] as $name) {
            $this->assertStringStartsWith("\x89PNG", sticker::png($name, '#2f5d62', 1));
            $this->assertStringStartsWith("\x89PNG", sticker::bottle($name, '#2f5d62', 1));
        }
    }
}
