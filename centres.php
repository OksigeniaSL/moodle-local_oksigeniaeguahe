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
 * The centres of the site, for whoever sets them up.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;

$c = 'local_oksigeniaeguahe';
require_login();
if (has_capability('moodle/site:config', context_system::instance())) {
    admin_externalpage_setup('local_oksigeniaeguahe_centres');
} else {
    // Who manages Eguahe without managing the site does not have the administration tree: the same page, on its own.
    require_capability('local/oksigeniaeguahe:manage', context_system::instance());
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/oksigeniaeguahe/centres.php'));
    $PAGE->set_pagelayout('admin');
    $PAGE->set_title(get_string('centres', $c));
    $PAGE->set_heading(get_string('centres', $c));
}

$list = [];
foreach (centres::all() as $centre) {
    $list[] = [
        'name' => format_string($centre->name),
        'url' => (new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]))->out(false),
        'settingsurl' => (new moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id]))->out(false),
        'members' => count(centres::members((int) $centre->id)),
        'waiting' => households::waiting_count((int) $centre->id),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('centres', $c));
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/centres', ['centres' => $list, 'hascentres' => (bool) $list,
    'newurl' => (new moodle_url('/local/oksigeniaeguahe/centre.php'))->out(false), 'sesskey' => sesskey()]);
echo $OUTPUT->footer();
