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
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\seen;
use local_oksigeniaeguahe\local\share;

/**
 * What a family shares, well shared (0.43.0): signed links that open one piece, the gift of the centre in each language,
 * the invitation and where it leads, the preview, and what is counted (never a robot, never the team). With example
 * data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(share::class)]
final class share_test extends \advanced_testcase {
    /**
     * Back to no browser.
     */
    protected function tearDown(): void {
        unset($_SERVER['HTTP_USER_AGENT']);
        parent::tearDown();
    }

    /**
     * A centre and a family of it.
     *
     * @param string $name
     * @param string $lang
     * @return array [centre, household]
     */
    protected function family(string $name = 'Colegio Los Arces', string $lang = 'es'): array {
        $id = centres::create((object) ['name' => $name, 'lang' => $lang, 'country' => 'ES', 'colour' => '#1f5a4e']);
        $household = households::get(households::create($id, (object) ['lang' => $lang, 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com']);
        return [centres::get($id), $household];
    }

    /**
     * Signed links: each opens its piece only; the gift of the centre, in each language with its article.
     */
    public function test_links_and_gift(): void {
        $this->resetAfterTest();
        [$centre, $household] = $this->family();
        $sig = share::signature('dibujo', (int) $household->id, 7);
        $this->assertTrue(share::signed('dibujo', (int) $household->id, 7, $sig));
        $this->assertFalse(share::signed('dibujo', (int) $household->id, 8, $sig), 'Another child.');
        $this->assertFalse(share::signed('cuento', (int) $household->id, 7, $sig), 'Another piece.');
        $this->assertFalse(share::signed('dibujo', (int) $household->id, 7, ''));

        $this->assertSame('Un regalo del Colegio Los Arces', share::gift($centre, 'es'));
        $this->assertSame('A gift from Colegio Los Arces', share::gift($centre, 'en'));
        [$escola] = $this->family('Escola do Sol', 'pt');
        $this->assertSame('Um presente da Escola do Sol', share::gift($escola, 'pt'));
        [$academy] = $this->family('Academia Norte', 'es');
        $this->assertSame('de la Academia Norte', share::from_centre($academy, 'es'));
        [$riverside] = $this->family('Riverside School', 'en');
        $this->assertSame('Riverside School', share::from_centre($riverside, 'en'));
        $this->assertSame('de Riverside School', share::from_centre($riverside, 'es'));

        $seal = share::seal('dibujo', 'es');
        $this->assertSame('https://eguahe.com/?de=dibujo&lang=es', $seal['url']);
        $this->assertSame('https://eguahe.com/?de=cuento&lang=pt-br', share::seal('cuento', 'pt_br')['url']);
    }

    /**
     * The video of a drawing: what it needs, with the words on it and its picture to send instead; nothing without its
     * strokes.
     */
    public function test_video(): void {
        $this->resetAfterTest();
        [$centre, $household] = $this->family();
        $childid = households::add_child($household, 'Leonardo', 0, 'Leo');
        $child = households::full($household)->children[$childid];
        $this->assertFalse(share::video($household, $child, $centre, 'es', '/foto', ''), 'Without strokes, the link goes.');
        $video = json_decode(share::video($household, $child, $centre, 'es', '/foto', '/trazos'), true);
        $this->assertSame('Leo', $video['name']);
        $this->assertSame('Un regalo del Colegio Los Arces', $video['gift']);
        $this->assertSame('El dibujo de Leo.mp4', $video['file']);
        $this->assertStringContainsString('og.php', $video['picture']);
        $this->assertStringContainsString('dibujo.php', $video['url']);
        $this->assertNotEmpty($video['words']['preparing']);
    }

    /**
     * The invitation: to the form chosen or the first active, with its own place per piece; without a form, to the
     * next open act; with nothing, or the centre saying no, none.
     */
    public function test_invitation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre] = $this->family();
        $go = new \moodle_url('/local/oksigeniaeguahe/dibujo.php');
        $this->assertNull(share::invite_target($centre, 'cuento'), 'Nowhere to lead.');
        $this->assertFalse(share::invitation($centre, 'cuento', 'es', $go));

        // An open day ahead: there.
        $eventid = events::save((int) $centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'place' => '',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + 2 * HOURSECS, 'capacity' => 0,
            'open' => 1]);
        $target = share::invite_target($centre, 'dibujo');
        $this->assertStringContainsString('/j.php', $target->out(false));
        $this->assertSame('dibujo', $target->get_param('d'));

        // A form: there, with its place for the piece, named in the language of the centre.
        $formid = entries::create((int) $centre->id, 'form', 'Pide información');
        $target = share::invite_target($centre, 'cuento');
        $this->assertStringContainsString('/w.php', $target->out(false));
        $this->assertSame('cuento', $target->get_param('d'));
        $this->assertSame('Compartido: el cuento', forms::origins(entries::get($formid, (int) $centre->id))['cuento']);
        $invitation = share::invitation($centre, 'cuento', 'es', $go);
        $this->assertSame('Vengan a conocer el Colegio Los Arces.', $invitation['text']);
        $this->assertSame('invitacion', (new \moodle_url($invitation['url']))->get_param('action'));

        // The centre says no.
        centres::update((int) $centre->id, (object) ['name' => $centre->name, 'lang' => 'es', 'shareinvite' => 0]);
        $this->assertFalse(share::invitation(centres::get((int) $centre->id), 'cuento', 'es', $go));
        $this->assertNotEmpty($eventid);
    }

    /**
     * Counted for the family that shared it: opened once in a browser, never by the readers that build a preview, never
     * with a piece unknown.
     */
    public function test_counted(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $household] = $this->family();
        $this->setGuestUser();
        $_SERVER['HTTP_USER_AGENT'] = 'WhatsApp/2.23.20.0 A';
        $this->assertFalse(share::count($household, $centre, 'opened', 'dibujo'), 'The preview of a chat.');
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';
        $this->assertTrue(share::count($household, $centre, 'opened', 'dibujo'));
        $this->assertFalse(share::count($household, $centre, 'opened', 'dibujo'), 'The same browser: once.');
        $this->assertTrue(share::count($household, $centre, 'invite', 'dibujo'));
        $this->assertFalse(seen::record($household, 'share', 'opened', 'otra'), 'A piece unknown.');
        $this->assertSame(2, $DB->count_records(seen::T, ['householdid' => $household->id, 'kind' => 'share']));
    }

    /**
     * The preview: its tags, and an image of 1200 × 630 in the colours of the centre.
     */
    public function test_preview(): void {
        $this->resetAfterTest();
        [$centre] = $this->family();
        $meta = share::meta(
            'Un cuento para Leo',
            'Un regalo del Colegio Los Arces',
            new \moodle_url('/local/oksigeniaeguahe/cuento.php'),
            new \moodle_url('/local/oksigeniaeguahe/og.php', ['p' => 'cuento']),
            'es',
            'Colegio Los Arces'
        );
        $this->assertStringContainsString('<meta property="og:image" content="', $meta);
        $this->assertStringContainsString('<meta property="og:locale" content="es_ES">', $meta);
        $png = share::card($centre, 'cuento', ['title' => 'Tu primer día', 'name' => 'Leo']);
        if ($png === '') {
            $this->markTestSkipped('No GD with fonts here.');
        }
        $this->assertStringStartsWith("\x89PNG", $png);
        $size = getimagesizefromstring($png);
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
        // The drawing stuck on the wall, and an act as the band of the visit, also square and upright.
        $sheet = imagecreatetruecolor(300, 400);
        imagefilledrectangle($sheet, 0, 0, 300, 400, imagecolorallocate($sheet, 240, 236, 228));
        ob_start();
        imagejpeg($sheet);
        $picture = (string) ob_get_clean();
        $size = getimagesizefromstring(share::card($centre, 'dibujo', ['name' => 'Leo'], $picture));
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
        $act = ['weekday' => 'Sábado', 'date' => '21 de noviembre', 'hours' => 'De 10:00 a 13:00',
            'title' => 'Jornada de puertas abiertas'];
        foreach ([[1200, 630], [1080, 1080], [1080, 1920]] as $shape) {
            $size = getimagesizefromstring(share::card($centre, 'acto', $act, null, $shape));
            $this->assertSame($shape, [$size[0], $size[1]]);
        }
        $this->assertNotSame(
            '',
            share::card($centre, 'cuento', ['title' => 'يومك الأول', 'name' => 'ليلى']),
            'Arabic, in its own face.'
        );
    }

    /**
     * An ambassador family: its own invitation, signed, that works while it is one and only in its centre; how it
     * signs; who told the family that came, for the team; and what it brought, as numbers.
     */
    public function test_ambassador(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $household] = $this->family();
        [$other] = $this->family('Escuela de ejemplo');
        $url = share::family_url($household);
        $this->assertStringContainsString('/local/oksigeniaeguahe/invita.php', $url->out(false));
        $signature = $url->get_param('s');
        $this->assertNull(share::ambassador((int) $household->id, $signature), 'Not an ambassador yet.');
        $DB->set_field(households::T, 'volunteer', time(), ['id' => $household->id]);
        $household = households::get((int) $household->id, (int) $centre->id);
        [$found, $itscentre] = share::ambassador((int) $household->id, $signature);
        $this->assertSame((int) $household->id, (int) $found->id);
        $this->assertSame((int) $centre->id, (int) $itscentre->id);
        $this->assertNull(share::ambassador((int) $household->id, 'otra'));
        $this->assertNull(share::invited_by($other, (int) $household->id, $signature), 'Only in its centre.');
        $this->assertNotNull(share::invited_by($centre, (int) $household->id, $signature));

        $this->assertSame('Una familia del centro', share::ambassador_sign($household, 'es'));
        $household->invitesign = 'La familia de Leo';
        $this->assertSame('La familia de Leo', share::ambassador_sign($household, 'es'));
        // On the card of the invitation, who invites goes apart to be written by hand, escaped inside its sentence.
        $card = '<span class="invitacion-firma" dir="auto">La familia de &lt;Leo&gt;</span> les invita';
        $this->assertSame($card, share::from_html('La familia de <Leo>', 'es'));

        // As it signed, which the new family already read: never the names of its adults.
        $told = share::told_by(['heardfrom' => '', 'heardwho' => ''], $household, 'es');
        $this->assertSame(['heardfrom' => 'centrefamilies', 'heardwho' => 'La familia de Leo'], $told);
        $written = ['heardfrom' => 'centrefamilies', 'heardwho' => 'Los de Leo'];
        $this->assertSame($written, share::told_by($written, $household));
        // What they said stays.
        $social = ['heardfrom' => 'social', 'heardwho' => ''];
        $this->assertSame($social, share::told_by($social, $household));

        $this->setGuestUser();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';
        $this->assertTrue(share::count($household, $centre, 'opened', 'familia'));
        $this->assertTrue(share::count($household, $centre, 'arrived', 'familia'));
        $this->assertTrue(share::count($household, $centre, 'arrived', 'familia'));
        $this->assertSame(['opened' => 1, 'arrived' => 2], share::ambassador_counts($household));

        // No longer an ambassador: the invitation stops working.
        $DB->set_field(households::T, 'volunteer', 0, ['id' => $household->id]);
        $this->assertNull(share::ambassador((int) $household->id, $signature));
    }
}
