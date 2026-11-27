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
 * The families that wait and may already be answered: saying so of several, and taking it back.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_settle;
use local_oksigeniaeguahe\local\settle;

require_login(null, false);

$centre = centres::require_work(centres::param('contestadas'));
$url = new moodle_url('/local/oksigeniaeguahe/contestadas.php', ['c' => $centre->id]);
$title = get_string('settle_title', 'local_oksigeniaeguahe');
panel::setup($url, $title);
$action = optional_param('action', '', PARAM_ALPHA);
if (data_submitted() && confirm_sesskey() && in_array($action, ['settle', 'undo'], true)) {
    centres::require_act((int) $centre->id);
    if ($action === 'undo') {
        settle::undo($centre, optional_param('key', '', PARAM_ALPHANUM), (int) $USER->id);
        redirect($url);
    }
    $ids = optional_param_array('ids', [], PARAM_INT);
    $seen = optional_param_array('seen', [], PARAM_INT);
    $key = settle::many($centre, $ids, optional_param('group', '', PARAM_ALPHANUMEXT), (int) $USER->id, 0, $seen);
    redirect(new moodle_url($url, $key !== '' ? ['hecho' => $key] : []));
}
panel::output($centre, 'inbox', $title, 'panel_settle', panel_settle::context(
    $centre,
    centres::can_act((int) $USER->id),
    optional_param('hecho', '', PARAM_ALPHANUM)
));
