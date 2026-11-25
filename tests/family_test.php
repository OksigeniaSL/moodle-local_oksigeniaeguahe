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

use local_oksigeniaeguahe\local\atycards;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\family;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\lookalike;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\roster;
use local_oksigeniaeguahe\local\settled;

/**
 * The common family record of the centre (0.43.0), when the site has local_oksigeniafamily: the hint that a family is
 * already one of the centre, confirmed by a person; the household passing to it when a child enrols, a brother or
 * sister to the same family; leaving it and coming back; and «They are gone» proposed when the centre takes a child
 * out of it. Without the record, nothing. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(family::class)]
final class family_test extends \advanced_testcase {
    /**
     * Only where the site has the common record.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_oksigeniafamily\api') || !method_exists('\local_oksigeniafamily\api', 'family_by_key')) {
            $this->markTestSkipped('The site has no common family record (local_oksigeniafamily 0.3.9 or later).');
        }
    }

    /**
     * Back to what the site says.
     */
    protected function tearDown(): void {
        family::$testavailable = null;
        parent::tearDown();
    }

    /**
     * A centre, an offer and a household with one adult and the children asked for.
     *
     * @param array $kids Their names.
     * @param string $email
     * @return array [centre, household, offer]
     */
    protected function household(array $kids, string $email = 'ana@example.com'): array {
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'sortorder' => 0, 'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo', 'email' => $email,
            'phone' => '+34600000001']);
        foreach ($kids as $kid) {
            households::add_child($household, $kid, $offer);
        }
        return [centres::get($id), households::get((int) $household->id, $id), $offer];
    }

    /**
     * Enrolled, the household passes to the record with its adult and the child; a brother or sister goes to the same
     * family; leaving takes them out, coming back puts them in again.
     */
    public function test_enrol_and_leave(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $household] = $this->household(['Leo', 'Sara']);
        $kids = array_values(households::full($household)->children);
        households::set_stage($kids[0], 'enrolled');
        $familyid = \local_oksigeniafamily\api::family_by_key(family::SOURCE, family::key('h', (int) $household->id));
        $this->assertNotNull($familyid);
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_adult', ['familyid' => $familyid]));
        $adult = $DB->get_record('local_oksigeniafamily_adult', ['familyid' => $familyid]);
        $this->assertSame('ana@example.com', $adult->email);
        $this->assertSame('0', (string) $adult->phoneverified, 'A phone written in a form identifies nobody.');
        $leo = $DB->get_record(households::CHILDREN, ['id' => $kids[0]->id]);
        $this->assertNotEmpty($leo->familychild);
        $key = $DB->get_field('local_oksigeniafamily_child', 'sourcekey', ['id' => $leo->familychild]);
        $this->assertSame(family::key('c', (int) $leo->id), $key);

        households::set_stage($kids[1], 'enrolled');
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid, 'status' => 'active']));
        $made = $DB->count_records('local_oksigeniafamily_family', ['sourcekey' => family::key('h', (int) $household->id)]);
        $this->assertSame(1, $made, 'One family for the household.');

        settled::leave($household, 2, 'moved', '', time());
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid, 'status' => 'active']));
        $leo = $DB->get_record(households::CHILDREN, ['id' => $kids[0]->id]);
        $this->assertSame('0', (string) $leo->familygone, 'Eguahe itself took them out: nothing to propose.');
        settled::stay($household, time());
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid, 'status' => 'active']));
    }

    /**
     * A family already in the record (by its email) is a hint, never shown to the family; a person says it is the
     * same, and its child goes to that family. And when the centre takes a child out of the record, «They are gone» is
     * proposed in the file.
     */
    public function test_hint_and_gone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $api = '\local_oksigeniafamily\api';
        $theirs = $api::create_family('manual');
        $api::add_adult($theirs, ['firstname' => 'Ana', 'lastname' => 'Ejemplo', 'email' => 'ANA@example.com']);
        [$centre, $household] = $this->household(['Leo']);
        $full = households::full($household);
        $hints = family::hints($full);
        $this->assertSame([$theirs => ['Ana Ejemplo']], $hints);
        $file = panel_household::context($household, $centre, true, '', time());
        $this->assertNotFalse($file['family']);
        $this->assertCount(1, $file['family']['hints']);

        family::confirm($household, $theirs);
        $household = households::get((int) $household->id, (int) $centre->id);
        $file = panel_household::context($household, $centre, true, '', time());
        $this->assertSame([], $file['family']['hints'], 'Said once.');
        $this->assertNotSame('', $file['family']['linked']);
        $kid = array_values($full->children)[0];
        households::set_stage($kid, 'enrolled');
        $kid = $DB->get_record(households::CHILDREN, ['id' => $kid->id]);
        $into = $DB->get_field('local_oksigeniafamily_child', 'familyid', ['id' => $kid->familychild]);
        $this->assertSame((string) $theirs, (string) $into, 'Into the family a person confirmed.');

        // The centre takes the child out of the record: proposed, for a person to confirm.
        $api::unlink_child((int) $kid->familychild, 'import');
        $kid = $DB->get_record(households::CHILDREN, ['id' => $kid->id]);
        $this->assertNotEmpty($kid->familygone);
        $this->assertCount(1, family::gone([$kid]));
        family::still_here($household);
        $this->assertCount(0, family::gone([$DB->get_record(households::CHILDREN, ['id' => $kid->id])]));
    }

    /**
     * Where the management system of the centre loads the families: enrolling makes nothing in the record, the file
     * says it waits; once the system loads the family, the file proposes joining them, the roll never brings it as a
     * second household, and joining finds its child there by name.
     */
    public function test_loaded_by_the_management_system(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $api = '\local_oksigeniafamily\api';
        [$centre, $household] = $this->household(['Leo']);
        centres::update((int) $centre->id, (object) ['familyfrom' => 'records']);
        $centre = centres::get((int) $centre->id);
        $kid = array_values(households::full($household)->children)[0];
        households::set_stage($kid, 'enrolled');
        $this->assertNull($api::family_by_key(family::SOURCE, family::key('h', (int) $household->id)), 'Eguahe makes none.');
        $this->assertSame('0', (string) $DB->get_field(households::CHILDREN, 'familychild', ['id' => $kid->id]));
        $file = panel_household::context($household, $centre, true, '', time());
        $this->assertNotSame('', $file['family']['waiting']);

        // The management system loads it, with the child by its key and its name.
        $theirs = $api::create_family('cifra', 'cifra:77');
        $api::add_adult($theirs, ['firstname' => 'Ana', 'lastname' => 'Ejemplo', 'email' => 'ana@example.com']);
        $childid = $api::add_child($theirs, null, 'cifra:123');
        $api::set_child_details('cifra:123', 'Leo', 'Ejemplo', 'INF 3ºA');
        $this->assertSame([$theirs => ['Ana Ejemplo']], family::hints(households::full($household)));
        $this->assertTrue(roster::awaiting($centre, roster::families()[$theirs]), 'Never brought as a second household.');

        family::confirm($household, $theirs);
        $this->assertSame((string) $childid, (string) $DB->get_field(households::CHILDREN, 'familychild', ['id' => $kid->id]));
        $this->assertFalse(roster::awaiting($centre, roster::families()[$theirs]));
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_child', ['status' => 'active']), 'Nobody twice.');
    }

    /**
     * Without the record, nothing happens and nothing is shown.
     */
    public function test_without_record(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        family::$testavailable = false;
        [$centre, $household] = $this->household(['Leo']);
        households::set_stage(array_values(households::full($household)->children)[0], 'enrolled');
        $this->assertNull(\local_oksigeniafamily\api::family_by_key(family::SOURCE, family::key('h', (int) $household->id)));
        $this->assertFalse(panel_household::context($household, $centre, true, '', time())['family']);
        $this->assertSame(0, $DB->count_records_select(households::CHILDREN, 'familychild > 0'));
    }

    /**
     * Separated, whoever goes no longer sees in the app the family that stays, and its child goes to its own; put together, the
     * file that stays is of the family that was made for the other.
     */
    public function test_separated_and_together(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $api = family::API;
        [$centre, $household] = $this->household(['Leo', 'Sara']);
        $luis = households::add_adult($household, (object) ['firstname' => 'Luis', 'email' => 'luis@example.com']);
        $kids = array_values(households::full($household)->children);
        households::set_stage($kids[0], 'enrolled');
        households::set_stage($kids[1], 'enrolled');
        $familyid = $api::family_by_key(family::SOURCE, family::key('h', (int) $household->id));
        $active = fn(string $table, int $id) => $DB->count_records('local_oksigeniafamily_' . $table, ['familyid' => $id, 'status' => 'active']);
        $this->assertSame([2, 2], [$active('adult', $familyid), $active('child', $familyid)]);
        $new = \local_oksigeniaeguahe\local\lookalike::split(
            households::get((int) $household->id, (int) $centre->id),
            ['adults' => [$luis], 'children' => [(int) $kids[1]->id => 'go']],
            2
        );
        $this->assertSame([1, 1], [$active('adult', $familyid), $active('child', $familyid)]);
        $theirs = $api::family_by_key(family::SOURCE, family::key('h', (int) $new->id));
        $this->assertNotNull($theirs);
        $this->assertSame([1, 1], [$active('adult', $theirs), $active('child', $theirs)]);
        // Two files: the older one, of nobody in the record yet, and the one Eguahe made a family for.
        $older = households::get(households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), (int) $centre->id);
        $DB->set_field(households::T, 'timecreated', 5, ['id' => $older->id]);
        $older = households::get((int) $older->id, 0);
        $kept = \local_oksigeniaeguahe\local\lookalike::merge($older, households::get((int) $new->id, 0), 2);
        $this->assertSame([(int) $older->id, $theirs], [(int) $kept->id, (int) $kept->familylink]);
        // A «no» of one of them is not over the family a person confirmed for the other.
        $one = households::get(households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), 0);
        $two = households::get(households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), 0);
        $DB->set_field(households::T, 'familylink', -1, ['id' => $one->id]);
        $DB->set_field(households::T, 'familylink', $familyid, ['id' => $two->id]);
        $this->assertSame($familyid, (int) \local_oksigeniaeguahe\local\lookalike::merge($one, $two, 2)->familylink);
    }

    /**
     * Two files of the same family put together, the one that goes being the one in the record: it stays its family there, a
     * brother goes to it, whoever sees it in the app goes on seeing it, and leaving takes the child out.
     */
    public function test_put_together(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $api = '\local_oksigeniafamily\api';
        [$centre, $keep, $offer] = $this->household(['Teo']);
        $gone = households::get(households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), 0);
        households::add_adult($gone, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo', 'email' => 'ana@example.com',
            'phone' => '+34600000001']);
        households::add_child($gone, 'Teo', $offer);
        $DB->set_field(households::T, 'timecreated', time() - DAYSECS, ['id' => $keep->id]);
        $keep = households::get((int) $keep->id, 0);
        // Nothing hints: the family is not in the record before.
        households::set_stage(array_values(households::full($gone)->children)[0], 'enrolled');
        $familyid = $api::family_by_key(family::SOURCE, family::key('h', (int) $gone->id));
        $kept = lookalike::merge($keep, $gone, 2);
        $this->assertSame((int) $keep->id, (int) $kept->id);
        $this->assertSame((int) $familyid, (int) $kept->familylink);
        $api::set_adult_user(
            (int) $DB->get_field('local_oksigeniafamily_adult', 'id', ['familyid' => $familyid]),
            (int) $this->getDataGenerator()->create_user()->id
        );
        $userid = (int) $DB->get_field('local_oksigeniafamily_adult', 'userid', ['familyid' => $familyid]);
        $this->assertArrayHasKey((int) $kept->id, atycards::households_of($userid));
        // A brother, to the same family.
        $kid = households::add_child($kept, 'Noa', $offer);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $kid]), 'enrolled');
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid, 'status' => 'active']));
        settled::leave(households::get((int) $kept->id, 0), 2, 'moved', '', time());
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid, 'status' => 'active']));
        settled::stay(households::get((int) $kept->id, 0), time());
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid, 'status' => 'active']));
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid]), 'Nobody twice.');

        // One said «it is not that family», the other that it is.
        $this->assertSame(7, family::merged((object) ['id' => 0, 'centreid' => $centre->id, 'familylink' => -1], (object) [
            'id' => 0, 'centreid' => $centre->id, 'familylink' => 7]));
        $this->assertSame(-1, family::merged((object) ['id' => 0, 'centreid' => $centre->id, 'familylink' => 0], (object) [
            'id' => 0, 'centreid' => $centre->id, 'familylink' => -1]));
    }

    /**
     * A child enrolled goes to the record with its name and its course, as they are later; and never to a family that closed.
     */
    public function test_name_and_closed_family(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $api = '\local_oksigeniafamily\api';
        [$centre, $household] = $this->household(['Leo']);
        $kid = array_values(households::full($household)->children)[0];
        households::set_stage($kid, 'enrolled');
        $id = (int) $DB->get_field(households::CHILDREN, 'familychild', ['id' => $kid->id]);
        $named = fn() => $DB->get_record('local_oksigeniafamily_child', ['id' => $id]);
        $this->assertSame(['Leo', 'Infantil 3 años'], [$named()->firstname, $named()->groupname]);
        $DB->set_field(households::CHILDREN, 'firstname', 'Leonardo', ['id' => $kid->id]);
        $this->assertSame(1, family::names((int) $centre->id));
        $this->assertSame('Leonardo', $named()->firstname);
        $this->assertSame(0, family::names((int) $centre->id));

        [$centre, $household] = $this->household(['Noa'], 'iris@example.com');
        $closed = $api::create_family('manual');
        $api::add_adult($closed, ['firstname' => 'Iris', 'email' => 'iris@example.com']);
        family::confirm($household, $closed);
        $api::close_family($closed);
        households::set_stage(array_values(households::full($household)->children)[0], 'enrolled');
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $closed]));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'familylink', ['id' => $household->id]));
    }

    /**
     * A child of the management system said to have gone: what the record does is only known, and when the record has it
     * again, it is back.
     */
    public function test_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $api = '\local_oksigeniafamily\api';
        [$centre, $household] = $this->household(['Leo']);
        centres::update((int) $centre->id, (object) ['familyfrom' => 'records']);
        $theirs = $api::create_family('cifra', 'cifra:77');
        $api::add_adult($theirs, ['firstname' => 'Ana', 'lastname' => 'Ejemplo', 'email' => 'ana@example.com']);
        $childid = $api::add_child($theirs, null, 'cifra:123');
        $api::set_child_details('cifra:123', 'Leo', 'Ejemplo', 'INF 3ºA');
        $kid = array_values(households::full($household)->children)[0];
        households::set_stage($kid, 'enrolled');
        family::confirm($household, $theirs);
        settled::leave(households::get((int) $household->id, 0), 2, 'moved', '', time());
        $left = fn() => (int) $DB->get_field(households::CHILDREN, 'lefttime', ['id' => $kid->id]);
        $this->assertGreaterThan(0, $left());
        $api::unlink_child($childid, 'import');
        $this->assertGreaterThan(0, $left());
        $api::add_child($theirs, null, 'cifra:123');
        $this->assertSame(0, $left(), 'It is with us again.');
        $this->assertSame('0', (string) $DB->get_field(households::CHILDREN, 'familygone', ['id' => $kid->id]));
    }
}
