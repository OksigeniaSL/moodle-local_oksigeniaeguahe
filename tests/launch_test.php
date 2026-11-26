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
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invitecheck;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\lookalike;

/**
 * The letter of an act launched by the team, without anybody at a console: stopped and resumed, never to an address of the
 * centre itself, once to two files of the same family, and the email permission given in the same step.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\invites
 */
#[\PHPUnit\Framework\Attributes\CoversClass(invites::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(invitecheck::class)]
final class launch_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $offer;

    /** @var \stdClass */
    protected $event;

    /**
     * A centre with its own domain, an offer and an open act to come.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'email' => 'admisiones@colegio-ejemplo.es']);
        $this->centre = centres::get($id);
        $this->offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $eventid = events::save($id, (object) ['kind' => 'other', 'name' => 'Respira',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]);
        $this->event = events::get($eventid, $id);
    }

    /**
     * A family with one child asking for a place, and its adults with their addresses and permissions.
     *
     * @param array $adults Each [first name, email, permission: true, false or null for none said].
     * @param string $child
     * @param string $birth
     * @return \stdClass
     */
    protected function family(array $adults, string $child = '', string $birth = ''): \stdClass {
        $centreid = (int) $this->centre->id;
        $household = households::get(households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $centreid);
        foreach ($adults as [$name, $email, $permission]) {
            $adult = households::add_adult($household, (object) ['firstname' => $name, 'lastname' => 'Ejemplo', 'email' => $email]);
            if ($permission !== null) {
                households::consent($household, $adult, 'email', $permission, 'form', 'es');
            }
        }
        $childid = households::add_child($household, $child !== '' ? $child : 'Hijo de ' . $adults[0][0], $this->offer);
        if ($birth !== '') {
            households::set_birthdate($household, $childid, $birth);
        }
        return households::get((int) $household->id, $centreid);
    }

    /**
     * Stopped before it reached everyone, it goes on with «Resume» to those it had not reached.
     */
    public function test_stop_and_resume(): void {
        $eventid = (int) $this->event->id;
        foreach (['ana', 'berta', 'carlos'] as $name) {
            $this->family([[$name, "$name@example.com", true]]);
        }
        $queued = invites::queue($this->event, $this->centre, 2, time(), '', null);
        $this->assertSame(3, $queued['email']);
        $this->assertSame(3, invites::on_the_way($eventid)[0]);
        $this->assertSame(3, invites::stop($eventid));
        $this->assertSame(0, invites::on_the_way($eventid)[0]);
        $this->assertCount(3, invites::paused($eventid));
        $this->assertSame(3, invites::resume($this->event, $this->centre, 2)['email']);
        $this->assertSame(3, invites::on_the_way($eventid)[0]);
        $this->assertSame([], invites::paused($eventid));
    }

    /**
     * An address of the centre itself never gets the letter: a family with another one gets it there; one with only those, none.
     * Two files that may be the same family get one letter, the older.
     */
    public function test_left_out_by_themselves(): void {
        $both = $this->family([['Marta', 'marta@colegio-ejemplo.es', true], ['Juan', 'juan@example.com', true]]);
        $only = $this->family([['Rosa', 'rosa@secretaria.colegio-ejemplo.es', true]]);
        $this->assertSame('juan@example.com', invites::letter_address($both, $this->centre));
        $older = $this->family([['Eva', 'eva@example.com', true]], 'Lucía', '2024-03-02');
        $newer = $this->family([['Pablo', 'pablo@example.com', true]], 'Lucía', '2024-03-02');
        lookalike::refresh((int) $this->centre->id, time() - lookalike::NEAR);
        $eligible = invites::eligible($this->event);
        $this->assertArrayHasKey((int) $both->id, $eligible);
        $this->assertArrayNotHasKey((int) $only->id, $eligible);
        $this->assertArrayHasKey((int) $older->id, $eligible);
        $this->assertArrayNotHasKey((int) $newer->id, $eligible);
        $out = invitecheck::left_out($this->event, $this->centre, $eligible);
        $this->assertSame([(int) $only->id], $out['centremail']);
        $this->assertSame([(int) $newer->id], $out['twin']);
        // A centre on a public provider: only its own address is of it, never the whole domain.
        $this->assertFalse(invites::of_centre_address('ana@gmail.com', ['domains' => [], 'emails' => ['colegio@gmail.com']]));
    }

    /**
     * Those left out only for never saying yes to email get it from a person, in the same step; never one who said no.
     */
    public function test_permit(): void {
        $none = $this->family([['Iris', 'iris@example.com', null]]);
        $no = $this->family([['Hugo', 'hugo@example.com', false]]);
        $this->assertSame([(int) $none->id, (int) $no->id], invitecheck::left_out($this->event, $this->centre, [])['nopermission']);
        $this->assertSame(1, invites::permit($this->event, $this->centre, 2));
        $eligible = invites::eligible($this->event);
        $this->assertArrayHasKey((int) $none->id, $eligible);
        $this->assertArrayNotHasKey((int) $no->id, $eligible);
    }
}
