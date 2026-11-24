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
use local_oksigeniaeguahe\local\happened;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\questions;

/**
 * Tests for what each line of the history of a family was: what answers it and what does not.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\happened
 */
#[\PHPUnit\Framework\Attributes\CoversClass(happened::class)]
final class happened_test extends \advanced_testcase {
    /**
     * An invitation sent to many, or a change told to the family, answers nothing: not in its history, not in the
     * figures, and what it asked is still to be answered.
     */
    public function test_what_answers(): void {
        global $DB;
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $user = $this->getDataGenerator()->create_user();
        $id = households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, $centreid);
        households::received($household, 'webform', '¿Hay comedor?', true, time() - HOURSECS);
        $household = households::get($id, $centreid);
        households::told($household, (int) $user->id, 'email', 'Invitación a la charla', true);
        households::told($household, (int) $user->id, 'email', 'La visita cambia de hora');
        $lines = array_values($DB->get_records(households::CONTACTS, ['householdid' => $id], 'timecreated ASC, id ASC'));
        $this->assertCount(3, $lines);
        $this->assertSame(['wrote', 'invited', 'told'], array_map(fn($l) => happened::kind($l), $lines));
        $this->assertSame([], happened::answers($lines));
        $this->assertSame(0, metrics::response($centreid)['answered']);
        $this->assertNotEmpty(questions::asked($lines));
        $this->assertGreaterThan(0, (int) households::get($id, $centreid)->waitingsince);

        households::answered(households::get($id, $centreid), (int) $user->id, 'email', 'Sí, hay comedor.');
        $this->assertSame(1, metrics::response($centreid)['answered']);
        $this->assertSame(0, (int) households::get($id, $centreid)->waitingsince);
    }
}
