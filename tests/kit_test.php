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

use local_oksigeniaeguahe\local\ai;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\kit;

/**
 * The kit of an act for the networks (0.43.0): a link per network of the centre, when to post, the pictures without a
 * QR, the texts by rules or by the AI of the centre (kept, and forgotten on request). With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(kit::class)]
final class kit_test extends \advanced_testcase {
    /**
     * Back to no AI.
     */
    protected function tearDown(): void {
        ai::$answer = null;
        parent::tearDown();
    }

    /**
     * A centre with two networks and an open day in so many days.
     *
     * @param int $days
     * @return array [centre, event]
     */
    protected function act(int $days): array {
        $id = centres::create((object) ['name' => 'Colegio Los Arces', 'lang' => 'en', 'country' => 'ES',
            'networks' => "Instagram\nX / Twitter"]);
        $start = time() + $days * DAYSECS;
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Open day', 'place' => 'Main door',
            'timestart' => $start, 'timeend' => $start + 2 * HOURSECS, 'capacity' => 40, 'open' => 1]);
        return [centres::get($id), events::get($eventid, $id)];
    }

    /**
     * A link for each network of the centre, its key made of letters; the network a key stands for.
     */
    public function test_links(): void {
        $this->resetAfterTest();
        [$centre, $event] = $this->act(20);
        $this->assertTrue(kit::has($event));
        $this->assertSame('xtwitter', kit::network_key('X / Twitter'));
        $links = kit::links($event, $centre);
        $this->assertSame(['instagram', 'xtwitter'], array_column($links, 'key'));
        $this->assertSame('instagram', (new \moodle_url($links[0]['url']))->get_param('d'));
        $this->assertSame('Instagram', kit::network_of($centre, 'instagram'));
        $this->assertSame('', kit::network_of($centre, 'cuento'));
    }

    /**
     * When to post: all the moments for an act far ahead; only what is still to come for one in three days.
     */
    public function test_when(): void {
        $this->resetAfterTest();
        [$centre, $event] = $this->act(20);
        $this->assertSame(['announce', 'tell', 'last', 'day'], array_column(kit::when($event, 'en', time()), 'key'));
        [$centre, $soon] = $this->act(3);
        $this->assertSame(['last', 'day'], array_column(kit::when($soon, 'en', time()), 'key'));
        $this->assertNotEmpty($centre);
    }

    /**
     * The texts: by rules, saying the act and the centre; by the AI of the centre when asked, kept and forgotten on
     * request; a malformed answer keeps the ones by rules.
     */
    public function test_texts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $event] = $this->act(20);
        $rules = kit::texts($event, $centre);
        $this->assertSame('rules', $rules['by']);
        $this->assertStringContainsString('Open day', $rules['post']);
        $this->assertStringContainsString('Colegio Los Arces', $rules['forward']);
        $this->assertCount(4, $rules['script']);

        $this->assertFalse(kit::write($event, $centre, 2), 'Without the AI on, nothing.');
        $centre->aion = 1;
        ai::$answer = fn($prompt) => 'Not JSON at all';
        $this->assertFalse(kit::write($event, $centre, 2));
        ai::$answer = function (string $prompt): string {
            $this->assertStringContainsString('Open day', $prompt);
            // As some providers answer: inside a fence.
            $fence = str_repeat(chr(96), 3);
            return $fence . "json\n" . json_encode(['post' => 'Come on Saturday.', 'forward' => 'Look at this.',
                'script' => [['shot' => 'The door', 'seconds' => 40, 'onscreen' => 'Saturday']]]) . "\n" . $fence;
        };
        $this->assertTrue(kit::write($event, $centre, 2));
        $event = events::get((int) $event->id, (int) $centre->id);
        $texts = kit::texts($event, $centre);
        $this->assertSame('ai', $texts['by']);
        $this->assertSame('Come on Saturday.', $texts['post']);
        $this->assertSame(15, $texts['script'][0]['seconds'], 'Never longer than a shot can be.');
        kit::forget($event);
        $this->assertSame('rules', kit::texts($event, $centre)['by']);
    }

    /**
     * The pictures, in the sizes the networks use, and none of an unknown shape.
     */
    public function test_pictures(): void {
        $this->resetAfterTest();
        [$centre, $event] = $this->act(20);
        foreach (kit::SHAPES as $shape => [$w, $h]) {
            $png = kit::picture($event, $centre, $shape);
            if ($png === '') {
                $this->markTestSkipped('No GD with fonts here.');
            }
            $size = getimagesizefromstring($png);
            $this->assertSame([$w, $h], [$size[0], $size[1]], $shape);
        }
        $this->assertSame('', kit::picture($event, $centre, 'round'));
    }
}
