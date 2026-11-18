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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\signup;

/**
 * The student who comes on their own: who signs up at each centre, their sign-up as one household of one, and being
 * spoken to as one person.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\signup
 */
#[\PHPUnit\Framework\Attributes\CoversClass(signup::class)]
final class self_test extends \advanced_testcase {
    /**
     * Who signs up: a family at a school, the student at a college, either where the centre says both.
     */
    public function test_who_signs_up(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Northgate College', 'lang' => 'en', 'retention' => 24]);
        $centre = centres::get($id);
        $this->assertSame('family', $centre->whoapplies);
        $this->assertFalse(signup::for_self($centre, 'yo'));
        centres::update($id, (object) ['whoapplies' => 'student']);
        $this->assertTrue(signup::for_self(centres::get($id), ''));
        centres::update($id, (object) ['whoapplies' => 'both']);
        $this->assertTrue(signup::for_self(centres::get($id), 'yo'));
        $this->assertFalse(signup::for_self(centres::get($id), 'familia'));
        centres::update($id, (object) ['whoapplies' => 'nonsense']);
        $this->assertSame('family', centres::get($id)->whoapplies);
    }

    /**
     * Their sign-up: they are the adult and the only one who would study, with the course they chose; nobody else.
     */
    public function test_as_self(): void {
        $data = [
            'adults' => [['firstname' => 'Raúl', 'lastname' => 'Medina', 'email' => 'raul@example.com', 'phone' => ''],
                ['firstname' => 'Ana', 'lastname' => 'Medina', 'email' => '', 'phone' => '']],
            'children' => [['firstname' => 'Hugo', 'tagname' => 'Hu', 'birthdate' => '', 'latinname' => '', 'offerid' => 7],
                ['firstname' => 'Vega', 'tagname' => '', 'birthdate' => '', 'latinname' => '', 'offerid' => 8]],
            'siblings' => [['firstname' => 'Leo', 'birthyear' => 2025]],
            'self' => false,
        ];
        $self = signup::as_self($data);
        $this->assertTrue($self['self']);
        $this->assertSame('Raúl', $self['children'][0]['firstname']);
        $this->assertSame(7, $self['children'][0]['offerid']);
        $this->assertSame('', $self['children'][0]['tagname']);
        $this->assertSame('', $self['children'][1]['firstname']);
        $this->assertSame('', $self['adults'][1]['firstname']);
        $this->assertSame('', $self['siblings'][0]['firstname']);
    }

    /**
     * Their household says so, and their page and mails speak to them as one person where a string has its variant.
     */
    public function test_spoken_to_as_one(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Northgate College', 'lang' => 'es', 'retention' => 24]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'self' => 1]), $id);
        $this->assertSame(1, (int) $household->self);
        $other = households::get(households::create($id, (object) ['lang' => 'es']), $id);
        $this->assertSame(0, (int) $other->self);

        $own = get_string('panel_story_after_close_self', 'local_oksigeniaeguahe');
        $family = get_string('panel_story_after_close', 'local_oksigeniaeguahe');
        publiclang::speak_to($household);
        $this->assertSame($own, publiclang::str('panel_story_after_close', 'en'));
        publiclang::speak_to($other);
        $this->assertSame($family, publiclang::str('panel_story_after_close', 'en'));
    }
}
