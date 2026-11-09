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

namespace local_oksigeniaeguahe\local\opendata_sources;

/**
 * A source of open data for the forecast: who gives, for some municipalities, the births by year and the children by
 * age. Eguahe knows no country: each source is a class of this namespace, found by itself, that says which official
 * codes it covers and brings their numbers. Where no source covers the area, the centre uploads the same numbers as a
 * CSV (imports.php, «population»).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class source {
    /**
     * Its key in Eguahe, kept with each number it brings; its name is the string opendata_source_ and the key.
     *
     * @return string
     */
    abstract public function key(): string;

    /**
     * Whether it has the numbers of a municipality, by its official code.
     *
     * @param string $code
     * @return bool
     */
    abstract public function covers(string $code): bool;

    /**
     * The numbers of some municipalities it covers: births by year (age -1) and children by single age.
     *
     * @param string[] $codes Only codes it covers.
     * @param int $maxage The oldest age wanted.
     * @return array Rows: kind (births or age), municipality, year, age, people.
     * @throws \moodle_exception When it does not answer.
     */
    abstract public function rows(array $codes, int $maxage): array;
}
