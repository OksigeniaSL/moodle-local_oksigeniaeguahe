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

use local_oksigeniaeguahe\local\antispam;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\signup;

/**
 * The page of the family (DESIGN.md of Gaudí): the colours of any centre stay readable, the name used at home heads the
 * page, the people who receive the family are those the centre shows, a way to write is asked for, and the families
 * moving to the area are noted. Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\familypage
 * @covers     \local_oksigeniaeguahe\local\publicpage
 */
#[\PHPUnit\Framework\Attributes\CoversClass(familypage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(publicpage::class)]
final class familypage_test extends \advanced_testcase {
    /**
     * Any colour a centre gives: text at 4.5:1 on the wall, fills at 3:1 in dark, the right ink on top.
     */
    public function test_centre_colours(): void {
        foreach (['#1f5a4e', '#f2b705', '#ffffff', '#000000', '#e53935', '#8ecae6', '#6a1b9a'] as $colour) {
            foreach ([false, true] as $dark) {
                $v = publicpage::centre_vars($colour, $dark);
                $this->assertGreaterThanOrEqual(
                    4.5,
                    publicpage::contrast($v['--c-acento-texto'], $v['--c-pared']),
                    "$colour text on the wall"
                );
                if ($dark) {
                    $this->assertGreaterThanOrEqual(3, publicpage::contrast($v['--c-acento-vivo'], '#211D1B'), "$colour fill");
                }
                $this->assertGreaterThanOrEqual(
                    4.5,
                    publicpage::contrast($v['--c-sobre-vivo'], $v['--c-acento-vivo']) + 1,
                    "$colour ink on the button"
                );
            }
        }
        $this->assertSame('#FFFFFF', publicpage::centre_vars('#1F5A4E', false)['--c-sobre-acento']);
        $this->assertSame('#1A1614', publicpage::centre_vars('#F2B705', false)['--c-sobre-acento']);
    }

    /**
     * The page of a family just signed up: the names on the labels, who receives them, and their way to be written to.
     */
    public function test_family_page(): void {
        global $DB;
        $this->resetAfterTest();
        antispam::$testpass = true;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'address' => 'Calle de ejemplo 1', 'email' => 'admisiones@example.com']);
        $centre = centres::get($id);
        $offerid = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $lucia = $this->getDataGenerator()->create_user(['firstname' => 'Lucía', 'lastname' => 'Medina']);
        $hidden = $this->getDataGenerator()->create_user(['firstname' => 'Pablo', 'lastname' => 'Sosa']);
        centres::add_member($id, (int) $lucia->id);
        centres::add_member($id, (int) $hidden->id);
        $member = $DB->get_record(centres::MEMBERS, ['centreid' => $id, 'userid' => $lucia->id]);
        $other = $DB->get_record(centres::MEMBERS, ['centreid' => $id, 'userid' => $hidden->id]);
        $DB->update_record(centres::MEMBERS, (object) ['id' => $member->id, 'public' => 1, 'rolename' => 'Tutora',
            'says' => 'Les enseño la clase.']);
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'timestart' => time() + DAYSECS, 'timeend' => time() + DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1,
            'hosts' => [$other->id, $member->id]]);
        $event = events::get($eventid, $id);
        // Only the people the centre shows can receive the families.
        $this->assertSame((string) $member->id, $event->hosts);

        $data = [
            'adults' => [['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@example.com', 'phone' => ''],
                ['firstname' => '', 'lastname' => '', 'email' => '', 'phone' => '']],
            'children' => [['firstname' => 'Leonardo', 'tagname' => 'Leo', 'offerid' => $offerid],
                ['firstname' => 'Noa', 'tagname' => '', 'offerid' => 0]],
            'people' => 3, 'slot' => 0, 'incentre' => '', 'municipality' => 'moving', 'municipalityother' => 'Lisboa',
            'heardfrom' => '', 'message' => '', 'consents' => ['email' => 0, 'whatsapp' => 1, 'phone' => 0], 'altcha' => 'x',
        ];
        // WhatsApp without a phone is no way to write to them.
        $this->assertArrayHasKey('channels', signup::check($data, $event, $centre, 'es'));
        $data['consents']['email'] = 1;
        $this->assertSame([], signup::check($data, $event, $centre, 'es'));
        [$household, $token] = signup::save($data, $event, $centre, 'es');
        $this->assertSame(1, (int) $DB->get_field(households::T, 'moving', ['id' => $household->id]));
        $this->assertSame('Lisboa', $DB->get_field(households::T, 'municipalityname', ['id' => $household->id]));

        $page = familypage::context(households::get((int) $household->id, $id), $centre, 'es', $token, true);
        $this->assertSame('Leo y Noa', $page['names']);
        $this->assertSame(['Leo', 'Noa'], array_column($page['pegs']['pegs'], 'name'));
        $this->assertStringContainsString('los recibe Lucía', $page['lead']);
        $this->assertStringContainsString('dejarían sus mochilas', $page['lead']);
        $this->assertSame(['Lucía Medina'], array_column($page['hosts'], 'name'));
        $this->assertSame('En su etiqueta: Leo', $page['children'][0]['ontag']);
        $this->assertSame('', $page['children'][1]['ontag']);
        $this->assertSame('Les escribimos por correo.', $page['writeby']);
        $this->assertTrue($page['hasnext']);
        antispam::$testpass = null;
    }
}
