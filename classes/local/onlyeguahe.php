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
 * A site that is only Eguahe, shared by several centres (the hosted Eguahe): its own way in, no home, dashboard or
 * courses of the platform, and each team in its own centre with a role that only lets it work for families.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class onlyeguahe {
    /** @var string Component. */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The pages of the platform that lead to Eguahe instead. */
    const AWAY = ['site-index', 'my-index', 'my-courses', 'course-index', 'course-index-category', 'course-search'];

    /** @var string The short name of the role every team member gets in a site that is only Eguahe. */
    const ROLE = 'eguaheteam';

    /**
     * Whether the site is only Eguahe.
     *
     * @return bool
     */
    public static function on(): bool {
        return (bool) get_config(self::C, 'onlyeguahe');
    }

    /**
     * Whether the person logged in runs the whole site: they keep the pages of the platform.
     *
     * @return bool
     */
    public static function runs_the_site(): bool {
        return isloggedin() && !isguestuser() && has_capability('moodle/site:config', \context_system::instance());
    }

    /**
     * The way in of the site.
     *
     * @return \moodle_url
     */
    public static function entry(): \moodle_url {
        return new \moodle_url('/local/oksigeniaeguahe/entrar.php');
    }

    /**
     * Where a page of the platform leads in a site that is only Eguahe, or null to let it be.
     *
     * @param string $pagetype
     * @return \moodle_url|null
     */
    public static function instead_of(string $pagetype): ?\moodle_url {
        if (!self::on() || !in_array($pagetype, self::AWAY, true) || self::runs_the_site()) {
            return null;
        }
        return isloggedin() && !isguestuser() ? new \moodle_url('/local/oksigeniaeguahe/index.php') : self::entry();
    }

    /**
     * The page of the service's terms and privacy, as the site writes it; empty, none.
     *
     * @return string
     */
    public static function legal(): string {
        $url = trim((string) get_config(self::C, 'onlyeguahelegal'));
        return clean_param($url, PARAM_URL);
    }

    /**
     * The role of the teams, made the first time it is needed: it lets work for the families of one's own centres, and
     * nothing else of the platform.
     *
     * @return int
     */
    public static function role(): int {
        global $DB;
        $id = (int) $DB->get_field('role', 'id', ['shortname' => self::ROLE]);
        if (!$id) {
            $id = create_role(get_string('onlyeguahe_role', self::C), self::ROLE, get_string('onlyeguahe_role_desc', self::C));
            set_role_contextlevels($id, [CONTEXT_SYSTEM]);
        }
        assign_capability('local/oksigeniaeguahe:work', CAP_ALLOW, $id, \context_system::instance()->id, true);
        return $id;
    }

    /**
     * Gives the role of the teams to someone who joined a team, in a site that is only Eguahe.
     *
     * @param int $userid
     */
    public static function join(int $userid): void {
        if (!self::on()) {
            return;
        }
        $context = \context_system::instance();
        $role = self::role();
        if (!user_has_role_assignment($userid, $role, $context->id)) {
            role_assign($role, $userid, $context->id, 'local_oksigeniaeguahe');
        }
    }

    /**
     * Takes the role of the teams from someone who is in no team any more.
     *
     * @param int $userid
     */
    public static function leave(int $userid): void {
        global $DB;
        if (!self::on() || $DB->record_exists(centres::MEMBERS, ['userid' => $userid])) {
            return;
        }
        $role = (int) $DB->get_field('role', 'id', ['shortname' => self::ROLE]);
        if ($role) {
            role_unassign($role, $userid, \context_system::instance()->id, 'local_oksigeniaeguahe');
        }
    }

    /**
     * When the site becomes only Eguahe: every member of a team gets the role of the teams.
     */
    public static function settings_changed(): void {
        global $DB;
        if (!self::on()) {
            return;
        }
        foreach ($DB->get_fieldset_sql('SELECT DISTINCT userid FROM {' . centres::MEMBERS . '}') as $userid) {
            self::join((int) $userid);
        }
    }

    /**
     * A new account for someone of a team, made by whoever changes the settings of their centre: they get an email with
     * their user name and a password to change the first time they come in.
     *
     * @param string $email
     * @param string $name Their name and surnames, as the team writes them.
     * @param string $lang Their language; empty, the one of whoever makes the account.
     * @return \stdClass|null The account, or null when the email is not one.
     */
    public static function new_account(string $email, string $name, string $lang = ''): ?\stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $email = \core_text::strtolower(trim($email));
        if (!validate_email($email)) {
            return null;
        }
        $where = 'deleted = 0 AND ' . $DB->sql_equal('email', ':email', false);
        $existing = $DB->get_record_select('user', $where, ['email' => $email], '*', IGNORE_MULTIPLE);
        if ($existing) {
            return $existing;
        }
        $words = preg_split('/\s+/u', trim($name), 2, PREG_SPLIT_NO_EMPTY);
        $local = \core_text::substr(strstr($email, '@', true), 0, 60);
        $user = (object) [
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $email,
            'email' => $email,
            'firstname' => $words[0] ?? $local,
            'lastname' => $words[1] ?? '',
            'lang' => $lang !== '' ? $lang : current_language(),
            'timezone' => '99',
        ];
        // From 5.3 the platform makes accounts with core\user; before, with user_create_user().
        $user->id = method_exists(\core\user::class, 'create_user') ? \core\user::create_user($user, false, false) :
            user_create_user($user, false, false);
        $user = $DB->get_record('user', ['id' => $user->id]);
        // Moodle's own way: a password made for them, sent by email, to change when they first come in.
        setnew_password_and_mail($user);
        set_user_preference('auth_forcepasswordchange', 1, $user);
        return $user;
    }
}
