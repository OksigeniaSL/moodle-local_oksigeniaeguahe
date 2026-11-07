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
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\staff;
use local_oksigeniaeguahe\local\visits;

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
        case 'reply':
            // The answer goes by email as it was written, with the page of the family below.
            if ($body === '') {
                redirect($url, get_string('reply_empty', $c), null, \core\output\notification::NOTIFY_ERROR);
            }
            $sent = false;
            ob_start();
            try {
                $sent = familymail::send(
                    'reply',
                    $household,
                    $centre,
                    null,
                    $household->lang ?: $centre->lang,
                    households::new_link($household, 'reply'),
                    null,
                    $body
                );
            } catch (\Throwable $e) {
                $sent = false;
            } finally {
                ob_end_clean();
            }
            if (!$sent) {
                redirect($url, get_string('reply_failed', $c), null, \core\output\notification::NOTIFY_ERROR);
            }
            households::answered($household, (int) $USER->id, 'email', $body);
            redirect($url, get_string('reply_sent', $c), null, \core\output\notification::NOTIFY_SUCCESS);
            break;
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
            // The new link is shown once, to send it to the family; all the ones before stop working.
            $newlink = (new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => households::renew_links($household)]))->out(false);
            break;
        case 'propose':
            // A visit of its own: the day, the hour and who guides them; the invitation goes by email if they have one.
            $date = optional_param('date', '', PARAM_TEXT);
            $time = optional_param('time', '', PARAM_TEXT);
            $start = 0;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) && preg_match('/^(\d{1,2}):(\d{2})$/', $time, $t)) {
                $start = make_timestamp((int) $d[1], (int) $d[2], (int) $d[3], (int) $t[1], (int) $t[2]);
            }
            if ($start <= time()) {
                redirect($url, get_string('visit_err_when', $c), null, \core\output\notification::NOTIFY_ERROR);
            }
            $event = visits::propose(
                $household,
                $start,
                optional_param('minutes', 45, PARAM_INT),
                optional_param('guide', 0, PARAM_INT),
                optional_param('place', '', PARAM_TEXT),
                optional_param('people', 2, PARAM_INT)
            );
            $token = households::new_link($household, 'invitation');
            $lang = $household->lang ?: $centre->lang;
            $signup = familymail::signup($household, (int) $event->id);
            $sent = false;
            ob_start();
            try {
                $sent = $signup && familymail::send('invitation', $household, $centre, $signup, $lang, $token);
            } catch (\Throwable $e) {
                $sent = false;
            } finally {
                ob_end_clean();
            }
            $host = centres::hosts((int) $centre->id, array_filter([(int) $event->hosts]))[0] ?? null;
            $note = get_string('visit_proposed_note', $c, (object) [
                'when' => userdate($start, get_string('strftimedaydatetime', 'langconfig')),
                'guide' => $host ? $host->name : get_string('visit_guide_none', $c),
            ]);
            households::answered($household, (int) $USER->id, $sent ? 'email' : 'other', $note);
            if ($sent) {
                redirect($url, get_string('visit_proposed_sent', $c), null, \core\output\notification::NOTIFY_SUCCESS);
            }
            // Without an email, the team tells them, with the link of their page shown once.
            \core\notification::warning(get_string('visit_proposed_nomail', $c));
            $newlink = (new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]))->out(false);
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
