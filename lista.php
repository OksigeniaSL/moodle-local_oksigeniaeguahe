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
 * What the last lists of the centre did by themselves, with «It is not so», and the lists that came cut short.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\listnews;
use local_oksigeniaeguahe\local\panel;

require_login(null, false);

$centre = centres::require_work(required_param('c', PARAM_INT));
$url = new moodle_url('/local/oksigeniaeguahe/lista.php', ['c' => $centre->id]);
$title = get_string('listnews_title', 'local_oksigeniaeguahe');
panel::setup($url, $title);
$action = optional_param('action', '', PARAM_ALPHA);
if (data_submitted() && confirm_sesskey() && in_array($action, ['child', 'say', 'accept'], true)) {
    centres::require_act((int) $centre->id);
    if ($action === 'accept') {
        listnews::accept((int) $centre->id, required_param('list', PARAM_TEXT));
        redirect($url);
    }
    $household = households::get(required_param('familia', PARAM_INT), (int) $centre->id);
    if ($household && $action === 'child') {
        listmatch::choose($household, required_param('fila', PARAM_INT), optional_param('nino', 0, PARAM_INT));
    } else if ($household) {
        listmatch::say($household, required_param('fila', PARAM_INT), optional_param('si', 0, PARAM_BOOL), (int) $USER->id);
    }
    redirect($url);
}
panel::output($centre, 'inbox', $title, 'panel_list', listnews::context($centre, centres::can_act((int) $USER->id)));
