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
use local_oksigeniaeguahe\local\commonmail;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\invites;

/**
 * The letters of Eguahe in the common mail: the brand of the centre that writes, its pictures inside and light, and the
 * same words as plain text. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\commonmail
 */
#[\PHPUnit\Framework\Attributes\CoversClass(commonmail::class)]
final class commonmail_test extends \advanced_testcase {
    /**
     * A picture of example data, of the given size, with noise so that it weighs.
     *
     * @param int $w
     * @param int $h
     * @return string PNG
     */
    protected static function png(int $w, int $h): string {
        $im = imagecreatetruecolor($w, $h);
        mt_srand(7);
        for ($i = 0; $i < 4000; $i++) {
            $colour = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), $colour);
        }
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /**
     * The letter takes the name, the colours and the logo of its centre, its poster light enough to travel, and says the
     * same in its plain text, with no address written twice and no picture from outside.
     */
    public function test_letter_of_the_centre(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $centre->colour = '#164281';
        $centre->brandcolours = '#164281 #067e36';
        $centre->phone = '+34922000000';
        $fs = get_file_storage();
        $system = \context_system::instance()->id;
        $fs->create_file_from_string(['contextid' => $system, 'component' => 'local_oksigeniaeguahe', 'filearea' => 'logo',
            'itemid' => $id, 'filepath' => '/', 'filename' => 'logo.png'], self::png(300, 100));

        $brand = commonmail::brand($centre);
        $this->assertSame(['Colegio de ejemplo', ['#164281', '#067e36'], true], [$brand['name'], $brand['colours'],
            $brand['wordmark']]);

        $eventid = events::save($id, (object) ['kind' => 'other', 'name' => 'Respira', 'timestart' => time() + 5 * DAYSECS,
            'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]);
        $DB->set_field(events::T, 'posteralt', 'Cartel de la charla', ['id' => $eventid]);
        $record = ['contextid' => $system, 'component' => 'local_oksigeniaeguahe', 'filearea' => acts::POSTER,
            'itemid' => $eventid, 'filepath' => '/', 'filename' => 'cartel.png'];
        $fs->create_file_from_string($record, self::png(2400, 1200));
        $event = events::get($eventid, $id);
        [$subject, $html, $text, $images, $letter] = familymail::letter('Ana', $centre, invites::of_letter($event), 'es', 'clave');

        // The logo on its plate and the poster across the top, inside the mail and light.
        $this->assertCount(2, $images);
        foreach ($images as $image) {
            $this->assertStringContainsString('cid:' . $image['cid'], $html);
            $this->assertStringEndsWith('@eguahe', $image['cid']);
            $this->assertLessThanOrEqual(commonmail::MAXBYTES, strlen($image['data']));
        }
        $poster = getimagesizefromstring($images[1]['data']);
        $this->assertSame([1200, 600, 'image/jpeg'], [$poster[0], $poster[1], $poster['mime']]);
        $this->assertStringContainsString('alt="Cartel de la charla"', $html);
        $this->assertStringContainsString('#164281', $html);
        $this->assertSame(0, preg_match('~(src|href)="http://~', $html), 'Nothing from outside, nothing without https.');

        // The same words in the text: the greeting, the button with its address, who signs; and the words of the team.
        $this->assertStringContainsString("Hola, Ana:\n", $text);
        $this->assertStringContainsString($letter['go'] . ': ' . $letter['url'], $text);
        $this->assertSame(1, substr_count($text, $letter['url']));
        $this->assertStringContainsString("Un saludo,\nColegio de ejemplo", $text);
        $this->assertStringContainsString('+34 922 000 000', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringNotContainsString("\n\n\n", $text);
        $this->assertStringContainsString('Ana', $letter['words']);
        $this->assertSame(1, substr_count($html, 'class="om-btn-a"'));

        // A logo that is not a picture: the letter goes without it, with the name in the band.
        $fs->delete_area_files($system, 'local_oksigeniaeguahe', 'logo', $id);
        $fs->create_file_from_string(['contextid' => $system, 'component' => 'local_oksigeniaeguahe', 'filearea' => 'logo',
            'itemid' => $id, 'filepath' => '/', 'filename' => 'logo.png'], 'not a picture');
        [, $html, , $images] = familymail::letter('', $centre, invites::of_letter($event), 'es', '');
        $this->assertCount(1, $images);
        $this->assertStringContainsString('Colegio de ejemplo', $html);
    }

    /**
     * A standing poster goes smaller beside the words, and one too heavy to travel is left out.
     */
    public function test_picture(): void {
        $standing = commonmail::picture(self::png(1000, 1500));
        $this->assertFalse($standing['wide']);
        $this->assertSame(2 * commonmail::STANDING, getimagesizefromstring($standing['bytes'])[0]);
        $this->assertNull(commonmail::picture('not a picture'));
        $this->assertNull(commonmail::picture(''));
    }
}
