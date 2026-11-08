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

use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\panel_start;
use local_oksigeniaeguahe\local\settled;

/**
 * After «Enrolled»: the household in the centre, out of the inbox until something happens; its page saying «All set»
 * with whoever carries them from now on, the last question and the offer to tell another family how it went; and the
 * first day, with who started. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(settled::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_start::class)]
final class settled_test extends \advanced_testcase {
    /**
     * A centre with two courses and a public team of two, and a family with Leo enrolled and Noa still asking.
     *
     * @return array [centre, household, Leo, Noa, offers by name, members by first name]
     */
    protected function family(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offers = [];
        foreach (['Infantil 3 años' => 25, '1.º de Primaria' => 4] as $name => $places) {
            $offers[$name] = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => $name,
                'places' => $places, 'active' => 1, 'sortorder' => 0]);
        }
        $members = [];
        foreach (['Lucía' => 'Medina', 'Pablo' => 'Sosa'] as $first => $last) {
            $user = $this->getDataGenerator()->create_user(['firstname' => $first, 'lastname' => $last]);
            centres::add_member($id, (int) $user->id);
            $member = $DB->get_record(centres::MEMBERS, ['centreid' => $id, 'userid' => $user->id]);
            $DB->update_record(centres::MEMBERS, (object) ['id' => $member->id, 'public' => 1, 'rolename' => 'Tutoría']);
            $members[$first] = (int) $member->id;
        }
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@example.com']);
        households::add_adult($household, (object) ['firstname' => 'Andrés', 'lastname' => 'Gil', 'email' => '']);
        $leo = households::add_child($household, 'Leonardo', $offers['Infantil 3 años'], 'Leo');
        $noa = households::add_child($household, 'Noa', $offers['1.º de Primaria']);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $leo]), 'enrolled');
        return [centres::get($id), households::get((int) $household->id, $id), $leo, $noa, $offers, $members];
    }

    /**
     * Who carries them from now on: the person of the course, else the one of the centre, else the place, else nobody;
     * only people families see.
     */
    public function test_handover(): void {
        global $DB;
        [$centre, $household, , , $offers, $members] = $this->family();
        $children = array_values(households::full($household)->children);
        $this->assertTrue(settled::in_centre($children));
        $this->assertCount(1, settled::enrolled($children));
        $this->assertNull(settled::handover($centre, $children), 'Nobody named: the page says the team.');

        centres::update((int) $centre->id, (object) ['name' => $centre->name, 'lang' => 'es', 'handover' => 0,
            'handoverplace' => 'Secretaría de alumnos, 922 000 000', 'handoverhow' => 'Por la plataforma del centro.']);
        $centre = centres::get((int) $centre->id);
        $this->assertSame(['place' => 'Secretaría de alumnos, 922 000 000'], settled::handover($centre, $children));

        centres::update((int) $centre->id, (object) ['name' => $centre->name, 'lang' => 'es', 'handover' => $members['Pablo'],
            'handoverplace' => '', 'handoverhow' => '']);
        $centre = centres::get((int) $centre->id);
        $this->assertSame('Pablo', settled::handover($centre, $children)['person']->first);

        // The tutor of the course goes first.
        $DB->set_field(centres::OFFERS, 'handover', $members['Lucía'], ['id' => $offers['Infantil 3 años']]);
        $this->assertSame('Lucía', settled::handover($centre, $children)['person']->first);
        // Someone families do not see is no one to show.
        $DB->set_field(centres::MEMBERS, 'public', 0, ['id' => $members['Lucía']]);
        $this->assertSame('Pablo', settled::handover($centre, $children)['person']->first);
    }

    /**
     * The page of the family once enrolled: «All set», where Leo has a place, the last question for whoever attends
     * them, the offer as a sentence with a yes; then the yes kept, with a line in the history and a promise.
     */
    public function test_family_page(): void {
        global $DB;
        [$centre, $household, , , , $members] = $this->family();
        $DB->set_field(centres::T, 'campaignstart', time() - 30 * DAYSECS, ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $lucia = $DB->get_field(centres::MEMBERS, 'userid', ['id' => $members['Lucía']]);
        $DB->set_field(households::T, 'ownerid', $lucia, ['id' => $household->id]);
        $household = households::get((int) $household->id, (int) $centre->id);

        $page = familypage::context($household, $centre, 'es', 'clave', false);
        $this->assertSame('Ya está', $page['greeting']);
        $this->assertSame('', $page['names']);
        $this->assertSame('Leo tiene su sitio en Infantil 3 años.', $page['lead']);
        $this->assertTrue($page['pegs']['came']);
        $this->assertSame('Desde ahora les acompaña el equipo del centro.', $page['settled']['team']);
        $this->assertSame('Gracias por venir, Marta y Andrés', $page['settled']['thanks']['text']);
        $this->assertSame('settledask', $page['wallask']['do']);
        $this->assertSame('¿Qué les quedó por preguntar antes del primer día?', $page['wallask']['title']);
        $this->assertStringContainsString('Lucía', $page['wallask']['button']);
        $this->assertFalse($page['howitwent']);
        $this->assertSame('Sí, con gusto', $page['settled']['volunteer']['yes']);

        // They ask: it waits for an answer, and the page says it was passed on.
        $this->assertTrue(settled::ask($household, '¿Qué llevan el primer día?'));
        $this->assertFalse(settled::ask($household, '  '));
        $this->assertTrue(settled::asked((int) $household->id));
        $this->assertGreaterThan(0, (int) $DB->get_field(households::T, 'waitingsince', ['id' => $household->id]));

        // They say yes: once.
        $now = time();
        $this->assertTrue(settled::volunteer($household, $centre, $now));
        $this->assertFalse(settled::volunteer($household, $centre, $now));
        $this->assertSame(1, settled::volunteers((int) $centre->id));
        $promise = attend::open_promise((int) $household->id);
        $this->assertNotNull($promise);
        $this->assertStringContainsString('cuenten a otra familia', $promise->body);
        $this->assertGreaterThan($now, (int) $promise->dueday);
        $this->assertLessThan($now + 340 * DAYSECS, (int) $promise->dueday, 'About a year after the campaign began.');
        $this->assertContains(
            'Se ofrecen a contarle a otra familia cómo fue, el curso que viene.',
            $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ?', [$household->id])
        );

        $page = familypage::context(households::get((int) $household->id, (int) $centre->id), $centre, 'es', 'clave', false);
        $this->assertFalse($page['settled']['volunteer']);
        $this->assertNotSame('', $page['settled']['volunteered']);
        $this->assertSame('¿Algo más?', $page['wallask']['title']);
        $this->assertNotSame('', $page['wallask']['sent']);

        // Before enrolling, none of this.
        $DB->set_field(households::CHILDREN, 'stage', 'visit', ['householdid' => $household->id]);
        $page = familypage::context(households::get((int) $household->id, (int) $centre->id), $centre, 'es', 'clave', false);
        $this->assertFalse($page['settled']);
        $this->assertNotSame('Ya está', $page['greeting']);
    }

    /**
     * In the inbox, the enrolled family behind the line, «In the centre», after the answered ones; it comes up when it
     * writes.
     */
    public function test_inbox(): void {
        [$centre, $household] = $this->family();
        $other = households::get(
            households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']),
            (int) $centre->id
        );
        households::add_child($other, 'Vera', 0);
        $days = panel_inbox::days(households::inbox((int) $centre->id, false), (int) $centre->id, time());
        $this->assertSame(['contestadas', 'encentro'], array_column($days, 'kind'));
        $this->assertSame((string) $household->id, $days[1]['rows'][0]['id']);

        settled::ask($household, '¿A qué hora es la entrada?');
        $days = panel_inbox::days(households::inbox((int) $centre->id, false), (int) $centre->id, time());
        $this->assertSame('espera', $days[0]['kind']);
        $this->assertNotContains('encentro', array_column($days, 'kind'));
    }

    /**
     * The first day: the enrolled children of the school year by course, «Started» at one touch and undone, and the
     * count once anyone is marked.
     */
    public function test_first_day(): void {
        global $DB;
        [$centre, $household, $leo, $noa] = $this->family();
        $centreid = (int) $centre->id;
        $this->assertSame('2027-28', settled::year($centreid));
        $this->assertSame([], settled::started($centreid, '2027-28'));
        $context = panel_start::context($centre, true);
        $this->assertCount(1, $context['groups']);
        $this->assertSame('Leo', $context['groups'][0]['rows'][0]['tag']);
        $this->assertFalse($context['groups'][0]['rows'][0]['started']);
        $this->assertStringContainsString('Marta Ruiz', $context['groups'][0]['rows'][0]['adults']);

        $this->assertFalse(settled::start($centreid, $noa, true, time()), 'Noa is not enrolled.');
        $this->assertTrue(settled::start($centreid, $leo, true, time()));
        $this->assertSame(['enrolled' => 1, 'started' => 1], settled::started($centreid, '2027-28'));
        $context = panel_start::context($centre, true);
        $this->assertTrue($context['groups'][0]['rows'][0]['started']);
        $this->assertSame(
            get_string('panel_firstday_of', 'local_oksigeniaeguahe', (object) ['started' => 1, 'enrolled' => 1]),
            $context['groups'][0]['count']
        );
        $this->assertStringContainsString('start.php', panel_start::link($centreid)['url']);

        $this->assertTrue(settled::start($centreid, $leo, false, time()));
        $this->assertSame(0, (int) $DB->get_field(households::CHILDREN, 'started', ['id' => $leo]));
        $this->assertSame([], settled::started($centreid, '2027-28'));
    }
}
