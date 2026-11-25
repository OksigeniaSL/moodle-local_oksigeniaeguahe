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
use local_oksigeniaeguahe\local\practice;

/**
 * What the other plugins read from Eguahe: the open acts for the screens of Hechauka.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\api
 */
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class api_test extends \advanced_testcase {
    /**
     * Today's act with how many came, the coming ones with their turns; never a cancelled act, a visit for one family,
     * one too far ahead or the practice centre.
     */
    public function test_open_acts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        centres::update($id, (object) ['guide' => 'perro', 'guidehello' => '¡Bienvenidos!']);
        $now = time();
        $act = fn(string $kind, int $from, int $hours, array $more = []) => events::save($id, (object) ($more + [
            'kind' => $kind, 'name' => 'Acto ' . $from, 'place' => 'Patio', 'timestart' => $now + $from,
            'timeend' => $now + $from + $hours * HOURSECS, 'capacity' => 40, 'open' => 1]));
        $today = $act('openday', -HOURSECS, 4);
        $later = $act('other', 10 * DAYSECS, 2);
        $cancelled = $act('openday', 3 * DAYSECS, 2);
        $DB->set_field(events::T, 'cancelled', 1, ['id' => $cancelled]);
        $act('openday', 40 * DAYSECS, 2);
        $act('visit', 2 * DAYSECS, 2, ['tailored' => 1]);
        $at = $now + 10 * DAYSECS;
        events::save_slots(events::get($later, $id), [
            ['id' => 0, 'timestart' => $at, 'timeend' => $at + HOURSECS, 'capacity' => 1],
            ['id' => 0, 'timestart' => $at + HOURSECS, 'timeend' => $at + 2 * HOURSECS, 'capacity' => 0],
        ]);
        $family = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $signup = events::sign_up(events::get($today, $id), (int) $family->id, 3);
        events::attend(events::get($today, $id), $signup, true, $now);
        practice::centre($now);

        $acts = api::open_acts(null, $now);
        $this->assertSame([$today, $later], array_column($acts, 'id'));
        [$first, $second] = $acts;
        $this->assertTrue($first['today']);
        $this->assertSame(1, $first['came']);
        $this->assertSame(3, $first['signed']);
        $this->assertSame('¡Bienvenidos!', $first['guide']['hello']);
        $this->assertStringContainsString('perro-saluda-512.webp', $first['guide']['img']);
        $this->assertFileExists($first['guide']['path']);
        $this->assertStringContainsString('j.php?t=', $first['url']);
        $this->assertFalse($second['today']);
        $this->assertNull($second['came']);
        $this->assertCount(2, $second['turns']);
        $this->assertSame([], api::open_acts($id + 100, $now));
    }

    /**
     * The visits of today for the screen at the door: the open day and a visit made for one family, with who welcomes
     * them, how long and how many came; never tomorrow, a cancelled one, nor anything of a family.
     */
    public function test_visits_today(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        centres::update($id, (object) ['guide' => 'perro', 'guidewhere' => 'Hasta Secretaría']);
        $ana = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Rivero']);
        centres::add_member($id, $ana->id);
        $memberid = (int) $DB->get_field(centres::MEMBERS, 'id', ['centreid' => $id, 'userid' => $ana->id]);
        $DB->update_record(centres::MEMBERS, (object) ['id' => $memberid, 'public' => 1, 'rolename' => 'Secretaría']);
        $now = usergetmidnight(time()) + 10 * HOURSECS;
        $act = fn(string $kind, int $from, int $minutes, array $more = []) => events::save($id, (object) ($more + [
            'kind' => $kind, 'name' => 'Acto ' . $from, 'place' => 'Entrada', 'timestart' => $now + $from,
            'timeend' => $now + $from + $minutes * MINSECS, 'capacity' => 40, 'open' => 1, 'hosts' => (string) $memberid]));
        $openday = $act('openday', -HOURSECS, 180);
        // The act where families ask for a visit of their own has no hour, only the time to ask: never on the screen.
        $act('visit', 2 * HOURSECS, 90 * 24 * 60, ['tailored' => 1]);
        $visit = $act('visit', 2 * HOURSECS, 45);
        $act('openday', DAYSECS, 60);
        $cancelled = $act('visit', 3 * HOURSECS, 30);
        $DB->set_field(events::T, 'cancelled', 1, ['id' => $cancelled]);
        $family = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $DB->set_field(events::T, 'householdid', $family->id, ['id' => $visit]);
        $signup = events::sign_up(events::get($openday, $id), (int) $family->id, 3);
        events::attend(events::get($openday, $id), $signup, true, $now);

        $visits = api::visits_today($id, $now);
        $this->assertSame([$openday, $visit], array_column($visits, 'id'));
        [$day, $one] = $visits;
        $this->assertSame(180, $day['minutes']);
        $this->assertSame(1, $day['came']);
        $this->assertSame(3, $day['signed']);
        $this->assertSame('Ana', $day['hosts'][0]['first']);
        $this->assertSame('Secretaría', $day['hosts'][0]['role']);
        $this->assertSame('Hasta Secretaría', $day['where']);
        $this->assertTrue($one['tailored']);
        $this->assertSame(45, $one['minutes']);
        $this->assertSame(0, $one['came']);
        // Nothing of a family: no name, no household, no link of theirs.
        $this->assertArrayNotHasKey('householdid', $one);
        $this->assertStringNotContainsString('f.php', json_encode($visits));
        $this->assertSame([], api::visits_today($id + 100, $now));
    }
    /**
     * The families that ask for a place, found by any word of the names of their adults or children, without accents,
     * for the people of their centre only, and only adults with a phone.
     */
    public function test_chat_candidates(): void {
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($cid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 0,
            'active' => 1]);
        $team = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniaeguahe:work', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $team->id, \context_system::instance()->id);
        role_assign($role, $other->id, \context_system::instance()->id);
        centres::add_member($cid, (int) $team->id);
        $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'meta']);
        $household = households::get($id, $cid);
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'García López',
            'email' => null, 'phone' => '+34600111222']);
        households::add_adult($household, (object) ['firstname' => 'Luis', 'lastname' => 'Pérez', 'email' => 'luis@example.com',
            'phone' => null]);
        households::add_child($household, 'Inés', $offer);
        $found = api::chat_candidates((int) $team->id, 'ines garcia', 10);
        $this->assertCount(1, $found);
        $this->assertSame('+34600111222', $found[0]['phone']);
        $this->assertSame('Marta García López', $found[0]['name']);
        $this->assertStringContainsString('Inés', $found[0]['line']);
        $this->assertCount(1, api::chat_candidates((int) $team->id, 'GARC', 10));
        $this->assertSame([], api::chat_candidates((int) $team->id, 'pedro', 10));
        // Luis has no phone: the family is found by his name, and only who can be called comes.
        $this->assertSame(['+34600111222'], array_column(api::chat_candidates((int) $team->id, 'luis perez', 10), 'phone'));
        // Another person, not of the centre, finds nothing.
        $this->assertSame([], api::chat_candidates((int) $other->id, 'ines', 10));
    }

    /**
     * The chat knows the phone of a family that asks for a place; not that of one enrolled, of the roll or of nobody.
     */
    public function test_chat_knows(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        centres::add_member($cid, (int) $USER->id);
        $offer = centres::save_offer($cid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 0, 'active' => 1]);
        $family = function (string $phone, string $stage, int $roster = 0) use ($DB, $cid, $offer): int {
            $household = households::get(households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $cid);
            households::add_adult($household, (object) ['firstname' => 'Ana', 'phone' => $phone]);
            $child = households::add_child($household, 'Leo', $offer);
            households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), $stage);
            $DB->set_field(households::T, 'roster', $roster, ['id' => $household->id]);
            $DB->set_field(households::T, 'incentre', $stage === 'enrolled' ? 1 : 0, ['id' => $household->id]);
            return (int) $household->id;
        };
        $asking = $family('+34600111401', 'inquiry');
        $family('+34600111402', 'enrolled');
        $family('+34600111403', 'inquiry', 1);
        $user = (int) $USER->id;
        $this->assertTrue(api::chat_knows('34600111401', $user));
        $this->assertTrue(api::chat_knows('+34 600 111 401', $user));
        $this->assertFalse(api::chat_knows('+34600111402', $user));
        $this->assertFalse(api::chat_knows('+34600111403', $user));
        $this->assertFalse(api::chat_knows('+34600111499', $user));
        $this->assertFalse(api::chat_knows('', $user));
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertFalse(api::chat_knows('+34600111401', (int) $nobody->id), 'Only of their own centres.');
        $mode = \local_oksigeniaeguahe\local\messaging::mode(centres::get($cid));
        $this->assertSame($mode === 'off' ? [] : [$asking], api::phone_households('34600111401'));
    }
}
