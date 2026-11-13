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

use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\drawings;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\renewal;
use local_oksigeniaeguahe\local\share;
use local_oksigeniaeguahe\local\siblings;
use local_oksigeniaeguahe\local\visits;

/**
 * What the deep review of November 2026 found and fixed: calling an act off, a family that cannot come, the reminder
 * after a change of day, the places taken, a brother or sister who never goes back, and how long a family that left is
 * kept. Example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\events
 */
#[\PHPUnit\Framework\Attributes\CoversClass(events::class)]
final class review_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /**
     * A centre with example data.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->centre = centres::get(centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es',
            'country' => 'ES', 'email' => 'secretaria@example.com']));
    }

    /**
     * A household with an adult who lets the centre write by email, or not.
     *
     * @param string $email
     * @param bool $mail
     * @return \stdClass
     */
    protected function household(string $email, bool $mail = true): \stdClass {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'web']);
        $household = households::get($id, (int) $this->centre->id);
        $adultid = households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz',
            'email' => $email]);
        households::consent($household, $adultid, 'email', $mail, 'form', 'es');
        return $household;
    }

    /**
     * An open day of the centre, two weeks ahead.
     *
     * @return \stdClass
     */
    protected function act(): \stdClass {
        $id = events::save((int) $this->centre->id, (object) ['kind' => 'open_day', 'name' => 'Jornada de ejemplo',
            'timestart' => time() + 14 * DAYSECS, 'timeend' => time() + 14 * DAYSECS + 3 * HOURSECS, 'capacity' => 0,
            'open' => 1]);
        return events::get($id, (int) $this->centre->id);
    }

    /**
     * Calling an act off tells by email who lets the centre write so, leaves a note for the others, takes every sign-up
     * away (so no reminder and no thanks goes) and closes the act.
     */
    public function test_cancel(): void {
        global $DB;
        unset_config('noemailever');
        $sink = $this->redirectEmails();
        $act = $this->act();
        $mail = $this->household('marta@example.com');
        $nomail = $this->household('pablo@example.com', false);
        events::sign_up($act, (int) $mail->id, 2);
        events::sign_up($act, (int) $nomail->id, 3);
        $told = events::cancel($act, $this->centre, 0, 'Lo pasamos a enero.', time());
        $this->assertSame(1, $told);
        $this->assertSame(1, $sink->count());
        $this->assertStringContainsString('Jornada de ejemplo', $sink->get_messages()[0]->subject);
        $this->assertSame(0, $DB->count_records(events::SIGNUPS, ['eventid' => $act->id]));
        $after = events::get((int) $act->id, (int) $this->centre->id);
        $this->assertSame(0, (int) $after->open);
        $this->assertGreaterThan(0, (int) $after->cancelled);
        $this->assertTrue($DB->record_exists(households::CONTACTS, ['householdid' => $nomail->id, 'direction' => 'note']));
        $sink->close();
    }

    /**
     * A family that cannot come after all: its sign-up goes, and it waits for a person.
     */
    public function test_cant_come(): void {
        global $DB;
        $act = $this->act();
        $household = $this->household('marta@example.com');
        events::sign_up($act, (int) $household->id, 2);
        $this->assertTrue(events::unsign($act, $household, 'Al final no podemos ir.', true));
        $this->assertFalse($DB->record_exists(events::SIGNUPS, ['eventid' => $act->id, 'householdid' => $household->id]));
        $this->assertGreaterThan(0, (int) households::get((int) $household->id, (int) $this->centre->id)->waitingsince);
        $this->assertFalse(events::unsign($act, $household, 'Otra vez.', true));
    }

    /**
     * Another day for the act, or another turn for a family: the reminder of the day before goes again.
     */
    public function test_reminder_again(): void {
        global $DB;
        $act = $this->act();
        $household = $this->household('marta@example.com');
        $id = events::sign_up($act, (int) $household->id, 2);
        $DB->set_field(events::SIGNUPS, 'reminded', time(), ['id' => $id]);
        events::save((int) $this->centre->id, (object) ['id' => $act->id, 'kind' => 'open_day', 'name' => $act->name,
            'timestart' => $act->timestart, 'timeend' => $act->timeend, 'capacity' => 0, 'open' => 1]);
        $this->assertGreaterThan(0, (int) $DB->get_field(events::SIGNUPS, 'reminded', ['id' => $id]), 'Same day: no.');
        events::save((int) $this->centre->id, (object) ['id' => $act->id, 'kind' => 'open_day', 'name' => $act->name,
            'timestart' => $act->timestart + DAYSECS, 'timeend' => $act->timeend + DAYSECS, 'capacity' => 0, 'open' => 1]);
        $this->assertSame(0, (int) $DB->get_field(events::SIGNUPS, 'reminded', ['id' => $id]));
    }

    /**
     * A place is taken by every child enrolled who has not left, from a family of the campaign or of the centre.
     */
    public function test_places(): void {
        global $DB;
        $offer = centres::save_offer((int) $this->centre->id, (object) ['schoolyear' => '2027-28', 'name' => '1.º de Primaria',
            'places' => 25, 'active' => 1]);
        $campaign = $this->household('marta@example.com');
        $ofcentre = $this->household('pablo@example.com');
        $DB->set_field(households::T, 'incentre', 1, ['id' => $ofcentre->id]);
        foreach ([[$campaign, 'Leo', 0], [$ofcentre, 'Vera', 0], [$ofcentre, 'Hugo', time()]] as [$household, $name, $left]) {
            $childid = households::add_child($household, $name, $offer);
            $DB->update_record(households::CHILDREN, (object) ['id' => $childid, 'stage' => 'enrolled', 'lefttime' => $left]);
        }
        $this->assertSame(2, households::offer_counts((int) $this->centre->id)[$offer]['enrolled']);
    }

    /**
     * Confirming a brother or sister never takes back a child of that name who is already enrolled: Mateo, whose turn
     * it is, was enrolled by the team already.
     */
    public function test_sibling_never_back(): void {
        global $DB;
        $now = make_timestamp(2026, 11, 8, 12);
        $offer = centres::save_offer((int) $this->centre->id, (object) ['schoolyear' => '2027-28',
            'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1, 'sortorder' => 0]);
        $DB->update_record(centres::T, (object) ['id' => $this->centre->id, 'siblingage' => 3,
            'campaignstart' => make_timestamp(2026, 10, 1)]);
        $centre = centres::get((int) $this->centre->id);
        $household = $this->household('marta@example.com');
        $childid = households::add_child($household, 'Mateo', $offer);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $childid]), 'enrolled');
        $mateo = siblings::add($household, 'Mateo', 2024, 'team', $now);
        $this->assertTrue(siblings::confirm($household, $centre, $mateo, $now));
        $this->assertSame('enrolled', $DB->get_field(households::CHILDREN, 'stage', ['id' => $childid]));
    }

    /**
     * A family whose child left is kept as long as any other from the day it left; one still studying is kept.
     */
    public function test_left_family_expires(): void {
        global $DB;
        $DB->set_field('local_oksigeniaeguahe_centre', 'retention', 24, ['id' => $this->centre->id]);
        $long = time() - 30 * 30 * DAYSECS;
        $studying = $this->household('marta@example.com');
        $left = $this->household('pablo@example.com');
        $leftnow = $this->household('ana@example.com');
        foreach ([[$studying, 0], [$left, $long], [$leftnow, time()]] as [$household, $when]) {
            $childid = households::add_child($household, 'Niño', 0);
            $DB->update_record(households::CHILDREN, (object) ['id' => $childid, 'stage' => 'enrolled', 'lefttime' => $when]);
            $DB->set_field(households::T, 'lastcontact', $long, ['id' => $household->id]);
        }
        $expired = array_keys(households::expired(time()));
        $this->assertSame([(int) $left->id], array_map('intval', $expired));
    }

    /**
     * Who to tell of the re-enrolment, in bulk: the families that let the centre write by email, by their last word.
     */
    public function test_emailable(): void {
        $yes = $this->household('marta@example.com');
        $no = $this->household('pablo@example.com', false);
        $changed = $this->household('ana@example.com');
        $adult = reset(households::full($changed)->adults);
        households::consent($changed, (int) $adult->id, 'email', false, 'family', 'es');
        $this->assertSame([(int) $yes->id], array_keys(households::emailable((int) $this->centre->id)));
        $this->assertNotContains((int) $no->id, array_keys(households::emailable((int) $this->centre->id)));
        $this->assertSame([], renewal::to_tell($this->centre));
    }

    /**
     * Taking «Ha venido» back takes the way of the children back too, unless something else moved it since.
     */
    public function test_attend_undo(): void {
        global $DB;
        $act = $this->act();
        $household = $this->household('marta@example.com');
        $childid = households::add_child($household, 'Leo', 0);
        $DB->set_field(households::CHILDREN, 'stage', 'inquiry', ['id' => $childid]);
        $signupid = events::sign_up($act, (int) $household->id, 2);
        events::attend($act, $signupid, true);
        $this->assertSame('visit', $DB->get_field(households::CHILDREN, 'stage', ['id' => $childid]));
        events::attend($act, $signupid, false);
        $this->assertSame('inquiry', $DB->get_field(households::CHILDREN, 'stage', ['id' => $childid]));
    }

    /**
     * An answer of always never keeps the family it was written for: names become gaps, surnames and emails go.
     */
    public function test_reply_without_family(): void {
        $household = $this->household('marta@example.com');
        households::add_child($household, 'Leonardo', 0, 'Leo');
        $full = households::full($household);
        $text = attend::without_family(
            'Marta, Leo puede venir el lunes. Escriban a marta@example.com o pregunten por Ruiz.',
            $full
        );
        $this->assertSame('{names}, {children} puede venir el lunes. Escriban a o pregunten por .', $text);
        $id = attend::keep_reply($household, 0, 'Marta, Leonardo tiene plaza.');
        $other = $this->household('pablo@example.com');
        $reply = attend::use_reply(households::full($other), $id);
        $this->assertStringNotContainsString('Leonardo', (string) $reply);
    }

    /**
     * A photo straight from a phone loses what the camera wrote in it, and a huge one is refused.
     */
    public function test_photo_clean(): void {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        // An EXIF block saying «turned 90°» (orientation 6), after the start of the JPEG.
        $tiff = 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) .
            pack('n', 6) . pack('n', 0) . pack('N', 0);
        $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;
        $withexif = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
        $this->assertSame(6, drawings::orientation($withexif));
        [$clean, $info] = drawings::clean($withexif, getimagesizefromstring($withexif));
        $this->assertStringNotContainsString("Exif\0\0", $clean);
        $this->assertSame([20, 40], [$info[0], $info[1]], 'Turned the way it was taken.');
    }

    /**
     * A visit of its own given again replaces the one before: one day for the family.
     */
    public function test_visit_replaced(): void {
        global $DB;
        $household = $this->household('marta@example.com');
        $first = visits::propose($household, time() + 3 * DAYSECS, 45, 0, 'Recepción', 2);
        $second = visits::propose($household, time() + 5 * DAYSECS, 45, 0, 'Recepción', 2);
        $this->assertGreaterThan(0, (int) $DB->get_field(events::T, 'cancelled', ['id' => $first->id]));
        $this->assertSame(0, $DB->count_records(events::SIGNUPS, ['eventid' => $first->id]));
        $this->assertSame(1, $DB->count_records(events::SIGNUPS, ['eventid' => $second->id]));
    }

    /**
     * A flood of sign-ups from one address to one act is stopped after the hour's allowance.
     */
    public function test_flood(): void {
        $act = $this->act();
        for ($i = 0; $i < events::PERHOUR; $i++) {
            $this->assertTrue(events::allowed_from($act, '192.0.2.10'));
        }
        $this->assertFalse(events::allowed_from($act, '192.0.2.10'));
        $this->assertTrue(events::allowed_from($act, '192.0.2.11'));
    }

    /**
     * The pages of the families open in the browser of the phone from Aty: each one is a page of the plugin.
     */
    public function test_aty_browser_pages(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/oksigeniaeguahe/lib.php');
        $pages = local_oksigeniaeguahe_oksigeniaaty_browser_pages();
        $this->assertContains('/local/oksigeniaeguahe/f.php', $pages);
        foreach ($pages as $page) {
            $this->assertFileExists($CFG->dirroot . $page);
        }
    }

    /**
     * A day from the 1st to the 9th keeps its day in the fields of a date: userdate() takes the zero away from %d, and
     * the fields came out empty, so saving the settings lost the first day.
     */
    public function test_day_keeps_its_zero(): void {
        $time = make_timestamp(2027, 9, 7);
        $this->assertSame('2027-09-07', userdate($time, '%Y-%m-%d', 99, false));
        foreach ([userdate($time, '%Y-%m-%d'), '2027-09-07'] as $value) {
            $date = publicpage::date_input('firstday', $value, 'es', $this->centre);
            $this->assertSame('2027-09-07', $date['value']);
            $this->assertSame(['d' => '07', 'm' => '09', 'y' => '2027'], array_column($date['parts'], 'value', 'key'));
        }
    }

    /**
     * How a family asks for a place: only a web address is kept as where, and two days written the wrong way round are
     * turned.
     */
    public function test_apply_days(): void {
        $from = make_timestamp(2027, 3, 1);
        $until = make_timestamp(2027, 3, 20);
        centres::update((int) $this->centre->id, (object) ['applyhow' => ' En secretaría. ', 'applyurl' => 'javascript:alert(1)',
            'applyfrom' => $until, 'applyuntil' => $from]);
        $centre = centres::get((int) $this->centre->id);
        $this->assertSame('En secretaría.', $centre->applyhow);
        $this->assertNull($centre->applyurl);
        $this->assertEquals($from, $centre->applyfrom);
        $this->assertEquals($until, $centre->applyuntil);
        // The settings always send both days, 0 when they are cleared; what is not sent is kept.
        centres::update((int) $this->centre->id, (object) ['applyhow' => '', 'applyurl' => 'https://example.com/admision',
            'applyfrom' => 0]);
        $centre = centres::get((int) $this->centre->id);
        $this->assertNull($centre->applyhow);
        $this->assertSame('https://example.com/admision', $centre->applyurl);
        $this->assertEquals(0, $centre->applyfrom);
    }

    /**
     * The page of how a centre treats the data opens only with its signature: the number of a centre alone is not
     * enough, so nobody goes through the centres of a site one by one.
     */
    public function test_privacy_signed(): void {
        $url = publicpage::privacy_url($this->centre, 'es');
        $id = (int) $this->centre->id;
        $this->assertSame($id, (int) $url->get_param('c'));
        $this->assertTrue(share::signed('privacy', 0, $id, (string) $url->get_param('k')));
        $this->assertFalse(share::signed('privacy', 0, $id + 1, (string) $url->get_param('k')));
        $this->assertFalse(share::signed('privacy', 0, $id, ''));
    }
}
