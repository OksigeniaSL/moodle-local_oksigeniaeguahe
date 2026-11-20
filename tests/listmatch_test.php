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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\pseudonym;

/**
 * What the list of the centre says of the families of Eguahe: a child enrolled, a family of the centre, or a question.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\listmatch
 */
#[\PHPUnit\Framework\Attributes\CoversClass(listmatch::class)]
final class listmatch_test extends \advanced_testcase {
    /**
     * A new student of the year enrols the child that asked for its course; an older one makes a family of the centre;
     * two children asking for the same course make a question; nothing is done twice.
     */
    public function test_detect(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $infantil = centres::save_offer($cid, (object) ['schoolyear' => '2026-27', 'name' => 'Infantil 3 años', 'places' => 0,
            'active' => 1]);
        $family = function (string $email, array $children) use ($cid, $infantil): \stdClass {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $household = households::get($id, $cid);
            households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => $email,
                'phone' => null]);
            foreach ($children as $name) {
                households::add_child($household, $name, $infantil);
            }
            return households::get($id, $cid);
        };
        $one = $family('ana@example.com', ['Lucía']);
        $two = $family('rosa@example.com', ['Pablo']);
        $three = $family('eva@example.com', ['Leo', 'Mía']);
        $keys = new pseudonym($cid);
        $import = (int) $DB->insert_record('local_oksigeniaeguahe_import', (object) ['centreid' => $cid, 'kind' => 'joins',
            'label' => 'Cifra 2026-27', 'rowcount' => 3, 'userid' => 2, 'campaignstart' => 0, 'timecreated' => time()]);
        $row = function (string $email, string $student, string $movement, string $grade) use ($DB, $cid, $import, $keys) {
            $DB->insert_record('local_oksigeniaeguahe_student', (object) ['centreid' => $cid, 'importid' => $import,
                'student' => $student, 'household' => $keys->token('household', [pseudonym::email($email)]),
                'schoolyear' => '2026-27', 'grade' => $grade, 'timein' => strtotime('2026-09-07'), 'timeout' => 0,
                'movement' => $movement, 'birthyear' => 0]);
        };
        $row('ana@example.com', 'ST_one000001', 'join', 'Infantil 3 años');
        $row('rosa@example.com', 'ST_two000001', 'roll', '3.º de ESO');
        $row('eva@example.com', 'ST_three0001', 'join', 'Infantil 3 años');

        $this->assertSame([1, 1, 1], listmatch::detect(centres::get($cid), time()));
        $lucia = $DB->get_record(households::CHILDREN, ['householdid' => $one->id]);
        $this->assertSame('enrolled', $lucia->stage);
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $two->id]));
        $this->assertNotSame(
            'enrolled',
            $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $two->id]),
            'A brother at the centre does not enrol the one asking.'
        );
        // Nothing twice.
        $this->assertSame([0, 0, 0], listmatch::detect(centres::get($cid), time()));

        // The question: a person says which.
        $full = households::full(households::get((int) $three->id, $cid));
        $ctx = listmatch::context($three, $full, true);
        $this->assertCount(1, $ctx['asks']);
        $this->assertCount(2, $ctx['asks'][0]['children']);
        listmatch::choose($three, $ctx['asks'][0]['id'], $ctx['asks'][0]['children'][1]['id']);
        $this->assertSame(['open', 'enrolled'], array_values(array_map(
            fn($k) => $k->stage === 'enrolled' ? 'enrolled' : 'open',
            $DB->get_records(households::CHILDREN, ['householdid' => $three->id], 'id ASC')
        )));
        $this->assertFalse(listmatch::context($three, households::full(households::get((int) $three->id, $cid)), true)['hasasks']);
    }
}
