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

use local_oksigeniaeguahe\local\birthdays;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_household;

/**
 * The birthday of each child (0.50.0): the date, the age, the course it is of, and what a person writes the day before
 * and on the day, in the tone of the age. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(birthdays::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_household::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(familypage::class)]
final class birthdays_test extends \advanced_testcase {
    /**
     * A time of the site on a day: noon, so the start of the day is the same in any time zone of the test.
     *
     * @param string $day YYYY-MM-DD
     * @return int
     */
    protected function noon(string $day): int {
        return (new \DateTimeImmutable($day . ' 12:00', \core_date::get_server_timezone_object()))->getTimestamp();
    }

    /**
     * What a date of birth is taken as, the age and the next birthday, the 29th of February too.
     */
    public function test_dates(): void {
        $this->resetAfterTest();
        $now = $this->noon('2026-11-10');
        $this->assertSame('2022-11-11', birthdays::clean(' 2022-11-11 ', $now));
        $this->assertNull(birthdays::clean('2022-02-30', $now), 'Not a real day.');
        $this->assertNull(birthdays::clean('2026-11-11', $now), 'Not after today.');
        $this->assertNull(birthdays::clean('11/11/2022', $now));
        $this->assertNull(birthdays::clean('1900-01-01', $now));
        $this->assertSame(3, birthdays::age('2022-11-11', $now));
        $this->assertSame(4, birthdays::age('2022-11-10', $now), 'Today is the birthday.');
        [$day, $age] = birthdays::next('2022-11-11', $now);
        $this->assertSame([$this->noon('2026-11-11') - 12 * HOURSECS, 4], [$day, $age]);
        [$day, $age] = birthdays::next('2020-02-29', $now);
        $this->assertSame('2027-02-28', userdate($day, '%Y-%m-%d', 99));
        $this->assertSame(7, $age);
    }

    /**
     * The day before and on the day, for a child of the centre, until someone writes to the family; the message in
     * the tone of the age it turns; and the course it is of by its age.
     */
    public function test_proposal(): void {
        global $DB;
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $infant = centres::save_offer($id, (object) ['schoolyear' => '2026-27', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $older = centres::save_offer($id, (object) ['schoolyear' => '2026-27', 'name' => 'Infantil 4 años', 'places' => 25,
            'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz',
            'email' => 'marta@example.com', 'phone' => '']);
        $childid = households::add_child($household, 'Juan Sebastián', $older, 'Sebas');
        $this->assertTrue(households::set_birthdate($household, $childid, '2023-11-11'));
        $this->assertFalse(households::set_birthdate($household, $childid, '2023-02-30'), 'Not a real day: nothing.');
        $this->assertSame('2023-11-11', $DB->get_field(households::CHILDREN, 'birthdate', ['id' => $childid]));

        // Asking for a place, nothing; in the centre, the day before.
        $this->assertNull(birthdays::step($household, $this->noon('2026-11-10')));
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $childid]), 'enrolled');
        $this->assertNull(birthdays::step($household, $this->noon('2026-11-08')), 'Too early.');
        $step = birthdays::step($household, $this->noon('2026-11-10'));
        $this->assertSame(['eve', 3], [$step['step'], $step['ages'][$childid]]);
        $proposal = panel_household::birthday_proposal($household, households::full($household), $this->noon('2026-11-10'));
        $this->assertSame('The day before a birthday', $proposal['sentence']);
        $this->assertStringContainsString('Tomorrow Sebas turns 3: happy birthday from the whole class!', $proposal['text']);
        $this->assertSame('day', birthdays::step($household, $this->noon('2026-11-11'))['step']);
        $this->assertNull(birthdays::step($household, $this->noon('2026-11-12')), 'It passed.');

        // Someone wrote to them: done.
        $DB->insert_record(households::CONTACTS, (object) ['householdid' => $household->id, 'centreid' => $id,
            'direction' => 'out', 'channel' => 'email', 'body' => '¡Felicidades!', 'timecreated' => $this->noon('2026-11-10')]);
        $this->assertNull(birthdays::step($household, $this->noon('2026-11-11')));

        // The team's message says where the gift of their age is.
        $this->assertStringContainsString('little birthday gift for Sebas', $proposal['text']);

        // Born in 2023, its course is the one that starts the year it turns 3.
        $fits = birthdays::courses('2023-11-11', $centre, $this->noon('2026-11-10'));
        $this->assertSame([$infant], array_map(fn($o) => (int) $o->id, $fits));
    }

    /**
     * On the page of the family, in the days of the birthday, the piece of the age it turns: the video, the card or the
     * seal; and nothing before the day or after a week.
     */
    public function test_family_page(): void {
        global $DB;
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2026-27', 'name' => 'Infantil 4 años', 'places' => 25,
            'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $childid = households::add_child($household, 'Juan Sebastián', $offer, 'Sebas');
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $childid]), 'enrolled');
        $token = households::new_link($household, 'team');
        $page = fn() => familypage::context(households::get((int) $household->id, $id), $centre, 'es', $token, false);
        $today = (new \DateTimeImmutable('today', \core_date::get_server_timezone_object()));
        $born = fn(int $age, int $days = 0) => $DB->set_field(households::CHILDREN, 'birthdate',
            $today->modify("-{$age} years")->modify("-{$days} days")->format('Y-m-d'), ['id' => $childid]);
        $born(4);
        [$card] = $page()['birthdays'];
        $data = json_decode($card['data'], true);
        $this->assertSame(['video', 'Sebas', 4, 'Hoy cumples 4 años.'], [$data['piece'], $data['o']['name'], $data['o']['age'],
            $data['o']['words']['years']]);
        $this->assertSame('Ver su vídeo', $card['button']);
        $born(7);
        $this->assertSame('card', json_decode($page()['birthdays'][0]['data'], true)['piece']);
        $born(13);
        $this->assertTrue($page()['birthdays'][0]['isseal']);
        $born(4, 6);
        $this->assertCount(1, $page()['birthdays'], 'The week of the birthday.');
        $born(4, 7);
        $this->assertSame([], $page()['birthdays']);
    }
}
