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
 * The artificial intelligence of each centre, through the AI subsystem of Moodle (5.0 and later, where providers are
 * instances): each centre chooses the instance with its own key, and Eguahe asks only that one. Moodle keeps its record
 * of each request and asks the team for its policy, as always. It works for the team, behind: never facing families,
 * never a mark on a household.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai {
    /**
     * Whether this Moodle has providers by instance.
     *
     * @return bool
     */
    public static function supported(): bool {
        return class_exists('\core_ai\manager') && method_exists('\core_ai\manager', 'get_sorted_providers');
    }

    /**
     * The provider instances of the site, for a centre to choose its own.
     *
     * @return array Instance id => name; empty where Moodle has no AI.
     */
    public static function providers(): array {
        if (!self::supported()) {
            return [];
        }
        $names = [];
        foreach (\core\di::get(\core_ai\manager::class)->get_sorted_providers() as $id => $provider) {
            $names[(int) $id] = format_string($provider->name);
        }
        return $names;
    }

    /**
     * The manager of Moodle that asks only the provider of a centre.
     *
     * @param \stdClass $centre
     * @return \core_ai\manager|null Null when the centre has none.
     */
    public static function manager(\stdClass $centre): ?\core_ai\manager {
        global $DB;
        if (!self::supported() || empty($centre->aiprovider)) {
            return null;
        }
        return (new ai_manager($DB))->only((int) $centre->aiprovider);
    }
}
