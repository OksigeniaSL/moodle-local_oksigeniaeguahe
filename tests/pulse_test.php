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
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\moments;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\pulse;
use local_oksigeniaeguahe\local\teamnotice;

/**
 * Whether what comes in through each entry is still coming: the four ways of going quiet, and that «nobody is waiting»
 * is only said of what arrived.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\pulse
 */
#[\PHPUnit\Framework\Attributes\CoversClass(pulse::class)]
final class pulse_test extends \advanced_testcase {
    /** @var \stdClass The centre. */
    protected \stdClass $centre;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->centre = centres::get(centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']));
    }

    /**
     * An entry of the centre.
     *
     * @param string $kind
     * @return \stdClass
     */
    protected function entry(string $kind): \stdClass {
        return entries::get(entries::create((int) $this->centre->id, $kind, 'Hoja de visitas'), (int) $this->centre->id);
    }

    /**
     * A sheet that calls every night is missed after one night; one that has only just begun is not.
     */
    public function test_every_night(): void {
        $now = time();
        $sheet = $this->entry('google');
        $this->assertSame('never', pulse::of($sheet, $now)['state']);
        pulse::called($sheet, 3, 0, true, [], $now - 4 * DAYSECS);
        // The same night, in several calls, is one.
        pulse::called($sheet, 3, 0, true, [], $now - 4 * DAYSECS + 60);
        $this->assertFalse(pulse::nightly($sheet));
        $this->assertSame('ok', pulse::of($sheet, $now)['state'], 'One call is not a rhythm.');
        pulse::called($sheet, 0, 0, true, [], $now - 3 * DAYSECS);
        $this->assertTrue(pulse::nightly($sheet));
        $this->assertSame('ok', pulse::of($sheet, $now - 3 * DAYSECS + 30 * HOURSECS)['state']);
        $pulse = pulse::of($sheet, $now);
        $this->assertSame(['quiet', $now - 3 * DAYSECS], [$pulse['state'], $pulse['since']]);
        // It calls again: nothing to say, and the next silence has another key.
        pulse::called($sheet, 1, 0, true, [], $now - 2 * DAYSECS);
        $this->assertSame('quiet', pulse::of($sheet, $now)['state']);
        $this->assertNotSame($pulse['key'], pulse::of($sheet, $now)['key']);
        pulse::called($sheet, 1, 0, false, [], $now);
        $this->assertSame('ok', pulse::of($sheet, $now)['state']);
    }

    /**
     * Refused by its key, tabs it does not find, and rows nothing is understood of.
     */
    public function test_calls_and_brings_nothing(): void {
        $now = time();
        $sheet = $this->entry('google');
        pulse::refused($sheet, $now - DAYSECS);
        pulse::refused($sheet, $now);
        $pulse = pulse::of(entries::get((int) $sheet->id, (int) $this->centre->id), $now);
        $this->assertSame(['refused', $now - DAYSECS], [$pulse['state'], $pulse['since']], 'Since the first time.');
        pulse::called($sheet, 1, 0, true, ['Visitas'], $now);
        pulse::called($sheet, 1, 0, true, ['Visitas', 'Publi'], $now + 13 * HOURSECS);
        $pulse = pulse::of($sheet, $now + 13 * HOURSECS);
        $this->assertSame(['missing', $now], [$pulse['state'], $pulse['since']], 'Since the first night it was missed.');
        pulse::called($sheet, 1, 0, true, [], $now + DAYSECS);
        $this->assertSame('ok', pulse::of($sheet, $now + DAYSECS)['state']);
        // A row of the end of a sheet, after others that were read, says nothing.
        pulse::called($sheet, 0, 40, true, [], $now + DAYSECS + 60);
        $this->assertSame('ok', pulse::of($sheet, $now + DAYSECS + 60)['state']);
        pulse::called($sheet, 0, 40, true, [], $now + 2 * DAYSECS);
        pulse::called($sheet, 0, 40, true, [], $now + 2 * DAYSECS + HOURSECS);
        $pulse = pulse::of($sheet, $now + 2 * DAYSECS + HOURSECS);
        $this->assertSame(['unread', $now + 2 * DAYSECS], [$pulse['state'], $pulse['since']]);
        pulse::called($sheet, 2, 0, true, [], $now + 3 * DAYSECS);
        $this->assertSame('ok', pulse::of($sheet, $now + 3 * DAYSECS)['state']);
        // The address of a form is anyone's: a call to it with a false key says nothing.
        $form = $this->entry('form');
        pulse::refused($form, $now);
        $this->assertNotSame('refused', pulse::of($form, $now)['state']);
    }

    /**
     * A form is only compared with itself: with few families it has no rhythm and is never hurried.
     */
    public function test_its_own_rhythm(): void {
        global $DB;
        $now = time();
        $form = $this->entry('form');
        $family = function (int $daysago) use ($DB, $form, $now): void {
            $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $DB->insert_record(households::CONTACTS, (object) ['centreid' => $this->centre->id, 'householdid' => $id,
                'direction' => 'in', 'channel' => 'webform', 'body' => 'Hola', 'entryid' => $form->id, 'userid' => 0,
                'timecreated' => $now - $daysago * DAYSECS]);
            $DB->set_field(entries::T, 'lastreceived', $now - $daysago * DAYSECS, ['id' => $form->id]);
        };
        foreach ([60, 40, 30] as $daysago) {
            $family($daysago);
        }
        $form = entries::get((int) $form->id, (int) $this->centre->id);
        $this->assertSame('ok', pulse::of($form, $now)['state'], 'Three families are not a rhythm.');
        foreach ([29, 27, 24, 22, 20, 19] as $daysago) {
            $family($daysago);
        }
        // The longest silence was twenty days: nineteen more are not strange.
        $form = entries::get((int) $form->id, (int) $this->centre->id);
        $this->assertSame('ok', pulse::of($form, $now)['state']);
        $later = $now + 25 * DAYSECS;
        $pulse = pulse::of($form, $later);
        $this->assertSame(['quiet', 20], [$pulse['state'], $pulse['longest']]);
        $words = fn($entry) => pulse::words(pulse::of($entry, $later) + ['entry' => $entry], $later);
        $this->assertStringContainsString(get_string('pulse_quiet_unopened', 'local_oksigeniaeguahe'), $words($form));
        pulse::opened($form, $later);
        pulse::opened($form, $later);
        $form = entries::get((int) $form->id, (int) $this->centre->id);
        $this->assertStringContainsString(get_string('pulse_quiet_opened', 'local_oksigeniaeguahe', (object) ['opened' => 2]), $words($form));
        // Sent: what was opened is counted from zero.
        entries::counted($form);
        $this->assertSame(0, (int) $DB->get_field(entries::T, 'opened', ['id' => $form->id]));
        // Closed, nothing is said of it.
        $DB->set_field(entries::T, 'active', 0, ['id' => $form->id]);
        $this->assertSame([], pulse::silent($this->centre, $later));
    }

    /**
     * The tape of an entry and its stamp: what came each day, quiet since the day after its last call, and nothing said of
     * the days before it was there.
     */
    public function test_tape(): void {
        global $DB;
        $now = time();
        $sheet = $this->entry('google');
        $this->assertFalse(pulse::tag(pulse::of($sheet, $now), $now));
        $DB->set_field(entries::T, 'timecreated', $now - 10 * DAYSECS, ['id' => $sheet->id]);
        foreach ([5, 5, 5, 4] as $daysago) {
            $DB->insert_record(households::CONTACTS, (object) ['centreid' => $this->centre->id, 'householdid' => households::create(
                (int) $this->centre->id,
                (object) ['lang' => 'es', 'firstchannel' => 'webform']
            ), 'direction' => 'in', 'channel' => 'webform', 'body' => 'Hola', 'entryid' => $sheet->id, 'userid' => 0,
                'timecreated' => $now - $daysago * DAYSECS]);
        }
        // One of them writes twice that day: it is still one family.
        $again = $DB->get_records(households::CONTACTS, ['entryid' => $sheet->id], 'id ASC', '*', 0, 1);
        $again = reset($again);
        unset($again->id);
        $DB->insert_record(households::CONTACTS, $again);
        pulse::called($sheet, 3, 0, true, [], $now - 4 * DAYSECS);
        pulse::called($sheet, 1, 0, true, [], $now - 3 * DAYSECS);
        $pulses = pulse::of_centre($this->centre, $now);
        $days = pulse::tapes($this->centre, $pulses, $now)[(int) $sheet->id];
        $this->assertCount(pulse::TAPE, $days);
        // The last one is today: «five days ago» is the sixth from the end.
        $ago = fn(int $n, ?array $of = null): array => ($of ?? $days)[pulse::TAPE - 1 - $n];
        $this->assertSame([3, 2], [$ago(5)['n'], $ago(5)['h']]);
        $this->assertSame([1, 1], [$ago(4)['n'], $ago(4)['h']]);
        $this->assertSame([false, true, true, true], [$ago(3)['quiet'], $ago(2)['quiet'], $ago(1)['quiet'], $ago(0)['quiet']]);
        $this->assertSame([true, false], [$ago(11)['before'], $ago(10)['before']]);
        // Without one of its tabs, a day with nobody is not known; one with families is.
        $DB->update_record(entries::T, (object) ['id' => $sheet->id, 'beats' => null, 'missing' => 'Visitas',
            'missingat' => $now - 5 * DAYSECS]);
        $missing = pulse::of_centre($this->centre, $now);
        $days = pulse::tapes($this->centre, $missing, $now)[(int) $sheet->id];
        $this->assertSame('missing', $missing[0]['state']);
        $this->assertSame([false, true, true], [$ago(4, $days)['quiet'], $ago(3, $days)['quiet'], $ago(0, $days)['quiet']]);
        $tag = pulse::tag($pulses[0], $now);
        $this->assertSame(get_string('pulse_tag_quiet', 'local_oksigeniaeguahe'), $tag['word']);
        $this->assertNotSame('', $tag['since']);
        $this->assertStringContainsString('Hoja de visitas', pulse::words($pulses[0], $now));
        $this->assertStringNotContainsString('Hoja de visitas', pulse::words($pulses[0], $now, false));
    }

    /**
     * With an entry that may not be bringing all there is, the inbox does not say that nobody is waiting; «known» takes
     * the note away, never that; and the team is told once.
     */
    public function test_what_is_said(): void {
        global $DB;
        $this->setAdminUser();
        $c = 'local_oksigeniaeguahe';
        $id = (int) $this->centre->id;
        $user = $this->getDataGenerator()->create_user();
        centres::add_member($id, (int) $user->id);
        teamnotice::toggle($id, (int) $user->id);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'phone']), $id);
        $DB->set_field(households::T, 'waitingsince', 0, ['id' => $household->id]);
        $noon = make_timestamp((int) date('Y'), (int) date('n'), (int) date('j'), 12);
        $title = fn() => panel_inbox::context($this->centre, false, (int) get_admin()->id, true, [], $noon)['head']['title'];
        $sheet = $this->entry('google');
        $this->assertSame(get_string('inbox_empty_waiting', $c), $title());
        pulse::refused($sheet, $noon - DAYSECS);
        $this->assertSame(get_string('inbox_empty_waiting_known', $c), $title());
        $sink = $this->redirectMessages();
        $night = make_timestamp((int) date('Y'), (int) date('n'), (int) date('j'), 3);
        $this->assertSame(0, teamnotice::silences($this->centre, $night), 'By day only.');
        $this->assertSame(1, teamnotice::silences($this->centre, $noon));
        $this->assertSame(0, teamnotice::silences($this->centre, $noon + HOURSECS), 'Once for each silence.');
        $this->assertSame('entrysilent', $sink->get_messages()[0]->eventtype);
        $sink->close();
        $key = pulse::silent($this->centre, $noon)[0]['key'];
        moments::know($id, $key);
        $this->assertTrue(moments::knows($id, $key));
        $this->assertSame(get_string('inbox_empty_waiting_known', $c), $title(), 'Known, and still not everything.');
        pulse::called($sheet, 1, 0, false, [], $noon);
        $this->assertSame(get_string('inbox_empty_waiting', $c), $title());
    }
}
