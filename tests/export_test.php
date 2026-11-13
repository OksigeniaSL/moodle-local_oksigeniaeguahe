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
use local_oksigeniaeguahe\local\export;
use local_oksigeniaeguahe\local\households;

/**
 * What a centre takes away in a spreadsheet (0.59.0): its families, a row for each child with the family beside it;
 * and the figures of each campaign, without a name or a way to reach anyone. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(export::class)]
final class export_test extends \advanced_testcase {
    /**
     * The families: a row for each child, the family repeated beside it; one row for a family without children.
     * The figures: no name, no email, no phone.
     */
    public function test_families_and_figures(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com', 'phone' => '+34600123456']);
        households::add_child($household, 'Leonardo', $offer);
        households::add_child($household, 'Sara', $offer);
        $alone = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'phone']), $id);
        households::add_adult($alone, (object) ['firstname' => 'Luis', 'lastname' => 'Prueba']);
        $centre = centres::get($id);

        $columns = export::family_columns();
        $rows = export::families($centre);
        $this->assertCount(3, $rows, 'Two children and a family without any.');
        $this->assertSame(array_keys($columns), array_keys($rows[0]));
        $byname = array_column($rows, null, 'child');
        $this->assertSame('Ana Ejemplo', $byname['Leonardo']['family']);
        $this->assertSame('ana@example.com', $byname['Sara']['emails']);
        $this->assertSame('Infantil 3 años · 2027-28', $byname['Sara']['course']);
        $this->assertSame('Luis Prueba', $byname['']['family']);

        $figures = export::figures($centre);
        $this->assertCount(2, $figures);
        $flat = json_encode($figures, JSON_UNESCAPED_UNICODE);
        foreach (['Ana', 'Ejemplo', 'ana@example.com', '600123456', 'Leonardo'] as $personal) {
            $this->assertStringNotContainsString($personal, $flat);
        }
        $this->assertSame(array_keys(export::figure_columns()), array_keys($figures[0]));
    }

    /**
     * A name written by a family never becomes a formula in the sheet; a phone stays as it is.
     */
    public function test_no_formulas(): void {
        $this->assertSame("'=HYPERLINK(\"https://x.example\")", export::cell('=HYPERLINK("https://x.example")'));
        $this->assertSame("'+cmd", export::cell('+cmd'));
        $this->assertSame("'@SUM(A1)", export::cell('@SUM(A1)'));
        $this->assertSame('+34600123456', export::cell('+34600123456'));
        $this->assertSame('Ana', export::cell('Ana'));
    }
}
