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
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\facts;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\letters;
use local_oksigeniaeguahe\local\numbers_questions;
use local_oksigeniaeguahe\local\panel_answers;

/**
 * The letters of the acts, all together: when it is best to send them.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\letters
 */
#[\PHPUnit\Framework\Attributes\CoversClass(letters::class)]
final class letters_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $eventid;

    /**
     * A centre with an act still to come.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setTimezone('Europe/Madrid');
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        $this->eventid = events::save($id, (object) ['kind' => 'other', 'name' => 'Respira',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]);
    }

    /**
     * A letter to a new family, gone at a time, and what came of it.
     *
     * @param int $sent
     * @param int $opened Seconds after it went; 0 for not opened.
     * @param string $reply
     * @return int The household.
     */
    protected function letter(int $sent, int $opened = 0, string $reply = ''): int {
        global $DB;
        $household = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $DB->insert_record(invites::T, (object) ['centreid' => $this->centre->id, 'eventid' => $this->eventid, 'householdid' => $household,
            'userid' => 2, 'channel' => 'email', 'bulkid' => 0, 'opened' => $opened ? $sent + $opened : 0, 'reply' => $reply,
            'replied' => $reply !== '' ? $sent + $opened + 60 : 0, 'timecreated' => $sent]);
        return $household;
    }

    /**
     * Counted by the hour and the day it went; whether opened, only after two days; the hour that opens best said against the
     * worst, with ten letters each; those that came back and those answered without a letter, apart.
     */
    public function test_figures(): void {
        global $DB;
        $d = usergetdate(time() - 3 * DAYSECS);
        $at = fn(int $h, int $m) => make_timestamp($d['year'], $d['mon'], $d['mday'], $h, $m);
        for ($i = 0; $i < 12; $i++) {
            // At ten, nine opened within the hour; two say they come.
            $this->letter($at(10, $i), $i < 9 ? 300 * ($i + 1) : 0, $i < 2 ? 'yes' : '');
            // At six in the evening, two opened, a day and more later.
            $this->letter($at(18, $i), $i < 2 ? 30 * HOURSECS : 0);
        }
        // One came back.
        $back = $this->letter($at(10, 30));
        $DB->insert_record(bounces::T, (object) ['centreid' => $this->centre->id, 'householdid' => $back, 'adultid' => 0,
            'emailkey' => sha1('x'), 'kind' => 'gone', 'sent' => 0, 'timebounced' => $at(10, 32), 'times' => 1,
            'state' => 'open', 'timecreated' => $at(10, 32)]);
        // One family answered with no letter before: not a letter.
        $now = time();
        $DB->insert_record(invites::T, (object) ['centreid' => $this->centre->id, 'eventid' => $this->eventid,
            'householdid' => households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']),
            'userid' => 0, 'channel' => 'email', 'bulkid' => 0, 'opened' => $now - 100, 'reply' => 'yes', 'replied' => $now - 100,
            'timecreated' => $now - 100]);
        // Two went a while ago, at night, far from ten and from six: too soon to say.
        $soon = self::night($now);
        $this->letter($soon, 120);
        $this->letter($soon);

        $f = letters::figures($this->centre, 0, 0, $now);
        $this->assertSame(27, $f['sent']);
        $this->assertSame(1, $f['back']);
        $this->assertSame(26, $f['arrived']);
        $this->assertSame(24, $f['ripe']);
        $this->assertSame(11, $f['ripeopened']);
        $this->assertSame(12, $f['opened']);
        $this->assertSame(2, $f['yes']);
        $hours = array_column($f['byhour'], null, 'key');
        $this->assertSame(12, $hours[10]['arrived']);
        $this->assertSame(9, $hours[10]['ripeopened']);
        $this->assertSame(1500, $hours[10]['half']);
        $this->assertSame(2, $hours[18]['ripeopened']);
        $this->assertNull($hours[18]['half'], 'Fewer than five behind: no time said.');
        $this->assertSame(10, $f['best']['key']);
        $this->assertSame(18, $f['worst']['key']);
        $went = (new \DateTime('@' . $at(10, 0)))->setTimezone(\core_date::get_user_timezone_object());
        $this->assertSame(24, array_column($f['byday'], null, 'key')[(int) $went->format('N')]['ripe']);

        $sentence = letters::sentence($f);
        $this->assertStringContainsString("(75\u{00A0}%)", $sentence);
        $this->assertStringContainsString("(17\u{00A0}%)", $sentence);

        // In Numbers: the question and its sheet, by hour and by day.
        $campaign = facts::campaigns($this->centre, $now)['live'];
        $row = numbers_questions::find($this->centre, $campaign, 'letters', $now);
        $this->assertSame($sentence, $row['answer']);
        $sheet = panel_answers::build($this->centre, 'letters', 'live', true, $now);
        $this->assertSame(['cartas', 'hora', 'dia'], array_column($sheet['blocks'], 'id'));
        $this->assertFalse($sheet['detail']);
    }

    /**
     * Half past two at night, the last one before now: less than two days ago.
     *
     * @param int $now
     * @return int
     */
    protected static function night(int $now): int {
        $today = usergetdate($now);
        $time = make_timestamp($today['year'], $today['mon'], $today['mday'], 2, 30);
        return $time < $now - MINSECS ? $time : $time - DAYSECS;
    }

    /**
     * Letters out for less than two days say only how many went and came back, and how many opened so far; with none, the
     * question says where the acts are.
     */
    public function test_soon_and_none(): void {
        $now = time();
        $campaign = facts::campaigns($this->centre, $now)['live'];
        $row = numbers_questions::find($this->centre, $campaign, 'letters', $now);
        $this->assertTrue($row['empty']);
        $this->assertNull(panel_answers::build($this->centre, 'letters', 'live', true, $now));

        $this->letter($now - HOURSECS, 120);
        $this->letter($now - HOURSECS);
        $f = letters::figures($this->centre, 0, 0, $now);
        $this->assertSame(0, $f['ripe']);
        $this->assertNull($f['best']);
        $sentence = letters::sentence($f);
        $this->assertStringContainsString(get_string('letters_sent', 'local_oksigeniaeguahe', 2), $sentence);
        $this->assertStringContainsString(get_string('letters_sofar_one', 'local_oksigeniaeguahe'), $sentence);
        $this->assertStringContainsString(get_string('letters_wait', 'local_oksigeniaeguahe'), $sentence);
    }

    /**
     * A reply with no letter before it is not a letter; a reaction later than a week is not one to the letter.
     */
    public function test_went_and_reaction(): void {
        $t = 1000000;
        $this->assertFalse(letters::went((object) ['timecreated' => $t, 'opened' => $t, 'replied' => $t]));
        $this->assertTrue(letters::went((object) ['timecreated' => $t, 'opened' => $t + 5, 'replied' => $t + 5]));
        $this->assertSame(5, letters::reaction((object) ['timecreated' => $t, 'opened' => $t + 5, 'replied' => 0, 'reply' => '']));
        $this->assertSame(3, letters::reaction((object) ['timecreated' => $t, 'opened' => $t + 5, 'replied' => $t + 3, 'reply' => 'no']));
        $this->assertNull(letters::reaction((object) ['timecreated' => $t, 'opened' => $t + 8 * DAYSECS, 'replied' => 0, 'reply' => '']));
    }
}
