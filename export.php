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
 * Takes away in a spreadsheet the families of a centre (who works with them) or its figures without names (who sees
 * the centre), in the format Moodle writes: Excel, ODS or CSV.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\export;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$what = optional_param('what', 'figures', PARAM_ALPHA) === 'families' ? 'families' : 'figures';
$format = optional_param('format', 'excel', PARAM_ALPHA);
$format = in_array($format, export::FORMATS, true) ? $format : 'excel';
// The families carry who they are and how to reach them: only whoever works with them; the figures, whoever sees the centre.
$centre = $what === 'families' ? centres::require_act($centreid) : centres::require_work($centreid);
require_sesskey();
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniaeguahe/export.php', ['c' => $centreid]));

$name = clean_filename(format_string($centre->name) . ' - ' . get_string('export_' . $what, 'local_oksigeniaeguahe') . ' - ' .
    userdate(time(), '%Y-%m-%d', 99, false));
$columns = $what === 'families' ? export::family_columns() : export::figure_columns();
$rows = $what === 'families' ? export::families($centre) : export::figures($centre);
\core\dataformat::download_data($name, $format, array_values($columns), new ArrayIterator(array_map('array_values', $rows)));
die();
