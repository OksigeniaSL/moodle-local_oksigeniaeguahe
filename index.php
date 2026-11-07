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
 * The inbox of a centre: who waits for an answer, since when, and how each offer is going.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);

$centreid = optional_param('c', 0, PARAM_INT);
$all = optional_param('all', 0, PARAM_BOOL);
$c = 'local_oksigeniaeguahe';

$centre = centres::pick($centreid);
if (!$centre) {
    // Someone with several centres chooses one.
    staff::setup(new moodle_url('/local/oksigeniaeguahe/index.php'), get_string('navname', $c));
    $list = [];
    foreach (centres::for_user((int) $USER->id) as $each) {
        $list[] = ['name' => format_string($each->name), 'url' => (new moodle_url(
            '/local/oksigeniaeguahe/index.php',
            ['c' => $each->id]
        ))->out(false), 'waiting' => households::waiting_count((int) $each->id)];
    }
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniaeguahe/pick_centre', ['centres' => $list, 'hascentres' => (bool) $list,
        'manageurl' => has_capability('local/oksigeniaeguahe:manage', context_system::instance()) ?
            (new moodle_url('/local/oksigeniaeguahe/centres.php'))->out(false) : '']);
    echo $OUTPUT->footer();
    die();
}

$url = new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id] + ($all ? ['all' => 1] : []));
staff::setup($url, get_string('inbox', $c), $centre);

$rows = [];
foreach (households::inbox((int) $centre->id, !$all) as $household) {
    $children = [];
    foreach ($household->children as $child) {
        $children[] = ['name' => $child->firstname,
            'offer' => $child->offer ? format_string($child->offer->name) : get_string('offer_unknown', $c),
            'stage' => get_string('stage_' . $child->stage, $c)];
    }
    $rows[] = [
        'url' => (new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
        'names' => staff::adult_names($household->adults),
        'children' => $children,
        'channel' => get_string('channel_' . $household->firstchannel, $c),
        'waiting' => $household->waitingsince > 0,
        'waited' => $household->waitingsince ? staff::waited((int) $household->waitingsince) : '',
        'last' => userdate((int) $household->lastcontact, get_string('strftimedatetimeshort', 'langconfig')),
    ];
}

$counts = households::offer_counts((int) $centre->id);
$offers = [];
foreach (centres::offers((int) $centre->id, true) as $offer) {
    $bystage = $counts[$offer->id] ?? [];
    $open = array_sum($bystage) - ($bystage['closed'] ?? 0) - ($bystage['enrolled'] ?? 0);
    $enrolled = $bystage['enrolled'] ?? 0;
    $offers[] = [
        'name' => format_string($offer->name),
        'year' => s($offer->schoolyear),
        'open' => $open,
        'visited' => ($bystage['visit'] ?? 0) + ($bystage['application'] ?? 0) + ($bystage['admitted'] ?? 0) +
            ($bystage['waitlist'] ?? 0),
        'enrolled' => $enrolled,
        'places' => (int) $offer->places,
        'hasplaces' => $offer->places > 0,
        'left' => max(0, (int) $offer->places - $enrolled),
    ];
}

$waiting = households::waiting_count((int) $centre->id);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/inbox', [
    'tabs' => staff::tabs($centre, 'inbox'),
    'waiting' => $waiting,
    'waitingtext' => get_string($waiting === 1 ? 'inbox_waiting_one' : 'inbox_waiting', $c, $waiting),
    'all' => $all,
    'waitingurl' => (new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]))->out(false),
    'allurl' => (new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id, 'all' => 1]))->out(false),
    'newurl' => (new moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id]))->out(false),
    'rows' => $rows,
    'hasrows' => (bool) $rows,
    'offers' => $offers,
    'hasoffers' => (bool) $offers,
    'settingsurl' => has_capability('local/oksigeniaeguahe:manage', context_system::instance()) ?
        (new moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id]))->out(false) : '',
]);
echo $OUTPUT->footer();
