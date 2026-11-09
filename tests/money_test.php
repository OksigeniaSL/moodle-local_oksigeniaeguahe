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

/**
 * The currency of a centre (0.41.0): the one it chose, or else the one of its country from the international data,
 * never a currency written in the code; and its amounts as the language of the page writes them.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(centres::class)]
final class money_test extends \advanced_testcase {
    /**
     * Each country its currency; one chosen wins; without a country, the number alone.
     */
    public function test_currency(): void {
        $this->resetAfterTest();
        foreach (['ES' => 'EUR', 'BR' => 'BRL', 'US' => 'USD', 'AU' => 'AUD', 'MX' => 'MXN', 'GB' => 'GBP'] as $country => $code) {
            $this->assertSame($code, centres::currency((object) ['country' => $country]), $country);
        }
        $this->assertSame('USD', centres::currency((object) ['country' => 'ES', 'currency' => 'USD']));
        $this->assertSame('', centres::currency((object) ['country' => null]));
        $id = centres::create((object) ['name' => 'Example School', 'lang' => 'en', 'country' => 'AU']);
        $centre = centres::get($id);
        $this->assertNull($centre->currency, 'Nothing chosen: the one of its country.');
        $this->assertStringContainsString('1,234', centres::money($centre, 1234.4));
        $this->assertStringContainsString('$', centres::money($centre, 1234.4));
        $this->assertSame('1234', centres::money((object) ['country' => null], 1234.4));
        centres::update($id, (object) ['name' => 'Example School', 'lang' => 'en', 'country' => 'AU', 'currency' => 'eur']);
        $this->assertSame('EUR', centres::get($id)->currency);
    }
}
