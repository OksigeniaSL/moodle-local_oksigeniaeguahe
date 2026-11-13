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
 * The settings of a centre, in the panel: how families see it, who receives them, its data, its area, its offer, its
 * team, the skin of the panel and its AI, saved at once. A new centre starts with its name, from the list of centres.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_centre;

$id = optional_param('id', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
require_capability('local/oksigeniaeguahe:manage', context_system::instance());

if (!$id) {
    // A new centre: its name, in the language of whoever makes it, with them in its team; the rest, in its settings.
    require_sesskey();
    $name = trim(required_param('name', PARAM_TEXT));
    if ($name === '') {
        redirect(new moodle_url('/local/oksigeniaeguahe/centres.php'));
    }
    $lang = in_array(current_language(), lists::LANGS, true) ? current_language() : 'en';
    $id = centres::create((object) ['name' => $name, 'lang' => $lang, 'retention' => 24]);
    centres::add_member($id, (int) $USER->id);
    redirect(new moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $id]));
}

$centre = centres::get($id);
if (!$centre) {
    throw new moodle_exception('invalidrecord', 'error');
}
$url = new moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $id]);
panel::setup($url, get_string('centre_settings', $c));

if (data_submitted()) {
    require_sesskey();
    $problems = panel_centre::save($centre);
    foreach ($problems as $problem) {
        \core\notification::warning($problem);
    }
    // Back where the gesture was: a card or a shelf of the offer, or else the place its button names (formaction), which
    // the browser keeps across the redirection; the school year of the offer that was seen, kept.
    $back = panel_centre::$back;
    $view = optional_param('offerview', '', PARAM_TEXT);
    $to = new moodle_url($url, $back['params'] ?? ($view !== '' ? ['year' => $view] : []), $back['anchor'] ?? null);
    // A switch or a chip is seen to change: only «Save» (or a problem) says it was saved.
    if (($back['quiet'] ?? !optional_param('guardar', 0, PARAM_BOOL)) && !$problems) {
        redirect($to);
    }
    redirect($to, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

panel::output(centres::get($id), 'settings', get_string('centre_settings', $c), 'panel_centre', panel_centre::context(
    $centre,
    // The families already at the centre: the label lifted to be hung on the hook of its course.
    optional_param('cohorte', '', PARAM_ALPHANUM)
));
