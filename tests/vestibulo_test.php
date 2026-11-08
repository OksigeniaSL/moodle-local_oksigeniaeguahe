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
use local_oksigeniaeguahe\local\drawings;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\vestibulo;

/**
 * The skin «vestíbulo» (the living hall) over the paper desk: what the scene takes from Eguahe (the contract of the art
 * director): each child with the name used at home, its course, the age of its course, whether a brother or sister shares
 * it and its drawing from the page of the family; the families waiting in the order of the queue; the courses of the
 * cloakroom; what comes in through the door. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(vestibulo::class)]
final class vestibulo_test extends \advanced_testcase {
    /**
     * A centre with the living hall, two courses, and two families waiting: the first with twins and an older brother.
     *
     * @return array [centre, first household, second household, offers by name]
     */
    protected function hall(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES', 'skin' => 'vestibulo']);
        $offers = [];
        foreach (['Infantil 3 años' => 25, '5.º de Primaria' => 4] as $name => $places) {
            $offers[$name] = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => $name, 'places' => $places,
                'active' => 1, 'sortorder' => 0]);
        }
        $first = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_child($first, 'Leonardo', $offers['Infantil 3 años'], 'Leo');
        households::add_child($first, 'Noa', $offers['Infantil 3 años']);
        households::add_child($first, 'Bruno', $offers['5.º de Primaria']);
        $second = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_child($second, 'Vera', $offers['Infantil 3 años']);
        $DB->set_field(households::T, 'waitingsince', time() - 3 * DAYSECS, ['id' => $first->id]);
        $DB->set_field(households::T, 'waitingsince', time() - HOURSECS, ['id' => $second->id]);
        return [centres::get($id), $first, $second, $offers];
    }

    /**
     * The hall of the inbox: the families in the order of the queue, each child as the scene takes it, the hour, the
     * texts and whom the board says hello to; only with the living hall as the skin of the centre.
     */
    public function test_inbox(): void {
        [$centre, $first, , $offers] = $this->hall();
        $this->assertTrue(vestibulo::on($centre));
        $queue = households::inbox((int) $centre->id, true);
        $data = vestibulo::hall($centre, $queue, time());
        $this->assertSame('bandeja', $data['kind']);
        $this->assertCount(2, $data['families']);
        $kids = $data['families'][0]['children'];
        $this->assertSame(['Leo', 'Noa', 'Bruno'], array_column($kids, 'tag'));
        $this->assertSame([3, 3, 10], array_column($kids, 'age'));
        $this->assertSame([true, true, false], array_column($kids, 'sibling_same_course'));
        $this->assertArrayNotHasKey('drawing', $kids[0], 'The inbox never shows the drawings.');
        $this->assertSame('Vera', $data['families'][1]['children'][0]['tag']);
        $this->assertSame('Hello, {names}!', $data['texts']['hello'], 'In the language of whoever looks.');
        $this->assertCount(7, $data['texts']['days']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00$/', $data['date']);
        $this->assertSame([], $data['greet']);
        $this->assertStringEndsWith('/local/oksigeniaeguahe/', $data['base']);

        // The card over the hall, and the context of the page with it.
        $card = vestibulo::card($queue, time());
        $this->assertSame('Leo, Noa and Bruno', $card[0]['tags']);
        $context = panel_inbox::context($centre, false, (int) get_admin()->id, true);
        $this->assertNotFalse($context['hall']);
        $this->assertSame(2, count(json_decode($context['hall']['json'], true)['families']));

        // What comes in through the door: the children of the family that wrote last.
        $latest = panel_inbox::check($centre)['latest'];
        $this->assertSame('Vera', $latest['children'][0]['tag']);

        // With the paper desk alone, no hall.
        $centre->skin = 'mostrador';
        $this->assertFalse(panel_inbox::context($centre, false, (int) get_admin()->id, true)['hall']);
        $this->assertSame(0, $offers['Infantil 3 años'] - $offers['Infantil 3 años']);
    }

    /**
     * The hall of the page of a family: their children with the drawing of each from their own link, hung once they
     * came; and the cloakroom of the places.
     */
    public function test_family_and_cloakroom(): void {
        [$centre, $first] = $this->hall();
        $children = array_values(households::full($first)->children);
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);
        $this->assertTrue(drawings::save($children[0], (string) ob_get_clean()));
        $data = vestibulo::family($centre, households::full($first), false, time(), 'clave', 'es');
        $this->assertSame('familia', $data['kind']);
        $this->assertSame('¡Hola, {names}!', $data['texts']['hello'], 'In the language of the page of the family.');
        $this->assertFalse($data['hung']);
        $this->assertSame('landscape', $data['children'][0]['drawing']['orientation']);
        $this->assertStringContainsString('f.php?k=clave&dibujo=' . $children[0]->id, $data['children'][0]['drawing']['url']);
        $this->assertFalse($data['children'][1]['drawing']);

        $page = familypage::context($first, $centre, 'es', 'clave', false);
        $this->assertNotFalse($page['hall']);
        $this->assertSame('familia', json_decode($page['hall']['json'], true)['kind']);

        $course = json_decode(vestibulo::ropero(25, 3, 1, 'Infantil 3 años'), true);
        $this->assertSame(['places' => 25, 'enrolled' => 3, 'waiting' => 1, 'age' => 3], $course);
        $full = json_decode(vestibulo::ropero(4, 9, 0, '5.º de Primaria'), true);
        $this->assertSame(4, $full['enrolled'], 'Never more than places.');
        $this->assertSame('mochila', panel::hanging_object('Infantil 3 años'));
    }
}
