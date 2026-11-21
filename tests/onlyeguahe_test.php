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
use local_oksigeniaeguahe\local\onlyeguahe;
use local_oksigeniaeguahe\local\panel_centre;

/**
 * A site that is only Eguahe, shared by several centres: its own way in, and each team in its own centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\onlyeguahe
 */
#[\PHPUnit\Framework\Attributes\CoversClass(onlyeguahe::class)]
final class onlyeguahe_test extends \advanced_testcase {
    /**
     * The pages of the platform lead to Eguahe only when the site says so, and never for whoever runs it.
     */
    public function test_pages_lead_to_eguahe(): void {
        $this->resetAfterTest();
        $this->assertNull(onlyeguahe::instead_of('site-index'));
        set_config('onlyeguahe', 1, 'local_oksigeniaeguahe');
        $this->assertStringEndsWith('/local/oksigeniaeguahe/entrar.php', onlyeguahe::instead_of('site-index')->get_path(false));
        $this->assertNull(onlyeguahe::instead_of('user-profile'));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertStringEndsWith('/local/oksigeniaeguahe/index.php', onlyeguahe::instead_of('my-index')->get_path(false));
        $this->setAdminUser();
        $this->assertNull(onlyeguahe::instead_of('my-index'));
    }

    /**
     * In a shared site, a team gets the role that lets it work, sees only its centre, and only who looks after the
     * settings of a centre changes them.
     */
    public function test_teams_apart(): void {
        $this->resetAfterTest();
        set_config('onlyeguahe', 1, 'local_oksigeniaeguahe');
        $one = centres::create((object) ['name' => 'One', 'lang' => 'en', 'retention' => 24]);
        $two = centres::create((object) ['name' => 'Two', 'lang' => 'en', 'retention' => 24]);
        $head = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        centres::add_member($one, (int) $head->id);
        centres::set_admin($one, (int) $head->id, true);
        centres::add_member($one, (int) $teacher->id);
        centres::add_member($two, (int) $other->id);

        // The role of the teams, given when they join: they can act for the families of their own centre only.
        $this->assertTrue(centres::can_act((int) $teacher->id));
        $this->assertSame([$one], array_keys(centres::for_user((int) $teacher->id)));
        $this->assertFalse(centres::can_work($two, (int) $head->id));

        // The settings: whoever the centre made so, of that centre only.
        $this->assertTrue(centres::can_manage($one, (int) $head->id));
        $this->assertFalse(centres::can_manage($two, (int) $head->id));
        $this->assertFalse(centres::can_manage($one, (int) $teacher->id));
        $this->assertFalse(centres::can_manage(0, (int) $head->id));

        // Out of every team, out of the role.
        centres::remove_member($two, (int) $other->id);
        $this->assertFalse(centres::can_act((int) $other->id));

        // Someone made «only looks» on the site keeps it when another centre adds them: never writing everywhere.
        $looks = $this->getDataGenerator()->create_user();
        $role = create_role('Only looks', 'eguahelooks', '');
        assign_capability('local/oksigeniaeguahe:view', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, (int) $looks->id, \context_system::instance()->id);
        $this->assertFalse(centres::add_member($two, (int) $looks->id));
        $this->assertFalse(centres::can_act((int) $looks->id));
        $this->assertTrue(centres::add_member($two, (int) $teacher->id));
    }

    /**
     * In a shared site, someone is found only by their whole email, never by a name that could be of another centre;
     * whoever is not there gets an account with their password by email.
     */
    public function test_people_by_email(): void {
        $this->resetAfterTest();
        $lucia = $this->getDataGenerator()->create_user(['firstname' => 'Lucía', 'lastname' => 'Medina',
            'email' => 'lucia@example.org']);
        $this->assertSame((int) $lucia->id, (int) panel_centre::find_user('Lucía Medina')->id);
        set_config('onlyeguahe', 1, 'local_oksigeniaeguahe');
        $this->assertNull(panel_centre::find_user('Lucía Medina'));
        $this->assertSame((int) $lucia->id, (int) panel_centre::find_user('lucia@example.org')->id);

        $sink = $this->redirectEmails();
        $new = onlyeguahe::new_account('Pablo.Ruiz@example.org', 'Pablo Ruiz Santana', 'en');
        $this->assertSame('pablo.ruiz@example.org', $new->username);
        $this->assertSame('Pablo', $new->firstname);
        $this->assertSame('Ruiz Santana', $new->lastname);
        $this->assertSame('en', $new->lang);
        $this->assertSame('1', get_user_preferences('auth_forcepasswordchange', null, $new));
        $this->assertCount(1, $sink->get_messages());
        // The same email again is the same account, and nothing more is sent.
        $this->assertSame((int) $new->id, (int) onlyeguahe::new_account('pablo.ruiz@example.org', 'Pablo')->id);
        $this->assertCount(1, $sink->get_messages());
        $this->assertNull(onlyeguahe::new_account('not an email', 'Nobody'));
    }
}
