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

use local_oksigeniaeguahe\local\appointments;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\notices;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\panel_notices;
use local_oksigeniaeguahe\local\panel_settle;
use local_oksigeniaeguahe\local\settle;
use local_oksigeniaeguahe\local\writeby;

/**
 * Tests for what the centre sent and how it went, and for the families that wait and may already be answered.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\notices
 * @covers     \local_oksigeniaeguahe\local\settle
 */
#[\PHPUnit\Framework\Attributes\CoversClass(notices::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(settle::class)]
final class notices_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /**
     * A centre.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->centre = centres::get(centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']));
    }

    /**
     * A family.
     *
     * @param string $name
     * @param bool $email Whether it has an email and lets the centre write by it.
     * @return \stdClass
     */
    protected function family(string $name, bool $email = true): \stdClass {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        $adult = households::add_adult($household, (object) ['firstname' => $name, 'phone' => '+3460033' . sprintf('%04d', $id % 10000),
            'email' => $email ? strtolower($name) . "$id@example.com" : '']);
        if ($email) {
            households::consent($household, $adult, 'email', true, 'signup', 'es');
        }
        return households::get($id, (int) $this->centre->id);
    }

    /**
     * What went and what did not, with why; the reminder to come, with its day; and sending it again.
     */
    public function test_notices(): void {
        $sink = $this->redirectEmails();
        $start = time() + 5 * DAYSECS;
        $ana = $this->family('Ana');
        appointments::give($ana, $this->centre, $start, 45, 0, 'Secretaría', 2, true);
        $all = notices::of($ana, $this->centre);
        $this->assertSame(['reminder', 'queued', ''], [$all[0]['kind'], $all[0]['state'], $all[0]['reason']]);
        $this->assertGreaterThan(time() + 3 * DAYSECS, $all[0]['due']);
        $this->assertSame(['invitation', 'email', 'sent', ''], [$all[1]['kind'], $all[1]['channel'], $all[1]['state'], $all[1]['reason']]);
        $this->assertTrue($all[1]['again']);
        $this->assertSame($start, $all[1]['start']);
        $this->assertNotSame('', $all[1]['what']);
        $this->assertTrue(notices::resend($ana, $this->centre, $all[1]['id']));
        $this->assertCount(3, notices::of($ana, $this->centre));
        $this->assertNull(notices::resend($ana, $this->centre, 999999));

        $luis = $this->family('Luis', false);
        appointments::give($luis, $this->centre, $start, 45, 0, 'Secretaría', 2, true);
        $this->assertSame(['skipped', 'noemail'], [notices::of($luis, $this->centre)[1]['state'], notices::of($luis, $this->centre)[1]['reason']]);
        $this->assertSame(['blocked', 'noemail'], [notices::of($luis, $this->centre)[0]['state'], notices::of($luis, $this->centre)[0]['reason']]);

        $eva = $this->family('Eva');
        writeby::set($eva, [], 'family');
        appointments::give(households::get((int) $eva->id, (int) $this->centre->id), $this->centre, $start, 45, 0, '', 2, true);
        $this->assertSame(['skipped', 'silent'], [notices::of($eva, $this->centre)[1]['state'], notices::of($eva, $this->centre)[1]['reason']]);

        $this->assertFalse(notices::of($eva, $this->centre)[1]['again'], 'It asked not to be written to.');
        $this->assertNull(notices::resend($eva, $this->centre, notices::of($eva, $this->centre)[1]['id']));

        // Not told on purpose, nothing is kept as if it had been tried.
        $leo = $this->family('Leo');
        appointments::give($leo, $this->centre, $start, 45, 0, '', 2, false);
        $this->assertSame(['reminder'], array_column(notices::of($leo, $this->centre), 'kind'));
        // In words, on its file.
        $words = panel_notices::context($ana, $this->centre, true);
        $this->assertSame(get_string('notice_kind_reminder', 'local_oksigeniaeguahe'), $words['ahead'][0]['name']);
        $this->assertSame(get_string('notice_kind_invitation', 'local_oksigeniaeguahe'), $words['recent'][0]['name']);
        $this->assertTrue($words['recent'][0]['again']);
        $this->assertCount(1, $words['older'], 'The one before it, sent again since.');
        $this->assertTrue(panel_notices::context($luis, $this->centre, true)['ahead'][0]['didnot']);
        $this->assertFalse(panel_notices::context($ana, $this->centre, false)['recent'][0]['again']);
        // Called off: what it was of stays in the line.
        $signup = \local_oksigeniaeguahe\local\familymail::signup($ana, $all[1]['eventid']);
        $this->assertNotNull($signup);
        events::cancel(events::get($all[1]['eventid'], (int) $this->centre->id), $this->centre, 2, '', time());
        $last = notices::of($ana, $this->centre)[0];
        $this->assertSame(['cancelled', 'sent', $all[1]['what']], [$last['kind'], $last['state'], $last['what']]);
        $this->assertFalse($last['again']);
        $sink->close();
    }

    /**
     * The reminder of the eve is kept too; and it does not go without a way to.
     */
    public function test_reminder(): void {
        $sink = $this->redirectEmails();
        $now = make_timestamp(2026, 11, 25, 10, 0, 0, 99);
        $start = make_timestamp(2026, 11, 26, 11, 0, 0, 99);
        [$ana, $luis] = [$this->family('Ana'), $this->family('Luis', false)];
        foreach ([$ana, $luis] as $household) {
            appointments::give($household, $this->centre, $start, 45, 0, '', 2, false);
        }
        \local_oksigeniaeguahe\task\send_reminders::$testnow = $now;
        (new \local_oksigeniaeguahe\task\send_reminders())->execute();
        \local_oksigeniaeguahe\task\send_reminders::$testnow = null;
        $of = fn($h) => array_map(fn($n) => [$n['kind'], $n['channel'], $n['state'], $n['reason']], notices::of($h, $this->centre, $now));
        $this->assertSame([['reminder', 'email', 'sent', '']], $of($ana));
        $this->assertSame([['reminder', 'email', 'skipped', 'noemail']], $of($luis));
        $sink->close();
    }

    /**
     * Those that wait and came after writing, or have an appointment given after it; none that wrote since. Said of several,
     * and taken back.
     */
    public function test_settle(): void {
        global $DB;
        $past = time() - 3 * DAYSECS;
        $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => $past,
            'timeend' => $past + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
        $families = [];
        foreach (['Ana', 'Luis', 'Eva', 'Leo'] as $name) {
            $families[$name] = $this->family($name);
            households::received($families[$name], 'webform', '¿Hay comedor?', true, $past - DAYSECS);
        }
        foreach (['Ana', 'Luis', 'Eva'] as $name) {
            $signup = events::sign_up($event, (int) $families[$name]->id, 2);
            events::attend($event, $signup, true);
        }
        // Eva wrote again after coming.
        households::received($families['Eva'], 'webform', 'Otra pregunta', true, time() - HOURSECS);
        $groups = settle::candidates($this->centre);
        $this->assertCount(1, $groups);
        $this->assertSame('came', $groups[0]['reason']);
        $this->assertEqualsCanonicalizing([(int) $families['Ana']->id, (int) $families['Luis']->id], array_keys($groups[0]['families']));

        // Given an appointment after writing.
        appointments::give($families['Leo'], $this->centre, time() + DAYSECS, 45, 0, '', 2, false);
        $booked = array_column(settle::candidates($this->centre), null, 'reason')['booked'];
        $this->assertSame([(int) $families['Leo']->id], array_keys($booked['families']));

        // The page says it by why, with what they wrote; the inbox, that there are some.
        $page = panel_settle::context($this->centre, true);
        $this->assertSame(3, array_sum(array_column($page['groups'], 'n')));
        $this->assertStringContainsString('¿Hay comedor?', $page['groups'][0]['families'][0]['said']);
        $inbox = panel_inbox::context($this->centre, true, 2, true);
        $this->assertContains(get_string('settle_title', 'local_oksigeniaeguahe'), array_column($inbox['today'], 'title'));

        $waits = fn($name) => (int) households::get((int) $families[$name]->id, (int) $this->centre->id)->waitingsince;
        $batch = settle::many($this->centre, [(int) $families['Ana']->id, (int) $families['Luis']->id, (int) $families['Eva']->id], $groups[0]['key'], 2);
        $this->assertSame([0, 0], [$waits('Ana'), $waits('Luis')]);
        $this->assertGreaterThan(0, $waits('Eva'));
        $this->assertSame('', settle::many($this->centre, [(int) $families['Ana']->id], $groups[0]['key'], 2), 'It no longer waits.');
        // Answered since, it does not wait again.
        households::answered(households::get((int) $families['Luis']->id, (int) $this->centre->id), 2, 'email', 'Hola');
        $this->assertSame(1, settle::undo($this->centre, $batch, 2));
        $this->assertSame($past - DAYSECS, $waits('Ana'));
        $this->assertSame(0, $waits('Luis'));
        $this->assertSame(0, $DB->count_records(settle::T));

        // Left waiting: not asked about again until something else happens; and taken back.
        $key = array_column(settle::candidates($this->centre), null, 'reason')['came']['key'];
        $batch = settle::many($this->centre, [], $key, 2, 0, [(int) $families['Ana']->id]);
        $this->assertNotSame('', $batch);
        $this->assertGreaterThan(0, $waits('Ana'));
        $ids = fn() => array_merge(...array_map(fn($g) => array_keys($g['families']), settle::candidates($this->centre)));
        $this->assertNotContains((int) $families['Ana']->id, $ids());
        $this->assertSame(get_string('settle_kept_one', 'local_oksigeniaeguahe'), panel_settle::context($this->centre, true, $batch)['undotext']);
        $this->assertSame(0, settle::undo($this->centre, $batch, 2));
        $this->assertContains((int) $families['Ana']->id, $ids());
    }
}
