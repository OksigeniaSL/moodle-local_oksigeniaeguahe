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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\privacy\provider;

/**
 * Privacy of the people of the teams: what is kept about them, and how it goes.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\privacy\provider
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * A person of a team who wrote an answer: found, exported, and taken out without losing the family's history.
     */
    public function test_team_member(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo']);
        centres::add_member($centreid, $user->id);
        $household = households::get(households::create($centreid, (object) ['lang' => 'es']), $centreid);
        households::answered($household, $user->id, 'phone', 'Les esperamos el sábado.');
        $system = \context_system::instance();

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($user->id)->get_contextids());
        $this->assertEquals([], provider::get_contexts_for_userid($other->id)->get_contextids());
        $userlist = new userlist($system, 'local_oksigeniaeguahe');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$user->id], $userlist->get_userids());

        provider::export_user_data(new approved_contextlist($user, 'local_oksigeniaeguahe', [$system->id]));
        $this->assertTrue(\core_privacy\local\request\writer::with_context($system)->has_any_data());

        provider::delete_data_for_users(new approved_userlist($system, 'local_oksigeniaeguahe', [$user->id]));
        $this->assertSame(0, $DB->count_records(centres::MEMBERS));
        $contact = $DB->get_record(households::CONTACTS, ['householdid' => $household->id]);
        $this->assertEquals(0, $contact->userid);
        $this->assertSame('Les esperamos el sábado.', $contact->body);
    }
}
