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
        global $CFG;
        $this->resetAfterTest();
        $this->assertSame([], chatpanel::origins());
        set_config('chatorigins', "https://Chat.Example.com/\nftp://x.example.com\nhttp://localhost:8199\nhttp://example.net\n" .
            "https://chat.example.com", 'local_oksigeniaeguahe');
        $this->assertSame(['https://chat.example.com', 'http://localhost:8199'], chatpanel::origins());
        // The pages of the site itself (the conversations of Marandu) are listened to as well.
        $parts = parse_url($CFG->wwwroot);
        $own = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
        $this->assertSame(['https://chat.example.com', 'http://localhost:8199', $own], chatpanel::listened());
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
        $this->assertSame('Lucía', $found['families'][0]['children'][0]['name']);
        $this->assertSame([true, false, false, false, false], array_column($found['families'][0]['children'][0]['track'], 'here'));
        $this->assertCount(1, chatpanel::find(2, '', '+34 600111222')['families'], 'The same phone, written another way.');
        $this->assertTrue(chatpanel::find(2, 'otra@example.com', '+34600999888')['nobody']);

        // A brother already enrolled: of the centre, and asking.
        $brother = households::add_child($household, 'Pablo', $offer);
        $DB->set_field(households::CHILDREN, 'stage', 'enrolled', ['id' => $brother]);
        $this->assertSame('both', chatpanel::find(2, 'ana@example.com', '')['families'][0]['kind']);

        // A waiting list is a pause after the application; how long, in short.
        $wait = chatpanel::child('Leo', 'Infantil 3 años', 'waitlist', false);
        $this->assertSame([true, true, true, false, false], array_column($wait['track'], 'done'));
        $this->assertSame([false, false, false, true, false], array_column($wait['track'], 'pause'));
        $this->assertSame(get_string('chat_span_days', 'local_oksigeniaeguahe', 7), chatpanel::span(7 * DAYSECS + 60));
        $this->assertSame(get_string('chat_span_hour', 'local_oksigeniaeguahe', 1), chatpanel::span(HOURSECS));

        // Nobody outside the team.
        $user = $this->getDataGenerator()->create_user();
        $this->assertTrue(chatpanel::find((int) $user->id, 'ana@example.com', '')['nobody']);
    }

    /**
     * A day the team noted in its sheet is the next visit too, here and in what the chat is told.
     */
    public function test_next_of_the_sheet(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        centres::add_member($cid, (int) $USER->id);
        $household = households::get(households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $cid);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'phone' => '+34600111333']);
        $this->assertNull(chatpanel::next($household, households::full($household), time()));
        $DB->insert_record(\local_oksigeniaeguahe\local\teamnotes::T, (object) ['centreid' => $cid, 'householdid' => $household->id,
            'mark' => sha1('cita'), 'entryid' => 3, 'contactid' => 0, 'visit' => time() + 4 * DAYSECS, 'dayonly' => 0, 'seen' => time(),
            'timecreated' => time()]);
        $next = chatpanel::next($household, households::full($household), time());
        $this->assertNotNull($next);
        $this->assertFalse($next['act']);
        $lines = \local_oksigeniaeguahe\local\chatcontext::lines('+34600111333', (int) $USER->id, time());
        $this->assertStringContainsString(\local_oksigeniaeguahe\local\panel::day_hour($next['start']), implode("\n", $lines));
    }
}
