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
 * Says that a moment of the inbox is known (moments): it is not said again.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\moments;

$centreid = required_param('c', PARAM_INT);
$key = required_param('key', PARAM_ALPHANUMEXT);
require_login(null, false);
require_sesskey();
$centre = centres::require_work($centreid);
centres::require_act((int) $centre->id);
moments::know((int) $centre->id, $key);
redirect(new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]));
