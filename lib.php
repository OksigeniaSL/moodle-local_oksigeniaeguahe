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
 * Library functions of local_oksigeniaeguahe.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serves the logo of a centre, which families see without an account on their pages; the photos of who receives them;
 * and the drawing of a child, only to the team of its centre.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool False when there is nothing to serve.
 */
function local_oksigeniaeguahe_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;
    // The voice saying a first name (namevoices) is for anyone: a page of the story asks for it without an account.
    $areas = ['logo', 'hostphoto', 'drawing', 'drawingstrokes', 'namevoice'];
    if ($context->contextlevel != CONTEXT_SYSTEM || !in_array($filearea, $areas, true)) {
        return false;
    }
    $itemid = (int) array_shift($args);
    // The photo of someone of the team: for everyone while the centre shows that person to the families, and always
    // for the team of that centre, which chooses whom to show.
    if ($filearea === 'hostphoto') {
        $member = $DB->get_record('local_oksigeniaeguahe_member', ['id' => $itemid], 'id, centreid, public');
        if (
            !$member || (!$member->public && !(isloggedin() && (
            \local_oksigeniaeguahe\local\centres::can_work((int) $member->centreid, (int) $USER->id) ||
            has_capability('local/oksigeniaeguahe:manage', $context))))
        ) {
            return false;
        }
    }
    // The drawing of a child and its strokes: only for the team of its centre.
    if ($filearea === 'drawing' || $filearea === 'drawingstrokes') {
        $centreid = (int) $DB->get_field('local_oksigeniaeguahe_child', 'centreid', ['id' => $itemid]);
        if (!$centreid || !isloggedin() || !\local_oksigeniaeguahe\local\centres::can_work($centreid, (int) $USER->id)) {
            return false;
        }
    }
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_oksigeniaeguahe', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, false, $options);
}

/**
 * What admissions can tell a family on the phone, with Oksigenia Marandu: their texts are in the language packs.
 *
 * @return array By notice: values for its examples and how many a week, to estimate the cost.
 */
function local_oksigeniaeguahe_oksigeniamarandu_notices(): array {
    return [
        'visit_confirmed' => ['examples' => ['Los Almendros School', 'Saturday 21 November, 10:00'], 'perweek' => 0.2],
        'visit_reminder' => ['examples' => ['Los Almendros School', 'Saturday 21 November, 10:00'], 'perweek' => 0.2],
        'visit_invitation' => ['examples' => ['Los Almendros School', 'Tuesday 10 November, 10:30', 'Lucía Medina'],
            'perweek' => 0.1],
        'request_received' => ['examples' => ['Los Almendros School'], 'perweek' => 0.1],
        'message_received' => ['examples' => ['Los Almendros School'], 'perweek' => 0.1],
        'answer_waiting' => ['examples' => ['Los Almendros School', 'Yes, there is a canteen with its own kitchen.'],
            'perweek' => 0.2],
        'visit_changed' => ['examples' => ['Los Almendros School', 'Saturday 21 November, from 11:00 to 13:00, in the hall'],
            'perweek' => 0.05],
        'visit_thanks' => ['examples' => ['Los Almendros School', 'Lucía Medina'], 'perweek' => 0.2],
    ];
}

/**
 * What admissions posts in the channel of Telegram of the organisation, with Oksigenia Marandu: the announcement of each
 * open day, and the rest of the moments of every open act (local\channel), after whoever reviews them unless the site
 * says otherwise. Their names are in the language packs.
 *
 * @return array By kind of post: its settings ([] for those of the site).
 */
function local_oksigeniaeguahe_oksigeniamarandu_posts(): array {
    return ['open_day' => [], 'act' => []];
}

/**
 * What happened in admissions in the last seven days, added up and without names, for the line of its door in «Your
 * work»: so whoever runs the centre sees it alive, not only what waits (the common rule of 8 November 2026). Families
 * that came in, that came to know the centre, and that looked at their page.
 *
 * @param int $centreid
 * @param int $now
 * @return string « · This week: …», or nothing in a quiet week.
 */
function local_oksigeniaeguahe_work_week(int $centreid, int $now): string {
    global $DB;
    $c = 'local_oksigeniaeguahe';
    $since = $now - 7 * DAYSECS;
    $new = $DB->count_records_select(
        'local_oksigeniaeguahe_household',
        'centreid = ? AND incentre = 0 AND timecreated >= ?',
        [$centreid, $since]
    );
    $came = (int) $DB->count_records_sql(
        'SELECT COUNT(DISTINCT s.householdid)
           FROM {local_oksigeniaeguahe_signup} s
           JOIN {local_oksigeniaeguahe_event} e ON e.id = s.eventid
          WHERE e.centreid = ? AND s.attended >= ?',
        [$centreid, $since]
    );
    $looked = (int) $DB->count_records_sql(
        'SELECT COUNT(DISTINCT householdid) FROM {local_oksigeniaeguahe_seen} WHERE centreid = ? AND kind = ? AND timecreated >= ?',
        [$centreid, 'open', $since]
    );
    $parts = [];
    foreach (['new' => $new, 'came' => $came, 'looked' => $looked] as $key => $n) {
        if ($n) {
            $parts[] = get_string('work_week_' . $key . ($n === 1 ? '_one' : ''), $c, $n);
        }
    }
    if (!$parts) {
        return '';
    }
    $last = array_pop($parts);
    $list = $parts ? get_string('andlist', $c, (object) ['list' => implode(', ', $parts), 'last' => $last]) : $last;
    return ' · ' . get_string('work_week', $c, $list);
}

/**
 * What admissions has for whoever works in it, for the block «Your work» of Oksigenia (Tembiapo): a door per centre,
 * with the families that wait for an answer and the visits of today, and on top what was promised to a family and is
 * due. Nothing for whoever does not work for a centre.
 *
 * @param int $userid
 * @return array As the block takes them: key, title, url, status, alerts and actions.
 */
function local_oksigeniaeguahe_oksigenia_work(int $userid): array {
    global $DB;
    $c = 'local_oksigeniaeguahe';
    $now = time();
    $centres = \local_oksigeniaeguahe\local\centres::for_user($userid);
    $start = (new \DateTime('@' . $now))->setTimezone(\core_date::get_user_timezone_object())->setTime(0, 0)->getTimestamp();
    $out = [];
    foreach ($centres as $centre) {
        $id = (int) $centre->id;
        $url = new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $id]);
        $waiting = \local_oksigeniaeguahe\local\households::waiting_count($id);
        $today = (int) $DB->count_records_sql(
            'SELECT COUNT(DISTINCT s.householdid)
                                                 FROM {local_oksigeniaeguahe_signup} s
                                                 JOIN {local_oksigeniaeguahe_event} e ON e.id = s.eventid
                                            LEFT JOIN {local_oksigeniaeguahe_slot} t ON t.id = s.slotid
                                                WHERE e.centreid = :centreid AND s.attended = 0
                                                      AND COALESCE(t.timestart, e.timestart) >= :start
                                                      AND COALESCE(t.timestart, e.timestart) < :end',
            ['centreid' => $id, 'start' => $start, 'end' => $start + DAYSECS]
        );
        $promised = \local_oksigeniaeguahe\local\attend::due($id, $now);
        $due = count($promised);
        $alerts = [];
        if ($due) {
            // The deadline of the alert: the oldest day promised among those due.
            $days = array_filter(array_map(fn($p) => (int) $p->dueday, $promised));
            $alerts[] = ['text' => get_string($due === 1 ? 'work_promised_one' : 'work_promised', $c, $due),
                'url' => $url->out(false), 'level' => 'urgent'] + ($days ? ['due' => min($days)] : []);
        }
        if ($today) {
            $alerts[] = ['text' => get_string($today === 1 ? 'work_today_one' : 'work_today', $c, $today),
                'url' => (new moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $id]))->out(false), 'level' => 'today'];
        }
        $out[] = [
            'key' => 'eguahe-' . $id,
            'title' => count($centres) > 1 ? get_string('work_title_centre', $c, format_string($centre->name)) :
                get_string('work_title', $c),
            'url' => $url->out(false),
            'status' => ($waiting ? get_string($waiting === 1 ? 'work_waiting_one' : 'work_waiting', $c, $waiting) :
                get_string('work_waiting_none', $c)) . local_oksigeniaeguahe_work_week($id, $now),
            'alerts' => $alerts,
            'actions' => \local_oksigeniaeguahe\local\centres::can_act($userid) ? [['label' => get_string('household_new', $c),
                'url' => (new moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $id]))->out(false)]] : [],
        ];
    }
    return $out;
}
