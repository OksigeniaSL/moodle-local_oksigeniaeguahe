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
use local_oksigeniaeguahe\local\howto;
use local_oksigeniaeguahe\local\households;

/**
 * «How is it done?»: what it answers, to whom, and what it keeps of what it could not answer.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\howto
 */
#[\PHPUnit\Framework\Attributes\CoversClass(howto::class)]
final class howto_test extends \advanced_testcase {
    /**
     * A centre of example, in Spanish.
     *
     * @return \stdClass
     */
    private function centre(): \stdClass {
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        return centres::get($id);
    }

    /**
     * Every task and every setting has its title, steps and words in English, and their steps say something.
     */
    public function test_every_entry_is_whole(): void {
        $this->resetAfterTest();
        $centre = $this->centre();
        foreach (array_keys(howto::TASKS) as $key) {
            $task = howto::task($key, $centre);
            $this->assertNotSame('', $task['title'], $key);
            $this->assertNotEmpty(array_filter($task['steps']), $key);
            $this->assertStringContainsString(',', $task['words'], $key);
        }
        foreach (array_keys(howto::SETTINGS) as $key) {
            $setting = howto::setting($key, $centre);
            $this->assertNotSame('', $setting['title'], $key);
            $this->assertStringContainsString('#aj-', $setting['url'], $key);
        }
    }

    /**
     * Some questions, as someone lost would type them: the right task first; a name or a phone, nothing.
     */
    public function test_finds(): void {
        $this->resetAfterTest();
        $centre = $this->centre();
        $first = fn(string $q, bool $manage = true) => howto::find($q, $centre, $manage)[0]['key'] ?? '';
        $this->assertSame('poster', $first('print the poster'));
        $this->assertSame('answer', $first('reply to a family'));
        $this->assertSame('door', $first('attendance'));
        $this->assertSame('renewal', $first('who stays on next year'));
        $this->assertSame('away', $first('holidays'));
        $this->assertSame('costs', $first('fees'));
        $this->assertSame([], howto::find('+34 600 111 222', $centre, true), 'A phone is not a task.');
        $this->assertSame([], howto::find('xyzzy', $centre, true));
        // The settings only for whoever manages Eguahe.
        $this->assertNotContains('costs', array_column(howto::find('fees', $centre, false), 'key'));
    }

    /**
     * The answers as the sheet draws them: a task waits for what it needs; a setting says what it is now.
     */
    public function test_answers(): void {
        $this->resetAfterTest();
        $centre = $this->centre();
        $answers = howto::answers('print the poster', $centre, true);
        $this->assertSame('poster', $answers['answers'][0]['key']);
        $this->assertTrue($answers['answers'][0]['wait'], 'No event yet: it says what comes first.');
        $this->assertSame(get_string('howto_need_event', 'local_oksigeniaeguahe'), $answers['answers'][0]['sub']);
        $ai = howto::answers('artificial intelligence', $centre, true)['answers'];
        $setting = array_values(array_filter($ai, fn($a) => $a['setting']))[0] ?? null;
        $this->assertNotNull($setting);
        $this->assertSame(get_string('howto_value_off', 'local_oksigeniaeguahe'), $setting['value']);
        $this->assertSame([], howto::answers('', $centre, true)['answers'], 'Nothing asked, nothing answered.');
        $this->assertNotEmpty(howto::answers('xyzzy', $centre, true)['none']);
    }

    /**
     * Asking the AI of the centre: only with it on; the guide is its only source, the question goes without a phone or a
     * mail, and what comes back names the tasks it used and loses any link.
     */
    public function test_asks_the_ai(): void {
        $this->resetAfterTest();
        $centre = $this->centre();
        $asked = '';
        ai::$answer = function (string $prompt) use (&$asked) {
            $asked = $prompt;
            return "Open «Open days and visits» and press «A new event». See https://example.com\nTASKS: newevent, nothing";
        };
        $this->assertNull(howto::ask('make an open day', $centre, true, 2), 'Without the AI of the centre on, nothing.');
        $this->assertFalse(howto::answers('xyzzy', $centre, true)['ask']);
        centres::update((int) $centre->id, (object) ['aion' => 1]);
        $centre = centres::get((int) $centre->id);
        $this->assertNotFalse(howto::answers('xyzzy', $centre, true)['ask'], 'Nothing found: it offers the AI.');
        $reply = howto::ask('make an open day, call me at +34 600 111 222', $centre, true, 2);
        $this->assertStringContainsString('[newevent]', $asked, 'The guide goes with it.');
        $this->assertStringNotContainsString('600 111 222', $asked);
        $this->assertStringNotContainsString('https://', $reply['text']);
        $this->assertStringNotContainsString('TASKS', $reply['text']);
        $this->assertSame(['newevent'], array_column($reply['answers'], 'key'));
        ai::$answer = null;
    }

    /**
     * What it could not answer is kept by its words: no digits, no name of anybody of the site or of the centre.
     */
    public function test_misses_keep_no_names(): void {
        $this->resetAfterTest();
        $centre = $this->centre();
        global $DB;
        $id = households::create((int) $centre->id, (object) ['lang' => 'es']);
        $DB->insert_record(households::ADULTS, (object) ['centreid' => $centre->id, 'householdid' => $id,
            'firstname' => 'Ramona', 'lastname' => 'Quesada', 'timecreated' => 1, 'timemodified' => 1]);
        $DB->insert_record(households::CHILDREN, (object) ['centreid' => $centre->id, 'householdid' => $id,
            'firstname' => 'Bartolo', 'timecreated' => 1, 'timemodified' => 1]);
        howto::missed('Ramona Quesada xyzzy 2027', $centre, 1000);
        howto::missed('xyzzy', $centre, 2000);
        howto::missed('Bartolo', $centre, 3000);
        $misses = howto::misses((int) $centre->id);
        $this->assertCount(1, $misses);
        $this->assertSame('xyzzy', $misses[0]['question']);
        $this->assertSame(2, $misses[0]['times']);
    }
}
