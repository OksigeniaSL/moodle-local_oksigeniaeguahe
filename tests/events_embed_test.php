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

/**
 * The signing up for an act inside the web of the centre: its code, and the mark the page carries there instead of a
 * session of this site. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(events::class)]
final class events_embed_test extends \advanced_testcase {
    /**
     * The code points at the act with embed=1 and loads the script that sizes it; a mark is good for a day and only for
     * its act.
     */
    public function test_embed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $acts = [];
        foreach (['Jornada', 'Otra jornada'] as $name) {
            $data = (object) ['kind' => 'openday', 'name' => $name, 'timestart' => time() + 5 * DAYSECS,
                'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1];
            $acts[] = events::get(events::save($centreid, $data), $centreid);
        }
        [$event, $other] = $acts;

        $code = events::embed_code($event, 'Jornada');
        $this->assertStringContainsString('j.php?t=' . $event->token . '&amp;embed=1', s($code));
        $this->assertStringContainsString('/local/oksigeniaeguahe/pub/embed.js', $code);

        $mark = events::mark($event);
        $this->assertTrue(events::mark_ok($event, $mark));
        $this->assertFalse(events::mark_ok($other, $mark));
        $this->assertFalse(events::mark_ok($event, events::mark($event, time() - DAYSECS - 60)));
        $this->assertFalse(events::mark_ok($event, 'no es una marca'));
        $this->assertFalse(events::mark_ok($event, substr($mark, 0, -1) . (substr($mark, -1) === 'a' ? 'b' : 'a')));
    }
}
