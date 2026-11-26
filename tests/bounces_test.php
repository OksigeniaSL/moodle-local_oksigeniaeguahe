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
use local_oksigeniaeguahe\local\imapread;
use local_oksigeniaeguahe\local\writeby;

/**
 * The mail that comes back: read, marked in the file of the family, and what a person says after calling.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\bounces
 */
#[\PHPUnit\Framework\Attributes\CoversClass(bounces::class)]
final class bounces_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /**
     * A centre that reads the returned mail.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        set_config('bounces', 1, 'local_oksigeniaeguahe');
    }

    /**
     * A returned mail as the mailer of a big provider writes it.
     *
     * @param string $to
     * @param string $status
     * @param string $action
     * @param string $diag
     * @return string
     */
    public static function dsn(string $to, string $status, string $action = 'failed', string $diag = ''): string {
        $subject = '=?utf-8?B?' . base64_encode('Respira: una charla el 2 de diciembre') . '?=';
        return "From: Mail Delivery Subsystem <mailer-daemon@example.net>\r\n" .
            "Subject: Delivery Status Notification (Failure)\r\nAuto-Submitted: auto-replied\r\n" .
            "X-Failed-Recipients: $to\r\n" .
            "Content-Type: multipart/report; boundary=\"b1\"; report-type=delivery-status\r\n\r\n" .
            "--b1\r\nContent-Type: text/plain; charset=\"UTF-8\"\r\n\r\nYour message was not delivered.\r\n\r\n" .
            "--b1\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; example.net\r\n\r\n" .
            "Final-Recipient: rfc822; $to\r\nAction: $action\r\nStatus: $status\r\n" .
            "Diagnostic-Code: smtp; " . ($diag ?: "550-$status The account does not exist.") . "\r\n\r\n" .
            "--b1\r\nContent-Type: text/rfc822-headers\r\n\r\nDate: Wed, 25 Nov 2026 19:03:13 +0000\r\n" .
            "From: \"Colegio de ejemplo\" <noreply@example.org>\r\nSubject: $subject\r\n\r\n--b1--\r\n";
    }

    /**
     * A family with two adults: one address that will come back and one that works.
     *
     * @return array [household, the adult that fails, the other]
     */
    protected function family(): array {
        global $DB;
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        $one = households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'López',
            'email' => 'Ana.Lopez@example.com', 'phone' => '+34600111222']);
        $other = households::add_adult($household, (object) ['firstname' => 'Juan', 'lastname' => 'Pérez',
            'email' => 'juan@example.com', 'phone' => null]);
        foreach ([$one, $other] as $adult) {
            households::consent($household, $adult, 'email', true, 'family', 'es');
        }
        return [households::get($id, (int) $this->centre->id), (int) $one, (int) $other];
    }

    /**
     * What a returned mail says: whom, why and what; a delay, or something else, says nothing.
     */
    public function test_parse(): void {
        $dsn = bounces::parse(self::dsn('ana.lopez@example.com', '5.2.2', 'failed', '552-5.2.2 The inbox is out of storage space.'));
        $this->assertSame(['ana.lopez@example.com'], $dsn['to']);
        $this->assertSame('full', $dsn['kind']);
        $this->assertSame('Respira: una charla el 2 de diciembre', $dsn['subject']);
        $this->assertSame(strtotime('2026-11-25 19:03:13 UTC'), $dsn['sent']);
        $this->assertSame('gone', bounces::parse(self::dsn('x@example.com', '5.1.1'))['kind']);
        $this->assertSame('gone', bounces::parse(self::dsn('x@example.com', '5.1.10'))['kind']);
        $this->assertSame('refused', bounces::parse(self::dsn('x@example.com', '5.7.1', 'failed', 'Message rejected as spam'))['kind']);
        $this->assertNull(bounces::parse(self::dsn('x@example.com', '4.2.2', 'delayed')));
        $this->assertNull(bounces::parse("From: ana@example.com\r\nSubject: Hola\r\n\r\nUna pregunta."));
    }

    /**
     * Marked once, with a line in its history; the other address is the one letters go to; the inbox asks to call.
     */
    public function test_found(): void {
        global $DB;
        [$household, $one, $other] = $this->family();
        $dsn = bounces::parse(self::dsn('ana.lopez@example.com', '5.1.1'));
        $this->assertSame(1, bounces::found($dsn, time()));
        $this->assertSame(0, bounces::found($dsn, time()), 'The same address is marked once.');
        $this->assertSame(2, (int) $DB->get_field(bounces::T, 'times', ['adultid' => $one]));
        $this->assertSame([$one], array_keys(bounces::failing([$one, $other])));
        $this->assertSame('juan@example.com', households::recipient($household));
        $this->assertSame([(int) $household->id], bounces::to_call((int) $this->centre->id));
        $like = 'householdid = ? AND ' . $DB->sql_like('body', '?');
        $this->assertTrue($DB->record_exists_select(households::CONTACTS, $like, [$household->id, '%ana.lopez@example.com%']));
        $card = bounces::context($household, households::full($household), true);
        $this->assertCount(1, $card['addresses']);
        $this->assertSame('tel:+34600111222', $card['phones'][0]['href']);
        $this->assertStringContainsString('Colegio de ejemplo', $card['addresses'][0]['sayhtml']);
    }

    /**
     * After calling: nobody answered (still to call), the right address (said right; changing it in the file does the same),
     * no mail (they are not written to by email), or let it be.
     */
    public function test_say(): void {
        global $DB;
        $centreid = (int) $this->centre->id;
        [$household, $one] = $this->family();
        bounces::found(bounces::parse(self::dsn('ana.lopez@example.com', '5.1.1')), time());
        $id = (int) $DB->get_field(bounces::T, 'id', ['adultid' => $one]);
        $this->assertTrue(bounces::say($household, $id, 'noanswer', 2));
        $this->assertSame([(int) $household->id], bounces::to_call($centreid));
        $this->assertFalse(bounces::say($household, $id, 'fixed', 2, 'no es un correo'));
        $this->assertTrue(bounces::say($household, $id, 'fixed', 2, 'ana.buena@example.com'));
        $this->assertSame('ana.buena@example.com', $DB->get_field(households::ADULTS, 'email', ['id' => $one]));
        $this->assertSame([], bounces::to_call($centreid));
        $this->assertSame('ana.buena@example.com', households::recipient(households::get((int) $household->id, $centreid)));

        // The right one came back too; then changed in the file: said right by itself.
        bounces::found(bounces::parse(self::dsn('ana.buena@example.com', '5.2.2')), time());
        $this->assertSame([(int) $household->id], bounces::to_call($centreid));
        $DB->set_field(households::ADULTS, 'email', 'ana.otra@example.com', ['id' => $one]);
        $this->assertSame(1, bounces::changed($household, 2));
        $this->assertSame([], bounces::to_call($centreid));

        // No mail.
        [$second, $two] = $this->family();
        $DB->set_field(households::ADULTS, 'email', 'dos@example.com', ['id' => $two]);
        bounces::found(bounces::parse(self::dsn('dos@example.com', '5.1.1')), time());
        $this->assertTrue(bounces::say($second, (int) $DB->get_field(bounces::T, 'id', ['adultid' => $two]), 'nomail', 2));
        $this->assertNotContains('email', writeby::chosen(households::get((int) $second->id, $centreid)) ?? ['email']);
        $this->assertSame([], bounces::to_call($centreid));
    }

    /**
     * Read from a mailbox: the returned mail of each folder once, from where it stopped.
     */
    public function test_read(): void {
        global $DB;
        [$household, $one] = $this->family();
        $box = new class extends imapread {
            /** @var array Folder => uid => message. */
            public $mail = [];
            /** @var string */
            public $open = '';
            /**
             * The folders.
             *
             * @return string[]
             */
            public function folders(): array {
                return array_keys($this->mail);
            }
            /**
             * Opens one.
             *
             * @param string $folder
             * @return string
             */
            public function examine(string $folder): string {
                $this->open = $folder;
                return '7';
            }
            /**
             * Its returned mail.
             *
             * @param int $after
             * @param int $since
             * @return int[]
             */
            public function returned(int $after, int $since): array {
                return array_values(array_filter(array_keys($this->mail[$this->open]), fn($uid) => $uid > $after));
            }
            /**
             * One.
             *
             * @param int $uid
             * @param int $max
             * @return string
             */
            public function fetch(int $uid, int $max): string {
                return $this->mail[$this->open][$uid];
            }
            /**
             * Nothing to close.
             */
            public function close(): void {
            }
        };
        $box->mail = ['INBOX' => [], '[Gmail]/Trash' => [3 => self::dsn('ana.lopez@example.com', '5.2.2'),
            4 => "From: mailer-daemon@example.net\r\nSubject: Something else\r\n\r\nNothing."]];
        $out = bounces::read(time(), $box);
        $this->assertSame(['read' => 2, 'marked' => 1, 'error' => ''], $out);
        $this->assertSame('full', $DB->get_field(bounces::T, 'kind', ['adultid' => $one]));
        $this->assertSame(['read' => 0, 'marked' => 0, 'error' => ''], bounces::read(time(), $box), 'Read once.');
        $this->assertSame(0, bounces::last()['read']);
    }
}
