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

use local_oksigeniaeguahe\local\phone;

/**
 * Phone numbers as one form, whichever way a family typed them.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\phone
 */
#[\PHPUnit\Framework\Attributes\CoversClass(phone::class)]
final class phone_test extends \basic_testcase {
    /**
     * Numbers with and without their country code, with spaces, dots and trunk zeros.
     */
    public function test_normalise(): void {
        $this->assertSame('+34600111222', phone::normalise('600 11 12 22', 'ES'));
        $this->assertSame('+34600111222', phone::normalise('+34 600-111-222', 'PT'));
        $this->assertSame('+34600111222', phone::normalise('0034 600 111 222', null));
        $this->assertSame('+351912345678', phone::normalise('912 345 678', 'PT'));
        $this->assertSame('+5511987654321', phone::normalise('(011) 98765-4321', 'BR'));
        $this->assertSame('+595981123456', phone::normalise('0981 123 456', 'PY'));
        $this->assertSame('+447700900123', phone::normalise('07700 900123', 'GB'));
    }

    /**
     * What cannot be told is not guessed.
     */
    public function test_unknown(): void {
        $this->assertNull(phone::normalise('', 'ES'));
        $this->assertNull(phone::normalise('600111222', null));
        $this->assertNull(phone::normalise('600111222', 'ZZ'));
        $this->assertNull(phone::normalise('12', 'ES'));
        $this->assertNull(phone::normalise('+1234567890123456', 'ES'));
    }
}
