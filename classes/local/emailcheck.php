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
 * Whether an email can get there, before anything is sent to it: most mail that bounces was mistyped (gmial.com,
 * hotmail.con). A domain that cannot receive mail is refused when a family writes it; one that looks like a slip of a
 * common one is pointed out, to the family as it writes and to the team in the file of the household.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class emailcheck {
    /** @var string[] The domains most families write, in any country: a slip is measured against them. */
    const DOMAINS = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.es', 'hotmail.co.uk', 'hotmail.fr',
        'hotmail.it', 'hotmail.com.br', 'outlook.com', 'outlook.es', 'outlook.com.br', 'outlook.pt', 'live.com', 'live.co.uk',
        'live.com.mx', 'msn.com', 'yahoo.com', 'yahoo.es', 'yahoo.co.uk', 'yahoo.fr', 'yahoo.com.mx', 'yahoo.com.ar',
        'yahoo.com.br', 'ymail.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'gmx.com', 'gmx.es', 'gmx.de', 'gmx.net',
        'protonmail.com', 'proton.me', 'telefonica.net', 'movistar.es', 'orange.es', 'orange.fr', 'free.fr', 'web.de',
        'uol.com.br', 'bol.com.br', 'terra.com.br', 'sapo.pt', 'mail.com', 'zoho.com', 'yandex.com'];

    /** @var bool|null Only for the tests: whether every domain receives mail, without asking the DNS. */
    public static $testreachable = null;

    /**
     * The email they surely meant, when the domain looks like a slip of a common one; null when it looks right.
     *
     * @param string $email
     * @return string|null
     */
    public static function suggest(string $email): ?string {
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domain = \core_text::strtolower(trim(substr($email, $at + 1)));
        if ($domain === '' || in_array($domain, self::DOMAINS, true)) {
            return null;
        }
        $best = null;
        $distance = PHP_INT_MAX;
        foreach (self::DOMAINS as $known) {
            $d = levenshtein($domain, $known);
            if ($d < $distance) {
                [$best, $distance] = [$known, $d];
            }
        }
        // One letter off for a short domain, up to two for a longer one: more than that is another domain.
        $limit = strlen($domain) < 8 ? 1 : 2;
        return $best !== null && $distance > 0 && $distance <= $limit ? substr($email, 0, $at) . '@' . $best : null;
    }

    /**
     * Whether the domain of an email can receive mail: it has a mail server, or at least an address. Asked once a day
     * per domain; when the DNS does not answer, it counts as yes.
     *
     * @param string $email
     * @return bool
     */
    public static function reachable(string $email): bool {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = \core_text::strtolower(rtrim(trim(substr($email, $at + 1)), '.'));
        if (self::$testreachable !== null || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return self::$testreachable ?? true;
        }
        if ($domain === '' || in_array($domain, self::DOMAINS, true) || !function_exists('checkdnsrr')) {
            return $domain !== '';
        }
        // The cache takes simple keys only: the domain as a hash.
        $cache = \cache::make('local_oksigeniaeguahe', 'maildomains');
        $key = md5($domain);
        $known = $cache->get($key);
        if ($known !== false) {
            return (bool) $known;
        }
        $mx = @checkdnsrr($domain . '.', 'MX');
        $yes = $mx || @checkdnsrr($domain . '.', 'A') || @checkdnsrr($domain . '.', 'AAAA');
        $cache->set($key, $yes ? 1 : 0);
        return $yes;
    }

    /**
     * What the team should know of an email before writing to it: that it looks mistyped, or that it cannot receive
     * mail. Empty when it looks right.
     *
     * @param string $email
     * @return string
     */
    public static function warning(string $email): string {
        if (trim($email) === '') {
            return '';
        }
        if (!self::reachable($email)) {
            return get_string('panel_email_unreachable', 'local_oksigeniaeguahe');
        }
        $maybe = self::suggest($email);
        return $maybe !== null ? get_string('panel_email_maybe', 'local_oksigeniaeguahe', $maybe) : '';
    }
}
