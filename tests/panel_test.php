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
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\messaging;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_event;
use local_oksigeniaeguahe\local\panel_eventform;
use local_oksigeniaeguahe\local\panel_events;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_inbox;

/**
 * The panel of the team: the time in words, the rail of the places, the names of the adults, and what the inbox and the
 * file of a household show. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(panel::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attend::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_inbox::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_household::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_events::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_event::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_eventform::class)]
final class panel_test extends \advanced_testcase {
    /**
     * Who looks, in a time zone of their own.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('timezone', 'Europe/Madrid');
        set_config('forcetimezone', 'Europe/Madrid');
    }

    /**
     * How long someone has waited, in words, from minutes to weeks.
     */
    public function test_wait_words(): void {
        $now = make_timestamp(2026, 11, 8, 12, 0);
        $words = panel::wait_words($now - 40 * MINSECS, $now);
        $this->assertSame(
            ['40 minutes ago', 'today, ' . panel::hour($now - 40 * MINSECS), 'for 40 minutes'],
            [$words['main'], $words['sub'], $words['since']]
        );
        $this->assertSame('3 hours ago', panel::wait_words($now - 3 * HOURSECS, $now)['main']);
        $yesterday = make_timestamp(2026, 11, 7, 19, 5);
        $this->assertSame('Since yesterday', panel::wait_words($yesterday, $now)['main']);
        $this->assertSame('since yesterday at ' . panel::hour($yesterday), panel::wait_words($yesterday, $now)['since']);
        $thursday = make_timestamp(2026, 11, 5, 18, 40);
        $words = panel::wait_words($thursday, $now);
        $this->assertSame('Since ' . userdate($thursday, '%A'), $words['main']);
        $this->assertSame('3 days ago', $words['sub']);
        $longago = make_timestamp(2026, 10, 26, 10, 0);
        $this->assertSame(
            'Since ' . userdate($longago, get_string('strftimedateshort', 'langconfig')),
            panel::wait_words($longago, $now)['main']
        );
        // When something happened, in words too.
        $this->assertSame('yesterday at ' . panel::hour($yesterday), panel::when_words($yesterday, $now));
        $this->assertSame('on ' . userdate($thursday, '%A') . ' at ' . panel::hour($thursday), panel::when_words($thursday, $now));
        $this->assertStringStartsNotWith('0', panel::hour(make_timestamp(2026, 11, 8, 8, 15)));
    }

    /**
     * The places of a course: a knob per place and a backpack on each one taken; with many, a band.
     */
    public function test_rail(): void {
        $svg = panel::rail(25, 6);
        $this->assertSame(25, substr_count($svg, 'class="r-pomo"'));
        $this->assertSame(6, substr_count($svg, 'class="r-mochila"'));
        $this->assertStringContainsString('aria-label="6 of 25 places taken"', $svg);
        $many = panel::rail(120, 30);
        $this->assertStringContainsString('class="r-franja"', $many);
        $this->assertSame(13, substr_count($many, 'class="r-pomo"'));
        $this->assertSame(4, substr_count(panel::rail(4, 9), 'class="r-mochila"'));
    }

    /**
     * The adults of a household in a line: the first surname, with its particles.
     */
    public function test_adults_line(): void {
        $adults = [
            (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz Cabrera'],
            (object) ['firstname' => 'Mohamed', 'lastname' => 'El Amrani Haddou'],
            (object) ['firstname' => 'Ana', 'lastname' => 'de la Torre Gil'],
        ];
        $this->assertSame('Marta Ruiz, Mohamed El Amrani and Ana de la Torre', panel::adults_line($adults));
        $this->assertSame(get_string('household_noname', 'local_oksigeniaeguahe'), panel::adults_line([]));
    }

    /**
     * The inbox and the file of a family that just wrote: it waits, with what it said, and the way to answer it.
     */
    public function test_inbox_and_file(): void {
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($centreid);
        centres::save_offer($centreid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1, 'sortorder' => 0]);
        $entry = entries::get(entries::create($centreid, 'hook', 'Web a medida'), $centreid);
        [$household] = entries::receive($entry, ['Nombre y apellidos del padre, madre o tutor' => 'Marta Ruiz Cabrera',
            'Correo electrónico' => 'marta@example.com', 'Nombre del alumno' => 'Leonardo',
            'Curso que solicita' => 'Infantil 3 años', 'Mensaje' => '¿Tienen comedor?'], 'x1');

        $inbox = panel_inbox::context($centre, false, (int) get_admin()->id, true);
        $this->assertSame(get_string('inbox_waiting_one', 'local_oksigeniaeguahe'), $inbox['head']['title']);
        // By the rule of time: one day, today, and the family under it.
        $this->assertCount(1, $inbox['days']);
        $this->assertSame(get_string('panel_day_today', 'local_oksigeniaeguahe'), $inbox['days'][0]['day']);
        $row = $inbox['days'][0]['rows'][0];
        $this->assertTrue($row['waiting']);
        $this->assertNull($row['owner']);
        $this->assertSame('fichas', $inbox['density']);
        $this->assertSame(1, $inbox['path']['total']);
        $this->assertSame(1, $inbox['path']['steps'][0]['n']);
        $this->assertSame(['waiting' => 1, 'adults' => 'Marta Ruiz'], ['waiting' => panel_inbox::check($centre)['waiting'],
            'adults' => panel_inbox::check($centre)['latest']['adults']]);
        // A filter by a course nobody asked for leaves the list empty.
        $this->assertFalse(panel_inbox::context($centre, false, (int) get_admin()->id, true, ['stage' => 'enrolled'])['hasrows']);
        $this->assertSame('Marta Ruiz', $row['adults']);
        $this->assertStringContainsString('marta@example.com', $row['search']);
        $this->assertSame('Infantil 3 años', $row['children'][0]['course']);
        $this->assertSame('', $row['children'][0]['stage']);
        $this->assertSame(1, $inbox['places'][0]['interested']);
        $this->assertSame(25, $inbox['places'][0]['free']);

        $file = panel_household::context($household, $centre, true);
        $this->assertSame('Marta Ruiz', $file['head']['title']);
        $this->assertTrue($file['history'][0]['family']);
        $this->assertStringContainsString('Web a medida', $file['history'][0]['how']);
        $this->assertSame(get_string('panel_greeting', 'local_oksigeniaeguahe', 'Marta') . ' ', $file['composer']['greeting']);
        $this->assertTrue($file['relay']);
        $this->assertNull($file['owner']);
        $this->assertTrue($file['hasuntold']);
        // By email, chosen first, or by a call; the phone app only with Oksigenia Marandu, and not joined yet.
        $ways = array_column($file['composer']['ways'], 'checked', 'value');
        $this->assertSame((messaging::available() ? ['mobile' => false] : []) + ['email' => true, 'phone' => false], $ways);
        $steps = $file['children'][0]['steps'];
        $this->assertCount(5, $steps);
        $this->assertTrue($steps[0]['here']);

        // Answered: it no longer waits, and the inbox says who answered last.
        households::answered($household, (int) get_admin()->id, 'phone', 'Les llamamos.');
        $inbox = panel_inbox::context($centre, false, (int) get_admin()->id, true);
        $this->assertSame(get_string('inbox_empty_waiting', 'local_oksigeniaeguahe'), $inbox['head']['title']);
        $this->assertStringContainsString('Admin', $inbox['head']['sub']);
        $all = panel_inbox::context($centre, true, (int) get_admin()->id, true);
        $this->assertSame('contestadas', $all['days'][0]['kind']);
        $this->assertFalse($all['days'][0]['rows'][0]['waiting']);
        $this->assertSame(get_string('panel_answered', 'local_oksigeniaeguahe'), $all['days'][0]['rows'][0]['stamp']['top']);
        $file = panel_household::context(households::get((int) $household->id, $centreid), $centre, true);
        $this->assertSame('Admin User', $file['answered']['who']);
    }

    /**
     * The open days and the door: what comes next, how full it is, and who came, marked and undone.
     */
    public function test_events_and_door(): void {
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($centreid);
        $start = time() + 10 * DAYSECS;
        $eventid = events::save($centreid, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'timestart' => $start, 'timeend' => $start + 3 * HOURSECS, 'capacity' => 120, 'open' => 1,
            'place' => 'Entrada principal']);
        $event = events::get($eventid, $centreid);
        $household = households::get(
            households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'openday']),
            $centreid
        );
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz Cabrera',
            'email' => 'marta@example.com', 'phone' => '']);
        households::add_child($household, 'Leonardo', 0, 'Leo');
        $signupid = events::sign_up($event, (int) $household->id, 3);

        $list = panel_events::context($centre, true);
        $this->assertStringContainsString('Jornada de puertas abiertas', $list['head']['title']);
        $this->assertSame(1, count($list['ahead']));
        $this->assertSame(3, $list['ahead'][0]['percent']);
        $this->assertFalse($list['haspast']);

        $door = panel_event::context(events::get($eventid, $centreid), $centre, true);
        $this->assertSame([0, 1], [$door['came'], $door['households']]);
        $signup = $door['groups'][0]['signups'][0];
        $this->assertSame('Leo', $signup['children'][0]['tag']);
        $this->assertFalse($signup['came']);
        events::attend($event, $signupid, true);
        $door = panel_event::context(events::get($eventid, $centreid), $centre, true);
        $this->assertSame(1, $door['came']);
        $this->assertTrue($door['groups'][0]['signups'][0]['came']);
        events::attend($event, $signupid, false);
        $door = panel_event::context(events::get($eventid, $centreid), $centre, true);
        $this->assertSame(0, $door['came']);
    }

    /**
     * Creating and changing an event: the values as the form writes them, the turns that go and those that stay, and a
     * change that reaches the families signed up, shown before it is saved and then told by mail.
     */
    public function test_eventform(): void {
        global $DB;
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($centreid);
        $new = panel_eventform::values(null, make_timestamp(2026, 11, 1, 12));
        $this->assertSame(['2026-11-15', '10:00', '13:00', 'openday'], [$new['day'], $new['start'], $new['end'], $new['kind']]);
        $this->assertSame(['name'], array_keys(panel_eventform::errors(['name' => ''] + $new)));
        $this->assertSame(['when'], array_keys(panel_eventform::errors(['end' => '09:00'] + $new)));

        // A visit by turns: two turns, a family in the first one.
        $values = ['name' => 'Visita por turnos', 'kind' => 'visit', 'place' => 'La entrada de Infantil',
            'slots' => [['id' => 0, 'start' => '10:00', 'minutes' => 45, 'capacity' => '8'],
                ['id' => 0, 'start' => '10:45', 'minutes' => 45, 'capacity' => '']]] + $new;
        $eventid = events::save($centreid, panel_eventform::data($values, $new));
        $event = events::get($eventid, $centreid);
        $old = panel_eventform::values($event);
        $this->assertSame(['10:00', '11:30'], [$old['start'], $old['end']]);
        $this->assertSame(['10:00', '10:45'], array_column($old['slots'], 'start'));
        $household = households::get(
            households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'visit']),
            $centreid
        );
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz Cabrera',
            'email' => 'marta@example.com', 'phone' => '']);
        events::sign_up($event, (int) $household->id, 2, $old['slots'][0]['id']);

        $context = panel_eventform::context($centre, $old);
        $this->assertFalse($context['slots'][0]['canremove']);
        $this->assertTrue($context['slots'][1]['canremove']);
        $this->assertSame([0.0, 50.0], array_column($context['slots'], 'left'));
        $this->assertSame(['10:00', '11:00', '11:30'], array_column($context['strip']['ticks'], 'text'));

        // The second turn goes when it is left out; a turn that nobody signed up in reaches nobody.
        $changed = $old;
        $changed['slots'] = [$old['slots'][0]];
        [$changes, $reached] = panel_eventform::changes($centre, $old, $changed);
        $this->assertSame([[], []], [$changes, $reached]);
        events::save($centreid, panel_eventform::data($changed, $old));
        $this->assertSame(1, $DB->count_records(events::SLOTS, ['eventid' => $eventid]));

        // The place and the hour of the first turn change: the family sees it before anything is saved.
        $old = panel_eventform::values(events::get($eventid, $centreid));
        $changed = $old;
        $changed['place'] = 'El salón de actos';
        $changed['slots'][0]['start'] = '10:30';
        [$changes, $reached] = panel_eventform::changes($centre, $old, $changed);
        $this->assertSame([(int) $household->id], $reached);
        $this->assertSame(['Turn 1', 'The place'], array_column($changes, 'what'));
        $notice = panel_eventform::notice($centre, $changes, $reached, $changed);
        $this->assertSame('This change reaches one family who signed up', $notice['title']);
        $this->assertSame('It reaches them by email to 1.', $notice['who']);
        $this->assertSame('Hola, Marta:', $notice['greeting']);
        $this->assertStringContainsString('de 10:30 a 11:15, en El salón de actos', $notice['message']);

        // Saved and told: by mail, and in the history of the household, which keeps waiting as it was.
        events::save($centreid, panel_eventform::data($changed, $old));
        $sink = $this->redirectEmails();
        $this->assertSame(1, panel_eventform::tell($centre, events::get($eventid, $centreid), $reached, 2));
        $mails = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Visita por turnos', $mails[0]->subject);
        $this->assertSame(1, $DB->count_records(households::CONTACTS, ['householdid' => $household->id,
            'direction' => 'out']));
    }

    /**
     * The skin of the panel: the one the offer suggests by the ages of its courses, unless the centre chose one.
     */
    public function test_skin(): void {
        $offer = function (int $centreid, string $name) {
            centres::save_offer($centreid, (object) ['schoolyear' => '2027-28', 'name' => $name, 'places' => 10,
                'active' => 1, 'sortorder' => 0]);
        };
        $small = centres::create((object) ['name' => 'Escuela infantil de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->assertSame('mostrador', panel::proposed_skin($small));
        $offer($small, 'Infantil 3 años');
        $offer($small, '1.º de Primaria');
        $this->assertSame('vestibulo', panel::proposed_skin($small));
        $offer($small, '1.º de ESO');
        $this->assertSame('mostrador', panel::proposed_skin($small));
        $secondary = centres::create((object) ['name' => 'Instituto de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer($secondary, '1.º de ESO');
        $offer($secondary, '1.º de Bachillerato');
        $this->assertSame('cinematico', panel::proposed_skin($secondary));
        $academy = centres::create((object) ['name' => 'Academia de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer($academy, 'Inglés B1');
        $this->assertSame('cinematico', panel::proposed_skin($academy));
        // The one chosen wins; anything else is the one suggested.
        $this->assertSame('vestibulo', panel::skin((object) ['id' => $academy, 'skin' => 'vestibulo']));
        $this->assertSame('cinematico', panel::skin((object) ['id' => $academy, 'skin' => 'otra']));
        $this->assertNull(centres::get(centres::create((object) ['name' => 'X', 'skin' => 'otra']))->skin);
        $this->assertSame('mostrador', centres::get(centres::create((object) ['name' => 'Y', 'skin' => 'mostrador']))->skin);
    }

    /**
     * Who attends a family, what was promised to it, the relay of whoever is away, the answers of always and who is
     * writing now.
     */
    public function test_attend(): void {
        global $DB;
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($centreid);
        $lucia = $this->getDataGenerator()->create_user(['firstname' => 'Lucía', 'lastname' => 'Medina']);
        $andres = $this->getDataGenerator()->create_user(['firstname' => 'Andrés', 'lastname' => 'Rivas']);
        centres::add_member($centreid, (int) $lucia->id);
        centres::add_member($centreid, (int) $andres->id);
        $entry = entries::get(entries::create($centreid, 'hook', 'Web a medida'), $centreid);
        [$household] = entries::receive($entry, ['Nombre y apellidos del padre, madre o tutor' => 'Marta Ruiz Cabrera',
            'Correo electrónico' => 'marta@example.com', 'Mensaje' => '¿Tienen comedor en Infantil?'], 'x1');

        // Nobody attends it until someone answers; then the one who answered; «I will» takes it.
        $this->assertNull(attend::owner($household, (int) $lucia->id));
        households::answered($household, (int) $lucia->id, 'email', 'Sí, hay comedor.');
        $household = households::get((int) $household->id, $centreid);
        $this->assertTrue(attend::owner($household, (int) $lucia->id)['mine']);
        attend::take($household, (int) $andres->id);
        $this->assertSame('Andrés', attend::owner(households::get((int) $household->id, $centreid), (int) $lucia->id)['first']);

        // A promise for yesterday goes up in the inbox, for anyone, until someone says it is done.
        $yesterday = time() - DAYSECS;
        $promiseid = attend::promise($household, (int) $andres->id, 'Llamarles para la visita', $yesterday);
        $this->assertSame([(int) $household->id], array_keys(attend::due($centreid, time())));
        $this->setUser($lucia);
        $inbox = panel_inbox::context($centre, true, (int) $lucia->id, true);
        $this->assertSame('prometido', $inbox['days'][0]['kind']);
        $this->assertStringContainsString('llamarles para la visita', $inbox['days'][0]['rows'][0]['promise']['text']);
        attend::done($household, $promiseid, (int) $lucia->id);
        $this->assertSame([], attend::due($centreid, time()));
        $this->assertTrue($DB->record_exists_select(
            households::CONTACTS,
            'householdid = ? AND direction = ?',
            [$household->id, 'note']
        ));

        // Without a day, it goes up only while whoever promised it is away.
        attend::promise($household, (int) $andres->id, 'Mandarles el menú');
        $this->assertSame([], attend::due($centreid, time()));
        attend::set_away($centreid, (int) $andres->id, time() + 3 * DAYSECS);
        $this->assertSame([(int) $household->id], array_keys(attend::due($centreid, time())));
        attend::set_away($centreid, (int) $andres->id, 0);

        // An answer kept as one of always, suggested to another family that asks the same, with its names.
        attend::keep_reply($household, (int) $lucia->id, panel_household::greeting(households::full($household)) .
            "\n\nSí, Marta, hay comedor con cocina propia en Infantil.");
        [$other] = entries::receive($entry, ['Nombre y apellidos del padre, madre o tutor' => 'Luis Pardo',
            'Correo electrónico' => 'luis@example.com', 'Mensaje' => 'Hola, ¿hay comedor para Infantil?'], 'x2');
        $suggested = attend::suggest(households::full($other));
        $this->assertCount(1, $suggested);
        $this->assertStringContainsString('Sí, Luis, hay comedor', $suggested[0]['text']);
        $this->assertNotNull(attend::use_reply(households::full($other), (int) $suggested[0]['id']));

        // Who is writing now: the others see it, not the one writing.
        attend::beat((int) $household->id, (int) $andres->id);
        $this->assertSame('Andrés', attend::typing((int) $household->id, (int) $lucia->id)['first']);
        $this->assertNull(attend::typing((int) $household->id, (int) $andres->id));

        // Erasing the family erases what was promised to it.
        households::erase(households::get((int) $household->id, $centreid));
        $this->assertSame(0, $DB->count_records(attend::PROMISES, ['householdid' => $household->id]));
    }
}
