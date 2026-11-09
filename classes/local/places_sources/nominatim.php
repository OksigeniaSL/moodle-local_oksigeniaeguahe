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
 * OpenStreetMap through Nominatim: any place of the world, without a key, one question a second as its rules of use
 * ask. The positions of OpenStreetMap are open data (ODbL), cited where they are shown.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nominatim extends source {
    /** @var string The service; a site can point to its own. */
    const URL = 'https://nominatim.openstreetmap.org/search';

    #[\Override]
    public function key(): string {
        return 'nominatim';
    }

    #[\Override]
    public function ready(): bool {
        return true;
    }

    #[\Override]
    public function find(string $name, string $country): array {
        $base = trim((string) get_config('local_oksigeniaeguahe', 'nominatimurl')) ?: self::URL;
        $params = ['q' => $name, 'format' => 'jsonv2', 'limit' => 5] + ($country !== '' ?
            ['countrycodes' => strtolower($country)] : []);
        $out = [];
        foreach ($this->get($base . '?' . http_build_query($params, '', '&')) as $row) {
            if (isset($row['lat'], $row['lon'])) {
                $out[] = [(float) $row['lat'], (float) $row['lon']];
            }
        }
        return $out;
    }
}
