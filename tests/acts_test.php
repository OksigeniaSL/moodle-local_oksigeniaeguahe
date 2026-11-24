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

use local_oksigeniaeguahe\local\acts;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\panel_eventform;
use local_oksigeniaeguahe\local\share;
use local_oksigeniaeguahe\local\signuppage;

/**
 * Tests for how an act is told: its word, what it is about, who gives it, for whom, its picture and free entry.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\acts
 */
#[\PHPUnit\Framework\Attributes\CoversClass(acts::class)]
final class acts_test extends \advanced_testcase {
    /**
     * A centre with two courses.
     *
     * @return \stdClass
     */
    protected function centre(): \stdClass {
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        foreach (['Infantil 3 años', '1.º de Primaria'] as $name) {
            centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => $name, 'places' => 25, 'active' => 1]);
        }
        return centres::get($id);
    }

    /**
     * The word a family reads: ours translated, the one of the centre as it is, and never «another event».
     */
    public function test_word(): void {
        $this->assertSame(['other', 'talk'], acts::kept('talk', 'lo que sea'));
        $this->assertSame(['other', 'Escape room'], acts::kept('other', '  Escape   room '));
        $this->assertSame(['visit', ''], acts::kept('visit', 'Charla'));
        $this->assertSame(['openday', ''], acts::kept('nada', ''));
        $this->assertSame('talk', acts::choice('other', 'talk'));
        $this->assertSame('other', acts::choice('other', 'Escape room'));
        $act = (object) ['kind' => 'other', 'word' => 'talk'];
        $this->assertSame('Charla', acts::word($act, 'es'));
        $this->assertSame('Talk', acts::word($act, 'en'));
        $act->word = 'Escape room';
        $this->assertSame('Escape room', acts::word($act, 'en'));
        $act->word = null;
        $this->assertSame('', acts::word($act, 'es'));
        $this->assertSame(get_string('event_kind_other', 'local_oksigeniaeguahe'), acts::team_word($act));
        $this->assertSame('Jornada de puertas abiertas', acts::word((object) ['kind' => 'openday'], 'es'));
    }

    /**
     * What the form keeps and what a family reads of it.
     */
    public function test_told(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $centre = $this->centre();
        $start = time() + 8 * DAYSECS;
        $id = events::save((int) $centre->id, (object) ['kind' => 'other', 'word' => 'talk', 'name' => 'Respirar y atender',
            'timestart' => $start, 'timeend' => $start + HOURSECS, 'capacity' => 0, 'open' => 1, 'told' => [
                'about' => str_repeat('a', 700), 'audience' => ['Infantil 3 años', 'Un curso que no está'], 'free' => 0,
                'speakers' => [['name' => 'Lucía Medina', 'role' => 'Psicóloga', 'key' => 'abcd1234'], ['name' => ' '],
                    ['name' => 'Andrés Rivas', 'role' => '']],
                'posteralt' => 'Una niña respira',
            ]]);
        $event = events::get($id, (int) $centre->id);
        $this->assertSame(acts::ABOUT, \core_text::strlen($event->about));
        $this->assertSame(['Infantil 3 años'], acts::audience($event));
        $this->assertSame(['Lucía Medina', 'Andrés Rivas'], array_column(acts::speakers($event), 'name'));
        $this->assertNotSame('', acts::speakers($event)[1]['key']);

        // Its picture, kept no longer than its side; the photo of who gives it, by its key.
        $image = imagecreatetruecolor(2400, 1200);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        $this->assertTrue(acts::store($bytes, acts::POSTER, $id));
        $this->assertFalse(acts::store('no es una imagen', acts::POSTER, $id));
        $this->assertTrue(acts::store($bytes, acts::SPEAKER, $id, '/abcd1234/'));
        $context = acts::context($event, 'es');
        $this->assertSame([acts::SIDE, 800], [$context['poster']['w'], $context['poster']['h']]);
        $this->assertSame('Una niña respira', $context['poster']['alt']);
        $this->assertSame('Charla', $context['word']);
        $this->assertSame('Para: Infantil 3 años', $context['audience']);
        $this->assertNotSame('', $context['speakers'][0]['photo']);
        $this->assertSame('', $context['speakers'][1]['photo']);
        $this->assertNotSame('', share::card($centre, 'cartel', [], acts::poster_file($id)->get_content()));
        // A second one takes its place, and what is no longer there goes.
        $this->assertTrue(acts::store($bytes, acts::POSTER, $id));
        $this->assertCount(1, get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniaeguahe',
            acts::POSTER,
            $id,
            'id',
            false
        ));
        acts::drop($id, acts::SPEAKER, ['otra']);
        $this->assertSame('', acts::photo($id, 'abcd1234'));
        acts::drop($id, acts::POSTER);
        $this->assertFalse(acts::poster($event));

        // The page where families sign up is of the talk, not «Come and meet us».
        $page = signuppage::context(
            $event,
            $centre,
            'es',
            \local_oksigeniaeguahe\local\signup::read($centre),
            [],
            new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token])
        );
        $this->assertSame(['Charla', 'Respirar y atender'], [$page['kicker'], $page['headline']]);

        // With free entry, the letter has nothing to sign up to.
        events::save((int) $centre->id, (object) ['id' => $id, 'kind' => 'other', 'word' => 'talk', 'name' => $event->name,
            'timestart' => $start, 'timeend' => $start + HOURSECS, 'open' => 1, 'told' => ['free' => 1]]);
        $event = events::get($id, (int) $centre->id);
        $this->assertNull($event->about);
        [, , $text] = familymail::letter('Ana', $centre, invites::of_letter($event), 'es', '');
        $this->assertStringContainsString('La entrada es libre y no hace falta inscribirse.', $text);
        $this->assertTrue(signuppage::free($event, $centre, 'es')['act']['free']);

        // Saved from elsewhere without any of this, it stays.
        events::save((int) $centre->id, (object) ['id' => $id, 'kind' => 'other', 'name' => 'Otro nombre',
            'timestart' => $start, 'timeend' => $start + HOURSECS, 'open' => 1]);
        $event = events::get($id, (int) $centre->id);
        $this->assertSame(['talk', 1], [$event->word, (int) $event->free]);
    }

    /**
     * The form offers the words, and says in one line what need not be touched.
     */
    public function test_form(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $centre = $this->centre();
        $context = panel_eventform::context($centre, panel_eventform::values(null));
        $this->assertSame(acts::CHOICES, array_column($context['kinds'], 'value'));
        $this->assertSame(['Jornada de puertas abiertas', '', '', '', '', ''], array_map(
            fn($k) => $k['suggested'] === '' ? '' : 'Jornada de puertas abiertas',
            $context['kinds']
        ));
        $this->assertSame(['Infantil 3 años', '1.º de Primaria'], array_column($context['courses'], 'value'));
        $this->assertSame(['turns', 'fit', 'hosts', 'asks', 'after'], array_keys($context['sums']));
        $this->assertNotContains('', $context['sums']);
    }
}
