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

/**
 * A centre with example data, to try Eguahe or show it: its offer with places, an open day in two weeks, and a few
 * households at different points of their way. Every name is made up.
 *
 * php local/oksigeniaeguahe/cli/sample.php [--member=username]
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;

[$options] = cli_get_params(['member' => 'admin', 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "A centre with example data for Oksigenia Eguahe.\n\n  --member=username  who joins its team (admin)\n";
    exit(0);
}

$now = time();
$centreid = centres::create((object) [
    'name' => 'Colegio Los Almendros (datos de ejemplo)',
    'lang' => 'es',
    'country' => 'ES',
    'colour' => '#2f5d62',
    'controller' => 'Colegio Los Almendros (datos de ejemplo)',
    'rightscontact' => 'secretaria@example.com',
    'retention' => 24,
    'municipalities' => "28065;Getafe\n28074;Leganés\n28058;Fuenlabrada\n28106;Parla",
    'address' => 'Calle de los Almendros 14, Getafe',
    'phone' => '+34 900 000 014',
    'email' => 'admisiones@example.com',
    'welcome' => 'Les enseñamos el centro y conocen a quienes estarían con su hijo cada día.',
]);
$offers = [];
foreach ([['Infantil 3 años', 25], ['1.º de Primaria', 4], ['1.º de ESO', 6], ['1.º de Bachillerato', 10]] as $i => $row) {
    $offers[] = centres::save_offer($centreid, (object) ['schoolyear' => '2027-28', 'name' => $row[0], 'places' => $row[1],
        'active' => 1, 'sortorder' => $i]);
}
$user = $DB->get_record('user', ['username' => $options['member'], 'deleted' => 0]);
if ($user) {
    centres::add_member($centreid, (int) $user->id);
}
$start = make_timestamp((int) date('Y'), (int) date('n'), (int) date('j') + 14, 10, 0);
$eventid = events::save($centreid, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
    'place' => 'Entrada principal', 'timestart' => $start, 'timeend' => $start + 3 * HOURSECS,
    'capacity' => 120, 'open' => 1]);
$event = events::get($eventid, $centreid);

// A visit by turns, three on a Saturday morning, and a talk open to everyone.
$saturday = make_timestamp((int) date('Y'), (int) date('n'), (int) date('j') + 21, 10, 0);
events::save($centreid, (object) ['kind' => 'visit', 'name' => 'Conócenos', 'place' => 'Recepción',
    'timestart' => $saturday, 'timeend' => $saturday + 3 * HOURSECS, 'capacity' => 0, 'open' => 1, 'slots' => [
        ['timestart' => $saturday, 'timeend' => $saturday + HOURSECS, 'capacity' => 12],
        ['timestart' => $saturday + HOURSECS, 'timeend' => $saturday + 2 * HOURSECS, 'capacity' => 12],
        ['timestart' => $saturday + 2 * HOURSECS, 'timeend' => $saturday + 3 * HOURSECS, 'capacity' => 12],
    ]]);
events::save($centreid, (object) ['kind' => 'other', 'name' => 'Charla para familias', 'place' => 'Salón de actos',
    'timestart' => $start + 7 * DAYSECS + 8 * HOURSECS, 'timeend' => $start + 7 * DAYSECS + 10 * HOURSECS,
    'capacity' => 200, 'open' => 1]);

$families = [
    [['Lucas', 'Pérez Gil', '+34600111222', 'lucas.perez@example.com'], [['Daniel', 0]], 'openday', 'family',
        '¿Hay comedor y horario ampliado?', 2],
    [['Carmen', 'Díaz Santana', '+34600333444', ''], [['Hugo', 0], ['Vera', 1]], 'phone', 'centrefamilies', '', 50],
    [['Mohamed', 'El Amrani', '+34600555666', 'mohamed@example.com'], [['Adam', 2]], 'webform', 'search',
        'Venimos de otro centro a mitad de curso.', 26],
    [['João', 'Silva', '+351912345678', 'joao.silva@example.com'], [['Beatriz', 0]], 'openday', 'social', '', 0],
    [['Andrea', 'Hernández', '', 'andrea.h@example.com'], [['Leo', 3]], 'walkin', 'passingby', '', 0],
];
foreach ($families as [$adult, $children, $channel, $heard, $message, $hoursago]) {
    $household = households::get(households::create($centreid, (object) ['lang' => $adult[0] === 'João' ? 'pt' : 'es',
        'firstchannel' => $channel, 'heardfrom' => $heard, 'municipality' => '28065',
        'municipalityname' => 'Getafe']), $centreid);
    $adultid = households::add_adult($household, (object) ['firstname' => $adult[0], 'lastname' => $adult[1],
        'phone' => $adult[2] ?: null, 'email' => $adult[3] ?: null]);
    households::consent($household, $adultid, 'whatsapp', true, 'form', $household->lang);
    foreach ($children as [$name, $offer]) {
        households::add_child($household, $name, $offers[$offer]);
    }
    $time = $now - $hoursago * HOURSECS;
    households::received($household, $channel, $message !== '' ? $message : 'Datos de ejemplo.', $message !== '', $time);
    if ($channel === 'openday') {
        events::sign_up($event, (int) $household->id, 3);
    }
}
echo "Centre {$centreid}: " . (new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centreid]))->out(false) . "\n";
echo "Open day: " . events::url($event)->out(false) . "\n";
