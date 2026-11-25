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
use local_oksigeniaeguahe\local\phone;
use local_oksigeniaeguahe\local\teamnotes;

/**
 * What the team wrote by hand in a sheet of families, read as gestures and kept once in the history of each family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\teamnotes
 */
#[\PHPUnit\Framework\Attributes\CoversClass(teamnotes::class)]
final class teamnotes_test extends \advanced_testcase {
    /**
     * The words of a follow-up column: the ones that say no go before, a day alone is a visit, a «no» says nothing.
     */
    public function test_read(): void {
        $this->resetAfterTest();
        $around = make_timestamp(2026, 11, 10, 12);
        $kinds = fn(?array $gestures) => $gestures === null ? null : array_column($gestures, 'kind');
        $this->assertSame(['noanswer'], $kinds(teamnotes::read('Priority', 'No contesta', $around)));
        $this->assertSame(['noanswer'], $kinds(teamnotes::read('Priority', 'Me colgó', $around)));
        $this->assertSame(['nowhatsapp'], $kinds(teamnotes::read('Priority', 'No tiene wasap', $around)));
        $this->assertSame(['contacted'], $kinds(teamnotes::read('Priority', 'Wasap', $around)));
        $this->assertSame(['familycalls'], $kinds(teamnotes::read('Priority', 'Nos devolverá la llamada', $around)));
        $this->assertSame(['callback'], $kinds(teamnotes::read('Priority', 'llamar por la tarde', $around)));
        $this->assertSame(['wrongnumber'], $kinds(teamnotes::read('Priority', 'Dice que el nº no existe', $around)));
        $this->assertSame(['cancelled', 'openday'], $kinds(teamnotes::read(
            'OBSERVACIONES',
            'Canceló la cita. Viene a PPAA 21 Nov',
            $around,
            true
        )));
        $this->assertSame(['closed'], $kinds(teamnotes::read('Canal', 'No nos eligieron', $around, true)));
        $this->assertSame(['stage'], $kinds(teamnotes::read('Estado solicitud', 'Formalizada', $around)));
        // A day alone is the day of their visit, placed after the row.
        $visit = teamnotes::read('Priority', '01/12', $around);
        $this->assertSame('visitbooked', $visit[0]['kind']);
        $this->assertSame('2026-12-01', userdate($visit[0]['time'], '%Y-%m-%d', 99, false));
        // In an observation, a date is never a visit.
        $this->assertSame([], teamnotes::read('OBSERVACIONES', 'Nacieron el 03/02/2026', $around, true));
        // What says nothing.
        $this->assertNull(teamnotes::read('lead_status', 'CREATED', $around));
        $this->assertNull(teamnotes::read('Enviada información', 'FALSE', $around));
        $this->assertSame(['absent'], $kinds(teamnotes::read('ASISTENCIA', 'FALSE', $around)));
        // A number written with its country code and without its «+».
        $this->assertSame('+34646303304', phone::normalise('34646303304', 'ES'));
        $this->assertSame('+34628642967', phone::normalise('628642967', 'ES'));
    }

    /**
     * A row of the sheet of the team: both tutors, several children, who attended them and the rest as notes; the same
     * row again adds nothing.
     */
    public function test_row_of_the_team(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        foreach (['Infantil 3 años', 'Infantil 4 años', '2.º de Primaria'] as $name) {
            centres::save_offer($cid, (object) ['schoolyear' => '2027-28', 'name' => $name, 'places' => 0, 'active' => 1]);
        }
        $mary = $this->getDataGenerator()->create_user(['firstname' => 'Mary', 'lastname' => 'Medina']);
        centres::add_member($cid, (int) $mary->id);
        $entry = entries::get(entries::create($cid, 'google', 'Hoja de visitas'), $cid);
        // A sheet the team keeps, as a person says in the entry (never from its headings).
        entries::update($entry, (object) ['name' => $entry->name, 'acknowledge' => 0, 'active' => 1, 'teamsheet' => 1]);
        $entry = entries::get((int) $entry->id, $cid);
        $row = ['Hora' => '11:00', 'FECHA' => '29/06/2026', 'Tutor 1' => 'Lucía Ramos', 'Tutor 2' => 'Pablo Ortega',
            'NOMBRE ALUMNO' => 'Hugo, Alba', 'Nivel' => '1º Inf, 2º Inf', 'CURSO' => '2027-28',
            'TELÉFONO' => '600111222', 'Teléfono 2' => '600111333', 'Canal' => 'Teléfono', 'Atención telefónica' => 'Mary',
            'E-MAIL tutor 1' => 'lucia@example.com', 'E-MAIL tutor 2' => 'pablo@example.com', 'ASISTENCIA' => 'TRUE',
            'ATENDIDO POR' => 'Mónica', 'WhatsApp' => 'Enviado', 'Estado solicitud' => 'Formalizada',
            'OBSERVACIONES' => 'Hermana en el cole'];
        [$household, $new] = entries::receive($entry, $row, '', null, true);
        $this->assertTrue($new);
        $this->assertSame('phone', $household->firstchannel);
        $this->assertSame(2, $DB->count_records(households::ADULTS, ['householdid' => $household->id]));
        $children = $DB->get_records(households::CHILDREN, ['householdid' => $household->id], 'id ASC');
        $this->assertSame(['Hugo', 'Alba'], array_values(array_column($children, 'firstname')));
        $this->assertSame(['application', 'application'], array_values(array_column($children, 'stage')));
        $this->assertSame((int) $mary->id, (int) $household->ownerid);
        $this->assertSame(1, (int) $household->incentre);
        $this->assertSame(0, (int) $household->waitingsince);
        $lines = $DB->count_records(households::CONTACTS, ['householdid' => $household->id]);
        $this->assertTrue($DB->record_exists_select(households::CONTACTS, 'householdid = ? AND ' .
            $DB->sql_like('body', '?'), [$household->id, '%Mónica%']));
        // Sent again, with a second answer written since: only that comes in.
        [$again, $new] = entries::receive($entry, $row + ['Respuesta 1' => 'No contesta'], '', null, true);
        $this->assertFalse($new);
        $this->assertSame((int) $household->id, (int) $again->id);
        $this->assertSame($lines + 1, $DB->count_records(households::CONTACTS, ['householdid' => $household->id]));
        // A contact of an ad of the same family, without the name of the child, is the same family and the same child.
        [$same] = entries::receive($entry, ['id' => 'l:9', 'created_time' => '2026-11-06T10:00:00+00:00',
            'platform' => 'ig', 'campaign_name' => 'Captación', 'Nombre y apellidos' => 'Lucía Ramos',
            'Teléfono' => '34600111222', 'selecciona_una_opción' => 'infantil_3-6', 'Priority' => 'Wasap'], '', null, true);
        $this->assertSame((int) $household->id, (int) $same->id);
        // A form of Google: the child by «…se llama:», whoever comes with them as the second adult, how many will come.
        $read = entries::read($entry, centres::get($cid), ['Marca temporal' => '9/11/2026 10:43:26',
            'Nombre y apellidos del padre, madre o tutor' => 'Andrea Suárez', 'Teléfono de contacto' => '600555444',
            'El que esperamos sea nuestro futuro alumno del colegio se llama:' => 'Martín',
            '¿Cuántas personas asistirán?' => '3', 'Nombre de la persona que les acompaña' => 'Jorge Díaz',
            'Teléfono de la persona que les acompaña' => '600555333']);
        $this->assertSame('Martín', $read->child);
        $this->assertSame(3, $read->people);
        $this->assertSame(['Andrea', 'Jorge'], array_column($read->adults, 'firstname'));
        $this->assertSame('+34600555333', $read->adults[1]->phone);
        $this->assertSame('2026-11-09', userdate($read->time, '%Y-%m-%d', 99, false));
        // The same name of a column twice: the second one is of whoever comes with them.
        [$rows] = entries::rows_of([['name' => 'Hoja', 'rows' => [
            ['Nombre y apellidos del padre, madre o tutor', 'Teléfono de contacto', 'Nombre de una de las personas que les acompañan',
                'Teléfono de contacto'],
            ['Ana Gil', '600111000', 'Luis Gil', '600111999'],
        ]]]);
        $this->assertSame('600111999', $rows[0]['Teléfono de contacto (2)']);
        $twice = entries::read($entry, centres::get($cid), $rows[0]);
        $this->assertSame(['+34600111000', '+34600111999'], array_column($twice->adults, 'phone'));
        // A person of the team trying the form: no family comes in.
        $mary->email = 'mary@example.com';
        $DB->set_field('user', 'email', 'mary@example.com', ['id' => $mary->id]);
        [$nobody] = entries::receive(
            $entry,
            ['Nombre y apellidos' => 'Mary Medina', 'Correo' => 'mary@example.com'],
            '',
            null,
            true
        );
        $this->assertNull($nobody);
        // The nursery they come from, kept to say who recommended them.
        $nursery = entries::read($entry, centres::get($cid), ['Nombre y apellidos' => 'Eva Díaz', 'Teléfono' => '600777666',
            'Guardería o centro de infantil que asiste actualmente.' => 'Escuela infantil Los Pinos']);
        $this->assertSame('Escuela infantil Los Pinos', $nursery->otherschool);
        // The old export of Meta writes its date the American way: «11/6/26» is the 6th of November.
        [$old] = entries::receive($entry, ['id' => '123', 'created_time' => '11/6/26', 'platform' => 'fb',
            'Nombre y apellidos' => 'Rosa León', 'Teléfono' => '600999888'], '', null, true);
        $this->assertSame('2026-11-06', userdate((int) $old->timecreated, '%Y-%m-%d', 99, false));
        $this->assertSame(2, $DB->count_records(households::CHILDREN, ['householdid' => $household->id]));
    }

    /**
     * What says who someone is is never shown as it came: each of those kinds of column has its example in words.
     */
    public function test_examples_of_personal_roles(): void {
        foreach (entries::PERSONAL as $role) {
            $this->assertTrue(get_string_manager()->string_exists('panel_example_' . $role, 'local_oksigeniaeguahe'), $role);
        }
    }

    /**
     * A call nobody picked up is not an answer: the family goes on waiting, and what was kept before is marked so.
     */
    public function test_tried_is_not_an_answer(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $household = households::get(households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $cid);
        $line = fn(string $body) => $DB->insert_record(households::CONTACTS, (object) ['householdid' => $household->id, 'centreid' => $cid,
            'direction' => 'out', 'channel' => 'phone', 'body' => $body, 'entryid' => 0, 'userid' => 0, 'timecreated' => time()]);
        $DB->insert_record(teamnotes::T, (object) ['centreid' => $cid, 'householdid' => $household->id, 'mark' => sha1('a'), 'entryid' => 1,
            'timecreated' => time()]);
        $kind = teamnotes::TRIED[0];
        $tried = $line(\local_oksigeniaeguahe\local\publiclang::str('teamnote_' . $kind, 'es') . ' · Llamar el lunes');
        $said = $line('Quedamos en que vienen el martes');
        $this->assertSame(1, teamnotes::mark_tried());
        $this->assertSame('tried', $DB->get_field(households::CONTACTS, 'aim', ['id' => $tried]));
        $this->assertNull($DB->get_field(households::CONTACTS, 'aim', ['id' => $said]));
        $this->assertSame(0, teamnotes::mark_tried(), 'Once.');
    }
}
