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
use local_oksigeniaeguahe\local\howto;

/**
 * «How is it done?», measured: a bank of phrasings of lost people of the team in Spanish (of Spain and of America),
 * English and Portuguese (of Portugal and of Brazil), written without seeing the search (tests/fixtures/howto/bank-*.jsonl),
 * each with the task or setting (set:<key>) that answers it, or none (a name, a phone, a thing of another tool). A third
 * of it (crc32 of the phrase % 3 == 0) is held out: it is never looked at to tune the words, only counted, so that it says
 * how the search does with what nobody has seen.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\howto
 */
#[\PHPUnit\Framework\Attributes\CoversClass(howto::class)]
final class howto_bank_test extends \advanced_testcase {
    /** @var string[][] What also answers a need: the card of a setting that holds another, or a task beside it. */
    private const ALSO = ['set:replydays' => ['set:contact'], 'set:guide' => ['set:miniature'],
        'set:team' => ['set:hosts'], 'share' => ['poster'], 'eventlabels' => ['familylabels'],
        'familylabels' => ['eventlabels']];

    /** @var float[] How much of each part the first answer has to get right, at least. */
    private const AT_LEAST = ['tune' => 0.88, 'held' => 0.72];

    /**
     * The first answer is the right one for most of the bank, in both parts, in the three languages together.
     */
    public function test_bank(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        // The plugin brings its strings in its languages; Moodle only reads them for a language it knows, so a site
        // without their packs (the CI) gets the least of each: its name.
        foreach (['es', 'pt', 'pt_br'] as $lang) {
            if (!get_string_manager()->translation_exists($lang, false)) {
                make_writable_directory($CFG->dataroot . '/lang/' . $lang);
                file_put_contents($CFG->dataroot . '/lang/' . $lang . '/langconfig.php', "<?php\n\$string['thislanguage'] = '"
                    . $lang . "';\n\$string['thislanguageint'] = '" . $lang . "';\n\$string['parentlanguage'] = '';\n");
            }
        }
        get_string_manager()->reset_caches(true);
        $centre = centres::get(centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']));
        $right = ['tune' => 0, 'held' => 0];
        $all = ['tune' => 0, 'held' => 0];
        $dir = __DIR__ . '/fixtures/howto';
        foreach (['es', 'en', 'pt'] as $file) {
            foreach (file($dir . "/bank-$file.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode($line, true);
                force_current_language($file === 'pt' ? ($row['region'] === 'PT' ? 'pt' : 'pt_br') : $file);
                $keys = array_map(
                    fn($f) => ($f['kind'] === 'setting' ? 'set:' : '') . $f['key'],
                    howto::find($row['q'], $centre, true)
                );
                $need = $row['need'];
                $good = $need === 'none' ? !$keys : in_array($keys[0] ?? '', array_merge([$need], self::ALSO[$need] ?? []), true);
                $part = crc32($row['q']) % 3 === 0 ? 'held' : 'tune';
                $all[$part]++;
                $right[$part] += $good ? 1 : 0;
            }
        }
        force_current_language('en');
        foreach ($right as $part => $ok) {
            $this->assertGreaterThanOrEqual(self::AT_LEAST[$part], $ok / $all[$part], $part . ': ' . $ok . '/' . $all[$part]);
        }
    }
}
