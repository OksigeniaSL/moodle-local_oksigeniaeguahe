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

use local_oksigeniaeguahe\local\arrival;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\notices;
use local_oksigeniaeguahe\local\outs;
use local_oksigeniaeguahe\local\ties;
use local_oksigeniaeguahe\local\who;

/**
 * The words that are chosen by a value of a list: every value has its own, so that no screen shows its key.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\lists
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lists::class)]
final class strings_test extends \advanced_testcase {
    /**
     * Each list with the beginning of its strings.
     */
    public function test_lists(): void {
        $manager = get_string_manager();
        $lists = [
            'mail_name_' => familymail::KINDS,
            'notice_why_' => notices::REASONS,
            'out_' => array_merge(outs::NEVER, outs::LEFT),
            'out_stops_' => outs::STOPS,
            'via_' => lists::CHANNELS,
            'stage_' => lists::STAGES,
            'closed_' => lists::CLOSED_REASONS,
            'arrival_channel_' => arrival::CHANNELS,
            'tie_' => ties::KINDS,
            'who_' => who::KINDS,
        ];
        foreach ($lists as $prefix => $values) {
            foreach ($values as $key => $value) {
                $value = is_string($key) ? $key : $value;
                $this->assertTrue($manager->string_exists($prefix . $value, 'local_oksigeniaeguahe'), $prefix . $value);
            }
        }
    }
}
