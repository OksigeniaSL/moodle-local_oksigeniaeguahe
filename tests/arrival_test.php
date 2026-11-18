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

use local_oksigeniaeguahe\local\antispam;
use local_oksigeniaeguahe\local\arrival;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\signup;

/**
 * Where a family arrived from, measured: the utm of its link and the site it came from, the first source the web of the
 * centre passed on, AI assistants known as such, kept the first time and never written over.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\arrival
 * @covers     \local_oksigeniaeguahe\local\households
 */
#[\PHPUnit\Framework\Attributes\CoversClass(arrival::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(households::class)]
final class arrival_test extends \advanced_testcase {
    /**
     * Clean the request after each test.
     */
    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        unset($_SERVER['HTTP_REFERER']);
        antispam::$testpass = null;
        parent::tearDown();
    }

    /**
     * Sites by their domain, sources in lower case, and the assistants of AI by their domain or their name.
     */
    public function test_clean_and_ai(): void {
        $this->assertSame('perplexity.ai', arrival::host('https://www.Perplexity.ai/search?q=colegios'));
        $this->assertSame('chatgpt.com', arrival::host('chatgpt.com'));
        $this->assertSame('', arrival::host('not a site at all'));
        $this->assertSame('chatgpt.com', arrival::clean('https://www.ChatGPT.com', 100, 'src'));
        $this->assertSame('Campaña 2027', arrival::clean('Campaña 2027', 255, 'cmp'));
        $this->assertSame('instagram', arrival::clean('<b>instagram</b>', 100, 'src'));
        $ais = ['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'gemini.google.com', 'claude.ai', 'chatgpt', 'copilot'];
        foreach ($ais as $ai) {
            $this->assertTrue(arrival::is_ai($ai), $ai);
        }
        foreach (['google', 'google.com', 'instagram', 'colegio.example.com', ''] as $other) {
            $this->assertFalse(arrival::is_ai($other), $other);
        }
        $this->assertSame(['arrivalsource' => 'chatgpt.com', 'arrivalmedium' => null, 'arrivalcampaign' => null,
            'arrivalref' => 'perplexity.ai'], arrival::fields(['src' => 'chatgpt.com', 'ref' => 'perplexity.ai']));
        $this->assertSame(['', false], arrival::of((object) []));
        $this->assertSame(['chatgpt.com', true], arrival::of((object) ['arrivalsource' => 'chatgpt.com']));
        $this->assertSame(['colegio.example.com', false], arrival::of((object) ['arrivalref' => 'colegio.example.com']));
        $this->assertSame(get_string('arrival_ai_group', 'local_oksigeniaeguahe'), arrival::group('perplexity.ai'));
        $this->assertSame('instagram', arrival::group('instagram'));
        // In the lists of Numbers, by their own name.
        foreach (
            ['instagram' => 'Instagram', 'l.instagram.com' => 'Instagram', 'ig' => 'Instagram',
                'lm.facebook.com' => 'Facebook', 'google.es' => 'Google', 'www.google.co.uk' => 'Google', 't.co' => 'X',
                'cartel' => 'Cartel', 'colegio.example.com' => 'Colegio.example.com'] as $raw => $name
        ) {
            $this->assertSame($name, arrival::label($raw), $raw);
        }
        $this->assertSame(get_string('arrival_ai_group', 'local_oksigeniaeguahe'), arrival::label('chatgpt.com'));
    }

    /**
     * Opened, what the web of the centre passed on comes first; then the link's utm and the site it came from, never this
     * site; sent, what the form brought from its first page.
     */
    public function test_read(): void {
        global $CFG;
        $_GET = ['utm_source' => 'ChatGPT.com', 'utm_medium' => 'referral'];
        $_SERVER['HTTP_REFERER'] = 'https://www.perplexity.ai/search?q=x';
        $this->assertSame(
            ['src' => 'chatgpt.com', 'med' => 'referral', 'cmp' => '', 'ref' => 'perplexity.ai'],
            arrival::read()
        );
        $_SERVER['HTTP_REFERER'] = $CFG->wwwroot . '/local/oksigeniaeguahe/j.php?t=x';
        $this->assertSame('', arrival::read()['ref']);
        $_GET = ['utm_source' => 'instagram', 'eg_src' => 'chatgpt.com', 'eg_ref' => 'chatgpt.com'];
        $this->assertSame(['src' => 'chatgpt.com', 'med' => '', 'cmp' => '', 'ref' => 'chatgpt.com'], arrival::read());
        $_GET = [];
        $_POST = ['llegadasent' => 1, 'llegadasrc' => 'gemini.google.com', 'llegadaref' => ''];
        $_SERVER['HTTP_REFERER'] = 'https://www.google.com/';
        $this->assertSame(['src' => 'gemini.google.com', 'med' => '', 'cmp' => '', 'ref' => ''], arrival::read());
    }

    /**
     * A family that signs up keeps where it arrived from the first time; signing up again from somewhere else does not
     * change it, and one that arrived with nothing measured gets it the next time.
     */
    public function test_kept_the_first_time(): void {
        $this->resetAfterTest();
        antispam::$testpass = true;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $event = events::get(events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'place' => 'Entrada principal', 'timestart' => time() + DAYSECS, 'timeend' => time() + DAYSECS + 3 * HOURSECS,
            'capacity' => 50, 'open' => 1]), $id);
        $data = function (string $email, array $arrival) use ($offer): array {
            return [
                'adults' => [['firstname' => 'Lucía', 'lastname' => 'Benítez', 'email' => $email, 'phone' => '']],
                'children' => [['firstname' => 'Martín', 'tagname' => '', 'birthdate' => '', 'latinname' => '',
                    'offerid' => $offer]],
                'siblings' => [], 'people' => 2, 'slot' => 0, 'incentre' => '', 'municipality' => '',
                'municipalityother' => '', 'heardfrom' => 'social', 'heardwho' => '', 'heardnet' => '', 'message' => '',
                'consents' => ['email' => 1], 'altcha' => '', 'days' => [], 'periods' => [], 'self' => false,
                'arrival' => $arrival,
            ];
        };
        [$household] = signup::save($data('lucia.benitez@example.com', ['src' => 'chatgpt.com', 'med' => '', 'cmp' => '',
            'ref' => '']), $event, $centre, 'es');
        $kept = households::get((int) $household->id, (int) $centre->id);
        $this->assertSame('chatgpt.com', $kept->arrivalsource);
        $this->assertSame('social', $kept->heardfrom);
        $this->assertSame(['chatgpt.com', true], arrival::of($kept));
        // Again, from Instagram: what was measured the first time stays.
        signup::save(
            $data('lucia.benitez@example.com', ['src' => 'instagram', 'med' => 'social', 'cmp' => '', 'ref' => '']),
            $event,
            $centre,
            'es'
        );
        $this->assertSame('chatgpt.com', households::get((int) $household->id, (int) $centre->id)->arrivalsource);
        // A family with nothing measured gets it the next time it comes.
        [$other] = signup::save(
            $data('jorge.delgado@example.com', ['src' => '', 'med' => '', 'cmp' => '', 'ref' => '']),
            $event,
            $centre,
            'es'
        );
        $this->assertSame(['', false], arrival::of(households::get((int) $other->id, (int) $centre->id)));
        signup::save(
            $data('jorge.delgado@example.com', ['src' => '', 'med' => '', 'cmp' => '', 'ref' => 'perplexity.ai']),
            $event,
            $centre,
            'es'
        );
        $this->assertSame(['perplexity.ai', true], arrival::of(households::get((int) $other->id, (int) $centre->id)));
        // The family takes it with its data.
        $export = households::export(households::get((int) $household->id, (int) $centre->id));
        $this->assertSame(['source' => 'chatgpt.com'], $export['household']['arrived_from']);
    }
}
