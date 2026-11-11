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
use local_oksigeniaeguahe\local\firstdays;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\settled;
use local_oksigeniaeguahe\local\story;

/**
 * The first day of each course (0.55.0): its own date, the general one of the centre only in its school year, when
 * Eguahe asks for it and whether it still holds, and the story, the eve, the day after and the birthday of each child
 * by the first day of its course. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(firstdays::class)]
final class firstdays_test extends \advanced_testcase {
    /**
     * A centre with two courses of the school year 2027-28 and a family with a child enrolled in each.
     *
     * @return array [centre, household, the little one (Infantil 3 años), the older one (1.º de Primaria)]
     */
    protected function family(): array {
        global $DB;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $infant = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $primary = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => '1.º de Primaria', 'places' => 25,
            'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com']);
        $kids = [];
        foreach ([[$infant, 'Leo'], [$primary, 'Sara']] as [$offer, $name]) {
            $childid = households::add_child($household, $name, $offer);
            households::set_stage($DB->get_record(households::CHILDREN, ['id' => $childid]), 'enrolled');
            $kids[] = $DB->get_record(households::CHILDREN, ['id' => $childid]);
        }
        return [centres::get($id), households::get((int) $household->id, $id), $kids[0], $kids[1]];
    }

    /**
     * The course of a child, fresh from the database.
     *
     * @param \stdClass $child
     * @return \stdClass
     */
    protected function offer(\stdClass $child): \stdClass {
        global $DB;
        return $DB->get_record(centres::OFFERS, ['id' => $child->offerid]);
    }

    /**
     * The first day of a course: its own; the general one of the centre only when it is of its school year (a date of
     * another year is not trusted); else none.
     */
    public function test_of(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, , $leo] = $this->family();
        $this->assertSame(0, firstdays::of($this->offer($leo), $centre), 'No date anywhere.');
        $DB->set_field(centres::T, 'firstday', make_timestamp(2027, 9, 8), ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $this->assertSame(make_timestamp(2027, 9, 8), firstdays::of($this->offer($leo), $centre), 'The general one.');
        $this->assertSame(make_timestamp(2027, 9, 8), firstdays::of(null, $centre), 'Without a course, the general one.');
        $DB->set_field(centres::T, 'firstday', make_timestamp(2026, 9, 8), ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $this->assertSame(0, firstdays::of($this->offer($leo), $centre), 'Last year\'s general date is not this year\'s.');
        firstdays::set($this->offer($leo), make_timestamp(2027, 9, 10), time());
        $this->assertSame(make_timestamp(2027, 9, 10), firstdays::of($this->offer($leo), $centre), 'Its own.');
        $this->assertSame(make_timestamp(2027, 9, 10), firstdays::parse('2027-09-10'));
        $this->assertSame(0, firstdays::parse('2027-02-30'));
        $this->assertSame(0, firstdays::parse('el jueves'));
    }

    /**
     * When Eguahe asks: the date of a course with enrolled children from some weeks before its usual start, and whether
     * a date holds three weeks before it, until someone writes or confirms it then; a course of nobody, never. The usual
     * start comes from the same course the year before, on the same weekday.
     */
    public function test_asking(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, , $leo, $sara] = $this->family();
        centres::save_offer((int) $centre->id, (object) ['schoolyear' => '2027-28', 'name' => '2.º de Primaria',
            'places' => 25, 'active' => 1]);
        $start = make_timestamp(2027, 9, 1, 12);
        $kinds = fn(int $now) => array_map(
            fn($a) => [$a['offer']->name, $a['kind']],
            firstdays::asking(centres::get((int) $centre->id), $now)
        );
        $this->assertSame([], $kinds($start - 70 * DAYSECS), 'Too early to ask.');
        $asked = $kinds($start - 50 * DAYSECS);
        $this->assertCount(2, $asked, 'Only the courses with enrolled children.');
        $this->assertSame([['missing'], ['missing']], array_map(fn($a) => [$a[1]], $asked));
        $this->assertSame([], $kinds($start + 20 * DAYSECS), 'Too late to ask.');

        // Written in July: three weeks before, Eguahe asks whether it still holds.
        $first = make_timestamp(2027, 9, 10);
        firstdays::set($this->offer($leo), $first, $start - 50 * DAYSECS);
        $this->assertSame([['1.º de Primaria', 'missing']], $kinds($start - 40 * DAYSECS));
        $this->assertContains(['Infantil 3 años', 'check'], $kinds($first - 10 * DAYSECS));
        firstdays::confirm($this->offer($leo), $centre, $first - 10 * DAYSECS);
        $this->assertNotContains(['Infantil 3 años', 'check'], $kinds($first - 9 * DAYSECS), 'Said to hold.');
        $this->assertSame((int) ($first - 10 * DAYSECS), (int) $this->offer($leo)->firstdayok);

        // The general date of the centre is asked about too, and once confirmed it is the course's own.
        $DB->set_field(centres::T, 'firstday', make_timestamp(2027, 9, 8), ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $this->assertContains(['1.º de Primaria', 'check'], $kinds(make_timestamp(2027, 9, 1)));
        $this->assertTrue(firstdays::confirm($this->offer($sara), $centre, make_timestamp(2027, 9, 1)));
        $this->assertSame(make_timestamp(2027, 9, 8), (int) $this->offer($sara)->firstday);

        // A child who left does not keep a course asking.
        firstdays::set($this->offer($sara), 0, time());
        $DB->set_field(centres::T, 'firstday', 0, ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $DB->set_field(households::CHILDREN, 'lefttime', time(), ['id' => $sara->id]);
        $this->assertNotContains(['1.º de Primaria', 'missing'], $kinds($start - 40 * DAYSECS));

        // The same course the year before says when it usually starts: a year later, on the same weekday.
        $before = centres::save_offer((int) $centre->id, (object) ['schoolyear' => '2026-27', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1]);
        firstdays::set($DB->get_record(centres::OFFERS, ['id' => $before]), make_timestamp(2026, 9, 9), time());
        $usual = firstdays::usual($this->offer($leo), centres::offers((int) $centre->id), $centre);
        $this->assertSame(make_timestamp(2026, 9, 9) + 364 * DAYSECS, $usual);
        $this->assertSame(userdate(make_timestamp(2026, 9, 9), '%A'), userdate($usual, '%A'));
    }

    /**
     * Each child by the first day of its course: the eve and the day after of one are not the other's; a course with no
     * date gets neither; the story comes by its own date; the birthday knows who is already in class.
     */
    public function test_by_course(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $household, $leo, $sara] = $this->family();
        firstdays::set($this->offer($leo), make_timestamp(2027, 9, 8), time());
        firstdays::set($this->offer($sara), make_timestamp(2027, 9, 10), time());

        $step = story::step($household, $centre, make_timestamp(2027, 9, 7, 12));
        $this->assertSame('eve', $step['step']);
        $this->assertSame(['Leo'], array_column($step['children'], 'firstname'), 'Only Leo starts tomorrow.');
        $step = story::step($household, $centre, make_timestamp(2027, 9, 9, 12));
        $this->assertSame('after', $step['step']);
        $this->assertSame(['Leo'], array_column($step['children'], 'firstname'));
        $step = story::step($household, $centre, make_timestamp(2027, 9, 12, 12));
        $this->assertSame('after', $step['step']);
        $this->assertSame(['Sara'], array_column($step['children'], 'firstname'), 'Her own day after.');

        // Their story, some days before Leo's first day, and only in its time.
        $step = story::step($household, $centre, make_timestamp(2027, 8, 25, 12));
        $this->assertSame('story', $step['step']);
        $this->assertSame(['Leo'], array_column($step['stories'], 'firstname'));
        $this->assertSame(make_timestamp(2027, 9, 8), $step['first']);
        $this->assertSame([], story::children($household, $centre, 'es', make_timestamp(2027, 7, 1)), 'Too early.');

        // Without a date for Sara's course, no day after for her.
        firstdays::set($this->offer($sara), 0, time());
        $this->assertNull(story::step($household, $centre, make_timestamp(2027, 9, 12, 12)));

        // How was their first day, on their page, by the latest first day; the birthday, by each course.
        $this->assertSame(make_timestamp(2027, 9, 8), firstdays::started_lately(
            [$leo, $sara],
            $centre,
            make_timestamp(2027, 9, 8, 18),
            settled::FIRSTDAYASK
        ));
        $this->assertSame(0, firstdays::started_lately([$leo, $sara], $centre, make_timestamp(2027, 9, 8, 6), DAYSECS));
        $DB->set_field(centres::T, 'firstday', make_timestamp(2027, 9, 1), ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $now = make_timestamp(2027, 9, 5, 12);
        $this->assertSame('waiting', birthdays::moment($leo, $this->offer($leo), $centre, $now), 'His course starts on the 8th.');
        $this->assertSame('class', birthdays::moment($sara, $this->offer($sara), $centre, $now), 'The general date, the 1st.');
    }
}
