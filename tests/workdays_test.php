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
use local_oksigeniaeguahe\local\workdays;

/**
 * The working days of a centre.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\workdays
 */
#[\PHPUnit\Framework\Attributes\CoversClass(workdays::class)]
final class workdays_test extends \advanced_testcase {
    /** @var string A calendar like the one a school gives. */
    const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" .
        "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261208\r\nDTEND;VALUE=DATE:20261209\r\nSUMMARY:Cerrado\r\nEND:VEVENT\r\n" .
        "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261223\r\nDTEND;VALUE=DATE:20261225\r\nSUMMARY:Admisiones: 7:30-13:00\r\n" .
        "END:VEVENT\r\n" .
        "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261226\r\nDTEND;VALUE=DATE:20261230\r\nSUMMARY:Comedor: cerrado\r\nEND:VEVENT\r\n" .
        "BEGIN:VEVENT\r\nDTSTART;TZID=Atlantic/Canary:20261210T090000\r\nSUMMARY:Reunión\r\nEND:VEVENT\r\n" .
        "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20270325\r\nDTEND;VALUE=DATE:20270327\r\nSUMMARY:Cerrado por Jueves \r\n" .
        " y Viernes Santo\r\nEND:VEVENT\r\n" .
        "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20201225\r\nRRULE:FREQ=YEARLY\r\nSUMMARY:Navidad\r\nEND:VEVENT\r\n" .
        "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261211\r\nSTATUS:CANCELLED\r\nSUMMARY:Puente\r\nEND:VEVENT\r\n" .
        "END:VCALENDAR\r\n";

    /**
     * A moment in the time of the site.
     *
     * @param string $when
     * @return int
     */
    protected function moment(string $when): int {
        return (new \DateTimeImmutable($when, \core_date::get_server_timezone_object()))->getTimestamp();
    }

    /**
     * A centre with its calendar read.
     *
     * @param string $country
     * @param string $ics
     * @return \stdClass
     */
    protected function centre(string $country = 'ES', string $ics = self::ICS): \stdClass {
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => $country, 'replydays' => 2]);
        $centre = centres::get($id);
        if ($ics !== '') {
            workdays::keep($centre, $ics, $this->moment('2026-11-24 10:00'));
        }
        return centres::get($id);
    }

    /**
     * Only its whole days closed: not the ones with other hours, nor of a part of the centre, nor what was cancelled.
     */
    public function test_read(): void {
        $this->assertSame(
            ['20251225', '20261208', '20261225', '20270325', '20270326', '20271225', '20281225'],
            workdays::read(self::ICS, $this->moment('2026-11-24 10:00'))
        );
        $this->assertSame([], workdays::read("BEGIN:VCALENDAR\nEND:VCALENDAR\n", time()));
        $this->assertSame([], workdays::read('<html>', time()));
    }

    /**
     * The day it was written does not count, the last one counts whole, and neither the weekend nor what is closed count.
     */
    public function test_deadline_and_limit(): void {
        $this->resetAfterTest();
        $centre = $this->centre();
        $end = fn(string $day): int => $this->moment($day . ' 23:59:59');
        $this->assertSame($end('2026-11-26'), workdays::deadline($centre, $this->moment('2026-11-24 09:00')), 'Tuesday: Thursday.');
        $this->assertSame($end('2026-12-01'), workdays::deadline($centre, $this->moment('2026-11-27 18:00')), 'Friday: Tuesday.');
        $this->assertSame($end('2026-12-01'), workdays::deadline($centre, $this->moment('2026-11-29 18:00')), 'Sunday: Tuesday.');
        $this->assertSame($end('2026-12-09'), workdays::deadline($centre, $this->moment('2026-12-04 08:00')), 'A holiday between.');

        // Both say the same, at every hour.
        foreach (['2026-11-29 12:00', '2026-12-01 23:59:59', '2026-12-02 00:00', '2026-12-09 12:00', '2026-12-10 07:00'] as $now) {
            $limit = workdays::limit($centre, $this->moment($now));
            for ($since = $this->moment('2026-11-23 05:00'); $since < $this->moment($now); $since += 7 * HOURSECS) {
                $this->assertSame(
                    $this->moment($now) > workdays::deadline($centre, $since),
                    $since < $limit,
                    $now . ' ' . userdate($since)
                );
            }
        }
        // A calendar that closes every day never stops the count.
        $always = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;VALUE=DATE:20260101\nDTEND;VALUE=DATE:20301231\nSUMMARY:Cerrado\n" .
            "END:VEVENT\nEND:VCALENDAR\n";
        $this->assertGreaterThan(0, workdays::deadline($this->centre('ES', $always), $this->moment('2026-11-24 09:00')));
    }

    /**
     * The weekend is the one of its country.
     */
    public function test_weekend(): void {
        $this->resetAfterTest();
        $this->assertSame([6, 7], workdays::weekend($this->centre('ES', '')));
        $this->assertSame([6, 7], workdays::weekend((object) ['country' => null]));
        if (class_exists('\IntlCalendar')) {
            $this->assertSame([5, 6], workdays::weekend($this->centre('EG', '')));
        }
    }

    /**
     * A calendar that cannot be read keeps the days of the last time and says so; the days gone by stay a year; taking it
     * away, nothing closes.
     */
    public function test_keep(): void {
        global $DB;
        $this->resetAfterTest();
        $centre = $this->centre();
        $id = (int) $centre->id;
        $now = $this->moment('2026-11-24 10:00');
        $DB->set_field(centres::T, 'closedics', 'https://example.com/a.ics', ['id' => $id]);
        $words = workdays::words(centres::get($id), $now);
        $this->assertStringContainsString('6', $words);
        $this->assertFalse(workdays::keep($centre, '<html>No</html>', $now + DAYSECS));
        $this->assertArrayHasKey('20261208', workdays::closed(centres::get($id)));
        $this->assertStringContainsString('25', workdays::words(centres::get($id), $now + DAYSECS));
        $later = $this->moment('2027-01-10 10:00');
        $next = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;VALUE=DATE:20270202\nSUMMARY:Cerrado\nEND:VEVENT\nEND:VCALENDAR\n";
        $this->assertTrue(workdays::keep($centre, $next, $later));
        $closed = workdays::closed(centres::get($id));
        $this->assertArrayHasKey('20261208', $closed, 'Gone by.');
        $this->assertArrayHasKey('20270202', $closed);
        $this->assertArrayNotHasKey('20270325', $closed, 'It no longer says it.');

        $this->assertSame('https://example.com/a.ics', workdays::address(' webcal://example.com/a.ics '));
        $this->assertSame('', workdays::address('http://example.com/a.ics'));
        $this->assertSame('', workdays::address('festivos'));
        centres::update($id, (object) ['closedics' => 'webcal://example.com/b.ics']);
        $this->assertSame('https://example.com/b.ics', centres::get($id)->closedics);
        centres::update($id, (object) ['phone' => '600 000 000']);
        $this->assertSame('https://example.com/b.ics', centres::get($id)->closedics, 'What is not passed stays.');
        centres::update($id, (object) ['closedics' => '']);
        workdays::fetch(centres::get($id));
        $this->assertSame([], workdays::closed(centres::get($id)));
        $this->assertSame('', workdays::words(centres::get($id), $now));
    }
}
