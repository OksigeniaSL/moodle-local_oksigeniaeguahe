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
use local_oksigeniaeguahe\local\chatpanel;
use local_oksigeniaeguahe\local\households;

/**
 * The family beside a conversation of the chat of the centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\chatpanel
 */
#[\PHPUnit\Framework\Attributes\CoversClass(chatpanel::class)]
final class chatpanel_test extends \advanced_testcase {
    /**
     * Only the chats of the setting, written well, may show the page.
     */
    public function test_origins(): void {
        $this->resetAfterTest();
        $this->assertSame([], chatpanel::origins());
        set_config('chatorigins', "https://Chat.Example.com/\nftp://x.example.com\nhttp://localhost:8199\nhttp://example.net\n" .
            "https://chat.example.com", 'local_oksigeniaeguahe');
        $this->assertSame(['https://chat.example.com', 'http://localhost:8199'], chatpanel::origins());
    }

    /**
     * Who writes, by the email or the phone the chat knows, as each way in wrote them: a family that asks for a place,
     * one of the centre, both, or nobody.
     */
    public function test_find(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($cid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 0,
            'active' => 1]);
        $household = households::get(households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $cid);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => 'ana@example.com',
            'phone' => '600 111 222']);
        households::add_child($household, 'Lucía', $offer);

        $found = chatpanel::find(2, 'ANA@example.com', '');
        $this->assertCount(1, $found['families']);
        $this->assertSame('asking', $found['families'][0]['kind']);
        $this->assertStringContainsString('Lucía', $found['families'][0]['children'][0]['text']);
        $this->assertCount(1, chatpanel::find(2, '', '+34 600111222')['families'], 'The same phone, written another way.');
        $this->assertTrue(chatpanel::find(2, 'otra@example.com', '+34600999888')['nobody']);

        // A brother already enrolled: of the centre, and asking.
        $brother = households::add_child($household, 'Pablo', $offer);
        $DB->set_field(households::CHILDREN, 'stage', 'enrolled', ['id' => $brother]);
        $this->assertSame('both', chatpanel::find(2, 'ana@example.com', '')['families'][0]['kind']);

        // Nobody outside the team.
        $user = $this->getDataGenerator()->create_user();
        $this->assertTrue(chatpanel::find((int) $user->id, 'ana@example.com', '')['nobody']);
    }
}
