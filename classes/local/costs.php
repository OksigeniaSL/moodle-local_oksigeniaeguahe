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
 * What it all costs (the fee, the dining room, the activities, keeping a place), as the centre writes it, a line per
 * thing. Each centre decides when families see it: from the start, after their visit (by default: the price is worth more
 * once they have seen the place and how far its services go), or never.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class costs {
    /** @var string[] When families see it. */
    const WHEN = ['start', 'after', 'never'];

    /**
     * The lines the centre wrote, each as what and how much when it says «what: how much».
     *
     * @param \stdClass $centre
     * @return array A list of [what, amount].
     */
    public static function rows(\stdClass $centre): array {
        $rows = [];
        foreach (preg_split('/\R/u', trim((string) ($centre->costs ?? ''))) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $at = \core_text::strrpos($line, ':');
            $rows[] = $at !== false && $at > 0 && $at < \core_text::strlen($line) - 1 ?
                ['what' => trim(\core_text::substr($line, 0, $at)), 'amount' => trim(\core_text::substr($line, $at + 1))] :
                ['what' => $line, 'amount' => ''];
        }
        return $rows;
    }

    /**
     * Whether a family sees what it costs now: the centre wrote it and shows it from the start, or after the visit and
     * the family came to one.
     *
     * @param \stdClass $centre
     * @param bool $came Whether the family came to a visit or an open day.
     * @return bool
     */
    public static function shown(\stdClass $centre, bool $came): bool {
        if (!self::rows($centre)) {
            return false;
        }
        $when = (string) ($centre->costswhen ?? 'after');
        return $when === 'start' || ($when === 'after' && $came);
    }

    /**
     * What it costs on the page of a family, with a line to ask about it.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return array title, rows, note.
     */
    public static function page(\stdClass $centre, string $lang): array {
        return ['title' => publiclang::str('pub_costs_title', $lang), 'rows' => self::rows($centre),
            'note' => publiclang::str('pub_costs_note', $lang)];
    }

    /**
     * The card of the settings: the lines, and the three moments to show them as leaves to press.
     *
     * @param \stdClass $centre
     * @return array
     */
    public static function settings(\stdClass $centre): array {
        $when = in_array($centre->costswhen ?? '', self::WHEN, true) ? $centre->costswhen : 'after';
        $rows = self::rows($centre);
        return [
            'text' => (string) ($centre->costs ?? ''),
            'written' => (bool) $rows,
            'read' => $rows ? get_string('panel_costs_read_' . $when, 'local_oksigeniaeguahe', count($rows)) :
                get_string('panel_costs_read_none', 'local_oksigeniaeguahe'),
            'whens' => array_map(fn($w) => ['value' => $w, 'name' => get_string('panel_costs_when_' . $w, 'local_oksigeniaeguahe'),
                'pressed' => $w === $when], self::WHEN),
        ];
    }
}
