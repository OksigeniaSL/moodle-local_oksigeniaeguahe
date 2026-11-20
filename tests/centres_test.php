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
use local_oksigeniaeguahe\local\grades;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publicpage;

/**
 * Who works for which centre: nobody of one sees the families of another.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\centres
 */
#[\PHPUnit\Framework\Attributes\CoversClass(centres::class)]
final class centres_test extends \advanced_testcase {
    /**
     * Team members work for their centres only; whoever sets them up sees them all.
     */
    public function test_teams(): void {
        global $DB;
        $this->resetAfterTest();
        $one = centres::create((object) ['name' => 'Uno']);
        $two = centres::create((object) ['name' => 'Dos']);
        $worker = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniaeguahe:work', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $worker->id, \context_system::instance());
        $this->assertSame([], centres::for_user($worker->id));

        centres::add_member($one, $worker->id);
        centres::add_member($one, $worker->id);
        $this->assertSame(1, $DB->count_records(centres::MEMBERS));
        $this->assertTrue(centres::can_work($one, $worker->id));
        $this->assertFalse(centres::can_work($two, $worker->id));

        $this->setAdminUser();
        $this->assertCount(2, centres::for_user(get_admin()->id));

        centres::remove_member($one, $worker->id);
        $this->assertFalse(centres::can_work($one, $worker->id));
    }

    /**
     * Whoever only sees, in the team of a centre, sees it but may not act; whoever works may.
     */
    public function test_view_only(): void {
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Uno']);
        $system = \context_system::instance();
        $roles = [];
        foreach (['view', 'work'] as $cap) {
            $user = $this->getDataGenerator()->create_user();
            $role = $this->getDataGenerator()->create_role();
            assign_capability('local/oksigeniaeguahe:' . $cap, CAP_ALLOW, $role, $system);
            role_assign($role, $user->id, $system);
            centres::add_member($centreid, $user->id);
            $roles[$cap] = $user;
        }
        $this->assertTrue(centres::can_work($centreid, $roles['view']->id));
        $this->assertFalse(centres::can_act($roles['view']->id));
        $this->assertTrue(centres::can_act($roles['work']->id));
        $this->setUser($roles['view']);
        $this->expectException(\required_capability_exception::class);
        centres::require_act($centreid);
    }

    /**
     * Cifras for the whole team, or only for its management: whoever changes the settings and whoever only looks.
     */
    public function test_metrics_for(): void {
        global $DB;
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Uno']);
        $system = \context_system::instance();
        $users = [];
        foreach (['view', 'work', 'head'] as $who) {
            $user = $this->getDataGenerator()->create_user();
            $role = $this->getDataGenerator()->create_role();
            assign_capability('local/oksigeniaeguahe:' . ($who === 'view' ? 'view' : 'work'), CAP_ALLOW, $role, $system);
            role_assign($role, $user->id, $system);
            centres::add_member($centreid, $user->id);
            $users[$who] = (int) $user->id;
        }
        $DB->set_field(centres::MEMBERS, 'admin', 1, ['centreid' => $centreid, 'userid' => $users['head']]);
        $outsider = (int) $this->getDataGenerator()->create_user()->id;
        $sees = fn() => array_map(
            fn($id) => centres::can_see_metrics(centres::get($centreid), $id),
            $users + ['outsider' => $outsider]
        );
        $this->assertSame(['view' => true, 'work' => true, 'head' => true, 'outsider' => false], $sees());
        centres::update($centreid, (object) ['metricsfor' => 'heads']);
        $this->assertSame('heads', centres::get($centreid)->metricsfor);
        $this->assertSame(['view' => true, 'work' => false, 'head' => true, 'outsider' => false], $sees());
        $this->setUser($users['work']);
        $this->expectException(\required_capability_exception::class);
        centres::require_metrics($centreid);
    }

    /**
     * A family is found again only in its own centre.
     */
    public function test_find_by_centre(): void {
        $this->resetAfterTest();
        $one = centres::create((object) ['name' => 'Uno']);
        $two = centres::create((object) ['name' => 'Dos']);
        $household = households::get(households::create($one, (object) ['lang' => 'es']), $one);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'phone' => '+34600111222']);
        $this->assertEquals($household->id, households::find($one, null, '+34600111222'));
        $this->assertNull(households::find($two, null, '+34600111222'));
        $this->assertNull(households::get($household->id, $two));
    }

    /**
     * A new centre takes the country of the site, so its school systems and its stages are there from the start; one
     * given its country keeps it, and a site without one leaves it to the centre.
     */
    public function test_site_country(): void {
        $this->resetAfterTest();
        set_config('country', '');
        $none = centres::get(centres::create((object) ['name' => 'Academia sin país', 'lang' => 'es']));
        $this->assertEmpty($none->country);
        $this->assertSame([], grades::stages($none));
        set_config('country', 'ES');
        $site = centres::get(centres::create((object) ['name' => 'Colegio del sitio', 'lang' => 'es']));
        $this->assertSame('ES', $site->country);
        $this->assertNotEmpty(grades::stages($site));
        $own = centres::get(centres::create((object) ['name' => 'Escola própria', 'lang' => 'pt', 'country' => 'PT']));
        $this->assertSame('PT', $own->country);
    }

    /**
     * The area of a centre, with and without codes.
     */
    public function test_municipalities(): void {
        $list = centres::municipalities((object) ['municipalities' => "28065;Getafe\nPinto\n\n"]);
        $this->assertSame('Getafe', $list['28065']);
        $this->assertContains('Pinto', $list);
        $this->assertCount(2, $list);
    }

    /**
     * The brand of a centre, whole, on the pages of its families: its main colour comes from its brand, every colour
     * that carries words reads on every ground, by day and at night, and a word of one letter never ends a title.
     */
    public function test_brand_pages(): void {
        $this->resetAfterTest();
        // A teal centre with a burgundy and gold brand: its pages are burgundy; without a brand, its own teal.
        $id = centres::create((object) ['name' => 'Colegio Montesol', 'colour' => '#2f5d62', 'brandcolours' => '#7a1f3d #c9a227']);
        $this->assertSame('#7a1f3d', centres::colour(centres::get($id)));
        $this->assertSame('#2f5d62', centres::colour((object) ['colour' => '#2f5d62', 'brandcolours' => '']));
        $this->assertSame('#164281', centres::colour((object) ['colour' => '#164281',
            'brandcolours' => '#164281 #067e36 #fbbe17 #ce1423']));
        // Only pale colours: the first, and the titles darkened until they read.
        $this->assertSame('#f4a6c1', centres::colour((object) ['colour' => '', 'brandcolours' => '#f4a6c1 #a8dadc']));
        foreach (
            [['#164281', '#067e36', '#fbbe17', '#ce1423'], ['#7a1f3d', '#c9a227'], ['#f4a6c1', '#a8dadc', '#ffe08a'],
                ['#00796b']] as $brand
        ) {
            foreach ([false, true] as $dark) {
                $v = publicpage::brand_vars($brand, $brand[0], $dark);
                foreach (['--m-papel', '--m-pared', '--m-superficie', '--m-relleno'] as $ground) {
                    $this->assertGreaterThanOrEqual($dark ? 7 : 4.5, publicpage::contrast($v['--m-titulo'], $v[$ground]));
                    $this->assertGreaterThanOrEqual(4.5, publicpage::contrast($v['--m-acento'], $v[$ground]));
                    $this->assertGreaterThanOrEqual(4.5, publicpage::contrast($v['--m-tinta-2'], $v[$ground]));
                    $this->assertGreaterThanOrEqual(3, publicpage::contrast($v['--m-raya'], $v[$ground]));
                }
                // The white of its signs by day; a dark of its own at night.
                if ($dark) {
                    $this->assertGreaterThan(15, publicpage::contrast($v['--m-papel'], '#FFFFFF'));
                } else {
                    $this->assertSame('#FFFFFF', $v['--m-papel']);
                }
            }
            // The stripe at night shows on the dark paper of the brand.
            $night = publicpage::brand_on_dark((object) ['colour' => $brand[0], 'brandcolours' => implode(' ', $brand)]);
            $paper = publicpage::brand_vars($brand, $brand[0], true)['--m-papel'];
            foreach ($night as $code) {
                $this->assertGreaterThanOrEqual(3, publicpage::contrast($code, $paper));
            }
        }
        // A word of one letter is tied to the next one in a title, never elsewhere nor inside a tag.
        $this->assertSame(
            "<h1 class=\"a b\">Vengan a\u{00A0}conocernos</h1><p>Vengan a conocernos</p>",
            publicpage::no_lone_letters('<h1 class="a b">Vengan a conocernos</h1><p>Vengan a conocernos</p>')
        );
        $this->assertSame(
            "<legend>Hermanos y\u{00A0}hermanas <span>(si quieren)</span></legend>",
            publicpage::no_lone_letters('<legend>Hermanos y hermanas <span>(si quieren)</span></legend>')
        );
    }
}
