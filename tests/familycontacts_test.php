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
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\hobbies;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\writeby;

/**
 * What a family changes itself on its page: its contact data, another person of the family written to the same way,
 * and what exactly it likes doing. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(households::class)]
final class familycontacts_test extends \advanced_testcase {
    /**
     * The contact data changed by the family, and another person added with the ways the family is written to by.
     */
    public function test_contacts(): void {
        global $DB;
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $ana = households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com', 'phone' => '+34600111222']);
        households::consent($household, $ana, 'email', true, 'form', 'es');
        writeby::set($household, ['email'], 'family');

        $this->assertTrue(households::family_contacts(
            $household,
            $centre,
            [$ana => ['firstname' => 'Ana María',
            'lastname' => 'Ejemplo', 'phone' => '600 333 444', 'email' => 'ana.maria@example.com']],
            ['firstname' => 'Luis', 'lastname' => 'Ejemplo', 'phone' => '', 'email' => 'luis@example.com']
        ));
        $adults = array_values(households::full($household)->adults);
        $this->assertCount(2, $adults);
        $this->assertSame('Ana María', $adults[0]->firstname);
        $this->assertSame('+34600333444', $adults[0]->phone);
        $this->assertSame('ana.maria@example.com', $adults[0]->email);
        $this->assertSame('Luis', $adults[1]->firstname);
        // The family is written to by email: so is the new person.
        $this->assertNotEmpty(households::permissions((int) $household->id)[$adults[1]->id]['email']);
        $this->assertTrue(familymail::may_email($household));
        // Nothing changed, nothing said; a name left empty keeps the one it had.
        $this->assertFalse(households::family_contacts(
            $household,
            $centre,
            [$ana => ['firstname' => '',
            'lastname' => 'Ejemplo', 'phone' => '+34 600 333 444', 'email' => 'ana.maria@example.com']],
            ['firstname' => '', 'lastname' => '', 'phone' => '', 'email' => '']
        ));
        $this->assertSame('Ana María', $DB->get_field(households::ADULTS, 'firstname', ['id' => $ana]));
    }

    /**
     * What exactly they like doing: kept with each hobby, said in the file and on their page.
     */
    public function test_hobby_details(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $ana = households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com']);
        $this->assertTrue(hobbies::save($household, $ana, ['sport', 'reading'], '', time(), ['sport' => '  pádel ',
            'dance' => 'salsa']));
        $told = hobbies::of((int) $household->id)[$ana];
        $this->assertSame(['sport', 'reading'], $told['keys']);
        $this->assertSame(['sport' => 'pádel'], $told['details']);
        $this->assertSame('Deporte (pádel) y lectura', hobbies::chips($told, 'es'));
    }
}
