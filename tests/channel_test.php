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
use local_oksigeniaeguahe\local\kit;

/**
 * The open acts in the channel of Telegram of the organisation, with Marandu: what each moment says, only what is
 * public of the act; its kind and key; what goes by itself; when; what a person chooses; and nothing without Marandu.
 * With example data.
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
        // The link says it came from Telegram, for the numbers.
        $this->assertSame((new \moodle_url(events::url($event), ['d' => 'telegram']))->out(false), $content['url']);
        $this->assertSame('open_day', $content['card']['type']);
        $this->assertStringStartsWith('Sábado 23', $content['card']['zones']['date']);
        if (!channel::ready()) {
            $this->assertSame(0, channel::open_day($event));
        }
    }

    /**
     * An open act that is not an open day, as a festival: each moment with its card, its button, and the text of the
     * kit when it tells something of it.
     */
    public function test_moments(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $start = make_timestamp(2030, 11, 23, 10, 0);
        $eventid = events::save($id, (object) ['kind' => 'other', 'name' => 'Festival de otoño', 'place' => 'El patio',
            'timestart' => $start, 'timeend' => $start + 3 * HOURSECS, 'capacity' => 0, 'open' => 1]);
        $event = events::get($eventid, $id);
        $announce = channel::content($event, 'announce');
        $this->assertSame('Abierto a todos', $announce['card']['zones']['kicker']);
        $this->assertSame('Festival de otoño', $announce['card']['zones']['title']);
        $this->assertSame('Inscribirse', $announce['label']);
        $this->assertArrayNotHasKey('text', $announce);
        $tell = channel::content($event, 'tell');
        $this->assertStringContainsString('Festival de otoño', $tell['text'], 'The text of the kit, by rules.');
        $last = channel::content($event, 'last');
        $this->assertSame('Último aviso', $last['card']['zones']['kicker']);
        $day = channel::content($event, 'day');
        $this->assertSame('Hoy', $day['card']['zones']['kicker']);
        $this->assertSame('Cómo llegar', $day['label']);
    }

    /**
     * The kind and the key of each moment: the announcement of an open day keeps the ones Marandu already has; the key
     * says back the act and the moment.
     */
    public function test_keys(): void {
        $openday = (object) ['id' => 7, 'kind' => 'openday'];
        $festival = (object) ['id' => 8, 'kind' => 'other'];
        $this->assertSame(['open_day', 'open_day-7'], channel::post_of($openday, 'announce'));
        $this->assertSame(['act', 'act-7-last'], channel::post_of($openday, 'last'));
        $this->assertSame(['act', 'act-8-announce'], channel::post_of($festival, 'announce'));
        $this->assertSame([7, 'announce'], channel::of_key('open_day-7'));
        $this->assertSame([7, 'last'], channel::of_key('act-7-last'));
        $this->assertSame([8, 'announce'], channel::of_key('act-8-announce'));
        $this->assertNull(channel::of_key('book-3'));
        $this->assertNull(channel::of_key('act-8-later'));
    }

    /**
     * What goes by itself: the announcement while the centre says nothing; none, or its own choice, in its order.
     */
    public function test_auto(): void {
        $this->assertSame(['announce'], channel::auto((object) []));
        $this->assertSame(['announce'], channel::auto((object) ['channelauto' => null]));
        $this->assertSame([], channel::auto((object) ['channelauto' => '']));
        $this->assertSame(['last', 'day'], channel::auto((object) ['channelauto' => 'day,last,later']));
        $this->assertSame('tell,day', channel::auto_value(['day', 'tell', 'nonsense']));
    }

    /**
     * When each moment goes by itself: the announcement now; the others from the start of their day, in the time zone
     * of the site; and what a centre writes in its settings is kept.
     */
    public function test_days(): void {
        $this->resetAfterTest();
        $this->setTimezone('Atlantic/Canary');
        $start = make_timestamp(2030, 11, 23, 10, 0, 0, 'Atlantic/Canary');
        $event = (object) ['id' => 1, 'kind' => 'openday', 'timestart' => $start];
        $this->assertSame(0, channel::day_of($event, 'announce'));
        $this->assertSame(make_timestamp(2030, 11, 16, 0, 0, 0, 'Atlantic/Canary'), channel::day_of($event, 'tell'));
        $this->assertSame(make_timestamp(2030, 11, 21, 0, 0, 0, 'Atlantic/Canary'), channel::day_of($event, 'last'));
        $this->assertSame(make_timestamp(2030, 11, 23, 0, 0, 0, 'Atlantic/Canary'), channel::day_of($event, 'day'));
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es']);
        centres::update($id, (object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'channelauto' => ['last', 'announce']]);
        $this->assertSame(['announce', 'last'], channel::auto(centres::get($id)));
    }

    /**
     * What a person chooses from the kit: a time must be still to come and before the act; «as the centre says» forgets
     * the choice. Kept with the kit, without touching its texts.
     */
    public function test_choose(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es']);
        $now = make_timestamp(2030, 11, 1, 9, 0);
        $start = make_timestamp(2030, 11, 23, 10, 0);
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => $start,
            'timeend' => $start + HOURSECS, 'capacity' => 0, 'open' => 1]);
        $event = events::get($eventid, $id);
        $this->assertFalse(channel::choose($event, 'last', 'at', $now - 60, $now), 'Not in the past.');
        $this->assertFalse(channel::choose($event, 'last', 'at', $start + 60, $now), 'Not after it starts.');
        $this->assertFalse(channel::choose($event, 'later', 'now', 0, $now));
        $this->assertFalse(channel::choose($event, 'last', 'maybe', 0, $now));
        $at = make_timestamp(2030, 11, 20, 18, 0);
        $this->assertTrue(channel::choose($event, 'last', 'at', $at, $now));
        $this->assertTrue(channel::choose(events::get($eventid, $id), 'day', 'off', 0, $now));
        $this->assertTrue(channel::choose(events::get($eventid, $id), 'tell', 'now', 0, $now));
        $event = events::get($eventid, $id);
        $this->assertSame(['last' => ['do' => 'at', 'at' => $at], 'day' => ['do' => 'off', 'at' => 0],
            'tell' => ['do' => 'now', 'at' => $now]], channel::plan($event));
        $this->assertNull(kit::kept($event)['texts']);
        $this->assertTrue(channel::choose($event, 'tell', 'centre', 0, $now));
        $this->assertArrayNotHasKey('tell', channel::plan(events::get($eventid, $id)));
        $moments = channel::moments(events::get($eventid, $id), centres::get($id), $now);
        $this->assertSame(channel::MOMENTS, array_column($moments, 'moment'));
        $this->assertTrue($moments[0]['auto']);
        $this->assertFalse($moments[2]['auto']);
        $this->assertSame('at', $moments[2]['choice']['do']);
    }
}
