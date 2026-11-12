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
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_renewal;
use local_oksigeniaeguahe\local\renewal;

/**
 * Who goes on next school year (0.60.0): whom the centre asks, what families and the team say, the signs that a family
 * may leave (facts, never a mark), the email once and the pages. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(renewal::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_renewal::class)]
final class renewal_test extends \advanced_testcase {
    /**
     * The bodies of what is in the history of a family, of one direction.
     *
     * @param \stdClass $household
     * @param string $direction
     * @return string[]
     */
    protected function bodies(\stdClass $household, string $direction): array {
        global $DB;
        return array_values($DB->get_fieldset_select(
            households::CONTACTS,
            'body',
            'householdid = ? AND direction = ?',
            [$household->id, $direction]
        ));
    }

    /**
     * A centre with 2027-28 and the first course of 2028-29; a family with Leo (Infantil 3 años) and Sara (6.º de
     * Primaria) enrolled and Noa still asking, which lets the centre email it; another with Hugo enrolled, without email.
     *
     * @return array [centre, family, other family, Leo, Sara, Noa, Hugo]
     */
    protected function centre(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = fn(string $year, string $name) => centres::save_offer($id, (object) ['schoolyear' => $year, 'name' => $name,
            'places' => 25, 'active' => 1, 'sortorder' => 0]);
        $three = $offer('2027-28', 'Infantil 3 años');
        $sixth = $offer('2027-28', '6.º de Primaria');
        $offer('2028-29', 'Infantil 4 años');
        $family = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $marta = households::add_adult($family, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz',
            'email' => 'marta@example.com']);
        households::consent($family, $marta, 'email', true, 'form', 'es');
        $leo = households::add_child($family, 'Leonardo', $three, 'Leo');
        $sara = households::add_child($family, 'Sara', $sixth);
        $noa = households::add_child($family, 'Noa', $three);
        $other = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'phone']), $id);
        households::add_adult($other, (object) ['firstname' => 'Luis', 'lastname' => 'Prueba']);
        $hugo = households::add_child($other, 'Hugo', $three);
        foreach ([$leo, $sara, $hugo] as $child) {
            households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'enrolled');
        }
        return [centres::get($id), households::get((int) $family->id, $id), households::get((int) $other->id, $id),
            $leo, $sara, $noa, $hugo];
    }

    /**
     * Whom it asks: the enrolled children of the year before who have a course to go on to; not the last course, not
     * one still asking. Only while it asks.
     */
    public function test_who_is_asked(): void {
        [$centre, , , $leo, , , $hugo] = $this->centre();
        $this->assertSame([], renewal::children($centre), 'Nothing asked yet.');
        $this->assertFalse(renewal::open($centre, time()));
        $this->assertSame([['year' => '2028-29', 'ready' => true, 'children' => 2]], renewal::candidates($centre));
        $this->assertSame('2027-28', renewal::year_before('2028-29'));
        $this->assertSame('2029-30', renewal::year_after('2028-29'));

        renewal::set($centre, '2028-29', time() - 10, 0);
        $this->assertTrue(renewal::open($centre, time()));
        $this->assertSame([$leo, $hugo], array_keys(renewal::children($centre)));
        $this->assertSame('Infantil 4 años', renewal::children($centre)[$leo][2]->name);

        renewal::set($centre, '2028-29', (int) $centre->renewalfrom, time());
        $this->assertFalse(renewal::open($centre, time()), 'Stopped.');
        renewal::set($centre, '', 0, 0);
        $this->assertSame([], renewal::children($centre));
    }

    /**
     * What a family taps: each answer kept and changed; why, tapped again, taken back; what they write waits for a
     * person, as «does not go on» does. A child not asked about is not kept.
     */
    public function test_family_answers(): void {
        global $DB;
        [$centre, $family, , $leo, , $noa] = $this->centre();
        renewal::set($centre, '2028-29', time() - 10, 0);
        $now = time();
        $this->assertTrue(renewal::from_family($centre, $family, $leo, 'stay', '', null, 'es', $now));
        $this->assertSame('stay', renewal::answers($centre)[$leo]->answer);
        $this->assertSame(0, (int) households::get((int) $family->id, (int) $centre->id)->waitingsince);
        $this->assertContains('Dicen en su página que Leo sigue en 2028-29.', $this->bodies($family, 'in'));

        renewal::from_family($centre, $family, $leo, 'leave', 'moved', null, 'es', $now + 1);
        $row = renewal::answers($centre)[$leo];
        $this->assertSame(['leave', 'moved', 0], [$row->answer, $row->reason, (int) $row->userid]);
        $this->assertGreaterThan(
            0,
            (int) households::get((int) $family->id, (int) $centre->id)->waitingsince,
            'Does not go on: a person talks to them.'
        );
        renewal::from_family($centre, $family, $leo, '', 'moved', null, 'es', $now + 2);
        $this->assertNull(renewal::answers($centre)[$leo]->reason, 'Tapped again, taken back.');
        renewal::from_family($centre, $family, $leo, '', 'cost', 'Nos vamos a otra isla.', 'es', $now + 3);
        $row = renewal::answers($centre)[$leo];
        $this->assertSame(['leave', 'cost', 'Nos vamos a otra isla.'], [$row->answer, $row->reason, $row->note]);
        $this->assertContains('Nos vamos a otra isla.', $this->bodies($family, 'in'));
        renewal::from_family($centre, $family, $leo, 'unsure', '', null, 'es', $now + 4);
        $row = renewal::answers($centre)[$leo];
        $this->assertSame(['unsure', null, 'Nos vamos a otra isla.'], [$row->answer, $row->reason, $row->note]);

        $this->assertFalse(renewal::from_family($centre, $family, $noa, 'stay', '', null, 'es', $now), 'Noa is not asked.');
        $this->assertFalse(renewal::from_family($centre, $family, $leo, 'maybe', '', null, 'es', $now));

        // Their page asks, with the answer pressed.
        $page = familypage::context($family, $centre, 'es', households::new_link($family, 'team'), false);
        $this->assertNotFalse($page['renewal']);
        $this->assertSame('¿Sigue Leo en 2028-29? Le toca Infantil 4 años.', $page['renewal']['children'][0]['question']);
        $pressed = array_column($page['renewal']['children'][0]['options'], 'pressed', 'key');
        $this->assertSame(['stay' => false, 'leave' => false, 'unsure' => true], $pressed);
        // The family can take it all away.
        households::erase($family);
        $this->assertFalse($DB->record_exists(renewal::T, ['childid' => $leo]));
    }

    /**
     * What the team notes, with who; the signs that a family may leave, each with its day.
     */
    public function test_team_and_signs(): void {
        global $DB, $USER;
        [$centre, $family, $other, , $sara, , $hugo] = $this->centre();
        renewal::set($centre, '2028-29', time() - 10, 0);
        $this->assertTrue(renewal::from_team($centre, $other, $hugo, 'leave', 'othercentre', (int) $USER->id, time()));
        $this->assertSame((int) $USER->id, (int) renewal::answers($centre)[$hugo]->userid);
        $this->assertContains(get_string('renewal_noted_leave', 'local_oksigeniaeguahe', (object) ['name' => 'Hugo',
            'year' => '2028-29']), $this->bodies($other, 'note'));

        $kinds = fn($household) => array_column(renewal::signs($household, $centre, time()), 'kind');
        $this->assertSame(['leave'], $kinds($other));
        $this->assertStringContainsString(
            '(' . get_string('left_othercentre', 'local_oksigeniaeguahe') . ')',
            renewal::signs($other, $centre, time())[0]['text']
        );
        $this->assertSame(['none'], $kinds($family), 'Asked a moment ago: not yet quiet.');
        $this->assertSame(['none', 'quiet'], array_column(renewal::signs($family, $centre, time() + 4 * WEEKSECS), 'kind'));
        $DB->set_field(households::CHILDREN, 'lefttime', time() - DAYSECS, ['id' => $sara]);
        $this->assertContains('left', $kinds($family));

        // The file of the family: the block of next school year, and the signs it does not already say.
        $file = panel_household::context($family, $centre, true);
        $this->assertSame(
            get_string('panel_renewal_next_one', 'local_oksigeniaeguahe', 'Infantil 4 años'),
            $file['renewal']['rows'][0]['next']
        );
        $this->assertSame([get_string('renewal_sign_left', 'local_oksigeniaeguahe', (object) ['name' => 'Sara',
            'day' => userdate(time() - DAYSECS, get_string('strftimedate', 'langconfig'))])], array_column($file['signs'], 'line'));
    }

    /**
     * The email to whoever has not answered and may be written to, once for the school year.
     */
    public function test_tell_once(): void {
        global $DB;
        [$centre, $family] = $this->centre();
        renewal::set($centre, '2028-29', time() - 10, make_timestamp(2029, 3, 16));
        $this->assertSame([(int) $family->id], renewal::to_tell($centre), 'Hugo\'s family does not let the centre email it.');
        $sink = $this->redirectEmails();
        $this->assertSame(1, renewal::tell($centre, 0, time()));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('marta@example.com', $messages[0]->to);
        $this->assertStringContainsString('2028-29', $messages[0]->subject);
        $this->assertSame(0, renewal::tell($centre, 0, time()), 'Once.');
        $this->assertSame(1, renewal::told($centre)['count']);
        $this->assertTrue($DB->record_exists(households::CONTACTS, ['householdid' => $family->id,
            'detail' => renewal::TOLD . '2028-29']));
    }

    /**
     * The page of the team: course by course, who does not go on first; and the link of the inbox.
     */
    public function test_page(): void {
        [$centre, , $other, $leo, , , $hugo] = $this->centre();
        $page = panel_renewal::context($centre, true, time());
        $this->assertNotFalse($page['start']);
        $this->assertTrue($page['start']['candidates'][0]['ready']);
        $this->assertStringContainsString('2028-29', panel_renewal::link($centre, time())['text']);

        renewal::set($centre, '2028-29', time() - 10, 0);
        renewal::from_team($centre, $other, $hugo, 'leave', '', 0, time());
        $page = panel_renewal::context($centre, true, time());
        $this->assertFalse($page['start']);
        $this->assertSame('Infantil 3 años', $page['groups'][0]['title']);
        $this->assertSame([$hugo, $leo], array_column($page['groups'][0]['rows'], 'id'), 'Who does not go on, first.');
        $c = 'local_oksigeniaeguahe';
        $this->assertSame(get_string('panel_renewal_count', $c, (object) ['stay' => 0, 'all' => 2]), $page['groups'][0]['count']);
        $this->assertSame(get_string('panel_renewal_tell', $c, 1), $page['open']['tell']);
        $this->assertSame(get_string('panel_renewal_link', $c, (object) ['year' => '2028-29', 'answered' => 1, 'asked' => 2,
            'leave' => 1]), panel_renewal::link($centre, time())['text']);
    }
}
