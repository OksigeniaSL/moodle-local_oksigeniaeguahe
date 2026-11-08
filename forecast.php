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
 * The forecast of a centre: its courses with their age, the students it can expect in the next years in ranges, and
 * how far it reaches in each municipality, with the open data of its area and their source.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\forecast;
use local_oksigeniaeguahe\local\opendata;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_forecast;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_work($centreid);
$canact = centres::can_act((int) $USER->id);
$url = new moodle_url('/local/oksigeniaeguahe/forecast.php', ['c' => $centre->id]);
$PAGE->set_context(context_system::instance());
panel::setup($url, get_string('tab_forecast', $c));

$action = data_submitted() ? optional_param('action', '', PARAM_ALPHA) : '';
if ($action !== '') {
    require_sesskey();
    centres::require_act((int) $centre->id);
    if ($action === 'fetch') {
        try {
            $n = opendata::fetch_istac((int) $centre->id, forecast::zone($centre));
            redirect(
                $url,
                get_string($n ? 'forecast_fetched' : 'forecast_nosource', $c, $n),
                null,
                $n ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
            );
        } catch (moodle_exception $e) {
            redirect($url, get_string('opendata_failed', $c, 'ISTAC'), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
    if ($action === 'ages') {
        $grades = optional_param_array('grade', [], PARAM_TEXT);
        $ages = optional_param_array('age', [], PARAM_RAW_TRIMMED);
        $flags = optional_param_array('forecast', [], PARAM_BOOL);
        $rows = [];
        foreach ($grades as $i => $grade) {
            if (isset($ages[$i]) && $ages[$i] !== '') {
                $rows[$grade] = ['age' => (int) $ages[$i], 'forecast' => !empty($flags[$i])];
            }
        }
        forecast::save_ages((int) $centre->id, $rows);
        redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

panel::output($centre, 'forecast', get_string('tab_forecast', $c), 'panel_forecast', panel_forecast::context($centre, $canact));
