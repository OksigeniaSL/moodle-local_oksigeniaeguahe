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
 * inside the chats the site lets in, and never with anything in the address.
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
     * @return array The context of chat_family.
     */
    public static function find(int $userid, string $email, string $phone): array {
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
                $same = [];
                $ends = $DB->get_records_select(
                    households::ADULTS,
                    'centreid = :centreid AND ' . $DB->sql_like('phone', ':ends'),
                    ['centreid' => $cid, 'ends' => '%' . substr($tel, -3)],
                    '',
                    'id, phone'
                );
                foreach ($ends as $adult) {
                    if (phone::normalise((string) $adult->phone, (string) ($centre->country ?? '')) === $tel) {
                        $same[] = (int) $adult->id;
                    }
                }
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
                $families[] = self::household($centre, $household, count($centres) > 1, $userid);
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
        $school = self::school($email, array_keys($phones), $linked);
        $first = reset($centres);
        return [
            'families' => $families,
            'hasfamilies' => (bool) $families,
            'school' => $school,
            'hasschool' => (bool) $school,
            'nobody' => !$families && !$school,
            'newurl' => $first && centres::can_act($userid) ? (new \moodle_url(
                '/local/oksigeniaeguahe/edit.php',
                ['c' => $first->id]
            ))->out(false) : false,
        ];
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
            if ($child->stage === 'enrolled' && !empty($child->lefttime)) {
                $text = get_string('chat_child_left', $c, $name);
            } else if ($child->stage === 'enrolled') {
                $incentre = true;
                $text = get_string('chat_child_enrolled', $c, (object) ['name' => $name, 'course' => $course]);
            } else if ($child->stage === 'closed') {
                $text = get_string('chat_child_asking', $c, (object) ['name' => $name, 'course' => $course,
                    'stage' => self::lower(get_string('stage_closed', $c))]);
            } else {
                $asking = true;
                $text = get_string('chat_child_asking', $c, (object) ['name' => $name, 'course' => $course,
                    'stage' => self::lower(get_string('stage_' . $child->stage, $c))]);
            }
            $children[] = ['text' => $text];
        }
        $kind = $asking && $incentre ? 'both' : ($incentre ? 'centre' : 'asking');
        $next = null;
        foreach ($full->signups as $signup) {
            if ((int) $signup->timestart > $now && empty($signup->attended)) {
                $next = get_string('chat_next', $c, (object) ['event' => format_string($signup->eventname),
                    'when' => userdate((int) $signup->timestart, get_string('strftimedaydatetime', 'langconfig'))]);
                break;
            }
        }
        $owner = (int) ($household->ownerid ?? 0) ? $DB->get_record('user', ['id' => $household->ownerid, 'deleted' => 0]) :
            null;
        return [
            'title' => panel::adults_line($full->adults) ?: get_string('chat_family_noname', $c),
            'centre' => $withcentre ? format_string($centre->name) : false,
            'kind' => $kind,
            'kindname' => get_string('chat_kind_' . $kind, $c),
            'children' => $children,
            'haschildren' => (bool) $children,
            'waiting' => (int) $household->waitingsince ? get_string(
                'chat_waiting',
                $c,
                panel::when_words((int) $household->waitingsince, $now)
            ) : false,
            'owner' => $owner ? get_string((int) $owner->id === $userid ? 'chat_owner_you' : 'chat_owner', $c, fullname($owner)) :
                false,
            'next' => $next ?? false,
            'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
        ];
    }

    /**
     * The families of the common record of the centre this contact is in, and Eguahe does not have yet: by a phone
     * verified there, or by an email. Their children by their first name and their group.
     *
     * @param string $email
     * @param string[] $phones In E.164.
     * @param array $linked Family ids already shown through a household of Eguahe.
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
                $children[] = ['text' => $group !== '' ? get_string('chat_school_child', self::C, (object) [
                    'name' => $name !== '' ? $name : get_string('chat_child_noname', self::C), 'group' => $group]) : $name];
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
            'origins' => json_encode(self::origins()),
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
