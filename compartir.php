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
 * The report to share of an act: an A4 document to keep as PDF from the browser and send out, with the colours of the centre,
 * no name of a family and «Made with Eguahe». Only for whoever sees the numbers of the centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\sharereport;

require_login(null, false);
$centre = centres::require_metrics(centres::param('compartir'));
// A centre with many families and years of acts behind: the families of each campaign are worked out whole.
raise_memory_limit(MEMORY_HUGE);
core_php_time_limit::raise(120);
$context = sharereport::act($centre, required_param('acto', PARAM_ALPHANUM), time());
if (!$context) {
    throw new moodle_exception('invalidrecord');
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniaeguahe/compartir.php', ['c' => $centre->id,
    'acto' => required_param('acto', PARAM_ALPHANUM)]));
publicpage::headers();
@header('Content-Type: text/html; charset=utf-8');
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/report_share', $context);
die();
