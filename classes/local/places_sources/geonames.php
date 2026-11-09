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
 * GeoNames: the populated places of the world, with the user of the site at geonames.org (free), set in the settings
 * of the site. Its data is open (CC BY 4.0), cited where it is shown.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class geonames extends source {
    /** @var string The service. */
    const URL = 'https://secure.geonames.org/searchJSON';

    #[\Override]
    public function key(): string {
        return 'geonames';
    }

    #[\Override]
    public function ready(): bool {
        return trim((string) get_config('local_oksigeniaeguahe', 'geonamesuser')) !== '';
    }

    #[\Override]
    public function pause(): float {
        return 0.5;
    }

    #[\Override]
    public function find(string $name, string $country): array {
        $params = ['q' => $name, 'maxRows' => 5, 'featureClass' => 'P', 'orderby' => 'relevance',
            'username' => trim((string) get_config('local_oksigeniaeguahe', 'geonamesuser'))] + ($country !== '' ?
            ['country' => strtoupper($country)] : []);
        $out = [];
        foreach ($this->get(self::URL . '?' . http_build_query($params, '', '&'))['geonames'] ?? [] as $row) {
            if (isset($row['lat'], $row['lng'])) {
                $out[] = [(float) $row['lat'], (float) $row['lng']];
            }
        }
        return $out;
    }
}
