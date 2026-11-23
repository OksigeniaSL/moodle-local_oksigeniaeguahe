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
use local_oksigeniaeguahe\local\emailcheck;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\signup;

/**
 * The address to test the entries of a centre (entries::TESTMAIL) goes through the forms: a domain that receives no mail
 * is mistyped, but that one is the test. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(signup::class)]
final class signup_testmail_test extends \advanced_testcase {
    /**
     * The test address is never refused for its domain; another one of a domain that cannot receive mail is.
     */
    public function test_testmail_passes(): void {
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($centreid);
        $data = (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => time() + DAYSECS,
            'timeend' => time() + DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1];
        $event = events::get(events::save($centreid, $data), $centreid);
        $form = fn(string $email) => ['adults' => [['firstname' => 'Prueba', 'lastname' => 'Eguahe', 'email' => $email,
            'phone' => '']], 'children' => [['firstname' => 'Leo', 'offerid' => 0, 'birthdate' => '']], 'people' => 1,
            'slotid' => 0, 'slot' => 0, 'answers' => [], 'consents' => []];
        // No domain receives mail here: the test address goes through all the same, and only it.
        emailcheck::$testreachable = false;
        try {
            $this->assertArrayNotHasKey('a0email', signup::check($form(entries::TESTMAIL), $event, $centre, 'es'));
            $this->assertArrayHasKey('a0email', signup::check($form('nadie@no-recibe.invalid'), $event, $centre, 'es'));
        } finally {
            emailcheck::$testreachable = null;
        }
    }
}
