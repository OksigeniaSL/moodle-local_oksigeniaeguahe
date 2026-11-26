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
use local_oksigeniaeguahe\local\imapread;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\replies;

/**
 * What the families answer by mail: taken in once, without what they quote, and what they seem to say of an invitation,
 * proposed for a person to confirm.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\replies
 */
#[\PHPUnit\Framework\Attributes\CoversClass(replies::class)]
final class replies_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $event;

    /** @var \stdClass */
    protected $family;

    /**
     * A centre, an act to come and a family that had its letter.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'email' => 'admisiones@colegio-ejemplo.es']);
        $this->centre = centres::get($id);
        $this->event = events::get(events::save($id, (object) ['kind' => 'other', 'name' => 'Respira',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), $id);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo', 'email' => 'Ana@familia-ejemplo.es']);
        $this->family = households::get((int) $household->id, $id);
        $DB->insert_record(invites::T, (object) ['centreid' => $id, 'eventid' => $this->event->id, 'householdid' => $household->id,
            'userid' => 2, 'channel' => 'email', 'bulkid' => 0, 'mailsent' => time() - DAYSECS, 'timecreated' => time() - DAYSECS]);
    }

    /**
     * A mail as a program of mail writes an answer.
     *
     * @param string $from
     * @param string $id Its Message-ID.
     * @param string $text
     * @param string $more Other headers.
     * @return string
     */
    protected static function mail(string $from, string $id, string $text, string $more = ''): string {
        return "From: Ana <$from>\r\nTo: admisiones@colegio-ejemplo.es\r\nSubject: Re: Respira: una charla\r\n" .
            "Date: " . date('r', time() - HOURSECS) . "\r\nMessage-ID: <$id>\r\n{$more}Content-Type: text/plain; charset=utf-8\r\n\r\n" .
            $text . "\r\n\r\nEl jue, 26 nov 2026 a las 18:11, Colegio de ejemplo (<noreply@example.org>)\r\nescribió:\r\n" .
            "> Les invitamos a Respira.\r\n";
    }

    /**
     * Read from the mailbox: the answer of a family to its file, without the quote, once; and nothing of anybody else, or of
     * an answer of a machine. What it says of the invitation is proposed, and confirmed by a person.
     */
    public function test_read_and_confirm(): void {
        global $DB;
        $box = new class extends imapread {
            /** @var string[] uid => message. */
            public $mail = [];
            /**
             * Opens the folder.
             *
             * @param string $folder
             * @return string
             */
            public function examine(string $folder): string {
                return '9';
            }
            /**
             * What came after a number.
             *
             * @param int $after
             * @param int $since
             * @return int[]
             */
            public function since(int $after, int $since): array {
                return array_values(array_filter(array_keys($this->mail), fn($uid) => $uid > $after));
            }
            /**
             * One.
             *
             * @param int $uid
             * @param int $max
             * @return string
             */
            public function fetch(int $uid, int $max): string {
                return $this->mail[$uid];
            }
            /**
             * Nothing to close.
             */
            public function close(): void {
            }
        };
        $box->mail = [
            1 => self::mail('ana@familia-ejemplo.es', 'a1@x', 'Buenas tardes, lo siento: no podemos ir, tenemos un viaje.'),
            2 => self::mail('otra@ejemplo.es', 'b1@x', 'No podemos ir.'),
            3 => self::mail('ana@familia-ejemplo.es', 'c1@x', 'Estoy fuera de la oficina.', "Auto-Submitted: auto-replied\r\n"),
            4 => self::mail('ana@familia-ejemplo.es', 'a1@x', 'Buenas tardes, lo siento: no podemos ir, tenemos un viaje.'),
        ];
        $out = replies::read($this->centre, time(), $box);
        $this->assertSame(['read' => 4, 'kept' => 1, 'answered' => 0, 'error' => ''], $out);
        $body = $DB->get_field_select(households::CONTACTS, 'body', 'householdid = ? AND direction = ?', [$this->family->id, 'in']);
        $this->assertSame('Buenas tardes, lo siento: no podemos ir, tenemos un viaje.', $body);
        $this->assertSame(['read' => 0, 'kept' => 0, 'answered' => 0, 'error' => ''], replies::read($this->centre, time(), $box), 'From where it stopped.');

        $card = replies::context($this->family, true);
        $this->assertCount(1, $card['items']);
        $this->assertSame('', invites::answer((int) $this->event->id, (int) $this->family->id), 'Never noted by itself.');
        $this->assertTrue(replies::confirm($this->family, $card['items'][0]['id'], true, 2));
        $this->assertSame('no', invites::answer((int) $this->event->id, (int) $this->family->id));
        $this->assertSame('email', $DB->get_field(invites::T, 'via', ['householdid' => $this->family->id]));
        $this->assertFalse(replies::context($this->family, true));
    }

    /**
     * What the team answered from the mailbox itself: to the file, and the family that waited since before it stops waiting;
     * once.
     */
    public function test_sent_from_the_mailbox(): void {
        global $DB;
        households::received($this->family, 'email', '¿Hay comedor?', true, time() - 2 * HOURSECS);
        $this->assertNotEquals(0, (int) $DB->get_field(households::T, 'waitingsince', ['id' => $this->family->id]));
        $raw = "From: Admisiones <admisiones@colegio-ejemplo.es>\r\nTo: Ana <ana@familia-ejemplo.es>, otro@ejemplo.es\r\n" .
            "Subject: Re: comedor\r\nDate: " . date('r', time() - HOURSECS) . "\r\nMessage-ID: <s1@x>\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n\r\nSí, hay comedor.\r\n\r\n> ¿Hay comedor?\r\n";
        $this->assertSame(1, replies::take_sent($this->centre, $raw, time()));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'waitingsince', ['id' => $this->family->id]));
        $out = $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ? AND direction = ?', [$this->family->id, 'out']);
        $this->assertSame(['Sí, hay comedor.'], array_values($out));
        $this->assertSame(0, replies::take_sent($this->centre, $raw, time()), 'Once.');
    }

    /**
     * Documents of admission sent by mail: their names in the line, and asking for a place proposed; confirmed, each child
     * still asking or visiting asks for a place. The little pictures of a signature are not files.
     */
    public function test_documents(): void {
        global $DB;
        households::add_child($this->family, 'Leo', 0);
        $raw = "From: Ana <ana@familia-ejemplo.es>\r\nSubject: Documentación de admisión\r\nDate: " . date('r') . "\r\n" .
            "Message-ID: <d1@x>\r\nContent-Type: multipart/mixed; boundary=\"b\"\r\n\r\n" .
            "--b\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nLes mando la solicitud y el DNI.\r\n" .
            "--b\r\nContent-Type: application/pdf; name=\"solicitud.pdf\"\r\nContent-Disposition: attachment; filename=\"solicitud.pdf\"\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\nJVBERi0=\r\n" .
            "--b\r\nContent-Type: image/png; name=\"image001.png\"\r\nContent-Disposition: inline; filename=\"image001.png\"\r\n\r\nx\r\n--b--\r\n";
        $this->assertSame(['solicitud.pdf'], replies::attachments($raw));
        $this->assertTrue(replies::take($this->centre, \local_oksigeniaeguahe\local\inbound::parse($raw), time(), 0, replies::attachments($raw)));
        $body = $DB->get_field_select(households::CONTACTS, 'body', 'householdid = ? AND direction = ?', [$this->family->id, 'in']);
        $this->assertStringContainsString('solicitud.pdf', $body);
        $card = replies::context($this->family, true);
        $this->assertSame(get_string('replies_confirm_doc', 'local_oksigeniaeguahe'), $card['items'][0]['yes']);
        $this->assertTrue(replies::confirm($this->family, $card['items'][0]['id'], true, 2));
        $stages = $DB->get_fieldset_select(households::CHILDREN, 'stage', 'householdid = ?', [$this->family->id]);
        $this->assertSame(['application'], array_values($stages));
    }

    /**
     * What a text says: they come, they do not, or it does not say (or says both); and the own text, without the quote.
     */
    public function test_says_and_own_text(): void {
        $this->assertSame('no', replies::says('Hola. Lamentablemente no podremos asistir.'));
        $this->assertSame('yes', replies::says('¡Allí estaremos! Gracias.'));
        $this->assertSame('yes', replies::says('Sí, contad con nosotros.'));
        $this->assertSame('', replies::says('¿Hay aparcamiento?'));
        $this->assertSame('', replies::says('No podemos el martes, pero iremos el jueves.'));
        $this->assertSame('Gracias.', replies::own_text("Gracias.\n\nOn Thu, Nov 26, 2026 at 6:11 PM Colegio wrote:\n> Hola"));
        $this->assertSame('Vale.', replies::own_text("Vale.\n-- \nAna"));
    }
}
