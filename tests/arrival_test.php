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
        // The «Sitio web» of the profile of the centre on Google, apart from a search on Google.
        $profile = arrival::of((object) ['arrivalsource' => 'google', 'arrivalmedium' => 'ficha']);
        $this->assertSame([arrival::GOOGLE_PROFILE, false], $profile);
        $this->assertSame(get_string('arrival_google_profile', 'local_oksigeniaeguahe'), arrival::shown($profile[0]));
        $this->assertSame('Google', arrival::shown(arrival::of((object) ['arrivalref' => 'google.com'])[0]));
        $this->assertSame('Google', arrival::shown(arrival::of((object) ['arrivalsource' => 'google',
            'arrivalmedium' => 'cpc'])[0]));
        // The channels: the work of the centre itself, advertising, other ways, and what was not measured.
        $centre = (object) ['email' => 'admisiones@colegio.example', 'emailsettled' => ''];
        $ch = fn(array $h) => arrival::channel((object) $h, $centre);
        $this->assertSame('profile', $ch(['arrivalsource' => 'google', 'arrivalmedium' => 'ficha']));
        $this->assertSame('search', $ch(['arrivalref' => 'google.com']));
        $this->assertSame('ai', $ch(['arrivalsource' => 'chatgpt.com']));
        $this->assertSame('social', $ch(['arrivalref' => 'l.instagram.com']));
        $this->assertSame('social', $ch(['arrivalsource' => 'tiktok', 'arrivalmedium' => 'social']));
        $this->assertSame('campaign', $ch(['arrivalsource' => 'facebook', 'arrivalmedium' => 'cpc', 'arrivalcampaign' => 'j-01']));
        $this->assertSame('campaign', $ch(['arrivalsource' => 'meta', 'arrivalmedium' => 'campaign']));
        $this->assertSame('mail', $ch(['arrivalsource' => 'boletin', 'arrivalmedium' => 'email']));
        $this->assertSame('print', $ch(['arrivalsource' => 'folleto-feria', 'arrivalmedium' => 'qr']));
        // A form shared on a network is that network; on the web of the centre, its web.
        $this->assertSame('social', $ch(['arrivalsource' => 'instagram', 'arrivalmedium' => 'formulario']));
        $this->assertSame('direct', $ch(['arrivalsource' => 'webinfantil', 'arrivalmedium' => 'formulario']));
        $this->assertSame('direct', $ch(['arrivalref' => 'nueva.colegio.example']));
        $this->assertSame('direct', $ch(['arrivalsource' => 'colegio.example', 'arrivalmedium' => 'web',
            'arrivalcampaign' => 'web-matricula']));
        $this->assertSame('web', $ch(['arrivalref' => 'prensa.example.org']));
        // A door is never where a family came from: an open day, a form, a call or the poster at the door of an act.
        // With nothing measured, what the family said; without that either, not measured.
        $this->assertSame('unmeasured', $ch(['firstchannel' => 'webform']));
        $this->assertSame('unmeasured', $ch(['firstchannel' => 'openday']));
        $this->assertSame('unmeasured', $ch(['firstchannel' => 'phone']));
        $this->assertSame('unmeasured', $ch(['arrivalsource' => 'puerta', 'arrivalmedium' => 'formulario']));
        $this->assertSame('search', $ch(['arrivalsource' => 'puerta', 'arrivalmedium' => 'formulario',
            'heardfrom' => 'search']));
        $this->assertSame('wordofmouth', $ch(['firstchannel' => 'openday', 'heardfrom' => 'family']));
        $this->assertSame('mail', $ch(['firstchannel' => 'webform', 'heardfrom' => 'centremessage']));
        $this->assertSame('campaign', $ch(['firstchannel' => 'webform', 'heardfrom' => 'ad']));
        $this->assertSame('offline', $ch(['firstchannel' => 'walkin', 'heardfrom' => 'passingby']));
        // The team noted it came from the web of the centre.
        $this->assertSame('direct', $ch(['firstchannel' => 'web']));
        // What was measured first is never written over by what it says later.
        $this->assertSame('ai', $ch(['arrivalsource' => 'chatgpt.com', 'heardfrom' => 'ad']));
        // The blocks of Numbers.
        $this->assertSame('own', arrival::block('wordofmouth'));
        $this->assertSame('own', arrival::block('direct'));
        $this->assertSame('paid', arrival::block('campaign'));
        $this->assertSame('other', arrival::block('print'));
        $this->assertSame('unmeasured', arrival::block('unmeasured'));
        $sum = arrival::by_channel([
            ['voices' => ['channel' => arrival::channel_name('search')], 'came' => true, 'enrolled' => true],
            ['voices' => ['channel' => arrival::channel_name('direct')], 'came' => true],
            ['voices' => ['channel' => arrival::channel_name('campaign')]],
            ['voices' => ['channel' => arrival::channel_name('print')]],
            ['voices' => ['channel' => arrival::channel_name('unmeasured')], 'enrolled' => true],
        ]);
        $this->assertSame([2, 1, 1, 0, 1, 0, 1, 1, 5], [$sum['own'], $sum['ownenrolled'], $sum['paid'], $sum['paidenrolled'],
            $sum['other'], $sum['otherenrolled'], $sum['unmeasured'], $sum['unmeasuredenrolled'], $sum['total']]);
        // The sentence adds up: each block with its number.
        $this->assertSame([2, 1, 1, 1, 5], array_map('intval', [$sum['own'], $sum['paid'], $sum['other'], $sum['unmeasured'],
            $sum['own'] + $sum['paid'] + $sum['other'] + $sum['unmeasured']]));
        $this->assertSame(['search', 'direct', 'campaign', 'print', 'unmeasured'], array_column($sum['rows'], 'key'));
        $this->assertSame(get_string('arrival_channel_profile', 'local_oksigeniaeguahe'), arrival::channel_name('profile'));
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
        // A family already known that later comes by an ad did not come by it: an ad never fills its arrival.
        [$known] = signup::save(
            $data('rosa.cabrera@example.com', ['src' => '', 'med' => '', 'cmp' => '', 'ref' => '']),
            $event,
            $centre,
            'es'
        );
        signup::save(
            $data('rosa.cabrera@example.com', ['src' => 'instagram', 'med' => 'paid', 'cmp' => 'Jornada', 'ref' => '']),
            $event,
            $centre,
            'es'
        );
        $known = households::get((int) $known->id, (int) $centre->id);
        $this->assertSame(['', false], arrival::of($known));
        $this->assertSame('social', arrival::channel($known, $centre), 'What they said, never the ad.');
        $this->assertTrue(arrival::paid(['arrivalmedium' => 'CPC']));
        $this->assertFalse(arrival::paid(['arrivalmedium' => 'formulario']));
        // The family takes it with its data.
        $export = households::export(households::get((int) $household->id, (int) $centre->id));
        $this->assertSame(['source' => 'chatgpt.com'], $export['household']['arrived_from']);
    }

    /**
     * By channel as a sheet: the totals of each block and in all, each step against the families of its own channel (one
     * of two hundred is a sliver, three of five are three fifths), those already of the centre apart, and «How it is
     * counted» without its first words for the fold.
     */
    public function test_sheet(): void {
        $c = 'local_oksigeniaeguahe';
        $who = fn(int $new, int $centre = 0) => ['new' => ['n' => $new, 'enrolled' => 0], 'nursery' => ['n' => 0, 'enrolled' => 0],
            'centre' => ['n' => $centre, 'enrolled' => 0], 'former' => ['n' => 0, 'enrolled' => 0],
            'alumni' => ['n' => 0, 'enrolled' => 0]];
        $row = fn(string $key, string $group, int $n, int $visited, int $enrolled, int $said, array $kinds) => ['key' => $key,
            'name' => arrival::channel_name($key), 'group' => $group, 'n' => $n, 'visited' => $visited, 'applied' => $enrolled,
            'enrolled' => $enrolled, 'said' => $said, 'adlater' => 0, 'who' => $kinds];
        $sheet = arrival::sheet(['rows' => [$row('wordofmouth', 'own', 5, 3, 3, 2, $who(5, 1)),
            $row('campaign', 'paid', 200, 1, 0, 0, $who(200))], 'total' => 205, 'adlater' => 0, 'best' => false,
            'worst' => false, 'who' => $who(205, 1)]);
        $this->assertSame([5, 200, 205], array_column($sheet['head'], 'n'));
        $this->assertTrue($sheet['head'][2]['total']);
        $own = $sheet['blocks'][0]['rows'][0];
        $this->assertEquals(0.6, $own['enrolled']['p'], 'Against its own five, not against the two hundred.');
        $this->assertSame('', $own['enrolled']['pct'], 'Of five, the number; a share needs ten.');
        $this->assertStringContainsString('%', $sheet['blocks'][1]['rows'][0]['enrolled']['pct']);
        $this->assertEquals(0.03, $sheet['blocks'][1]['rows'][0]['visit']['p'], 'One family of two hundred is a sliver.');
        $this->assertSame(0, $sheet['blocks'][1]['rows'][0]['applied']['p']);
        $this->assertSame(get_string('bychannel_note_said', $c, 2), $own['said']);
        $this->assertTrue($sheet['hasapart']);
        $this->assertSame([false, true], array_column($own['kinds'], 'apart'));
        $this->assertFalse($sheet['fold']);
        $this->assertStringStartsNotWith(get_string('bychannel_how', $c), $sheet['how']);
        $this->assertStringStartsWith(get_string('bychannel_how', $c), $sheet['foot']);
    }
}
