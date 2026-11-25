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
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invitecheck;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\panel_event;

/**
 * Looking over an invitation before it goes, and what did not go. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\invitecheck
 */
#[\PHPUnit\Framework\Attributes\CoversClass(invitecheck::class)]
final class invitecheck_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /**
     * A family with a child at a stage.
     *
     * @param string $name
     * @param string $stage
     * @param bool $allowed Whether the centre may write to it by email.
     * @param string|null $email
     * @return \stdClass
     */
    protected function family(string $name, string $stage = 'visit', bool $allowed = true, ?string $email = ''): \stdClass {
        global $DB;
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        $adult = households::add_adult($household, (object) ['firstname' => $name, 'lastname' => 'Ejemplo', 'email' => $email === '' ?
            \core_text::strtolower(\core_text::specialtoascii($name)) . '@example.com' : $email, 'phone' => '+3460000' . (1000 + $id)]);
        if ($email !== null) {
            households::consent($household, $adult, 'email', $allowed, 'form', 'es');
        }
        $child = households::add_child($household, 'Hijo de ' . $name, 0);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), $stage, $stage === 'closed' ? 'moved' : null);
        return households::get($id, (int) $this->centre->id);
    }

    /**
     * What a person sees before it goes, and afterwards what did not.
     */
    public function test_review(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES', 'email' => 'hola@example.com']);
        $this->centre = centres::get($id);
        $f = ['Ana' => $this->family('Ana'), 'Info' => $this->family('Info'), 'Eva' => $this->family('Eva')];
        $this->family('Carlos', 'visit', false);
        $this->family('Dora', 'closed');
        $this->family('Elsa', 'enrolled');
        $this->family('Flor', 'visit', true, null);
        $noinvites = $this->family('Gema');
        $DB->set_field(households::T, 'noinvites', 1, ['id' => $noinvites->id]);
        $silent = $this->family('Hugo');
        $DB->update_record(households::T, (object) ['id' => $silent->id, 'writeby' => 'none', 'writebytime' => time() - DAYSECS]);
        $event = events::get(events::save($id, (object) ['kind' => 'other', 'name' => 'Charla', 'timestart' => time() + 5 * DAYSECS,
            'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 2, 'open' => 1]), $id);
        $chosen = invites::eligible($event);
        $this->assertEqualsCanonicalizing(array_map(fn($h) => (int) $h->id, array_values($f)), array_map('intval', array_keys($chosen)));
        $user = (int) get_admin()->id;
        $review = invitecheck::of($event, $this->centre, $chosen, $user, time());
        $this->assertSame('Hola, Ana:', $review['greetings'][(int) $f['Ana']->id]);
        $this->assertSame('Hola:', $review['greetings'][(int) $f['Info']->id]);
        $texts = array_column($review['rows'], 'ok', 'text');
        $c = 'local_oksigeniaeguahe';
        $this->assertFalse($texts[get_string('invitecheck_nottried', $c)]);
        $this->assertFalse($texts[get_string('invitecheck_noabout', $c)]);
        $this->assertFalse($texts[get_string('invitecheck_room', $c, (object) ['room' => 2, 'n' => 3])]);
        $this->assertFalse($texts[get_string('invitecheck_unnamed_one', $c, (object) ['n' => 1, 'of' => 3])]);
        $this->assertTrue($texts[get_string('invitecheck_answer_touch', $c)]);
        $this->assertTrue($texts[get_string('invitecheck_reply', $c, 'hola@example.com')]);
        foreach (['centre', 'closed', 'silent', 'noinvites', 'noemail', 'nopermission'] as $why) {
            $this->assertTrue($texts[get_string('invitecheck_out_' . $why . '_one', $c)] ?? false, $why);
        }
        // Tried on as it is now; changed, it has to be seen again.
        invitecheck::keep_tried($event, $this->centre, $user);
        $this->assertTrue(invitecheck::tried($event, $this->centre, $user));
        $event->invitewords = 'Habrá chocolate.';
        $this->assertFalse(invitecheck::tried($event, $this->centre, $user));
        $event->invitewords = null;
        // On the page, with who it goes to.
        $page = panel_event::invite_page($event, $this->centre, true, time(), '', true);
        $this->assertContains('Hola, Ana:', array_column($page['check']['going'], 'greeting'));
        $this->assertFalse($page['skipped']);
        // It goes, and one had signed up meanwhile: said, never in silence.
        $sink = $this->redirectEmails();
        invites::queue($event, $this->centre, $user, time());
        events::sign_up($event, (int) $f['Eva']->id, 1);
        $this->runAdhocTasks('\local_oksigeniaeguahe\task\send_invites');
        $this->assertCount(2, $sink->get_messages());
        $skipped = invites::skipped((int) $event->id);
        $this->assertSame([get_string('notice_why_signedup', $c)], array_column($skipped['names'], 'note'));
        $sink->close();
    }
}
