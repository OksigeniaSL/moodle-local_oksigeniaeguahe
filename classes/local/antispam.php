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

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;

/**
 * Against spam in the public forms, without third parties nor cookies: ALTCHA (MIT, thirdparty/altcha-lib-php), a
 * small proof of work that the phone of the family solves while it fills the form, signed by the site and used once.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class antispam {
    /** @var int Seconds a challenge lives. */
    const LIFETIME = 1200;

    /** @var int Rounds of PBKDF2 of each attempt: well under a second on a modest phone. */
    const COST = 2000;

    /** @var bool|null Whether a solution passes in the tests, without solving it; null outside them. */
    public static $testpass = null;

    /**
     * Loads the library, which has no autoloader of Moodle.
     */
    protected static function load(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $base = __DIR__ . '/../../thirdparty/altcha-lib-php/src/';
        spl_autoload_register(function (string $class) use ($base): void {
            $prefix = 'AltchaOrg\\Altcha\\';
            if (strpos($class, $prefix) === 0) {
                $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_readable($file)) {
                    require_once($file);
                }
            }
        });
        $done = true;
    }

    /**
     * The secret of the site that signs the challenges, made the first time.
     *
     * @return string
     */
    protected static function secret(): string {
        $secret = (string) get_config('local_oksigeniaeguahe', 'antispamsecret');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            set_config('antispamsecret', $secret, 'local_oksigeniaeguahe');
        }
        return $secret;
    }

    /**
     * A new challenge, as the widget reads it.
     *
     * @return array
     */
    public static function challenge(): array {
        self::load();
        $altcha = new Altcha(hmacSignatureSecret: self::secret());
        $challenge = $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: self::COST,
            expiresAt: time() + self::LIFETIME,
        ));
        return $challenge->toArray();
    }

    /**
     * Whether what the form sent solves a challenge of this site that has not been used before; uses it.
     *
     * @param string $payload The field «altcha» of the form, as the widget wrote it.
     * @return bool
     */
    public static function verify(string $payload): bool {
        if (self::$testpass !== null) {
            return self::$testpass;
        }
        if ($payload === '' || strlen($payload) > 4096) {
            return false;
        }
        self::load();
        try {
            $altcha = new Altcha(hmacSignatureSecret: self::secret());
            $result = $altcha->verifySolution(new VerifySolutionOptions(payload: $payload, algorithm: new Pbkdf2()));
        } catch (\Throwable $e) {
            return false;
        }
        if (!$result->verified) {
            return false;
        }
        // Once only: a solved challenge sent twice is a copy, however its payload is written.
        $decoded = json_decode((string) base64_decode($payload, true), true);
        $signature = (string) ($decoded['challenge']['signature'] ?? '');
        if ($signature === '') {
            return false;
        }
        $cache = \cache::make('local_oksigeniaeguahe', 'usedchallenges');
        $key = sha1($signature);
        if ($cache->get($key)) {
            return false;
        }
        $cache->set($key, 1);
        return true;
    }
}
