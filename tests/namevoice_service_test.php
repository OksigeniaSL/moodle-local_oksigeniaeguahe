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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\namevoice_service;
use local_oksigeniaeguahe\local\namevoices;

/**
 * The voice that says each child's name (0.48.0): which readings each child needs, and what the site does with each
 * answer of the voice service of Oksigenia. With example data and the answers of the service faked.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(namevoice_service::class)]
final class namevoice_service_test extends \advanced_testcase {
    /**
     * A centre with a first course of school, and a family with a child enrolled in it, «Leo» at home.
     *
     * @return array [centre, child]
     */
    protected function family(): array {
        global $DB;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $infant = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        $childid = households::add_child($household, 'Leonardo', $infant);
        $DB->set_field(households::CHILDREN, 'tagname', 'Leo', ['id' => $childid]);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $childid]), 'enrolled');
        return [centres::get($id), $DB->get_record(households::CHILDREN, ['id' => $childid])];
    }

    /**
     * The readings of each language, by the teacher of the course.
     */
    public function test_readings(): void {
        $this->assertSame(['nombre'], namevoice_service::readings('es', 'maestra'));
        $this->assertSame(['nombre-maestro'], namevoice_service::readings('es', 'maestro'));
        $this->assertSame(['nombre'], namevoice_service::readings('en', 'maestro'), 'In English «teacher» is the same word.');
        $this->assertSame(['nombre-f', 'nombre-m'], namevoice_service::readings('ar', 'maestra'));
        $this->assertSame(['nombre-maestro-f', 'nombre-maestro-m'], namevoice_service::readings('ar', 'maestro'));
    }

    /**
     * Who needs a voice: the children enrolled in a course that gives the story, until their story has passed.
     */
    public function test_wanted(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $child] = $this->family();
        $now = time();
        $this->assertSame(['es/nombre/leo' => ['es', 'nombre', 'Leo']], namevoice_service::wanted($now));
        $DB->update_record(centres::OFFERS, (object) ['id' => $child->offerid, 'teacher' => 'maestro']);
        $this->assertSame(['es/nombre-maestro/leo'], array_keys(namevoice_service::wanted($now)));
        $DB->set_field(centres::T, 'firstday', $now - 61 * DAYSECS, ['id' => $centre->id]);
        $this->assertSame([], namevoice_service::wanted($now), 'Their story has passed.');
        $DB->set_field(centres::T, 'firstday', $now + 90 * DAYSECS, ['id' => $centre->id]);
        $this->assertCount(1, namevoice_service::wanted($now), 'Asked early, to be ready by then.');
        // What they call them at home is what the story says.
        households::set_home_name(households::get((int) $child->householdid, (int) $centre->id), (int) $child->id, 'Leíto');
        $this->assertSame(['es/nombre-maestro/leito'], array_keys(namevoice_service::wanted($now)));
        $DB->set_field(households::CHILDREN, 'lefttime', $now, ['id' => $child->id]);
        $this->assertSame([], namevoice_service::wanted($now), 'They left.');
    }

    /**
     * What the site does with each answer: nothing without the service; pending waits; ready brings the ZIP, checks it
     * and keeps it in the bank; unavailable waits 30 days; limited stops everything for a while.
     */
    public function test_fetch(): void {
        global $DB;
        $this->resetAfterTest();
        namevoice_service::$calm = false;
        $this->family();
        $now = time();
        $this->assertSame(0, namevoice_service::fetch($now), 'Without the service, nothing is asked.');
        set_config('voicesapi', 'https://voces.example/v1', 'local_oksigeniaeguahe');
        set_config('voiceskey', 'k', 'local_oksigeniaeguahe');

        \curl::mock_response(json_encode(['status' => 'pending', 'retry_after' => 900]));
        $this->assertSame(1, namevoice_service::fetch($now));
        $row = $DB->get_record(namevoice_service::T, ['lang' => 'es', 'reading' => 'nombre', 'namekey' => 'leo']);
        $this->assertSame(['pending', $now + 900], [$row->status, (int) $row->nextcheck]);
        $this->assertSame(0, namevoice_service::fetch($now + 60), 'Not before the time it said.');

        // Ready: its ZIP, with the folder inside.
        $dir = make_request_directory();
        $speech = ['00-portada' => [[0.03, 0.85]]];
        file_put_contents($dir . '/habla.json', json_encode($speech));
        file_put_contents($dir . '/00-portada.mp3', 'ID3');
        $zip = $dir . '/leo.zip';
        get_file_packer('application/zip')->archive_to_pathname(['leo/habla.json' => $dir . '/habla.json',
            'leo/00-portada.mp3' => $dir . '/00-portada.mp3'], $zip);
        $body = file_get_contents($zip);
        \curl::mock_response($body);
        \curl::mock_response(json_encode(['status' => 'ready', 'url' => 'https://voces.example/v1/files/x.zip',
            'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'recipe_version' => '1']));
        $this->assertSame(1, namevoice_service::fetch($now + 1000));
        $this->assertSame($speech, namevoices::find('es', 'nombre', 'Leo')['speech']);
        $row = $DB->get_record(namevoice_service::T, ['id' => $row->id]);
        $this->assertSame(['ready', '1'], [$row->status, $row->recipeversion]);
        $this->assertSame($now + 1000 + namevoice_service::AGAIN, (int) $row->nextcheck);

        // A ZIP that is not the one it said is not kept.
        namevoices::delete('es', 'nombre', 'Leo');
        $DB->set_field(namevoice_service::T, 'nextcheck', 0, ['id' => $row->id]);
        $DB->set_field(namevoice_service::T, 'sha256', '', ['id' => $row->id]);
        \curl::mock_response('not the zip');
        \curl::mock_response(json_encode(['status' => 'ready', 'url' => 'https://voces.example/v1/files/x.zip',
            'sha256' => hash('sha256', $body), 'recipe_version' => '1']));
        namevoice_service::fetch($now + 2000);
        $this->assertNull(namevoices::find('es', 'nombre', 'Leo'));
        $this->assertSame('pending', $DB->get_field(namevoice_service::T, 'status', ['id' => $row->id]));

        // No voice for that name: not again for 30 days.
        $DB->set_field(namevoice_service::T, 'nextcheck', 0, ['id' => $row->id]);
        \curl::mock_response(json_encode(['status' => 'unavailable', 'reason' => 'failed']));
        namevoice_service::fetch($now + 3000);
        $next = (int) $DB->get_field(namevoice_service::T, 'nextcheck', ['id' => $row->id]);
        $this->assertSame($now + 3000 + namevoice_service::AGAIN, $next);

        // The site asked enough today: everything waits.
        $DB->set_field(namevoice_service::T, 'nextcheck', 0, ['id' => $row->id]);
        \curl::mock_response(json_encode(['status' => 'limited', 'retry_after' => 3600]));
        namevoice_service::fetch($now + 4000);
        $this->assertSame(0, namevoice_service::fetch($now + 4100));
        $this->assertSame($now + 4000 + 3600, (int) get_config('local_oksigeniaeguahe', 'voicespause'));
    }
}
