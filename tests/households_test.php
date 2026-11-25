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
use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\settled;
use local_oksigeniaeguahe\local\siblings;
use local_oksigeniaeguahe\local\visits;

/**
 * What a family gives, gives again or asks again, and what is kept of it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\households
 */
#[\PHPUnit\Framework\Attributes\CoversClass(households::class)]
final class households_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $infantil;

    /** @var int */
    protected $eso;

    /** @var int */
    protected $next;

    /**
     * A centre with three courses.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        $offer = fn(string $name, string $year) => centres::save_offer($id, (object) ['schoolyear' => $year, 'name' => $name, 'places' => 25,
            'active' => 1]);
        $this->infantil = $offer('Infantil 3 años', '2027-28');
        $this->eso = $offer('1.º de ESO', '2027-28');
        $this->next = $offer('1.º de Primaria', '2028-29');
    }

    /**
     * A household with an adult.
     *
     * @param array $adult
     * @return \stdClass
     */
    protected function family(array $adult = ['firstname' => 'Ana', 'email' => 'ana@example.com']): \stdClass {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        households::add_adult($household, (object) $adult);
        return $household;
    }

    /**
     * A child as it is now.
     *
     * @param int $id
     * @return \stdClass
     */
    protected function child(int $id): \stdClass {
        global $DB;
        return $DB->get_record(households::CHILDREN, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * What an adult already there had not said is written only when it comes from who can be trusted.
     */
    public function test_adult_filled(): void {
        $household = $this->family();
        $given = (object) ['firstname' => 'Ana', 'lastname' => 'Pérez', 'email' => 'ana@example.com', 'phone' => '+34600111222'];
        $id = households::add_adult($household, $given);
        $adult = fn() => households::full($household)->adults[$id];
        $this->assertSame('', (string) $adult()->phone, 'A form anyone can fill writes nothing in it.');
        households::add_adult($household, $given, true);
        $this->assertSame(['Pérez', '+34600111222'], [$adult()->lastname, $adult()->phone]);
        // An ad, with only a phone, and the sheet that says who it is.
        $ad = $this->family(['firstname' => '', 'phone' => '+34600800001']);
        $id = households::add_adult($ad, (object) ['firstname' => 'Tel', 'lastname' => 'Solo', 'email' => 'tel@example.com',
            'phone' => '+34600800001'], true);
        $this->assertSame(['Tel', 'tel@example.com'], [households::full($ad)->adults[$id]->firstname, households::full($ad)->adults[$id]->email]);
    }

    /**
     * Written down by the team in a family that was there: nothing of it is lost, and another person is another one.
     */
    public function test_written_down(): void {
        global $DB;
        $household = $this->family(['firstname' => 'Ana', 'lastname' => 'Pérez', 'phone' => '+34922000111']);
        $ana = (int) $DB->get_field(households::ADULTS, 'id', ['householdid' => $household->id]);
        // The same one, with her email.
        $this->assertSame($ana, households::write_down($household, (object) ['firstname' => 'Ana', 'email' => 'ana@example.com',
            'phone' => '+34922000111'], 2));
        $this->assertSame('ana@example.com', $DB->get_field(households::ADULTS, 'email', ['id' => $ana]));
        // The father, from the phone of the home.
        $luis = households::write_down($household, (object) ['firstname' => 'Luis', 'email' => null, 'phone' => '+34922000111'], 2);
        $this->assertNotSame($ana, $luis);
        // The same email and another phone: it stays in a note.
        $this->assertSame($ana, households::write_down($household, (object) ['firstname' => 'Ana', 'email' => 'ana@example.com',
            'phone' => '+34600333444'], 2));
        $this->assertSame('+34922000111', $DB->get_field(households::ADULTS, 'phone', ['id' => $ana]));
        $this->assertStringContainsString('600', $DB->get_field(households::CONTACTS, 'body', ['householdid' => $household->id, 'direction' => 'note']));
        // Her whole name, with the phone she had not given.
        $other = $this->family(['firstname' => 'Eva', 'lastname' => 'Gil Soto', 'email' => 'eva@example.com']);
        $eva = (int) $DB->get_field(households::ADULTS, 'id', ['householdid' => $other->id]);
        $this->assertSame($eva, households::write_down($other, (object) ['firstname' => 'Eva', 'lastname' => 'Gil Soto', 'phone' => '+34600555666'], 2));
        $this->assertSame('+34600555666', $DB->get_field(households::ADULTS, 'phone', ['id' => $eva]));
    }

    /**
     * The same child written otherwise is one.
     */
    public function test_child_named_otherwise(): void {
        $household = $this->family();
        $martin = households::add_child($household, 'Martín', $this->infantil);
        $this->assertSame($martin, households::add_child($household, 'Martin', 0));
        $this->assertSame($martin, households::add_child($household, 'MARTÍN Pérez', 0));
        $this->assertNotSame($martin, households::add_child($household, 'Martina', 0));
        // «Ana» could be either of them: neither.
        $household = $this->family();
        households::add_child($household, 'Ana Belén', 0);
        households::add_child($household, 'Ana María', 0);
        households::add_child($household, 'Ana', 0);
        $this->assertCount(3, households::full($household)->children);
    }

    /**
     * An anonymous form never changes the course of a child already on its way: it is kept, and a person answers.
     */
    public function test_course_of_a_form(): void {
        global $DB;
        $household = $this->family();
        $uma = households::add_child($household, 'Uma', $this->infantil);
        households::set_stage($this->child($uma), 'admitted');
        households::add_child($household, 'Uma', $this->eso, '', true);
        $this->assertSame([$this->infantil, 'admitted'], [(int) $this->child($uma)->offerid, $this->child($uma)->stage]);
        $this->assertGreaterThan(0, (int) $DB->get_field(households::T, 'waitingsince', ['id' => $household->id]));
        $this->assertStringContainsString('1.º de ESO', $DB->get_field(households::CONTACTS, 'body', ['householdid' => $household->id]));
        // Another school year, from who can be trusted: its way starts again, and the history keeps the one it had.
        households::add_child($household, 'Uma', $this->next);
        $this->assertSame([$this->next, 'inquiry'], [(int) $this->child($uma)->offerid, $this->child($uma)->stage]);
        // A row of an older year changes nothing.
        households::add_child($household, 'Uma', $this->infantil);
        $this->assertSame($this->next, (int) $this->child($uma)->offerid);
    }

    /**
     * A family whose way had stopped writes again about its child, or signs up to see the centre: it is open, with its trace.
     */
    public function test_closed_and_back(): void {
        global $DB;
        $household = $this->family();
        $leo = households::add_child($household, 'Leo', $this->infantil);
        households::set_stage($this->child($leo), 'application');
        households::set_stage($this->child($leo), 'closed', 'noanswer');
        $this->assertSame('application', $this->child($leo)->closedstage);
        $DB->set_field(households::CHILDREN, 'stagetime', time() - DAYSECS, ['id' => $leo]);
        // An old answer sent again, or a row of the sheet of the team: not that.
        households::add_child($household, 'Leo', $this->next, '', true);
        households::received($household, 'googleforms', 'Hola', true, time() - YEARSECS, 1);
        households::add_child($household, 'Leo', 0);
        households::received($household, 'other', 'Fila', true, null, 1, '', '', false);
        $this->assertSame('closed', $this->child($leo)->stage);
        // The team notes that they called: they say nothing of it.
        households::received($household, 'phone', 'No cogen', true, null, 0, '', '', true, false, 2);
        $this->assertSame('closed', $this->child($leo)->stage);
        // It writes by a form, with the same course or none.
        households::add_child($household, 'Leo', 0, '', true);
        households::received($household, 'webform', 'Sí queremos plaza', true);
        $this->assertSame(['inquiry', null], [$this->child($leo)->stage, $this->child($leo)->closedreason]);
        $bodies = implode("\n", $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ?', [$household->id]));
        $this->assertStringContainsString(get_string('closed_noanswer', 'local_oksigeniaeguahe'), $bodies);
        // Signed up to an open day.
        households::set_stage($this->child($leo), 'closed', 'other');
        $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => time() + DAYSECS,
            'timeend' => time() + DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
        $signup = events::sign_up($event, (int) $household->id, 2);
        events::attend($event, $signup, true);
        $this->assertSame('visit', $this->child($leo)->stage);
    }

    /**
     * A child that left and is asked for again is on its way, and one whose step changes is no longer gone.
     */
    public function test_left_and_back(): void {
        global $DB;
        $household = $this->family();
        $leo = households::add_child($household, 'Leo', $this->infantil);
        households::set_stage($this->child($leo), 'enrolled');
        settled::leave($household, 2, 'moved', 'A otra isla', time() - YEARSECS);
        $DB->set_field(households::CHILDREN, 'stagetime', time() - 2 * YEARSECS, ['id' => $leo]);
        $this->assertTrue(settled::gone(households::full($household)->children));
        households::add_child($household, 'Leo', $this->next, '', true);
        households::received($household, 'webform', 'Volvemos', true);
        $child = $this->child($leo);
        $this->assertSame(['inquiry', 0, $this->next], [$child->stage, (int) $child->lefttime, (int) $child->offerid]);
        $this->assertFalse(settled::gone(households::full($household)->children));
        // Moved by hand, «Enrolled» again: it is at the centre, not gone.
        $other = $this->family(['firstname' => 'Eva', 'email' => 'eva@example.com']);
        $noa = households::add_child($other, 'Noa', $this->infantil);
        households::set_stage($this->child($noa), 'enrolled');
        settled::leave($other, 2, 'cost', '', time());
        households::set_stage($this->child($noa), 'inquiry');
        households::set_stage($this->child($noa), 'enrolled');
        $this->assertSame([0, null], [(int) $this->child($noa)->lefttime, $this->child($noa)->leftreason]);
        // One left and another asks for a place: they did not go.
        settled::leave($other, 2, 'cost', '', time());
        households::add_child($other, 'Pau', $this->next);
        $this->assertFalse(settled::gone(households::full($other)->children));
        $groups = panel_inbox::days(households::inbox((int) $this->centre->id, false), (int) $this->centre->id, time());
        $this->assertNotContains('sefueron', array_column($groups, 'key'));
    }

    /**
     * What was sent long ago never makes the last time older, and nobody who waits or has a visit to come is erased.
     */
    public function test_not_expired(): void {
        global $DB;
        $now = time();
        $household = $this->family();
        households::received($household, 'googleforms', 'De hace mucho', false, $now - 5 * YEARSECS);
        $this->assertGreaterThan($now - MINSECS, (int) $DB->get_field(households::T, 'lastcontact', ['id' => $household->id]));
        $DB->set_field(households::T, 'lastcontact', $now - 10 * YEARSECS, ['id' => $household->id]);
        $this->assertArrayHasKey($household->id, households::expired($now));
        $DB->set_field(households::T, 'waitingsince', $now - 10 * YEARSECS, ['id' => $household->id]);
        $this->assertArrayNotHasKey($household->id, households::expired($now));
        $DB->set_field(households::T, 'waitingsince', 0, ['id' => $household->id]);
        visits::propose($household, $now + DAYSECS, 45, 0, '', 2);
        $this->assertArrayNotHasKey($household->id, households::expired($now));
    }

    /**
     * What was promised and is due, and who comes today, are in the inbox whatever the family is; those that were said to come
     * or not, no longer; and the closed ones, apart with why.
     */
    public function test_inbox(): void {
        global $DB;
        $this->setAdminUser();
        $centreid = (int) $this->centre->id;
        $now = make_timestamp(2026, 11, 25, 9, 0);
        $guest = $this->family();
        $DB->set_field(households::T, 'guest', 1, ['id' => $guest->id]);
        attend::promise($guest, 2, 'Mandarles el programa', $now - 2 * DAYSECS);
        $comes = $this->family(['firstname' => 'Eva', 'email' => 'eva@example.com']);
        $start = make_timestamp(2026, 11, 25, 12, 0);
        $event = events::get(events::save($centreid, (object) ['kind' => 'visit', 'name' => 'Visita', 'timestart' => $start,
            'timeend' => $start + HOURSECS, 'capacity' => 0, 'open' => 0]), $centreid);
        $signup = events::sign_up($event, (int) $comes->id, 1);
        $closed = $this->family(['firstname' => 'Luis', 'email' => 'luis@example.com']);
        $child = households::add_child($closed, 'Leo', $this->infantil);
        households::set_stage($this->child($child), 'closed', 'cost');
        $keys = function (bool $all) use ($now): array {
            $context = panel_inbox::context($this->centre, $all, 2, true, [], $now);
            $out = [];
            foreach ($context['days'] as $day) {
                $out[$day['key']] = array_column($day['rows'], 'id');
            }
            return $out;
        };
        $waiting = $keys(false);
        $this->assertSame([(string) $guest->id], $waiting['prometido']);
        $this->assertSame([(string) $comes->id], $waiting['hoy']);
        $this->assertSame([(string) $closed->id], $keys(true)['cerradas']);
        $this->assertCount(1, panel_inbox::coming_today($centreid, $now));
        $DB->set_field(events::SIGNUPS, 'missed', $now, ['id' => $signup]);
        $this->assertSame([], panel_inbox::coming_today($centreid, $now));
    }

    /**
     * Nothing of a family that erased its data stays in what the team was told, and whoever was to receive it knows.
     */
    public function test_erase(): void {
        global $DB;
        $household = $this->family(['firstname' => 'Marta', 'lastname' => 'Gil', 'email' => 'marta@example.com']);
        households::add_child($household, 'Sol', $this->infantil);
        $reply = (int) $DB->insert_record(attend::REPLIES, (object) ['centreid' => $this->centre->id, 'userid' => 2, 'title' => 'Pregunten',
            'body' => 'Pregunten por Marta en Secretaría, al sol del patio. Como a Marta Gil (marta@example.com).', 'words' => '', 'uses' => 0,
            'timecreated' => 1, 'timemodified' => 1]);
        $url = fn(int $id) => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $id]))->out(false);
        $notice = fn(string $text) => (int) $DB->insert_record('notifications', (object) ['useridfrom' => 2, 'useridto' => 2, 'subject' => 'Familia nueva',
            'fullmessage' => $text, 'fullmessageformat' => FORMAT_PLAIN, 'fullmessagehtml' => '', 'smallmessage' => '',
            'component' => 'local_oksigeniaeguahe', 'eventtype' => 'newfamily', 'contexturl' => '', 'timecreated' => 1]);
        $mine = $notice('Marta Gil — 25 nov' . "\n" . $url((int) $household->id) . "\n\nLa bandeja");
        $two = $notice('Marta Gil — 25 nov' . "\n" . $url((int) $household->id) . "\n\nEva — 25 nov\n" . $url((int) $household->id * 10) . "\n\nLa bandeja");
        $event = visits::propose($household, time() + DAYSECS, 45, 0, '', 2);
        households::erase($household);
        $this->assertSame(
            'Pregunten por Marta en Secretaría, al sol del patio. Como a {names} ().',
            $DB->get_field(attend::REPLIES, 'body', ['id' => $reply])
        );
        $this->assertFalse($DB->record_exists('notifications', ['id' => $mine]));
        $left = $DB->get_field('notifications', 'fullmessage', ['id' => $two]);
        $this->assertStringNotContainsString('Marta', $left);
        $this->assertStringContainsString('Eva', $left);
        $this->assertSame([(int) $event->timestart], households::gone_visits((int) $this->centre->id, time()));
        $this->assertSame([], households::gone_visits((int) $this->centre->id, time() + 2 * DAYSECS));
    }

    /**
     * The download has what it answered to a letter, that it did not come, the visits of the sheet, the notices and what the lists say.
     */
    public function test_export_whole(): void {
        global $DB;
        $household = $this->family();
        $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'other', 'name' => 'Charla', 'timestart' => time() - HOURSECS,
            'timeend' => time() + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
        $signup = events::sign_up($event, (int) $household->id, 1);
        $DB->set_field(events::SIGNUPS, 'missed', 5, ['id' => $signup]);
        $DB->insert_record(invites::T, (object) ['centreid' => $this->centre->id, 'eventid' => $event->id, 'householdid' => $household->id, 'userid' => 2,
            'channel' => 'email', 'bulkid' => 0, 'opened' => 6, 'reply' => 'yes', 'replied' => 7, 'timecreated' => 4]);
        $DB->insert_record(\local_oksigeniaeguahe\local\teamnotes::T, (object) ['centreid' => $this->centre->id, 'householdid' => $household->id,
            'mark' => 'x', 'visit' => time() - DAYSECS, 'dayonly' => 0, 'state' => 'notcame', 'timecreated' => 1]);
        $DB->insert_record(\local_oksigeniaeguahe\local\listmatch::T, (object) ['centreid' => $this->centre->id, 'householdid' => $household->id,
            'schoolyear' => '2026-27', 'student' => 't', 'kind' => 'incentre', 'grade' => '4.º', 'timein' => 0, 'childid' => 0, 'done' => 1,
            'marked' => 0, 'timecreated' => 1]);
        \local_oksigeniaeguahe\local\notices::log($household, 'reminder', null, 'email', 'sent');
        $data = households::export($household);
        $this->assertNotNull($data['events'][0]['did_not_come']);
        $this->assertSame('yes', $data['invited_to'][0]['you_said']);
        $this->assertSame('no', $data['visits_the_team_wrote_down'][0]['came']);
        $this->assertSame('reminder', $data['notices'][0]['what']);
        $this->assertSame('4.º', $data['in_the_lists_of_the_centre'][0]['course']);
    }

    /**
     * A brother or sister of a family that closed or asked not to be written to is not proposed, and what was promised goes
     * with whoever is taken out.
     */
    public function test_siblings(): void {
        global $DB;
        $DB->set_field(centres::T, 'siblingage', 3, ['id' => $this->centre->id]);
        $centre = centres::get((int) $this->centre->id);
        $now = time();
        $year = siblings::campaign_year($centre, $now) - 3;
        $household = $this->family();
        $leo = households::add_child($household, 'Leo', $this->infantil);
        $id = siblings::add($household, 'Pau', $year, 'team', $now);
        $DB->set_field(centres::T, 'campaignstart', $now - DAYSECS, ['id' => $centre->id]);
        $centre = centres::get((int) $this->centre->id);
        $DB->set_field(siblings::T, 'birthyear', siblings::campaign_year($centre, $now) - 3, ['id' => $id]);
        $this->assertArrayHasKey($household->id, siblings::due($centre, $now));
        households::set_stage($this->child($leo), 'closed', 'other');
        $this->assertArrayNotHasKey($household->id, siblings::due($centre, $now));
        siblings::notify($household, $centre, $id, $now);
        $this->assertArrayHasKey($household->id, siblings::due($centre, $now), 'It asked to be told.');
        $DB->set_field(households::T, 'writeby', 'none', ['id' => $household->id]);
        $this->assertArrayNotHasKey($household->id, siblings::due($centre, $now));
        $this->assertNotNull(attend::open_promise((int) $household->id));
        siblings::remove($household, $id);
        $this->assertNull(attend::open_promise((int) $household->id));
    }

    /**
     * All that was promised is there, each with its «Done»; a new one never hides the one before.
     */
    public function test_promises(): void {
        $this->setAdminUser();
        $household = $this->family();
        attend::promise($household, 2, 'Mandarle la hoja', time());
        attend::promise($household, 2, 'Llamarla', time() + 2 * DAYSECS);
        $context = \local_oksigeniaeguahe\local\panel_household::context($household, $this->centre, true);
        $this->assertCount(2, $context['promises']);
    }

    /**
     * The questions of the form of the centre are not of the family; and what was only noted is not said as written to them.
     */
    public function test_said_as_it_was(): void {
        $this->setAdminUser();
        $household = $this->family();
        households::received($household, 'webform', "Llegó por «Pidan información»\n\n¿Para cuándo buscan plaza?: 2027-28\n¿Algo más?: ¿Tienen comedor?", true);
        $asked = array_column(\local_oksigeniaeguahe\local\questions::asked(households::full($household)->contacts), 'text');
        $this->assertSame(['¿Tienen comedor?'], $asked);
        households::told($household, 2, 'other', 'Cita cambiada: jueves 3.');
        $context = \local_oksigeniaeguahe\local\panel_household::context(households::get((int) $household->id, 0), $this->centre, true);
        $this->assertContains(get_string('panel_noted_only', 'local_oksigeniaeguahe'), array_column($context['history'], 'how'));
        $this->assertSame(
            get_string('panel_writeby_onlyanswer', 'local_oksigeniaeguahe'),
            $context['writeby']['now']
        );
    }

    /**
     * With its ways closed a family no longer waits, when a person says so, and that is not an answer.
     */
    public function test_closed_waits_no_more(): void {
        global $DB;
        $household = $this->family();
        $leo = households::add_child($household, 'Leo', $this->infantil);
        $noa = households::add_child($household, 'Noa', $this->infantil);
        households::received($household, 'email', 'Hola', true);
        households::set_stage($this->child($leo), 'closed', 'other');
        $this->assertFalse(\local_oksigeniaeguahe\local\settle::closed($household, 2), 'Her sister goes on.');
        households::set_stage($this->child($noa), 'closed', 'other');
        $this->assertTrue(\local_oksigeniaeguahe\local\settle::closed($household, 2));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'waitingsince', ['id' => $household->id]));
        $this->assertSame(0, $DB->count_records(households::CONTACTS, ['householdid' => $household->id, 'direction' => 'out']));
    }
}
