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
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_entries;

require_login(null, false);
$centre = centres::require_work(required_param('c', PARAM_INT));
$url = new moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]);
$c = 'local_oksigeniaeguahe';
panel::setup($url, get_string('tab_entries', $c));
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

panel::output($centre, 'entries', get_string('tab_entries', $c), 'panel_entries', panel_entries::context($centre, $canact));
