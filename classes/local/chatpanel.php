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

namespace local_oksigeniaeguahe\local;

/**
 * The family beside a conversation of the chat of the centre (a dashboard app of Chatwoot): when a person of the team
 * opens a conversation, the chat tells this page who writes, and the page shows whether it is a family that asks for a
 * place, a family of the centre, or both, with its children, who attends it and its next visit. Only for the team, only
 * inside the chats the site lets in or the pages of the site itself (the conversations of Marandu), and never with
 * anything in the address.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chatpanel {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The chats that may show this page inside them, from the setting of the site: one origin per line.
     *
     * @return string[] Origins such as https://chat.example.com.
     */
    public static function origins(): array {
        $out = [];
        foreach (preg_split('/[\s,]+/', (string) get_config(self::C, 'chatorigins')) as $line) {
            $line = \core_text::strtolower(rtrim(trim($line), '/'));
            $secure = preg_match('~^https://[a-z0-9.-]+(:\d{1,5})?$~', $line);
            $local = preg_match('~^http://(localhost|127\.0\.0\.1)(:\d{1,5})?$~', $line);
            if ($secure || $local) {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Whose word the page takes on who writes: the chats of the setting and the site itself, whose own pages (the
     * conversations of Marandu) may also hold it, as frame-ancestors 'self' already lets them.
     *
     * @return string[] Origins.
     */
    public static function listened(): array {
        global $CFG;
        $out = self::origins();
        $parts = parse_url((string) $CFG->wwwroot);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $out[] = \core_text::strtolower($parts['scheme'] . '://' . $parts['host']) .
                (!empty($parts['port']) ? ':' . (int) $parts['port'] : '');
        }
        return array_values(array_unique($out));
    }

    /**
     * The headers of the page: inside the chats of the setting, and nowhere else.
     */
    public static function headers(): void {
        $origins = self::origins();
        header_remove('X-Frame-Options');
        @header('Content-Type: text/html; charset=utf-8');
        @header('X-Content-Type-Options: nosniff');
        @header('Referrer-Policy: same-origin');
        @header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; " .
            "script-src 'self'; connect-src 'self'; frame-ancestors 'self'" . ($origins ? ' ' . implode(' ', $origins) : '') .
            "; form-action 'self'; base-uri 'none'; object-src 'none'");
        @header('Cache-Control: no-store');
    }

    /**
     * Who writes, among the families of the centres of a person: by the email or the phone the chat knows.
     *
     * @param int $userid
     * @param string $email
     * @param string $phone
     * @param string $name The name the chat knows, to note a family nobody knew.
     * @return array The context of chat_family.
     */
    public static function find(int $userid, string $email, string $phone, string $name = ''): array {
        global $DB;
        $email = \core_text::strtolower(trim($email));
        $email = validate_email($email) ? $email : '';
        $phone = trim($phone);
        $centres = array_filter(centres::for_user($userid), fn($c) => !centres::is_practice((int) $c->id));
        $families = [];
        $linked = [];
        $phones = [];
        foreach ($centres as $centre) {
            $cid = (int) $centre->id;
            $tel = $phone !== '' ? phone::normalise($phone, (string) ($centre->country ?? '')) : null;
            $where = [];
            $params = ['centreid' => $cid];
            if ($email !== '') {
                $where[] = $DB->sql_equal('a.email', ':email', false);
                $params['email'] = $email;
            }
            if ($tel) {
                // The phones as each way in wrote them («600 111 222», «+34600111222»): the few that end the same, and
                // of those, the ones that are the same number.
                $phones[$tel] = true;
                $same = self::adults_with_phone($centre, $tel);
                if ($same) {
                    [$in, $inparams] = $DB->get_in_or_equal($same, SQL_PARAMS_NAMED, 'ad');
                    $where[] = "a.id $in";
                    $params += $inparams;
                }
            }
            if (!$where) {
                continue;
            }
            $ids = $DB->get_fieldset_sql('SELECT DISTINCT h.id
                                            FROM {' . households::T . '} h
                                            JOIN {' . households::ADULTS . '} a ON a.householdid = h.id
                                           WHERE h.centreid = :centreid AND (' . implode(' OR ', $where) . ')
                                        ORDER BY h.id DESC', $params);
            foreach (array_slice($ids, 0, 5) as $id) {
                $household = households::get((int) $id, $cid);
                if (!$household) {
                    continue;
                }
                $families[] = self::household($centre, $household, count($centres) > 1, $userid) + ['centreid' => $cid];
                if ((int) ($household->familylink ?? 0) > 0) {
                    $linked[(int) $household->familylink] = true;
                }
                if (family::available()) {
                    $api = family::API;
                    $own = $api::family_by_key(family::SOURCE, family::key('h', (int) $household->id));
                    if ($own) {
                        $linked[(int) $own] = true;
                    }
                }
            }
        }
        // The common record of families knows no centres: only for whoever may see it all (Family's own permission), or
        // on a site with one real centre; never without a real centre of their own.
        $school = $centres && self::may_see_family($userid) ? self::school($email, array_keys($phones), $linked) : [];
        // Where a family nobody knew is noted: the centre of this person with the latest family that wrote.
        $first = reset($centres);
        if (count($centres) > 1) {
            [$in, $inparams] = $DB->get_in_or_equal(array_map(fn($c) => (int) $c->id, $centres), SQL_PARAMS_NAMED);
            $latest = $DB->get_records_sql_menu('SELECT centreid, MAX(lastcontact) AS last FROM {' . households::T . '}
                                                  WHERE centreid ' . $in . ' GROUP BY centreid', $inparams);
            arsort($latest);
            foreach (array_keys($latest) as $cid) {
                foreach ($centres as $centre) {
                    if ((int) $centre->id === (int) $cid) {
                        $first = $centre;
                        break 2;
                    }
                }
            }
        }
        // Two households or more of one centre with this contact: it may be the same family, joined from its file.
        $same = false;
        foreach (array_count_values(array_column($families, 'centreid')) as $cid => $n) {
            if ($n > 1 && !$same) {
                $one = array_values(array_filter($families, fn($f) => $f['centreid'] === $cid))[0];
                $same = ['n' => $n, 'url' => (new \moodle_url(
                    '/local/oksigeniaeguahe/household.php',
                    ['id' => $one['id']],
                    'misma-familia'
                ))->out(false)];
            }
        }
        $name = trim(clean_param($name, PARAM_TEXT));
        // The phone as people read it («+34 699 00 00 00»), when it is a number.
        $tel = $phone !== '' && $first ? phone::normalise($phone, (string) ($first->country ?? '')) : null;
        $shown = $tel ? phone::display($tel) : ($phone !== '' ? $phone : false);
        return [
            'contact' => ['name' => $name, 'phone' => $shown, 'email' => $email !== '' ? $email : false],
            'same' => $same,
            'families' => $families,
            'hasfamilies' => (bool) $families,
            'school' => $school,
            'hasschool' => (bool) $school,
            'nobody' => !$families && !$school,
            'newurl' => $first && centres::can_act($userid) ? (new \moodle_url(
                '/local/oksigeniaeguahe/edit.php',
                ['c' => $first->id]
            ))->out(false) : false,
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The adults of a centre with a phone, as each way in wrote it («600 111 222», «+34600111222»): the few that end the
     * same, and of those, the ones that are the same number.
     *
     * @param \stdClass $centre
     * @param string $tel The phone, normalised.
     * @return int[] Their ids.
     */
    public static function adults_with_phone(\stdClass $centre, string $tel): array {
        global $DB;
        $same = [];
        $ends = $DB->get_records_select(
            households::ADULTS,
            'centreid = :centreid AND ' . $DB->sql_like('phone', ':ends'),
            ['centreid' => (int) $centre->id, 'ends' => '%' . substr($tel, -3)],
            '',
            'id, phone'
        );
        foreach ($ends as $adult) {
            if (phone::normalise((string) $adult->phone, (string) ($centre->country ?? '')) === $tel) {
                $same[] = (int) $adult->id;
            }
        }
        return $same;
    }

    /**
     * A household of Eguahe as the chat shows it.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param bool $withcentre Whether to say its centre (a person in several).
     * @param int $userid
     * @return array
     */
    protected static function household(\stdClass $centre, \stdClass $household, bool $withcentre, int $userid): array {
        global $DB;
        $c = self::C;
        $full = households::full($household);
        $now = time();
        $offers = $DB->get_records_menu(centres::OFFERS, ['centreid' => $centre->id], '', 'id, name');
        $children = [];
        $asking = false;
        $incentre = !empty($household->incentre) || !empty($household->roster) || (int) ($household->familylink ?? 0) > 0;
        foreach ($full->children as $child) {
            $name = familypage::label_name($child) ?: get_string('chat_child_noname', $c);
            $course = isset($offers[$child->offerid]) ? format_string($offers[$child->offerid]) :
                get_string('panel_nocourse', $c);
            $children[] = self::child($name, $course, (string) $child->stage, !empty($child->lefttime));
            if ($child->stage === 'enrolled' && empty($child->lefttime)) {
                $incentre = true;
            } else if (!in_array($child->stage, ['enrolled', 'closed'], true)) {
                $asking = true;
            }
        }
        $kind = $asking && $incentre ? 'both' : ($incentre ? 'centre' : 'asking');
        $next = false;
        foreach ($full->signups as $signup) {
            $start = (int) $signup->timestart;
            if ($start > $now && empty($signup->attended)) {
                $next = ['weekday' => rtrim(userdate($start, '%a'), '.'), 'day' => userdate($start, '%d', 99, false),
                    'month' => rtrim(userdate($start, '%b'), '.'), 'event' => format_string($signup->eventname),
                    'when' => self::upper(userdate($start, '%A')) . ', ' . panel::hour($start)];
                break;
            }
        }
        $waiting = false;
        if ($since = (int) $household->waitingsince) {
            $replydays = max(1, (int) ($centre->replydays ?? 2));
            $waiting = ['short' => self::span($now - $since), 'long' => get_string('chat_since', $c, panel::when_words($since, $now)),
                'level' => $now - $since > $replydays * DAYSECS ? 'tarde' : 'atiempo'];
        }
        $owner = (int) ($household->ownerid ?? 0) ? $DB->get_record('user', ['id' => $household->ownerid, 'deleted' => 0]) :
            null;
        return [
            'id' => (int) $household->id,
            'title' => panel::adults_line($full->adults) ?: get_string('chat_family_noname', $c),
            'centre' => $withcentre ? format_string($centre->name) : false,
            'kind' => $kind,
            'kindname' => get_string('chat_kind_' . $kind, $c),
            'children' => $children,
            'haschildren' => (bool) $children,
            'waiting' => $waiting,
            'owner' => $owner ? ['name' => fullname($owner), 'you' => (int) $owner->id === $userid,
                'initials' => \core_text::strtoupper(\core_text::substr(trim($owner->firstname), 0, 1) .
                    \core_text::substr(trim($owner->lastname), 0, 1))] : false,
            'noowner' => !$owner,
            'next' => $next,
            // A family that asked not to be written to, with its day: a warning for whoever writes, never a block.
            'nomessages' => writeby::silent($household) ? ['since' => get_string(
                'chat_nomessages_since',
                $c,
                userdate((int) $household->writebytime, get_string('strftimedateshort', 'langconfig'))
            )] : false,
            'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
        ];
    }

    /**
     * A child as the chat shows it: a backpack, full when at the centre and drawn when asking, with the five steps of the
     * way and the one it is at; a waiting list is a pause after the application.
     *
     * @param string $name
     * @param string $course
     * @param string $stage
     * @param bool $left
     * @return array
     */
    public static function child(string $name, string $course, string $stage, bool $left): array {
        $c = self::C;
        if ($stage === 'enrolled') {
            return ['name' => $name, 'course' => $course, 'state' => $left ? 'left' : 'enrolled',
                'stagename' => get_string($left ? 'chat_left' : 'chat_enrolled_in', $c), 'hastrack' => false, 'track' => [],
                'tracklabel' => ''];
        }
        if ($stage === 'closed') {
            return ['name' => $name, 'course' => $course, 'state' => 'closed', 'stagename' => get_string('stage_closed', $c),
                'hastrack' => false, 'track' => [], 'tracklabel' => ''];
        }
        $wait = $stage === 'waitlist';
        $here = $wait ? 3 : max(0, (int) array_search($stage, panel_household::PATH, true));
        $stagename = get_string($wait ? 'chat_waitlist' : 'stage_' . $stage, $c);
        $track = [];
        foreach (panel_household::PATH as $i => $step) {
            $track[] = ['name' => get_string($wait && $i === 3 ? 'chat_waitlist_step' : 'chat_step_' . $step, $c),
                'done' => $i < $here, 'here' => $i === $here && !$wait, 'pause' => $wait && $i === 3];
        }
        return ['name' => $name, 'course' => $course, 'state' => $wait ? 'waitlist' : 'asking', 'stagename' => $stagename,
            'hastrack' => true, 'track' => $track,
            'tracklabel' => get_string('chat_track_label', $c, (object) ['name' => $name, 'stage' => self::lower($stagename)])];
    }

    /**
     * How long, in short: «15 minutos», «2 horas», «7 días».
     *
     * @param int $seconds
     * @return string
     */
    public static function span(int $seconds): string {
        $minutes = max(1, intdiv($seconds, MINSECS));
        [$n, $unit] = $minutes < 60 ? [$minutes, 'minute'] : ($minutes < 1440 ? [intdiv($minutes, 60), 'hour'] :
            [intdiv($minutes, 1440), 'day']);
        return get_string('chat_span_' . $unit . ($n === 1 ? '' : 's'), self::C, $n);
    }

    /**
     * Whether a person may see what the common record of families (Family) holds, which knows no centres.
     *
     * @param int $userid
     * @return bool
     */
    protected static function may_see_family(int $userid): bool {
        global $DB;
        if (
            get_capability_info('local/oksigeniafamily:manage') &&
                has_capability('local/oksigeniafamily:manage', \context_system::instance(), $userid)
        ) {
            return true;
        }
        return $DB->count_records(centres::T, ['practice' => 0]) === 1;
    }

    /**
     * The families of the common record with that email or phone, with their children and groups.
     *
     * @param string $email
     * @param string[] $phones
     * @param array $linked
     * @return array
     */
    protected static function school(string $email, array $phones, array $linked): array {
        if (!family::available()) {
            return [];
        }
        $api = family::API;
        $adults = [];
        foreach ($phones as $tel) {
            $adults += $api::adults_by_phone($tel);
        }
        if ($email !== '') {
            $adults += $api::adults_by_email($email);
        }
        $ids = array_diff(array_unique(array_map(fn($a) => (int) $a->familyid, $adults)), array_keys($linked));
        $out = [];
        foreach (array_slice($ids, 0, 3) as $familyid) {
            $family = self::family_household((int) $familyid);
            if (!$family) {
                continue;
            }
            $children = [];
            foreach ($family->children ?? [] as $child) {
                $name = trim((string) ($child->firstname ?? ''));
                $group = trim((string) ($child->group ?? ''));
                if ($name === '' && $group === '') {
                    continue;
                }
                $children[] = ['name' => $name !== '' ? $name : get_string('chat_child_noname', self::C), 'group' => $group];
            }
            $out[] = ['children' => $children, 'haschildren' => (bool) $children];
        }
        return $out;
    }

    /**
     * One family of the common record, with its children: by its own call when Family has it, or found in the roll.
     *
     * @param int $familyid
     * @return \stdClass|null
     */
    protected static function family_household(int $familyid): ?\stdClass {
        $api = family::API;
        if (method_exists($api, 'household')) {
            return $api::household($familyid, false) ?: null;
        }
        if (!method_exists($api, 'each_household')) {
            // A Family before 0.3.13 says nothing of a household by its number.
            return null;
        }
        foreach ($api::each_household(true, false) as $id => $household) {
            if ((int) $id === $familyid) {
                return $household;
            }
        }
        return null;
    }

    /**
     * The page: what the chat shows beside a conversation.
     */
    public static function page(): void {
        global $CFG, $OUTPUT, $USER;
        $base = $CFG->wwwroot . '/local/oksigeniaeguahe/pub/';
        $version = (int) get_config(self::C, 'version');
        $in = isloggedin() && !isguestuser();
        $team = $in && (bool) array_filter(centres::for_user((int) $USER->id), fn($c) => !centres::is_practice((int) $c->id));
        $lang = current_language();
        $context = [
            'in' => $in,
            'team' => $team,
            'loginurl' => (new \moodle_url('/login/index.php'))->out(false),
            'url' => (new \moodle_url('/local/oksigeniaeguahe/chat.php'))->out(false),
            'sesskey' => $in ? sesskey() : '',
            'origins' => json_encode(self::listened()),
            'logo' => $CFG->wwwroot . '/local/oksigeniaeguahe/pix/icon.svg',
        ];
        self::headers();
        echo '<!DOCTYPE html>
<html lang="' . s(str_replace('_', '-', $lang === 'pt_br' ? 'pt-BR' : $lang)) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>' . s(get_string('chat_title', self::C)) . '</title>
<link rel="stylesheet" href="' . $base . 'panel.css?v=' . $version . '">
<style>:root { ' . self::vars(false) . ' }
@media (prefers-color-scheme: dark) { :root:not([data-bs-theme]) { ' . self::vars(true) . ' } }
:root[data-bs-theme="dark"] { ' . self::vars(true) . ' }</style>
<script src="' . $base . 'chat.js?v=' . $version . '" defer></script>
</head>
<body class="panel pl-chat">
' . $OUTPUT->render_from_template('local_oksigeniaeguahe/chat_panel', $context) . '
</body>
</html>';
    }

    /**
     * A word that opens a line: its first letter capital.
     *
     * @param string $text
     * @return string
     */
    protected static function upper(string $text): string {
        return \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }

    /**
     * A name of a stage inside a sentence: its first letter small.
     *
     * @param string $text
     * @return string
     */
    protected static function lower(string $text): string {
        return \core_text::strtolower(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }

    /**
     * The colours of Eguahe, as the panel takes them.
     *
     * @param bool $dark
     * @return string
     */
    protected static function vars(bool $dark): string {
        $list = publicpage::centre_vars('#6a3f78', $dark);
        return implode(' ', array_map(fn($k, $v) => "$k: $v;", array_keys($list), $list));
    }
}
