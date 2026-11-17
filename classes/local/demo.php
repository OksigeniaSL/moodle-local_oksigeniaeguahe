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
 * Trying Eguahe without an account, in a site that is only Eguahe: each visitor gets a centre of their own, made like
 * the practice centre (made-up families, an open day under way, nothing ever sent), and a passing account that only
 * works for it. Both go away after a day. Nobody sees what another visitor wrote.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class demo {
    /** @var string Component. */
    const C = 'local_oksigeniaeguahe';

    /** @var int The value of centre.practice for the centre of one visitor (1 is the practice centre of the helps). */
    const KIND = 2;

    /** @var int How long a visitor's centre and account last. */
    const KEEP = DAYSECS;

    /** @var int Visitors' centres at once on a site: more wait until the oldest go. */
    const MAX = 300;

    /** @var int Demos started from one address of the internet in an hour. */
    const PERHOUR = 6;

    /** @var string The preference that marks a passing account, with its centre. */
    const PREF = 'local_oksigeniaeguahe_demo';

    /**
     * Whether the site lets visitors try Eguahe without an account.
     *
     * @return bool
     */
    public static function on(): bool {
        return onlyeguahe::on() && (bool) get_config(self::C, 'demo');
    }

    /**
     * The centre of a visitor, or null when the person is not one.
     *
     * @param int $userid
     * @return \stdClass|null
     */
    public static function centre_of(int $userid): ?\stdClass {
        if (!$userid) {
            return null;
        }
        $id = (int) get_user_preferences(self::PREF, 0, $userid);
        $centre = $id ? centres::get($id) : null;
        return $centre && (int) $centre->practice === self::KIND ? $centre : null;
    }

    /**
     * Whether the person is a visitor trying Eguahe.
     *
     * @param int $userid
     * @return bool
     */
    public static function is_visitor(int $userid): bool {
        return $userid && (int) get_user_preferences(self::PREF, 0, $userid) > 0;
    }

    /**
     * Whether one more visitor may start now, from an address of the internet: within the hour's allowance from it, and
     * with room on the site.
     *
     * @param string $ip
     * @return bool
     */
    public static function allowed_from(string $ip): bool {
        global $DB;
        if ($DB->count_records(centres::T, ['practice' => self::KIND]) >= self::MAX) {
            return false;
        }
        $cache = \cache::make(self::C, 'signupsfrom');
        $key = sha1('demo|' . $ip . '|' . get_site_identifier());
        $count = (int) $cache->get($key) + 1;
        $cache->set($key, $count);
        return $count <= self::PERHOUR;
    }

    /**
     * A centre and a passing account for a visitor, logged in: the account is theirs until the centre goes.
     *
     * @param string $lang
     * @return \stdClass The centre.
     */
    public static function start(string $lang): \stdClass {
        global $DB;
        [$centre, $userid] = self::make($lang);
        complete_user_login($DB->get_record('user', ['id' => $userid]));
        return $centre;
    }

    /**
     * A centre and a passing account for a visitor, not yet logged in.
     *
     * @param string $lang
     * @return array The centre and the id of the account.
     */
    public static function make(string $lang): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $lang = in_array($lang, ['es', 'en', 'pt', 'pt_br'], true) ? $lang : 'en';
        $before = force_current_language($lang);
        try {
            $id = practice::make(0, time(), self::KIND);
        } finally {
            force_current_language($before);
        }
        $key = bin2hex(random_bytes(6));
        $user = (object) [
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => 'demo-' . $key,
            // An address that can receive nothing: whatever a visitor tries, nothing leaves.
            'email' => 'demo-' . $key . '@eguahe.invalid',
            'emailstop' => 1,
            'firstname' => publiclang::str('demo_firstname', $lang),
            'lastname' => publiclang::str('demo_lastname', $lang),
            'lang' => $lang,
            'timezone' => '99',
        ];
        // No password: the visitor is in for this session only, and nobody can come in with that account another way.
        $user->id = method_exists(\core\user::class, 'create_user') ? \core\user::create_user($user, false, false) :
            user_create_user($user, false, false);
        set_user_preference(self::PREF, $id, $user->id);
        centres::add_member($id, (int) $user->id);
        centres::set_admin($id, (int) $user->id, true);
        // Whoever tries it sees their own name as the one who receives.
        $DB->set_field(centres::MEMBERS, 'public', 1, ['centreid' => $id, 'userid' => $user->id]);
        return [centres::get($id), (int) $user->id];
    }

    /**
     * Takes away the visitors' centres and accounts that have had their day.
     *
     * @param int $now
     * @return int How many went.
     */
    public static function tidy(int $now): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $gone = 0;
        $old = $DB->get_fieldset_select(centres::T, 'id', 'practice = ? AND timecreated < ?', [self::KIND, $now - self::KEEP]);
        foreach ($old as $id) {
            foreach ($DB->get_fieldset_select(centres::MEMBERS, 'userid', 'centreid = ?', [$id]) as $userid) {
                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                if ($user && self::is_visitor((int) $userid)) {
                    delete_user($user);
                }
            }
            practice::remove((int) $id);
            $gone++;
        }
        return $gone;
    }
}
