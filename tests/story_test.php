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
use local_oksigeniaeguahe\local\panel_likes;
use local_oksigeniaeguahe\local\panel_metrics;
use local_oksigeniaeguahe\local\seen;
use local_oksigeniaeguahe\local\story;

/**
 * «Your first day» (0.35.0, in five languages since 0.38.0): who has the story and when, the book with its words, the
 * teacher of each course and its name, the link to send it that opens the story and nothing else, and what is counted of
 * it. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(story::class)]
final class story_test extends \advanced_testcase {
    /**
     * A centre with a first course of school and a later one, and a family with a child enrolled in the first.
     *
     * @return array [centre, household, child, the later offer]
     */
    protected function family(): array {
        global $DB;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $infant = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $later = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => '1.º de ESO', 'places' => 25,
            'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com']);
        $childid = households::add_child($household, 'Leonardo', $infant);
        $DB->set_field(households::CHILDREN, 'tagname', 'Leo', ['id' => $childid]);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $childid]), 'enrolled');
        return [centres::get($id), households::get((int) $household->id, $id),
            $DB->get_record(households::CHILDREN, ['id' => $childid]), $later];
    }

    /**
     * Who has it: the children enrolled in a course that gives it, by its age until the centre chooses, around the first
     * day, and in a language the story is written in.
     */
    public function test_who_and_when(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $household, $child, $later] = $this->family();
        $now = time();
        $this->assertSame([(int) $child->id], array_keys(story::children($household, $centre, 'es', $now)));
        $this->assertCount(1, story::children($household, $centre, 'en', $now), 'Written in English too.');
        $this->assertSame([], story::children($household, $centre, 'fr', $now), 'Not written in French.');
        $this->assertFalse(story::offer_gives($DB->get_record(centres::OFFERS, ['id' => $later]), (int) $centre->id));
        $DB->set_field(centres::OFFERS, 'story', 0, ['id' => $child->offerid]);
        $this->assertSame([], story::children($household, $centre, 'es', $now), 'The centre said no.');
        $DB->set_field(centres::OFFERS, 'story', 1, ['id' => $child->offerid]);
        $DB->set_field(centres::T, 'firstday', $now + 40 * DAYSECS, ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $this->assertSame([], story::children($household, $centre, 'es', $now), 'Too early.');
        $this->assertCount(1, story::children($household, $centre, 'es', $now + 25 * DAYSECS));
        $this->assertSame([], story::children($household, $centre, 'es', $now + 120 * DAYSECS), 'Long over.');
        $DB->set_field(households::CHILDREN, 'lefttime', $now, ['id' => $child->id]);
        $this->assertSame([], story::children($household, $centre, 'es', $now + 25 * DAYSECS), 'Gone.');
    }

    /**
     * The book: ten pages with the words of the language, the other pegs never with the child's own name, the voice, and
     * the link to send it, which only opens this child's story.
     */
    public function test_book_and_link(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $household, $child] = $this->family();
        $share = story::share_url($household, $child, 'es');
        $this->assertSame((string) $share->get_param('s'), story::signature((int) $household->id, (int) $child->id));
        $this->assertNotNull(story::from_link((int) $household->id, (int) $child->id, (string) $share->get_param('s')));
        $this->assertNull(story::from_link((int) $household->id, (int) $child->id, str_repeat('0', 32)));
        $this->assertNull(story::from_link((int) $household->id, (int) $child->id + 1, (string) $share->get_param('s')));

        $this->assertSame('el Colegio de ejemplo', story::phrase('Colegio de ejemplo', 'es'));
        $this->assertSame('la Escuela Infantil El Sol', story::phrase('Escuela Infantil El Sol', 'es'));
        $this->assertSame('Montessori Village', story::phrase('Montessori Village', 'es'));
        $DB->set_field(centres::T, 'namephrase', 'nuestro Colegio de ejemplo', ['id' => $centre->id]);
        $links = ['drawing' => '', 'share' => $share->out(false), 'measure' => null];
        $context = story::context($household, $child, centres::get((int) $centre->id), 'es', $links);
        $book = json_decode($context['data'], true);
        $this->assertSame('Leo', $book['child']);
        $this->assertSame('es', $book['lang']);
        $this->assertSame(['es' => 'nuestro Colegio de ejemplo'], $book['centrePhrases'], 'As the centre wrote it.');
        $this->assertCount(10, $book['pages']);
        $this->assertSame('p3-pizarra-maestro', $book['pages'][3]['maestro']['img']);
        $this->assertCount(count(story::STRINGS), $book['strings']['es']);
        $this->assertStringStartsWith('Para {nombre}', $book['strings']['es']['dedication']);
        $pegs = array_column(array_filter($book['pages'][4]['names'], fn($n) => isset($n['text'])), 'text');
        $this->assertCount(5, $pegs);
        $this->assertNotContains('Leo', $pegs);
        $this->assertArrayHasKey('sin-nombre', $book['voices']['es']);
        $this->assertArrayHasKey('sin-nombre-maestro', $book['voices']['es']);
        $this->assertSame(['maestra', ''], [$book['teacher'], $book['tutors']['maestra']], 'Nobody named for the course.');
        $this->assertNull($book['drawing'], 'Without a drawing of theirs, one of the class hangs on the wall.');
        $this->assertStringContainsString('clase-garabato.jpg', $book['classDrawing']);
        $this->assertSame('Tu primer día · Leo', $context['title']);
        $this->assertFalse($context['choosevoice']);
        $this->assertStringNotContainsString('</', $context['data']);

        // A man receives the group of the course, and families see him: the book says «maestro» and his name.
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Pablo', 'lastname' => 'Sosa']);
        centres::add_member((int) $centre->id, (int) $user->id);
        $member = $DB->get_record(centres::MEMBERS, ['centreid' => $centre->id, 'userid' => $user->id]);
        $DB->set_field(centres::MEMBERS, 'public', 1, ['id' => $member->id]);
        $DB->update_record(centres::OFFERS, (object) ['id' => $child->offerid, 'teacher' => 'maestro',
            'handover' => $member->id]);
        $this->assertSame(['maestro', 'Pablo'], story::teacher(centres::get((int) $centre->id), $child));
        $DB->set_field(centres::MEMBERS, 'public', 0, ['id' => $member->id]);
        $this->assertSame(['maestro', ''], story::teacher(centres::get((int) $centre->id), $child), 'Not seen, not named.');

        // In English, the name of the centre as it is; in Arabic, from right to left, and the voice is chosen first.
        $english = json_decode(story::context($household, $child, centres::get((int) $centre->id), 'en', $links)['data'], true);
        $this->assertSame(['en' => 'Colegio de ejemplo'], $english['centrePhrases']);
        $this->assertSame('Your First Day', $english['strings']['en']['title']);
        $this->assertArrayHasKey('sin-nombre', $english['voices']['en']);
        $arabic = story::context($household, $child, centres::get((int) $centre->id), 'ar', $links);
        $this->assertSame('rtl', $arabic['dir']);
        $this->assertNotFalse($arabic['choosevoice']);
        $readings = array_keys(json_decode($arabic['data'], true)['voices']['ar']);
        sort($readings);
        $this->assertSame(['sin-nombre-f', 'sin-nombre-m', 'sin-nombre-maestro-f', 'sin-nombre-maestro-m'], $readings);
        $portuguese = story::context($household, $child, centres::get((int) $centre->id), 'pt', $links);
        $portuguese = json_decode($portuguese['data'], true);
        $this->assertSame(['pt-PT' => 'o Colegio de ejemplo'], $portuguese['centrePhrases']);

        // The story as a video to keep: its file and the gift of the centre, for the sheet.
        $video = json_decode(
            story::context($household, $child, centres::get((int) $centre->id), 'es', $links)['data'],
            true
        )['video'];
        $this->assertSame('Tu primer día, Leo.mp4', $video['file']);
        $this->assertStringContainsString('Colegio de ejemplo', $video['gift']);

        // On the page of the family, the card.
        $page = familypage::context($household, centres::get((int) $centre->id), 'es', 'k', false);
        $this->assertCount(1, $page['stories']);
        $this->assertSame('Un cuento para Leo', $page['stories'][0]['kicker']);
    }

    /**
     * What a person writes around the first day: the story in the days before, the eve and «How was their first day?»
     * after; each until someone writes to them in its time. And their page asks how it went.
     */
    public function test_steps(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $household] = $this->family();
        $now = time();
        // Without a first day, the story as soon as they enrol.
        $this->assertSame('story', story::step($household, $centre, $now)['step']);
        $soon = $now + 10 * DAYSECS;
        $first = make_timestamp((int) date('Y', $soon), (int) date('n', $soon), (int) date('j', $soon));
        $DB->set_field(centres::T, 'firstday', $first, ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $this->assertSame('story', story::step($household, $centre, $first - 5 * DAYSECS)['step']);
        $this->assertSame('eve', story::step($household, $centre, $first - HOURSECS)['step']);
        $this->assertNull(story::step($household, $centre, $first + HOURSECS), 'On the day itself, nothing to write.');
        $this->assertSame('after', story::step($household, $centre, $first + DAYSECS + HOURSECS)['step']);
        $this->assertNull(story::step($household, $centre, $first + 5 * DAYSECS));
        $proposal = \local_oksigeniaeguahe\local\panel_household::firstday_proposal(
            $household,
            households::full($household),
            $centre,
            $first - HOURSECS
        );
        $this->assertStringContainsString('Leo', $proposal['text']);
        // Written to them on the eve: the eve is done.
        $DB->insert_record(households::CONTACTS, (object) ['centreid' => $centre->id, 'householdid' => $household->id,
            'direction' => 'out', 'channel' => 'email', 'body' => 'Hasta mañana', 'userid' => 2,
            'timecreated' => $first - 2 * HOURSECS]);
        $this->assertNull(story::step($household, $centre, $first - HOURSECS));
        $this->assertSame('after', story::step($household, $centre, $first + DAYSECS + HOURSECS)['step']);
    }

    /**
     * What is counted: the story opened once a reading, the pages it reaches, and in Cifras where they leave it.
     */
    public function test_counted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $household] = $this->family();
        $now = time();
        $this->assertTrue(seen::record($household, 'story', 'opened', '', $now));
        $this->assertFalse(seen::record($household, 'story', 'opened', '', $now + 60), 'The same reading.');
        $this->assertFalse(seen::record($household, 'story', 'nothing', '', $now));
        foreach ([1, 2, 3, 4] as $page) {
            seen::record($household, 'story', 'page', 'p' . $page, $now);
        }
        seen::record($household, 'story', 'like', 'pX', $now);
        $this->assertSame([], seen::summary((int) $household->id)['mails'], 'The pages of the story are no mail pressed.');
        $campaign = panel_metrics::campaigns($centre)['live'];
        $firstday = panel_likes::context($centre, $campaign, panel_metrics::families($centre, $campaign), $now);
        $this->assertTrue($firstday['empty'], 'Few families: the card waits.');
        $method = new \ReflectionMethod(panel_likes::class, 'firstday');
        $rows = seen::of([(int) $household->id]);
        $count = new \ReflectionMethod(panel_likes::class, 'count_rows');
        $result = $method->invoke(null, $centre, [(int) $household->id], $count->invoke(null, $rows));
        $this->assertSame([1, 1, 0], [$result['opened'], $result['of'], $result['whole']]);
        $this->assertSame(1, $result['strip'][4]['families'], 'They left it at page 4.');
        $this->assertSame(0, $result['strip'][9]['families']);
    }
}
