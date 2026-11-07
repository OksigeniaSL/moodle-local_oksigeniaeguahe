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
 * Who receives the families: the people of the team of a centre the families see on their page, with their photo,
 * what they do and a line of theirs. Photos taken at the centre, in natural light, head and shoulders.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\form\host_form;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\staff;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$memberid = optional_param('m', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';
$context = context_system::instance();

$centre = centres::require_act($centreid);
$url = new moodle_url('/local/oksigeniaeguahe/hosts.php', ['c' => $centre->id]);
staff::setup($url, get_string('hosts_title', $c), $centre);

$members = centres::members((int) $centre->id);
$bymember = array_column($members, null, 'memberid');
if ($memberid && isset($bymember[$memberid])) {
    $member = $DB->get_record(centres::MEMBERS, ['id' => $memberid, 'centreid' => $centre->id], '*', MUST_EXIST);
    $form = new host_form(new moodle_url($url, ['m' => $memberid]), ['name' => fullname($bymember[$memberid])]);
    $draft = file_get_submitted_draft_itemid('photo');
    file_prepare_draft_area($draft, $context->id, $c, 'hostphoto', $memberid, ['subdirs' => 0, 'maxfiles' => 1]);
    $form->set_data(['c' => $centre->id, 'm' => $memberid, 'public' => $member->public, 'rolename' => $member->rolename,
        'says' => $member->says, 'sortorder' => $member->sortorder, 'photo' => $draft]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $DB->update_record(centres::MEMBERS, (object) ['id' => $memberid, 'public' => empty($data->public) ? 0 : 1,
            'rolename' => \core_text::substr(trim((string) $data->rolename), 0, 255) ?: null,
            'says' => trim((string) $data->says) ?: null, 'sortorder' => max(0, min(999, (int) $data->sortorder))]);
        file_save_draft_area_files($data->photo, $context->id, $c, 'hostphoto', $memberid, ['subdirs' => 0,
            'maxfiles' => 1]);
        redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniaeguahe/tabs', ['tabs' => staff::tabs($centre, 'settings')]);
    $form->display();
    echo $OUTPUT->footer();
    die();
}

$shown = array_column(centres::hosts((int) $centre->id), null, 'memberid');
$people = [];
foreach ($members as $member) {
    $host = $shown[$member->memberid] ?? null;
    $people[] = [
        'name' => fullname($member),
        'shown' => (bool) $host,
        'role' => $host ? s($host->role) : '',
        'says' => $host ? s($host->says) : '',
        'photo' => $host && $host->photo ? $host->photo->out(false) : '',
        'initials' => $host ? s($host->initials) : '',
        'editurl' => (new moodle_url($url, ['m' => $member->memberid]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/hosts', ['tabs' => staff::tabs($centre, 'settings'),
    'people' => $people, 'haspeople' => (bool) $people,
    'settingsurl' => (new moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id]))->out(false)]);
echo $OUTPUT->footer();
