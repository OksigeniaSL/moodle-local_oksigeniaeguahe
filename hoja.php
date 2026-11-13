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
 * Eguahe on one page, for the office: what is done each day, at the door and after, to print and keep by the phone.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_inbox;

require_login(null, false);

$centre = centres::require_work(required_param('c', PARAM_INT));
$c = 'local_oksigeniaeguahe';
$url = new moodle_url('/local/oksigeniaeguahe/hoja.php', ['c' => $centre->id]);
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('panel_onepage', $c));

panel::output($centre, 'inbox', get_string('panel_onepage', $c), 'panel_onepage', panel_inbox::onepage($centre));
