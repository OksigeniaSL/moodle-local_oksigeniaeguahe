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
 * The entries of a centre: its forms made in Eguahe, and the bridges with what it already uses (Google, Meta, any
 * other tool). How many families came through each, and when the last one did.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);
$centre = centres::require_work(required_param('c', PARAM_INT));
$url = new moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]);
$c = 'local_oksigeniaeguahe';
staff::setup($url, get_string('tab_entries', $c), $centre);
$canact = centres::can_act((int) $USER->id);

if ($canact && data_submitted() && confirm_sesskey()) {
    centres::require_act((int) $centre->id);
    $kind = required_param('kind', PARAM_ALPHA);
    $name = trim(optional_param('name', '', PARAM_TEXT));
    if (isset(entries::KINDS[$kind])) {
        $id = entries::create((int) $centre->id, $kind, $name !== '' ? $name : get_string('entry_kind_' . $kind, $c));
        redirect(new moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $id]));
    }
}

$forms = [];
$bridges = [];
foreach (entries::for_centre((int) $centre->id) as $entry) {
    $row = [
        'name' => format_string($entry->name),
        'kind' => get_string('entry_kind_' . $entry->kind, $c),
        'received' => (int) $entry->received,
        'last' => $entry->lastreceived ? userdate((int) $entry->lastreceived, get_string('strftimedatetimeshort', 'langconfig')) :
            get_string('entry_never', $c),
        'active' => (bool) $entry->active,
        'url' => (new moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id]))->out(false),
    ];
    if ($entry->kind === 'form') {
        $forms[] = $row;
    } else {
        $bridges[] = $row;
    }
}
$kinds = [];
foreach (array_keys(entries::KINDS) as $kind) {
    if ($kind !== 'form') {
        $kinds[] = ['value' => $kind, 'name' => get_string('entry_kind_' . $kind, $c),
            'help' => get_string('entry_kind_' . $kind . '_help', $c)];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/entries', [
    'tabs' => staff::tabs($centre, 'entries'),
    'forms' => $forms,
    'hasforms' => (bool) $forms,
    'bridges' => $bridges,
    'hasbridges' => (bool) $bridges,
    'kinds' => $kinds,
    'canact' => $canact,
    'action' => $url->out(false),
    'sesskey' => sesskey(),
]);
echo $OUTPUT->footer();
