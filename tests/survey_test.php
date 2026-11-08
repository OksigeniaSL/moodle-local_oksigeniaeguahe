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
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\panel_event;
use local_oksigeniaeguahe\local\signup;
use local_oksigeniaeguahe\local\surveys;
use local_oksigeniaeguahe\local\tales;
use local_oksigeniaeguahe\task\send_thanks;

/**
 * After the visit: the one open question, which goes to the thread and to whoever receives the family; the survey How
 * it went the centre turns on for an event, each answer kept once per family and event; its results in sentences and
 * quotes; the thanks a couple of hours after; and all of it erased with the family. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(surveys::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(send_thanks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(familypage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_event::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tales::class)]
final class survey_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $event;

    /** @var int The user who receives the families. */
    protected $hostuser;

    /**
     * A centre with someone who receives the families and an open day tomorrow, with example data.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        antispam::$testpass = true;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'email' => 'admisiones@example.com']);
        $this->centre = centres::get($id);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Lucía', 'lastname' => 'Medina']);
        $this->hostuser = (int) $user->id;
        centres::add_member($id, (int) $user->id);
        $member = $DB->get_record(centres::MEMBERS, ['centreid' => $id, 'userid' => $user->id]);
        $DB->update_record(centres::MEMBERS, (object) ['id' => $member->id, 'public' => 1, 'rolename' => 'Tutora']);
        $start = time() + DAYSECS;
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'place' => 'Entrada principal', 'timestart' => $start, 'timeend' => $start + 3 * HOURSECS, 'capacity' => 0,
            'open' => 1, 'hosts' => [$member->id]]);
        $this->event = events::get($eventid, $id);
    }

    /**
     * Resets the widget against spam and the clock of the thanks.
     */
    protected function tearDown(): void {
        antispam::$testpass = null;
        send_thanks::$testnow = null;
        parent::tearDown();
    }

    /**
     * A family signed up for the open day, who came.
     *
     * @param string $name The child, by the name used at home.
     * @param bool $email Whether they let the centre write by email.
     * @return array [household, token]
     */
    protected function came(string $name, bool $email = true): array {
        global $DB;
        [$household, $token] = signup::save([
            'adults' => [['firstname' => 'Adulto de ' . $name, 'lastname' => '', 'email' => strtolower($name) . '@example.com',
                'phone' => '600' . random_int(100000, 999999)],
                ['firstname' => '', 'lastname' => '', 'email' => '', 'phone' => '']],
            'children' => [['firstname' => $name, 'tagname' => $name, 'offerid' => 0], ['firstname' => '', 'offerid' => 0]],
            'people' => 2, 'slot' => 0, 'incentre' => '', 'municipality' => '', 'municipalityother' => '', 'heardfrom' => '',
            'message' => '', 'consents' => ['email' => $email ? 1 : 0, 'whatsapp' => 0, 'phone' => 0], 'altcha' => 'x',
        ], $this->event, $this->centre, 'es');
        $signup = $DB->get_record(events::SIGNUPS, ['eventid' => $this->event->id, 'householdid' => $household->id]);
        events::attend($this->event, (int) $signup->id, true);
        return [households::get((int) $household->id, (int) $this->centre->id), $token];
    }

    /**
     * The open day is over: it started yesterday.
     */
    protected function over(): void {
        global $DB;
        $start = time() - DAYSECS;
        $DB->update_record(events::T, (object) ['id' => $this->event->id, 'timestart' => $start,
            'timeend' => $start + 3 * HOURSECS]);
        $this->event = events::get((int) $this->event->id, (int) $this->centre->id);
    }

    /**
     * After the visit, the open question on the wall names whoever answers and how soon; what they ask goes to the
     * thread, waits for a person and is attended by whoever received them; never counted as a way they came in.
     */
    public function test_open_question(): void {
        global $DB;
        [$household, $token] = $this->came('Leo');
        $this->assertNull(surveys::last_visit($household, time()), 'Not before the visit is over.');
        $this->over();
        $page = familypage::context($household, $this->centre, 'es', $token, false);
        $this->assertFalse($page['howitwent']);
        $this->assertSame('¿Qué les quedó por preguntar?', $page['wallask']['title']);
        $this->assertSame('Enviar a Lucía', $page['wallask']['button']);
        $this->assertSame('Lucía les contesta por correo, normalmente en 2 días laborables.', $page['wallask']['reply']);
        $this->assertSame('', $page['wallask']['sent']);

        $DB->set_field(households::T, 'waitingsince', 0, ['id' => $household->id]);
        $household = households::get((int) $household->id, (int) $this->centre->id);
        surveys::ask($household, $this->event, '¿Hay comedor los viernes?');
        $household = households::get((int) $household->id, (int) $this->centre->id);
        $this->assertGreaterThan(0, (int) $household->waitingsince);
        $this->assertSame($this->hostuser, (int) $household->ownerid);
        $contact = $DB->get_record(households::CONTACTS, ['householdid' => $household->id, 'channel' => lists::PAGE]);
        $this->assertSame('¿Hay comedor los viernes?', $contact->body);
        $this->assertSame('in', $contact->direction);

        $page = familypage::context($household, $this->centre, 'es', $token, false, ['asked' => true]);
        $this->assertSame('Se lo hemos pasado a Lucía.', $page['wallask']['sent']);
        $this->assertSame('¿Algo más?', $page['wallask']['title']);
        $this->assertTrue($page['wallask']['justsent']);

        // A second question joins the first.
        surveys::ask($household, $this->event, 'Y el horario de tarde.');
        $this->assertSame("¿Hay comedor los viernes?\n\nY el horario de tarde.", surveys::answers(
            (int) $this->event->id,
            (int) $household->id
        )[0]);

        // In the results of the event, as a quote with the name used at home.
        $results = panel_event::howitwent($this->event, $this->centre, true, time());
        $this->assertSame('What they didn’t get to ask', $results['title']);
        $this->assertSame('Leo', $results['questions'][0]['quotes'][0]['who']);
        $this->assertStringStartsWith('The family who came has written to us afterwards', $results['told']);
    }

    /**
     * The survey of an event: in the band, the open question first; each answer kept once, a wrong one refused, a
     * question that no longer shows without its answer; the results in sentences, and in bars from three answers.
     */
    public function test_survey(): void {
        global $DB;
        $surveyid = surveys::create((int) $this->centre->id, 'howitwent');
        $survey = entries::get($surveyid, (int) $this->centre->id);
        $this->assertSame(surveys::KIND, $survey->kind);
        $fields = array_values(forms::fields($surveyid));
        $this->assertCount(3, $fields);
        $this->assertSame('scale', $fields[0]->type);
        $this->assertSame(['Nada', 'Poco', 'Bastante', 'Mucho'], forms::texts($fields[0], 'es', 'es')['options']);
        $this->assertSame('Did the visit help you picture everyday life here?', forms::texts($fields[0], 'en', 'es')['label']);
        // A survey is never a door: no link of its own and not among the entries.
        $this->assertNull(entries::by_token((string) $survey->token));
        $this->assertArrayNotHasKey($surveyid, entries::for_centre((int) $this->centre->id));

        events::save((int) $this->centre->id, (object) array_merge((array) $this->event, ['survey' => $surveyid]));
        $this->event = events::get((int) $this->event->id, (int) $this->centre->id);
        $this->assertSame($surveyid, (int) $this->event->survey);
        [$leo, $token] = $this->came('Leo');
        $this->over();
        $page = familypage::context($leo, $this->centre, 'es', $token, false);
        $this->assertFalse($page['wallask'], 'Never both waiting.');
        $this->assertSame('Son 4 preguntas, y contestan solo las que quieran.', $page['howitwent']['lead']);
        $this->assertSame('Lo lee el equipo que los recibió.', $page['howitwent']['reads']);
        $this->assertTrue($page['pegs']['came'], 'The label stuck down once they came.');
        $this->assertSame('¿Qué les quedó por preguntar?', $page['howitwent']['ask']['title']);
        $this->assertCount(3, $page['howitwent']['fields']);

        $scale = (int) $fields[0]->id;
        $this->assertFalse(surveys::save($leo, $this->event, $scale, 'Muchísimo', 'es'));
        $this->assertTrue(surveys::save($leo, $this->event, $scale, 'Mucho', 'es'));
        $this->assertTrue(surveys::save($leo, $this->event, $scale, 'Bastante', 'es'));
        $this->assertSame(1, $DB->count_records(surveys::T, ['householdid' => $leo->id, 'fieldid' => $scale]));
        $this->assertSame('Bastante', surveys::answers((int) $this->event->id, (int) $leo->id)[$scale]);

        // With one family, only sentences.
        $results = panel_event::howitwent($this->event, $this->centre, true, time());
        $this->assertSame('How it went', $results['title']);
        $this->assertFalse($results['questions'][0]['hasrows']);
        $this->assertSame('1 of 1 said “a lot” or “quite”.', $results['questions'][0]['sentence']);

        [$noa] = $this->came('Noa');
        [$gael] = $this->came('Gael');
        surveys::save($noa, $this->event, $scale, 'Mucho', 'es');
        surveys::save($gael, $this->event, $scale, 'Poco', 'es');
        surveys::save($gael, $this->event, (int) $fields[2]->id, 'Más tiempo en el patio.', 'es');
        $results = panel_event::howitwent($this->event, $this->centre, true, time());
        $this->assertStringStartsWith('3 of the 3 families who came have told us how it went', $results['told']);
        $this->assertTrue($results['questions'][0]['hasrows']);
        $this->assertSame([0, 1, 1, 1], array_column($results['questions'][0]['rows'], 'n'));
        $this->assertSame('2 of 3 said “a lot” or “quite”.', $results['questions'][0]['sentence']);
        $this->assertSame('Más tiempo en el patio.', $results['questions'][2]['quotes'][0]['text']);
        $this->assertSame('Gael', $results['questions'][2]['quotes'][0]['who']);
        $this->assertStringContainsString('household.php?id=' . $gael->id, $results['questions'][2]['quotes'][0]['url']);
        // The words the sentence counts, the tally of who told, the stamp of the date.
        $this->assertSame([false, false, true, true], array_column($results['questions'][0]['rows'], 'upper'));
        $this->assertSame(3, count(array_filter(array_column($results['tally']['groups'][0]['strokes'], 'ink'))));
        $this->assertNotFalse($results['stamp']);
        // Done once the questions to choose are answered: the text never holds it back.
        $this->assertSame('', familypage::context($noa, $this->centre, 'es', 'x', false)['howitwent']['done']);
        surveys::save($noa, $this->event, (int) $fields[1]->id, 'Poco', 'es');
        $this->assertNotSame('', familypage::context($noa, $this->centre, 'es', 'x', false)['howitwent']['done']);

        // In the file of the family, and in its download; gone with the family.
        $told = surveys::of_household($gael, 'es');
        $this->assertSame('Poco', $told[0]['answers'][0]['value']);
        $this->assertSame('A little', surveys::of_household($gael, 'en')[0]['answers'][0]['value']);
        $this->assertNotEmpty(households::export($gael)['after_visits']);
        households::erase($gael);
        $this->assertSame(0, $DB->count_records(surveys::T, ['householdid' => $gael->id]));

        // After two weeks the survey is no longer asked: the open question is back on the wall.
        $page = familypage::context($leo, $this->centre, 'es', $token, false);
        $this->assertNotFalse($page['howitwent']);
        $DB->set_field(events::T, 'timeend', time() - (surveys::DAYS + 1) * DAYSECS, ['id' => $this->event->id]);
        $page = familypage::context($leo, $this->centre, 'es', $token, false);
        $this->assertFalse($page['howitwent']);
        $this->assertNotFalse($page['wallask']);
    }

    /**
     * Without Ayvurenda in the same Moodle, no library to choose and no link to stories on the page of a family.
     */
    public function test_tales_without_ayvurenda(): void {
        if (tales::available()) {
            $this->markTestSkipped('This site has Ayvurenda.');
        }
        $this->assertSame([], tales::libraries());
        $this->centre->library = 'biblioteca';
        $this->assertNull(tales::url($this->centre, 'Infantil 3 años'));
        [$household, $token] = $this->came('Leo');
        $page = familypage::context($household, $this->centre, 'es', $token, false);
        $this->assertSame('', $page['children'][0]['tales']);
    }

    /**
     * The thanks goes once, a couple of hours after the end, by email to whoever allowed it, with the link to the open
     * question of their page; never for a visit of days ago.
     */
    public function test_thanks(): void {
        global $DB;
        $this->came('Leo');
        $this->came('Noa', false);
        $this->over();
        $sink = $this->redirectEmails();
        send_thanks::$testnow = (int) $this->event->timeend + HOURSECS;
        (new send_thanks())->execute();
        $this->assertCount(0, $sink->get_messages(), 'Not before two hours.');
        send_thanks::$testnow = (int) $this->event->timeend + 3 * HOURSECS;
        (new send_thanks())->execute();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('Gracias por venir a Colegio de ejemplo', $messages[0]->subject);
        $this->assertSame('leo@example.com', $messages[0]->to);
        (new send_thanks())->execute();
        $this->assertCount(1, $sink->get_messages(), 'Once.');
        $this->assertSame(0, $DB->count_records(events::SIGNUPS, ['eventid' => $this->event->id, 'thanked' => 0]));

        // What the mail says.
        $household = $DB->get_record(households::T, ['id' => $DB->get_field(events::SIGNUPS, 'householdid', ['eventid' =>
            $this->event->id], IGNORE_MULTIPLE)]);
        $signup = familymail::signup($household, (int) $this->event->id);
        [, $html, $text] = familymail::build('thanks', $household, $this->centre, $signup, 'es', 'token');
        $this->assertStringContainsString('Gracias por venir', $html);
        $this->assertStringContainsString('Escribirle a Lucía', $html);
        $this->assertStringContainsString('#pregunta', $text);

        // A visit of days ago gets no thanks.
        $sink->clear();
        $DB->set_field(events::SIGNUPS, 'thanked', 0, ['eventid' => $this->event->id]);
        send_thanks::$testnow = (int) $this->event->timeend + (surveys::THANKSUNTIL + 1) * DAYSECS;
        (new send_thanks())->execute();
        $this->assertCount(0, $sink->get_messages());
    }
}
