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
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_renewal;
use local_oksigeniaeguahe\local\renewal;
use local_oksigeniaeguahe\local\roster;

/**
 * The families already at the centre, from its roll (0.61.0): where the course of each student comes from, what each
 * cohort is, bringing them out of the numbers of the campaign and out of the inbox, and never again once they erased
 * their data. With example data and a roll made here, as the common family record gives it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(roster::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_renewal::class)]
final class roster_test extends \advanced_testcase {
    /**
     * Forgets the roll made for the tests.
     */
    protected function tearDown(): void {
        roster::$testfamilies = null;
        parent::tearDown();
    }

    /**
     * A centre with 3.º and 4.º de Primaria in 2026-27 and 4.º in 2027-28; two students with an account, Lucía in the
     * cohorts «3.º B» and «Comedor» and Hugo in «4.º A», and one without an account; each in their family of the roll.
     *
     * @return array [centre, the user of Lucía]
     */
    protected function centre(): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        foreach ([['2026-27', '3.º de Primaria'], ['2026-27', '4.º de Primaria'], ['2027-28', '4.º de Primaria']] as [$y, $n]) {
            centres::save_offer($id, (object) ['schoolyear' => $y, 'name' => $n, 'places' => 25, 'active' => 1, 'sortorder' => 0]);
        }
        $gen = $this->getDataGenerator();
        $lucia = $gen->create_user(['firstname' => 'Lucía', 'lastname' => 'Ejemplo']);
        $hugo = $gen->create_user(['firstname' => 'Hugo', 'lastname' => 'Prueba']);
        foreach (['3.º B' => [$lucia], 'Comedor' => [$lucia], '4.º A' => [$hugo]] as $name => $users) {
            $cohort = $gen->create_cohort(['name' => $name]);
            foreach ($users as $user) {
                cohort_add_member($cohort->id, $user->id);
            }
        }
        $adult = fn(int $id, string $first, string $email) => (object) ['adultid' => $id, 'userid' => null,
            'firstname' => $first, 'lastname' => 'Ejemplo', 'email' => $email, 'phone' => '+34600111222',
            'phoneverified' => true, 'status' => 'active'];
        $child = fn(int $id, ?int $userid, string $first, ?string $group = null) => (object) ['childid' => $id,
            'userid' => $userid, 'sourcekey' => 'csv:' . $id, 'firstname' => $first !== '' ? $first : null, 'lastname' => null,
            'group' => $group, 'status' => 'active', 'timeleft' => null];
        roster::$testfamilies = [
            101 => (object) ['familyid' => 101, 'status' => 'active', 'source' => 'csv', 'sourcekey' => 'csv:f1',
                'timeclosed' => null, 'children' => [$child(11, (int) $lucia->id, 'Lucía'), $child(12, null, '')],
                'adults' => [$adult(21, 'Pedro', 'pedro@example.com')]],
            102 => (object) ['familyid' => 102, 'status' => 'active', 'source' => 'csv', 'sourcekey' => 'csv:f2',
                'timeclosed' => null, 'children' => [$child(13, (int) $hugo->id, 'Hugo')],
                'adults' => [$adult(22, 'Ana', 'ana@example.com')]],
            // Without an account: the record has his name and group from the data of the centre.
            103 => (object) ['familyid' => 103, 'status' => 'active', 'source' => 'csv', 'sourcekey' => 'csv:f3',
                'timeclosed' => null, 'children' => [$child(14, null, 'Mateo', 'INF 3ºA')],
                'adults' => [$adult(23, 'Carmen', 'carmen@example.com')]],
        ];
        return [centres::get($id), $lucia];
    }

    /**
     * Where the courses come from and what each cohort most likely is; only what a person said places a student.
     */
    public function test_source_and_maps(): void {
        [$centre] = $this->centre();
        $this->assertNull(roster::source($centre));
        roster::set_source($centre, 'cohort', '', '2026-27');
        $this->assertSame(['cohort', ''], roster::source($centre));
        $overview = roster::overview($centre);
        $this->assertSame(3, $overview['students'], 'Lucía and Hugo, and Mateo without an account.');
        $this->assertSame(1, $overview['nameless']);
        $this->assertSame(0, $overview['placed'], 'Nothing said yet.');
        $guesses = array_column($overview['values'], 'guess', 'value');
        $this->assertSame(
            ['3.º B' => '3.º de Primaria', '4.º A' => '4.º de Primaria', 'Comedor' => '', 'INF 3ºA' => ''],
            $guesses
        );

        roster::set_map($centre, '3.º B', '3.º de Primaria');
        roster::set_map($centre, 'Comedor', '');
        roster::set_map($centre, '4.º A', '4.º A');
        $this->assertArrayNotHasKey('4.º A', roster::maps($centre), 'Not a course of the centre.');
        $this->assertSame(1, roster::overview($centre)['placed']);
        roster::set_source($centre, 'profile', 'curso', '2026-27');
        $this->assertSame(['profile', 'curso'], roster::source($centre));
        roster::set_source($centre, '', '', '');
        $this->assertNull(roster::source($centre));
    }

    /**
     * Bringing them: a household already at the centre, its adults with what the centre may do, its students enrolled
     * in their course; once, out of the inbox unless they wait, with their own greeting; and asked whether they go on.
     */
    public function test_bring(): void {
        global $DB;
        [$centre] = $this->centre();
        roster::set_source($centre, 'cohort', '', '2026-27');
        roster::set_map($centre, '3.º B', '3.º de Primaria');
        $done = roster::sync($centre, time());
        $this->assertSame(['families' => 1, 'children' => 1, 'moved' => 0], $done, 'Hugo has no course said yet.');
        $household = households::get((int) $DB->get_field(roster::T, 'householdid', ['familyid' => 101]), (int) $centre->id);
        $this->assertNotNull($household);
        $this->assertSame([1, 101], [(int) $household->incentre, (int) $household->familylink]);
        $this->assertGreaterThan(0, (int) $household->roster);
        $children = array_values(households::full($household)->children);
        $this->assertSame(['Lucía', 'enrolled', 11], [$children[0]->firstname, $children[0]->stage,
            (int) $children[0]->familychild]);
        $this->assertTrue(familymail::may_email($household));
        $this->assertSame(['families' => 0, 'children' => 0, 'moved' => 0], roster::sync($centre, time()), 'Once.');
        $this->assertSame(1, roster::brought($centre)['families']);

        // Out of the inbox, unless they wait for someone.
        $this->assertArrayNotHasKey((int) $household->id, households::inbox((int) $centre->id, false));
        $this->assertArrayHasKey((int) $household->id, households::inbox((int) $centre->id, false, 0, true));
        $DB->set_field(households::T, 'waitingsince', time(), ['id' => $household->id]);
        $this->assertArrayHasKey((int) $household->id, households::inbox((int) $centre->id, false));

        // Their page: no «All set», which is for those who just enrolled.
        $page = familypage::context($household, $centre, 'es', households::new_link($household, 'team'), false);
        $this->assertFalse($page['settled']);
        $this->assertSame('Hola,', $page['greeting']);

        // And the centre can ask them whether Lucía goes on.
        renewal::set($centre, '2027-28', time() - 10, 0);
        $this->assertArrayHasKey((int) $children[0]->id, renewal::children($centre));
    }

    /**
     * A family that erased its data is never brought back; one Eguahe already has gets its student, not a new household.
     */
    public function test_erased_and_linked(): void {
        global $DB;
        [$centre] = $this->centre();
        roster::set_source($centre, 'cohort', '', '2026-27');
        roster::set_map($centre, '3.º B', '3.º de Primaria');
        roster::set_map($centre, '4.º A', '4.º de Primaria');
        // Ana's family came through Eguahe and a person said it is that family of the centre.
        $ana = households::get(
            households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']),
            (int) $centre->id
        );
        $DB->set_field(households::T, 'familylink', 102, ['id' => $ana->id]);
        $this->assertSame(1, roster::sync($centre, time())['families'], 'Only Pedro\'s.');
        $this->assertSame((int) $ana->id, (int) $DB->get_field(households::CHILDREN, 'householdid', ['familychild' => 13]));

        $pedro = households::get((int) $DB->get_field(roster::T, 'householdid', ['familyid' => 101]), (int) $centre->id);
        households::erase($pedro);
        $this->assertSame(0, (int) $DB->get_field(roster::T, 'householdid', ['familyid' => 101]));
        $this->assertSame(0, roster::sync($centre, time())['families'], 'Never brought back.');
        $this->assertFalse($DB->record_exists(households::CHILDREN, ['familychild' => 11]));
    }

    /**
     * The rack of «Who goes on»: each cohort hung from the hook of its course, the one Eguahe proposes marked, those that
     * are not courses in their drawer, and the one lifted to be hung.
     */
    public function test_rack(): void {
        [$centre] = $this->centre();
        roster::set_source($centre, 'cohort', '', '2026-27');
        roster::set_map($centre, '3.º B', '3.º de Primaria');
        roster::set_map($centre, 'Comedor', '');
        // Set up in the settings of the centre; «Who goes on» only says how it is and links there.
        $this->assertFalse(panel_renewal::context($centre, true, time())['roster']);
        $this->assertStringContainsString('centre.php', panel_renewal::context($centre, true, time())['rosterlink']['url']);
        $page = panel_renewal::settings_roster($centre, dechex(crc32('4.º A')));
        $this->assertStringContainsString('volver=ajustes', $page['action']);
        $hooks = [];
        foreach ($page['rack'] as $stage) {
            foreach ($stage['grades'] as $grade) {
                $hooks[$grade['value']] = array_map(fn($t) => [$t['value'], $t['guess']], $grade['tags']);
            }
        }
        $this->assertSame(['3.º de Primaria' => [['3.º B', false]], '4.º de Primaria' => [['4.º A', true]]], $hooks);
        $this->assertSame(['Comedor'], array_column($page['notcourses'], 'value'));
        $this->assertSame('4.º A', $page['picked']['value']);
        $this->assertSame(['INF 3ºA'], array_column($page['loose'], 'value'), 'No course for it, nor a guess.');
    }

    /**
     * A student without an account comes with the name and the group the record has from the data of the centre, once
     * the centre says what that group is; and a centre can take only those groups.
     */
    public function test_without_account(): void {
        global $DB;
        [$centre] = $this->centre();
        centres::save_offer((int) $centre->id, (object) ['schoolyear' => '2026-27', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1, 'sortorder' => 0]);
        roster::set_source($centre, 'family', '', '2026-27');
        $this->assertSame(['family', ''], roster::source($centre));
        $values = roster::overview($centre)['values'];
        $this->assertSame(['INF 3ºA'], array_column($values, 'value'), 'Only the groups.');
        // The value «INF 3ºA»: by its words, Infantil 3 años; by its age, the third year of Infantil (five). Two readings,
        // no guess.
        $this->assertSame('', $values[0]['guess'], 'A person hangs it.');
        roster::set_map($centre, 'INF 3ºA', 'Infantil 3 años');
        $this->assertSame(1, roster::sync($centre, time())['families']);
        $child = $DB->get_record(households::CHILDREN, ['familychild' => 14]);
        $this->assertSame(['Mateo', 'enrolled'], [$child->firstname, $child->stage]);
    }
}
