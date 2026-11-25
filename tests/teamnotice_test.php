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
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\teamnotice;

/**
 * «A new family»: the team hears of each family that comes on its own, never of what it wrote down itself.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\teamnotice
 */
#[\PHPUnit\Framework\Attributes\CoversClass(teamnotice::class)]
final class teamnotice_test extends \advanced_testcase {
    /**
     * Those chosen hear of the families that came by a form or a message; nothing of what the team wrote down or
     * brought from its sheets; more than three at once go in one notice.
     */
    public function test_new_families(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($cid);
        $mary = $this->getDataGenerator()->create_user(['firstname' => 'María', 'lastname' => 'Admisiones']);
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Pedro', 'lastname' => 'Secretaría']);
        centres::add_member($cid, (int) $mary->id);
        centres::add_member($cid, (int) $other->id);
        teamnotice::toggle($cid, (int) $mary->id);
        teamnotice::toggle($cid, 999999);
        $this->assertSame([(int) $mary->id], teamnotice::chosen($cid), 'Only members of the team.');
        $this->assertSame([(int) $mary->id], array_keys(teamnotice::recipients($centre)));

        $form = entries::create($cid, 'form', 'La web del colegio');
        $sheet = entries::create($cid, 'google', 'Hoja de visitas');
        $DB->set_field(entries::T, 'teamsheet', 1, ['id' => $sheet]);
        $start = time() - 60;
        $family = function (string $name, int $entry, int $userid, string $direction = 'in') use ($DB, $cid): \stdClass {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $household = households::get($id, $cid);
            households::add_adult($household, (object) ['firstname' => $name, 'lastname' => 'Pérez',
                'email' => strtolower($name) . '@example.com', 'phone' => null]);
            households::add_child($household, 'Leo', 0);
            $DB->insert_record(households::CONTACTS, (object) ['centreid' => $cid, 'householdid' => $id,
                'direction' => $direction, 'channel' => 'webform', 'body' => 'Queremos ver el colegio con calma.',
                'entryid' => $entry, 'detail' => 'Web · Pide una visita', 'userid' => $userid, 'timecreated' => time()]);
            return households::get($id, $cid);
        };
        $ana = $family('Ana', $form, 0);
        $family('Noted', 0, (int) $other->id);
        $family('Sheet', $sheet, 0, 'note');
        $came = teamnotice::arrived($cid, $start, time() + 1);
        $this->assertSame([(int) $ana->id], array_keys($came));

        $sink = $this->redirectMessages();
        $this->assertSame(1, teamnotice::send($centre, $came));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame((int) $mary->id, (int) $messages[0]->useridto);
        $this->assertStringContainsString('Ana Pérez', $messages[0]->subject);
        $this->assertStringContainsString('La web del colegio · Web · Pide una visita', $messages[0]->fullmessage);
        $this->assertStringContainsString('Queremos ver el colegio', $messages[0]->fullmessage);
        $sink->clear();

        // Four at once: one notice with all of them.
        $more = [];
        foreach (['Bea', 'Carla', 'Dani', 'Eva'] as $name) {
            $more[] = $family($name, $form, 0);
        }
        $this->assertSame(1, teamnotice::send($centre, $more));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        foreach (['Bea', 'Carla', 'Dani', 'Eva'] as $name) {
            $this->assertStringContainsString($name . ' Pérez', $messages[0]->fullmessage);
        }
        $sink->close();

        // A practice centre never tells anyone.
        $DB->set_field(centres::T, 'practice', 1, ['id' => $cid]);
        $this->assertSame(0, teamnotice::send(centres::get($cid), $came));
    }

    /**
     * A contact of the ads that comes in through the sheet of the team, hours after it wrote, is told once, when it comes
     * in; a row the team wrote, one of weeks ago, or one that was already here, never.
     */
    public function test_ads_through_the_sheet(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $entryid = entries::create($cid, 'google', 'Hoja de visitas');
        $DB->set_field(entries::T, 'teamsheet', 1, ['id' => $entryid]);
        $entry = entries::get($entryid, $cid);
        $start = time() - 60;
        $ad = fn(int $n, int $when): array => ['id' => 'l:10000000' . $n, 'created_time' => date('c', $when),
            'ad_id' => 'ag:1', 'ad_name' => 'Anuncio', 'campaign_id' => 'c:1', 'campaign_name' => 'Otoño', 'platform' => 'ig',
            'Nombre y apellidos' => 'Persona ' . $n, 'E-mail' => 'p' . $n . '@example.com', 'Teléfono' => 'p:+3460000010' . $n];
        [$late] = entries::receive($entry, $ad(1, time() - 10 * HOURSECS), '', null, true);
        [$old] = entries::receive($entry, $ad(2, time() - 20 * DAYSECS), '', null, true);
        [$row] = entries::receive($entry, ['FECHA' => date('d/m/Y'), 'Tutor 1' => 'Marta Ejemplo',
            'TELÉFONO' => '600 000 009', 'Canal' => 'Teléfono'], '', null, true);
        $this->assertNotNull($late);
        $this->assertNotNull($old);
        $this->assertNotNull($row);
        $this->assertSame([(int) $late->id], array_keys(teamnotice::arrived($cid, $start, time() + 1)));
        // The next look, and the sheet sent again: nothing more.
        $this->assertSame([], teamnotice::arrived($cid, time() + 1, time() + 301));
        entries::receive($entry, $ad(1, time() - 10 * HOURSECS), '', null, true);
        $this->assertCount(1, teamnotice::arrived($cid, $start, time() + 1));
    }

    /**
     * A family that had gone and writes again is told of as a new one, and whoever is erased leaves no notice with its name.
     */
    public function test_back_and_erased(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $user = $this->getDataGenerator()->create_user();
        centres::add_member($cid, (int) $user->id);
        $household = households::get(households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $cid);
        households::add_adult($household, (object) ['firstname' => 'Zaida', 'email' => 'zaida@example.com']);
        teamnotice::back((int) $household->id);
        $this->assertGreaterThan(0, (int) $DB->get_field(households::T, 'cameback', ['id' => $household->id]));
        $url = (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false);
        $note = fn(string $text, ?string $context) => $DB->insert_record('notifications', (object) ['useridfrom' => -20,
            'useridto' => $user->id, 'subject' => 'Familia nueva', 'fullmessage' => $text, 'fullmessageformat' => FORMAT_PLAIN,
            'smallmessage' => '', 'component' => 'local_oksigeniaeguahe', 'eventtype' => 'newfamily', 'contexturl' => $context,
            'timecreated' => time()]);
        $mine = $note("Zaida\n$url", $url);
        $other = $note("Otra\n{$url}9", $url . '9');
        households::erase($household);
        $this->assertFalse($DB->record_exists('notifications', ['id' => $mine]));
        $this->assertTrue($DB->record_exists('notifications', ['id' => $other]), 'The one of another family stays.');
    }
}
