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
 * Short links through a link shortener that speaks the API of YOURLS (YOURLS itself, or another service compatible with
 * it): a long address in, a short one out, and its destination changed when the long one is renewed. Always by POST, so
 * the key never travels in an address.
 *
 * Shared by the plugins of Oksigenia: the original lives in moodle-tools/comun and each plugin carries a copy in its own
 * namespace (comun/copia.sh). The administrator sets the shortener once: a plugin without its own address and key uses
 * those of the first plugin of Oksigenia that has them (settings «shortenerapi» and «shortenerkey»).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shortener {
    /** @var int Seconds to wait for the shortener: a page never waits long for a nicety. */
    const TIMEOUT = 4;

    /** @var int Seconds to wait before trying again when it says it is going too fast (none in the tests). */
    public static int $wait = 15;

    /** @var int How many times to try again then: a minute in all, what a limit per minute needs. */
    const RETRIES = 5;

    /** @var string Address of its API (yourls-api.php). */
    protected string $api;

    /** @var string The key of this program (the «signature» of YOURLS). */
    protected string $key;

    /**
     * A shortener with its address and key.
     *
     * @param string $api Address of its API.
     * @param string $key The key of this program.
     */
    public function __construct(string $api, string $key) {
        $this->api = trim($api);
        $this->key = trim($key);
    }

    /**
     * The shortener of this site, or null when it has none: the one of this plugin, or else the one of the first plugin of
     * Oksigenia that has one, so the administrator sets it once for all of them. Only by https.
     *
     * @return self|null
     */
    public static function of_site(): ?self {
        global $DB;
        // The plugin of this copy: its namespace starts with its name («local_oksigeniameals\local»).
        $own = explode('\\', __NAMESPACE__)[0];
        $usable = fn(string $api, string $key) => trim($api) !== '' && trim($key) !== '' && preg_match('#^https://#i', trim($api));
        $api = (string) get_config($own, 'shortenerapi');
        $key = (string) get_config($own, 'shortenerkey');
        if ($usable($api, $key)) {
            return new self($api, $key);
        }
        $others = $DB->get_records_select(
            'config_plugins',
            "name IN ('shortenerapi', 'shortenerkey') AND " . $DB->sql_like('plugin', ':oks'),
            ['oks' => '%oksigenia%'],
            'plugin, name'
        );
        $by = [];
        foreach ($others as $setting) {
            $by[$setting->plugin][$setting->name] = (string) $setting->value;
        }
        foreach ($by as $plugin => $settings) {
            if ($usable($settings['shortenerapi'] ?? '', $settings['shortenerkey'] ?? '')) {
                return new self($settings['shortenerapi'], $settings['shortenerkey']);
            }
        }
        return null;
    }

    /**
     * The short address of a long one: a new one, or the one it already had.
     *
     * @param string $url The long address.
     * @param string $title What it is, for whoever looks at the links of the shortener.
     * @param bool $patient Whether to wait and try again when it says it is going too fast (many at once).
     * @param string $keyword A word asked for («tele», to type it with a remote); when it is taken, the one it gives.
     * @return string|null The short address, or null when the shortener did not give one.
     */
    public function shorten(string $url, string $title = '', bool $patient = false, string $keyword = ''): ?string {
        $fields = ['action' => 'shorturl', 'url' => $url, 'title' => \core_text::substr($title, 0, 200)];
        if (trim($keyword) !== '') {
            $answer = $this->call($fields + ['keyword' => trim($keyword)]);
            if (($answer['code'] ?? '') !== 'error:keyword') {
                return self::short_of($answer);
            }
        }
        $answer = $this->call($fields);
        for ($try = 0; $patient && ($answer['code'] ?? '') === 'error:limit' && $try < self::RETRIES; $try++) {
            sleep(self::$wait);
            $answer = $this->call($fields);
        }
        return self::short_of($answer);
    }

    /**
     * The short address in an answer: created, or already there with the same long address.
     *
     * @param array $answer What the shortener answered.
     * @return string|null
     */
    protected static function short_of(array $answer): ?string {
        $short = (string) ($answer['shorturl'] ?? '');
        $ok = ($answer['status'] ?? '') === 'success' || ($answer['code'] ?? '') === 'error:url';
        return $ok && preg_match('#^https?://\S+$#', $short) ? $short : null;
    }

    /**
     * Send a short address to a new long one, when the long one was renewed.
     *
     * @param string $short The short address (or its name).
     * @param string $url The new long address.
     * @param string $title What it is.
     * @return bool Whether it changed.
     */
    public function update(string $short, string $url, string $title = ''): bool {
        $answer = $this->call(['action' => 'update', 'shorturl' => $short, 'url' => $url,
            'title' => \core_text::substr($title, 0, 200)]);
        return str_starts_with((string) ($answer['message'] ?? ''), 'success');
    }

    /**
     * One call to the API: by POST, as a form, with the key and asking for JSON.
     *
     * @param array $fields What to send.
     * @return array What it answered, or an empty array when it did not answer.
     */
    protected function call(array $fields): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setopt(['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT]);
        $curl->setHeader(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        try {
            $body = $curl->post($this->api, http_build_query($fields + ['signature' => $this->key, 'format' => 'json'], '', '&'));
        } catch (\Throwable $error) {
            debugging('Shortener: ' . $error->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
        $answer = json_decode((string) $body, true);
        return is_array($answer) ? $answer : [];
    }
}
