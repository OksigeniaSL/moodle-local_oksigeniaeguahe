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
use local_oksigeniaeguahe\local\channel;
use local_oksigeniaeguahe\local\events;

/**
 * The open days in the channel of Telegram of the organisation, with Marandu: what the post says, only what is public
 * of the day; and nothing without Marandu. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(channel::class)]
final class channel_test extends \advanced_testcase {
    /**
     * The post of an open day: its name, when and where, the button to sign up and its card; without the channel of
     * Marandu, it does not go.
     */
    public function test_open_day(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'address' => 'Calle de ejemplo 1']);
        $start = make_timestamp(2030, 11, 23, 10, 0);
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'place' => 'Entrada principal', 'timestart' => $start, 'timeend' => $start + 3 * HOURSECS, 'capacity' => 0,
            'open' => 1]);
        $event = events::get($eventid, $id);
        $content = channel::content($event);
        $this->assertSame('Jornada de puertas abiertas', $content['title']);
        $this->assertStringContainsString('23 de noviembre', $content['lines'][0]);
        $this->assertSame('Entrada principal, Calle de ejemplo 1', $content['lines'][2]);
        $this->assertSame('Ven a conocernos', $content['card']['zones']['title'], 'Not the kicker twice.');
        $this->assertSame('Reservar visita', $content['label']);
        $this->assertSame(events::url($event)->out(false), $content['url']);
        $this->assertSame('open_day', $content['card']['type']);
        $this->assertStringStartsWith('Sábado 23', $content['card']['zones']['date']);
        if (!channel::ready()) {
            $this->assertSame(0, channel::open_day($event));
        }
    }
}
