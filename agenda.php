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
 * The agenda of the team: the week, and saying who came.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\appointments;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_agenda;

require_login(null, false);

$centre = centres::require_work(required_param('c', PARAM_INT));
$canact = centres::can_act((int) $USER->id);
$pass = optional_param('pasada', 0, PARAM_BOOL);
// Of one act, when it comes from it.
$act = $pass ? events::get(optional_param('acto', 0, PARAM_INT), (int) $centre->id) : null;
$url = new moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id] + ($pass ? ['pasada' => 1] : []) +
    ($act ? ['acto' => $act->id] : []));
$title = get_string($pass ? 'agenda_pass_title' : 'tab_events', 'local_oksigeniaeguahe');
panel::setup($url, $title);
$action = optional_param('action', '', PARAM_ALPHA);
if (data_submitted() && confirm_sesskey() && in_array($action, ['mark', 'undo'], true)) {
    // Who came to the ones that passed, several at once; and taking it back.
    centres::require_act((int) $centre->id);
    $key = optional_param('key', '', PARAM_ALPHANUM);
    $said = '';
    if ($action === 'undo') {
        appointments::undo($centre, $key);
        $key = '';
    } else {
        $ids = optional_param_array('ids', [], PARAM_ALPHANUM);
        $rest = !$ids;
        if ($rest) {
            $ids = explode(',', optional_param('idlist', '', PARAM_TAGLIST));
        }
        $state = optional_param('state', '', PARAM_ALPHA);
        $key = appointments::mark($centre, $ids, $state, (int) $USER->id, 0, $rest);
        $said = $key !== '' ? panel_agenda::said(
            $DB->count_records(appointments::T, ['centreid' => $centre->id, 'batch' => $key]),
            $state
        ) : '';
    }
    if (optional_param('fetch', 0, PARAM_BOOL)) {
        @header('Content-Type: application/json; charset=utf-8');
        @header('Cache-Control: no-store');
        echo json_encode(['ok' => $action === 'undo' || $key !== '', 'key' => $key, 'said' => $said,
            'left' => count(panel_agenda::topass($centre, time(), $act ?: null))]);
        die();
    }
    redirect(new moodle_url($url, $key !== '' ? ['hecho' => $key] : []), $said);
}
if ($pass) {
    panel::output($centre, 'events', $title, 'panel_agenda_pass', panel_agenda::pass(
        $centre,
        $canact,
        optional_param('hecho', '', PARAM_ALPHANUM),
        0,
        $act ?: null
    ));
}
panel::output($centre, 'events', $title, 'panel_agenda', panel_agenda::context(
    $centre,
    $canact,
    optional_param('semana', '', PARAM_RAW_TRIMMED),
    optional_param('guia', 0, PARAM_INT)
));
