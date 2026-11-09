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

namespace local_oksigeniaeguahe\local\places_sources;

/**
 * A source of positions of places: given a name and a country, where it is (latitude and longitude). Worldwide and
 * interchangeable: each source is a class of this namespace, found by itself; the site chooses one in its settings.
 * When a name is in several places, the one nearest to the centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class source {
    /**
     * Its key, kept with each position it gives; its name is the string places_source_ and the key.
     *
     * @return string
     */
    abstract public function key(): string;

    /**
     * Whether it can be asked now (some ask for a user of theirs, set in the settings of the site).
     *
     * @return bool
     */
    abstract public function ready(): bool;

    /**
     * The seconds to wait between two questions, as its rules of use ask.
     *
     * @return float
     */
    public function pause(): float {
        return 1.1;
    }

    /**
     * Asks a web service and reads its JSON.
     *
     * @param string $url
     * @return array
     * @throws \moodle_exception When it does not answer.
     */
    protected function get(string $url): array {
        global $CFG;
        if (\local_oksigeniaeguahe\local\places::$testanswer !== null) {
            return (\local_oksigeniaeguahe\local\places::$testanswer)($url);
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        // Their rules ask each application to say who it is.
        $curl->setHeader(['Accept: application/json', 'User-Agent: Eguahe (Moodle plugin; ' . $CFG->wwwroot . ')']);
        $body = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 20, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        $info = $curl->get_info();
        if ($curl->get_errno() || (int) ($info['http_code'] ?? 0) !== 200) {
            throw new \moodle_exception('places_failed', 'local_oksigeniaeguahe', '', $this->key());
        }
        return json_decode((string) $body, true) ?: [];
    }

    /**
     * The places of a name in a country, the best first.
     *
     * @param string $name A town, or an address.
     * @param string $country ISO 3166-1 alpha-2, or '' for anywhere.
     * @return array A list of [lat, lon].
     * @throws \moodle_exception When it does not answer.
     */
    abstract public function find(string $name, string $country): array;
}
