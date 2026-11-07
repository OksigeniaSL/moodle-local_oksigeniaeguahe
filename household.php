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
 * A household for the team: who they are, the way of each child, what was said, the permissions, and what to do next
 * (note an answer, a note, move a way, give the family a new link).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\householdpage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\staff;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$c = 'local_oksigeniaeguahe';

require_login(null, false);
$household = households::get($id, 0);
if (!$household) {
    throw new moodle_exception('invalidrecord', 'error');
}
$centre = centres::require_work((int) $household->centreid);
$url = new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]);
staff::setup($url, get_string('household', $c), $centre);

$canact = centres::can_act((int) $USER->id);
if ($action && !$canact) {
    centres::require_act((int) $centre->id);
}

if ($action === 'eraseask') {
    // What it does, in the question and in its button.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('erase_confirm', $c),
        new single_button(
            new moodle_url($url, ['action' => 'erase', 'sesskey' => sesskey()]),
            get_string('erase_yes', $c),
            'post',
            single_button::BUTTON_DANGER
        ),
        new single_button($url, get_string('erase_no', $c), 'get')
    );
    echo $OUTPUT->footer();
    die();
}

$newlink = '';
if ($action && data_submitted() && confirm_sesskey()) {
    $body = trim(optional_param('body', '', PARAM_TEXT));
    $channel = optional_param('channel', 'other', PARAM_ALPHA);
    $channel = in_array($channel, lists::HAND_CHANNELS, true) ? $channel : 'other';
    switch ($action) {
        case 'answer':
            households::answered($household, (int) $USER->id, $channel, $body);
            redirect($url, get_string('answered_saved', $c), null, \core\output\notification::NOTIFY_SUCCESS);
            break;
        case 'received':
            households::received($household, $channel, $body, true);
            redirect($url);
            break;
        case 'note':
            if ($body !== '') {
                households::note($household, (int) $USER->id, $body);
            }
            redirect($url);
            break;
        case 'stage':
            $childid = required_param('child', PARAM_INT);
            $child = $DB->get_record(
                households::CHILDREN,
                ['id' => $childid, 'householdid' => $household->id],
                '*',
                MUST_EXIST
            );
            $stage = required_param('stage', PARAM_ALPHA);
            if (in_array($stage, lists::STAGES, true)) {
                households::set_stage($child, $stage, optional_param('reason', null, PARAM_ALPHA));
            }
            redirect($url);
            break;
        case 'link':
            // The new link is shown once, to send it to the family; the one before stops working.
            $newlink = (new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => households::new_link($household)]))->out(false);
            break;
        case 'erase':
            households::erase($household);
            redirect(
                new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]),
                get_string('erased_by_team', $c),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniaeguahe/household', householdpage::context(
    $household,
    $centre,
    $newlink,
    $canact
));
echo $OUTPUT->footer();
