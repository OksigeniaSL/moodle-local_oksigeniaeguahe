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

use local_oksigeniaeguahe\local\actmail;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\greeting;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\notices;
use local_oksigeniaeguahe\local\writeby;

/**
 * What a family gets and sees after saying it comes to a talk: plain letters about it, and nothing of a visit. With example
 * data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\actmail
 * @covers     \local_oksigeniaeguahe\local\greeting
 */
#[\PHPUnit\Framework\Attributes\CoversClass(actmail::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(greeting::class)]
final class actmail_test extends \advanced_testcase {
    /** @var string[] What is of a visit and never in what is told of a talk. */
    const VISIT = ['Leo', 'visita', 'Visita', 'mochila', 'recibe', 'esperamos', 'Su visita', 'cuesta', 'plaza'];

    /**
     * A centre, a talk of the next days and a family that asks for a place for Leo.
     *
     * @param array $event
     * @return array [centre, event, household]
     */
    protected function talk(array $event = []): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'address' => 'Calle Mayor 3, 28001 Madrid', 'phone' => '+34 900 000 000']);
        $start = make_timestamp(2030, 12, 4, 15, 0);
        $eventid = events::save($id, (object) ($event + ['kind' => 'other', 'word' => 'talk', 'name' => 'Respira',
            'timestart' => $start, 'timeend' => $start + 2 * HOURSECS,
            'capacity' => 0, 'open' => 1, 'place' => 'Biblioteca del Colegio de ejemplo', 'free' => 2]));
        $DB->set_field(events::T, 'topic', 'respiración y atención: si respirar ayuda', ['id' => $eventid]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $adult = households::add_adult($household, (object) ['firstname' => 'Ana', 'email' => 'ana@example.com']);
        households::consent($household, $adult, 'email', true, 'form', 'es');
        households::add_child($household, 'Leo', 0);
        return [centres::get($id), events::get($eventid, $id), households::get((int) $household->id, $id)];
    }

    /**
     * Each letter: to the adult, about the talk, with its day, its place and one touch to say they do not come.
     */
    public function test_letters(): void {
        [$centre, $event, $household] = $this->talk();
        $this->assertTrue(invites::reply($event, $household, 'yes', 2));
        $signup = familymail::signup($household, (int) $event->id);
        $this->assertTrue(actmail::is($signup));
        $said = [];
        foreach (actmail::KINDS as $kind) {
            [$subject, $html, $text, $images] = familymail::build($kind, $household, $centre, $signup, 'es', 'clave');
            $said[$kind] = [$subject, $text];
            $this->assertSame([], $images, $kind);
            $words = $subject . preg_replace('~https?://\S+~', '', $text);
            foreach (self::VISIT as $never) {
                $this->assertStringNotContainsString($never, $words, $kind);
            }
            $this->assertStringStartsWith('Hola, Ana:', $text);
            $this->assertStringContainsString('Respira', $text);
            $this->assertStringContainsString('Les escribimos porque nos dijeron que vienen a la charla.', $text);
            $this->assertStringNotContainsString('[[', $words . strip_tags($html));
        }
        $this->assertSame('Les guardamos sitio: charla del 4 de diciembre', $said['confirmation'][0]);
        $this->assertStringContainsString('Les guardamos sitio en la charla «Respira».', $said['confirmation'][1]);
        $this->assertStringContainsString('de 15:00 a 17:00', $said['confirmation'][1], 'Never «come when you like».');
        $this->assertStringContainsString('Es en la Biblioteca del Colegio de ejemplo, Calle Mayor 3.', $said['confirmation'][1]);
        $this->assertStringContainsString('Para 2 personas.', $said['confirmation'][1]);
        $this->assertStringContainsString('www.google.com/maps/search/?api=1&query=', $said['confirmation'][1]);
        $this->assertStringContainsString('/j.php?t=' . $event->token . '&k=clave', $said['confirmation'][1]);
        $this->assertSame('Mañana, charla a las 15:00: respiración y atención', $said['reminder'][0]);
        $this->assertSame('Anulación: charla del 4 de diciembre', $said['cancelled'][0]);
        // The calendar: the centre named once, and nothing of who receives them.
        $ics = preg_replace("/\r\n /", '', familymail::ics($household, $centre, $event, $signup, 'es', 'clave'));
        $this->assertStringContainsString('LOCATION:Biblioteca del Colegio de ejemplo', $ics);
        $this->assertStringNotContainsString('Leo', $ics);
        $this->assertStringNotContainsString('visita y sus datos', $ics);
        // A party, and an act with no word: their own sentences, in every language.
        foreach (['party' => 'la fiesta «Respira»', '' => 'en «Respira»'] as $word => $part) {
            $other = clone($event);
            $other->word = $word;
            $this->assertStringContainsString($part, actmail::saved($other, 'es'));
            foreach (['en', 'pt', 'pt_br', 'ar'] as $lang) {
                $this->assertStringNotContainsString('[[', actmail::saved($other, $lang));
            }
        }
    }

    /**
     * It goes by email whatever they marked for the rest, unless they asked not to be written to; and no thanks go after a
     * talk, unless the team asks there how it went.
     */
    public function test_sending(): void {
        global $DB;
        [$centre, $event, $household] = $this->talk();
        invites::reply($event, $household, 'yes', 1);
        writeby::set($household, ['whatsapp'], 'family');
        $signup = familymail::signup($household, (int) $event->id);
        $this->assertTrue(writeby::email_now($household, $centre, 'reminder', true));
        $sink = $this->redirectEmails();
        $this->assertTrue(familymail::send('confirmation', $household, $centre, $signup, 'es', 'clave'));
        $this->assertCount(1, $sink->get_messages());
        $this->assertStringNotContainsString('List-Unsubscribe', $sink->get_messages()[0]->header);
        $sink->clear();
        $DB->set_field('local_oksigeniaeguahe_event', 'timestart', time() - 4 * HOURSECS, ['id' => $event->id]);
        $DB->set_field('local_oksigeniaeguahe_event', 'timeend', time() - 3 * HOURSECS, ['id' => $event->id]);
        $event = events::get((int) $event->id, (int) $centre->id);
        events::attend($event, (int) $signup->id, true);
        ob_start();
        (new task\send_thanks())->execute();
        ob_end_clean();
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame([], array_filter(notices::of($household, $centre), fn($n) => $n['kind'] === 'thanks'));
        writeby::set($household, [], 'family');
        $this->assertFalse(familymail::send('reminder', $household, $centre, $signup, 'es', 'clave'));
        $sink->close();
    }

    /**
     * Their page: the talk in a line of its own, and all the rest as it was; a family of the centre keeps its page; somebody
     * that only signed up is greeted by their name and is shown no prices.
     */
    public function test_page(): void {
        global $DB;
        [$centre, $event, $household] = $this->talk();
        $before = familypage::context($household, $centre, 'es', 'clave', false);
        invites::reply($event, $household, 'yes', 1);
        $after = familypage::context($household, $centre, 'es', 'clave', false);
        // What there is now is the act: coming to see the centre is said in its own line, further down.
        $this->assertNotSame('', $before['lead']);
        $this->assertSame(['', html_entity_decode($before['lead'])], [$after['lead'], $after['novisit']]);
        foreach (['greeting', 'hasnext', 'guidehello', 'bandlabel', 'hashosts'] as $same) {
            $this->assertSame($before[$same], $after[$same], $same);
        }
        $things = array_merge([$after['nowcard'] ?: $after['nowband']], $after['more']);
        $acts = array_values(array_filter(array_column($things, 'act')));
        $this->assertCount(1, $acts);
        $this->assertSame(['Charla', 'Respira'], [$acts[0]['kind'], $acts[0]['name']]);
        // Enrolled: «all set», as before the talk.
        $child = $DB->get_record(households::CHILDREN, ['householdid' => $household->id]);
        households::set_stage($child, 'enrolled');
        $enrolled = familypage::context(households::get((int) $household->id, (int) $centre->id), $centre, 'es', 'clave', false);
        $this->assertTrue($enrolled['quiet']);
        // Only of the act.
        $DB->set_field(households::T, 'guest', 1, ['id' => $household->id]);
        $DB->delete_records(households::CHILDREN, ['householdid' => $household->id]);
        $guest = familypage::context(households::get((int) $household->id, (int) $centre->id), $centre, 'es', 'clave', false);
        $this->assertSame('Hola, Ana', $guest['greeting']);
        $this->assertStringNotContainsString('escríbannos', $guest['lead']);
    }

    /**
     * Whatever changes it and from where, the sign-up and what they said in the letter say the same.
     */
    public function test_one_word(): void {
        [$centre, $event, $household] = $this->talk();
        $id = (int) $household->id;
        invites::reply($event, $household, 'yes', 1);
        $waits = fn() => (int) households::get($id, (int) $centre->id)->waitingsince;
        events::unsign($event, $household, 'No vienen.', false);
        $this->assertSame('no', invites::answer((int) $event->id, $id));
        $this->assertSame(0, $waits());
        events::sign_up($event, $id, 2);
        $this->assertSame('yes', invites::answer((int) $event->id, $id));
        $this->assertSame(0, $waits());
    }

    /**
     * The first name, as it is said: plain letters, its short forms whole, and nothing when it does not look like one.
     */
    public function test_greeting(): void {
        foreach (
            ['ᴄʀɪsᴛɪɴᴀ' => 'Cristina', '𝑰𝒓𝒆𝒏𝒆' => 'Irene', '𝘕𝘦𝘳𝘦𝘢' => 'Nerea', 'Mª' => 'María', 'Mª José' => 'María José',
                'MARÍA DEL CARMEN' => 'María del Carmen', 'ana' => 'Ana', 'Ana 🌸' => 'Ana', 'D’Angelo' => 'D’Angelo',
                'McKenna' => 'McKenna', 'Anne-Marie' => 'Anne-Marie', 'Aarón' => 'Aarón', 'Lee' => 'Lee', 'Noa' => 'Noa',
                'Иван' => 'Иван', 'أحمد' => 'أحمد', 'Rhys' => 'Rhys', 'DaviRambreu' => '', 'DianelisMedina' => '', 'I’m' => '',
                'Roo' => '', 'Fanii' => '', 'Saraa' => '', 'Sch' => '', 'M.' => '', 'ana@example.com' => '', 'ana22' => '',
                '' => ''] as $raw => $said
        ) {
            $this->assertSame($said, greeting::name((string) $raw), (string) $raw);
        }
    }
}
