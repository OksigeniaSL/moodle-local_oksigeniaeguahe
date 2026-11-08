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
use local_oksigeniaeguahe\local\messaging;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_inbox;

/**
 * The panel of the team: the time in words, the rail of the places, the names of the adults, and what the inbox and the
 * file of a household show. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(panel::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_inbox::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_household::class)]
final class panel_test extends \advanced_testcase {
    /**
     * Who looks, in a time zone of their own.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('timezone', 'Europe/Madrid');
        set_config('forcetimezone', 'Europe/Madrid');
    }

    /**
     * How long someone has waited, in words, from minutes to weeks.
     */
    public function test_wait_words(): void {
        $now = make_timestamp(2026, 11, 8, 12, 0);
        $words = panel::wait_words($now - 40 * MINSECS, $now);
        $this->assertSame(
            ['40 minutes ago', 'today, ' . panel::hour($now - 40 * MINSECS), 'for 40 minutes'],
            [$words['main'], $words['sub'], $words['since']]
        );
        $this->assertSame('3 hours ago', panel::wait_words($now - 3 * HOURSECS, $now)['main']);
        $yesterday = make_timestamp(2026, 11, 7, 19, 5);
        $this->assertSame('Since yesterday', panel::wait_words($yesterday, $now)['main']);
        $this->assertSame('since yesterday at ' . panel::hour($yesterday), panel::wait_words($yesterday, $now)['since']);
        $thursday = make_timestamp(2026, 11, 5, 18, 40);
        $words = panel::wait_words($thursday, $now);
        $this->assertSame('Since ' . userdate($thursday, '%A'), $words['main']);
        $this->assertSame('3 days ago', $words['sub']);
        $longago = make_timestamp(2026, 10, 26, 10, 0);
        $this->assertSame(
            'Since ' . userdate($longago, get_string('strftimedateshort', 'langconfig')),
            panel::wait_words($longago, $now)['main']
        );
        // When something happened, in words too.
        $this->assertSame('yesterday at ' . panel::hour($yesterday), panel::when_words($yesterday, $now));
        $this->assertSame('on ' . userdate($thursday, '%A') . ' at ' . panel::hour($thursday), panel::when_words($thursday, $now));
        $this->assertStringStartsNotWith('0', panel::hour(make_timestamp(2026, 11, 8, 8, 15)));
    }

    /**
     * The places of a course: a knob per place and a backpack on each one taken; with many, a band.
     */
    public function test_rail(): void {
        $svg = panel::rail(25, 6);
        $this->assertSame(25, substr_count($svg, 'class="pomo"'));
        $this->assertSame(12, substr_count($svg, 'class="ocupada"'));
        $this->assertStringContainsString('aria-label="6 of 25 places taken"', $svg);
        $many = panel::rail(120, 30);
        $this->assertStringContainsString('class="franja"', $many);
        $this->assertSame(13, substr_count($many, 'class="pomo"'));
        $this->assertSame(8, substr_count(panel::rail(4, 9), 'class="ocupada"'));
    }

    /**
     * The adults of a household in a line: the first surname, with its particles.
     */
    public function test_adults_line(): void {
        $adults = [
            (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz Cabrera'],
            (object) ['firstname' => 'Mohamed', 'lastname' => 'El Amrani Haddou'],
            (object) ['firstname' => 'Ana', 'lastname' => 'de la Torre Gil'],
        ];
        $this->assertSame('Marta Ruiz, Mohamed El Amrani and Ana de la Torre', panel::adults_line($adults));
        $this->assertSame(get_string('household_noname', 'local_oksigeniaeguahe'), panel::adults_line([]));
    }

    /**
     * The inbox and the file of a family that just wrote: it waits, with what it said, and the way to answer it.
     */
    public function test_inbox_and_file(): void {
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($centreid);
        centres::save_offer($centreid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1, 'sortorder' => 0]);
        $entry = entries::get(entries::create($centreid, 'hook', 'Web a medida'), $centreid);
        [$household] = entries::receive($entry, ['Nombre y apellidos del padre, madre o tutor' => 'Marta Ruiz Cabrera',
            'Correo electrónico' => 'marta@example.com', 'Nombre del alumno' => 'Leonardo',
            'Curso que solicita' => 'Infantil 3 años', 'Mensaje' => '¿Tienen comedor?'], 'x1');

        $inbox = panel_inbox::context($centre, false, (int) get_admin()->id, true);
        $this->assertSame(get_string('inbox_waiting_one', 'local_oksigeniaeguahe'), $inbox['head']['title']);
        $this->assertCount(1, $inbox['rows']);
        $row = $inbox['rows'][0];
        $this->assertTrue($row['waiting']);
        $this->assertSame('Marta Ruiz', $row['adults']);
        $this->assertStringContainsString('marta@example.com', $row['search']);
        $this->assertSame('Infantil 3 años', $row['children'][0]['course']);
        $this->assertSame('', $row['children'][0]['stage']);
        $this->assertSame(1, $inbox['places'][0]['interested']);
        $this->assertSame(25, $inbox['places'][0]['free']);

        $file = panel_household::context($household, $centre, true);
        $this->assertSame('Marta Ruiz', $file['head']['title']);
        $this->assertTrue($file['history'][0]['family']);
        $this->assertStringContainsString('Web a medida', $file['history'][0]['how']);
        // By email, chosen first, or by a call; the phone app only with Oksigenia Marandu, and not joined yet.
        $ways = array_column($file['composer']['ways'], 'checked', 'value');
        $this->assertSame((messaging::available() ? ['mobile' => false] : []) + ['email' => true, 'phone' => false], $ways);
        $steps = $file['children'][0]['steps'];
        $this->assertCount(5, $steps);
        $this->assertTrue($steps[0]['here']);

        // Answered: it no longer waits, and the inbox says who answered last.
        households::answered($household, (int) get_admin()->id, 'phone', 'Les llamamos.');
        $inbox = panel_inbox::context($centre, false, (int) get_admin()->id, true);
        $this->assertSame(get_string('inbox_empty_waiting', 'local_oksigeniaeguahe'), $inbox['head']['title']);
        $this->assertStringContainsString('Admin', $inbox['head']['sub']);
        $all = panel_inbox::context($centre, true, (int) get_admin()->id, true);
        $this->assertFalse($all['rows'][0]['waiting']);
        $this->assertSame(get_string('panel_answered', 'local_oksigeniaeguahe'), $all['rows'][0]['main']);
    }
}
