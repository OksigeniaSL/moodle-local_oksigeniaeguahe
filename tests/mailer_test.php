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

use local_oksigeniaeguahe\local\bounces;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\mail;
use local_oksigeniaeguahe\local\mailer;

/**
 * How the letters of a centre go out: by the mail of the site, or by a service of its own, tried first, never tracked, and
 * what comes back by its events.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\mailer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mailer::class)]
final class mailer_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /**
     * A centre with its own address to answer to.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'email' => 'admisiones@colegio-ejemplo.es']);
        $this->centre = centres::get($id);
    }

    /**
     * Mailjet, chosen: not used until a trial goes by it; the trial that fails leaves the mail of the site; the one that goes,
     * the service. Secrets left empty keep the ones before, and choosing another way leaves it until it is tried.
     */
    public function test_choose_and_try(): void {
        global $USER;
        $this->assertSame('site', mailer::way($this->centre));
        mailer::choose($this->centre, 'mailjet');
        $this->assertSame('site', mailer::way($this->centre), 'Chosen, not tried: the mail of the site.');
        $in = ['from' => 'admisiones@colegio-ejemplo.es', 'key' => 'k1', 'secret' => 's1'];

        \curl::mock_response(json_encode(['Messages' => [['Status' => 'error', 'Errors' => [['ErrorMessage' => 'Sender not validated']]]]]));
        $said = mailer::save($this->centre, $in, $USER);
        $this->assertStringContainsString('Sender not validated', $said);
        $this->assertSame('site', mailer::way($this->centre));

        \curl::mock_response(json_encode(['Messages' => [['Status' => 'success']]]));
        $this->assertSame('', mailer::save($this->centre, $in + ['secret' => ''], $USER));
        $this->assertSame('mailjet', mailer::way($this->centre));
        $this->assertSame('s1', mailer::settings((int) $this->centre->id)['secret'], 'An empty secret keeps the one before.');
        $this->assertNotSame('', mailer::hook_url($this->centre));

        mailer::choose($this->centre, 'smtp');
        $this->assertSame('site', mailer::way($this->centre), 'Another way, until it is tried.');
        $this->assertSame(get_string('mailer_err_host', 'local_oksigeniaeguahe'), mailer::save($this->centre, $in, $USER));
    }

    /**
     * A letter for the API: no tracking, its pictures inside (only those it shows), the centre to answer to, and the click to
     * unsubscribe; and sent by it when the centre's way is Mailjet.
     */
    public function test_message(): void {
        global $USER;
        mailer::choose($this->centre, 'mailjet');
        \curl::mock_response(json_encode(['Messages' => [['Status' => 'success']]]));
        mailer::save($this->centre, ['from' => 'cartas@colegio-ejemplo.es', 'fromname' => 'Admisiones', 'key' => 'k', 'secret' => 's'], $USER);
        $images = [['data' => 'png', 'cid' => 'etiqueta', 'name' => 'etiqueta.png', 'mime' => 'image/png'],
            ['data' => 'gif', 'cid' => 'nada', 'name' => 'nada.gif', 'mime' => 'image/gif']];
        $m = mailer::message($this->centre, 'ana@example.com', 'Respira', '<img src="cid:etiqueta">', 'Respira', $images, 'https://x/baja');
        $this->assertSame('disabled', $m['TrackOpens']);
        $this->assertSame('disabled', $m['TrackClicks']);
        $this->assertSame(['Email' => 'cartas@colegio-ejemplo.es', 'Name' => 'Admisiones'], $m['From']);
        $this->assertSame('admisiones@colegio-ejemplo.es', $m['ReplyTo']['Email']);
        $this->assertSame(['etiqueta'], array_column($m['InlinedAttachments'], 'ContentID'));
        $this->assertSame('<https://x/baja>', $m['Headers']['List-Unsubscribe']);

        \curl::mock_response(json_encode(['Messages' => [['Status' => 'success']]]));
        $sink = $this->redirectEmails();
        $this->assertTrue(mail::send_rich($this->centre, 'ana@colegio-familia.es', 'Respira', '<p>Hola</p>', 'Hola'));
        $this->assertCount(0, $sink->get_messages(), 'By the service, nothing by the mail of the site.');
        $sink->close();
    }

    /**
     * What Mailjet tells: a bounce for good, a full mailbox and spam are marked in the file of a family of the centre; a
     * bounce that may pass, or an address of no family of it, nothing.
     */
    public function test_events(): void {
        global $DB;
        $centreid = (int) $this->centre->id;
        $household = households::get(households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $centreid);
        $adult = households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo', 'email' => 'ana@colegio-familia.es']);
        $soft = ['event' => 'bounce', 'email' => 'ana@colegio-familia.es', 'time' => time(), 'hard_bounce' => false, 'error' => 'timeout'];
        $this->assertNull(mailer::as_returned($soft));
        $this->assertSame('full', mailer::as_returned(['error' => 'quota exceeded'] + $soft)['kind']);
        $this->assertSame('refused', mailer::as_returned(['event' => 'spam'] + $soft)['kind']);
        $this->assertSame(0, mailer::events($centreid, [['email' => 'otro@colegio-familia.es', 'hard_bounce' => true] + $soft], time()));
        $this->assertSame(1, mailer::events($centreid, [['hard_bounce' => true, 'error' => 'user unknown'] + $soft], time()));
        $this->assertSame('gone', $DB->get_field(bounces::T, 'kind', ['adultid' => $adult]));
        $this->assertTrue(mailer::hook_ok($centreid, '') === false);
    }
}
