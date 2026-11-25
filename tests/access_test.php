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

use local_oksigeniaeguahe\local\access;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;

/**
 * What keeps the page of a family theirs when its link travels: the code before downloading or erasing, the links that
 * stop working after months unused, and the notice to the address there was when an email changes.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\access
 */
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
final class access_test extends \advanced_testcase {
    /**
     * A centre and a family with one adult and an email.
     *
     * @return array [centre, household]
     */
    protected function family(): array {
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'email' => 'admisiones@example.com']);
        $id = households::create($cid, (object) ['lang' => 'es']);
        $household = households::get($id, $cid);
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@example.com',
            'phone' => '+34600111222']);
        return [centres::get($cid), $household];
    }

    /**
     * The code goes to the email of the family, once a minute; a wrong one counts, five are too many, and the right one
     * opens the door in this browser for ten minutes.
     */
    public function test_code(): void {
        [$centre, $household] = $this->family();
        $this->assertSame('ma•••@example.com', access::mask('marta@example.com'));
        $this->assertSame('a•••@example.com', access::mask('ab@example.com'));
        $sink = $this->redirectEmails();
        $now = time();
        $this->assertSame(['ma•••@example.com'], access::send($household, $centre, 'download', 'es', $now));
        $this->assertSame(['ma•••@example.com'], access::send($household, $centre, 'download', 'es', $now + 10));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages, 'Not again within a minute.');
        $this->assertSame('marta@example.com', $messages[0]->to);
        $this->assertMatchesRegularExpression('/^\d{6} es su código para /', $messages[0]->subject);
        $code = substr($messages[0]->subject, 0, 6);
        $this->assertFalse(access::proven($household, 'download'));
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->assertSame('wrong', access::check($household, 'download', $wrong, $now + 20));
        $this->assertSame('ok', access::check($household, 'download', substr($code, 0, 3) . ' ' . substr($code, 3), $now + 30));
        $this->assertTrue(access::proven($household, 'download', $now + 40));
        $this->assertFalse(access::proven($household, 'download', $now + 40 + access::PROOF));
        $this->assertFalse(access::proven($household, 'erase', $now + 40), 'Each thing, its own code.');
        $this->assertSame('expired', access::check($household, 'download', $code, $now + 50), 'Used, it is gone.');
        // Five wrong ones and that code is over.
        access::send($household, $centre, 'erase', 'es', $now + 100);
        for ($i = 1; $i < access::TRIES; $i++) {
            $this->assertSame('wrong', access::check($household, 'erase', 'abc', $now + 100 + $i));
        }
        $this->assertSame('tries', access::check($household, 'erase', 'abc', $now + 120));
        // Without any email, nothing is sent.
        $id = households::create((int) $centre->id, (object) ['lang' => 'es']);
        $nomail = households::get($id, (int) $centre->id);
        households::add_adult($nomail, (object) ['firstname' => 'Luis', 'phone' => '+34600333444']);
        $this->assertSame([], access::send($nomail, $centre, 'download', 'es'));
    }

    /**
     * A link opened keeps working; four months without opening it, it stops; a new one goes to the email of the family.
     */
    public function test_links(): void {
        global $DB;
        [$centre, $household] = $this->family();
        $token = households::new_link($household);
        $link = access::link($token);
        $now = time();
        $this->assertFalse(access::expired($link, $now));
        $DB->set_field(households::LINKS, 'timeused', $now - 200 * DAYSECS, ['id' => $link->id]);
        $DB->set_field(households::LINKS, 'timecreated', $now - 300 * DAYSECS, ['id' => $link->id]);
        $this->assertTrue(access::expired(access::link($token), $now));
        $sink = $this->redirectEmails();
        $this->assertSame(['ma•••@example.com'], access::send_new_link($household, $centre, 'es', $now));
        access::send_new_link($household, $centre, 'es', $now + 60);
        $this->assertCount(1, $sink->get_messages(), 'Once an hour.');
        $this->assertStringContainsString(
            '/local/oksigeniaeguahe/f.php?k=',
            quoted_printable_decode($sink->get_messages()[0]->body)
        );
        $this->assertNull(access::link('nope'));
    }

    /**
     * Changing an email tells the old one and the new one, without a link; a phone, the email of the family.
     */
    public function test_changed(): void {
        [$centre, $household] = $this->family();
        $adult = array_values(households::full($household)->adults)[0];
        $sink = $this->redirectEmails();
        households::family_contacts($household, $centre, [$adult->id => ['firstname' => 'Marta', 'lastname' => 'Ruiz',
            'phone' => '+34600111222', 'email' => 'otra@example.com']], ['firstname' => '', 'lastname' => '', 'phone' => '',
            'email' => '']);
        $to = array_map(fn($m) => $m->to, $sink->get_messages());
        sort($to);
        $this->assertSame(['marta@example.com', 'otra@example.com'], $to);
        $this->assertStringNotContainsString('f.php', quoted_printable_decode($sink->get_messages()[0]->body));
        $sink->clear();
        households::family_contacts($household, $centre, [$adult->id => ['firstname' => 'Marta', 'lastname' => 'Ruiz',
            'phone' => '+34600999888', 'email' => 'otra@example.com']], ['firstname' => '', 'lastname' => '', 'phone' => '',
            'email' => '']);
        $this->assertSame(['otra@example.com'], array_map(fn($m) => $m->to, $sink->get_messages()));
    }

    /**
     * An email changed on the page a few days ago gets no code yet, and the page says that, not that there is none.
     */
    public function test_email_changed_lately(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES', 'email' => 'centro@example.com']);
        $centre = centres::get($cid);
        $household = households::get(households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $cid);
        $adult = households::add_adult($household, (object) ['firstname' => 'Ana', 'email' => 'ana.nueva@example.com']);
        $DB->set_field(households::ADULTS, 'emailpage', time() - HOURSECS, ['id' => $adult]);
        $text = fn() => access::page_context($household, $centre, 'es', 'x', 'download', [])['text'];
        $this->assertStringStartsWith('Han cambiado su correo hace poco', $text());
        $DB->set_field(households::ADULTS, 'email', null, ['id' => $adult]);
        $this->assertStringNotContainsString('Han cambiado', $text());
    }
}
