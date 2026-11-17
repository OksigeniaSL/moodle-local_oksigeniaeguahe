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
}
