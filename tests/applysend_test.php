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
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\signup;

/**
 * How to ask for a place, sent by the team when a family is ready: at a centre that does not offer it after the visit,
 * the family's page shows it only once it was sent; the message comes written with the centre's words and address.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\households
 * @covers     \local_oksigeniaeguahe\local\familypage
 * @covers     \local_oksigeniaeguahe\local\panel_household
 */
#[\PHPUnit\Framework\Attributes\CoversClass(households::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(familypage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_household::class)]
final class applysend_test extends \advanced_testcase {
    /**
     * Resets the widget against spam.
     */
    protected function tearDown(): void {
        antispam::$testpass = null;
        parent::tearDown();
    }

    /**
     * A family that came: with the centre offering it after the visit, its page says how to ask for a place; with the
     * centre that sends it, only after the team sent it; and the message says how, until when and where.
     */
    public function test_sent_by_the_team(): void {
        global $DB;
        $this->resetAfterTest();
        antispam::$testpass = true;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'email' => 'admisiones@example.com']);
        centres::update($id, (object) ['applyhow' => 'Rellenen la solicitud de matrícula.',
            'applyurl' => 'https://matricula.example.com/solicitud', 'applyuntil' => time() + 30 * DAYSECS]);
        $offerid = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'timestart' => time() - 3 * HOURSECS, 'timeend' => time() - HOURSECS, 'capacity' => 0, 'open' => 1]);
        $event = events::get($eventid, $id);
        $data = [
            'adults' => [['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@example.com', 'phone' => '']],
            'children' => [['firstname' => 'Leonardo', 'tagname' => 'Leo', 'offerid' => $offerid]],
            'people' => 2, 'slot' => 0, 'incentre' => '', 'municipality' => '', 'municipalityother' => '',
            'heardfrom' => '', 'message' => '', 'consents' => ['email' => 1], 'altcha' => 'x',
        ];
        [$household, $token] = signup::save($data, $event, centres::get($id), 'es');
        $signup = $DB->get_record(events::SIGNUPS, ['eventid' => $eventid, 'householdid' => $household->id]);
        events::attend($event, (int) $signup->id, true, time() - 2 * HOURSECS);
        // The page puts its band where it goes (familypage::arrange()): look for it anywhere on the page.
        $offered = fn() => strpos(json_encode(familypage::context(
            households::get((int) $household->id, $id),
            centres::get($id),
            'es',
            $token,
            false
        )), '"isapply":true') !== false;

        // Offered after the visit, as every centre did until now.
        $this->assertTrue($offered());
        // A centre where the team sends it: nothing until it is sent.
        centres::update($id, (object) ['applyafter' => 0]);
        $this->assertFalse($offered());
        households::apply_sent(households::get((int) $household->id, $id));
        $this->assertTrue($offered());

        // The message, written with the centre's words, until when and where.
        $text = panel_household::apply_text(
            households::get((int) $household->id, $id),
            households::full(households::get((int) $household->id, $id)),
            centres::get($id)
        );
        $this->assertStringContainsString('Marta', $text);
        $this->assertStringContainsString('Leo', $text);
        $this->assertStringContainsString('Rellenen la solicitud de matrícula.', $text);
        $this->assertStringContainsString('https://matricula.example.com/solicitud', $text);
    }
}
