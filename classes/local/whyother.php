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
 * Why a family chose another centre, if it wants to say: once the team closes its way with «They chose another centre»,
 * its page asks it in one tap, voluntary, for a while. What it says goes to its file and, added up and without names, to
 * Cifras. Never a score of the family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whyother {
    /** @var string[] What may have decided it, as the page offers it. */
    const REASONS = ['near', 'cost', 'hours', 'place', 'siblings', 'teaching', 'feel', 'other'];

    /** @var int For how long after the closing the page asks. */
    const DAYS = 60;

    /**
     * Since when the page may ask it: every child of the household closed, one of them because they chose another
     * centre, not long ago, and not told yet. Null when it does not ask.
     *
     * @param \stdClass $household
     * @param \stdClass[] $children
     * @param int $now
     * @return int|null The time of the closing.
     */
    public static function since(\stdClass $household, array $children, int $now): ?int {
        if (!$children || (int) ($household->whyothertime ?? 0)) {
            return null;
        }
        $closed = 0;
        foreach ($children as $child) {
            if ($child->stage !== 'closed') {
                return null;
            }
            if ($child->closedreason === 'othercentre') {
                $closed = max($closed, (int) $child->stagetime);
            }
        }
        return $closed && $closed > $now - self::DAYS * DAYSECS ? $closed : null;
    }

    /**
     * What the family said: the reasons it marked, and anything it wrote, which goes to its conversation without
     * waiting for an answer.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string[] $keys
     * @param string $text
     * @param int $now
     * @return bool Whether anything was kept.
     */
    public static function save(\stdClass $household, \stdClass $centre, array $keys, string $text, int $now): bool {
        global $DB;
        $keys = array_values(array_intersect(self::REASONS, array_map('strval', $keys)));
        $text = \core_text::substr(trim($text), 0, 500);
        if (!$keys && $text === '') {
            return false;
        }
        $DB->update_record(households::T, (object) ['id' => $household->id, 'whyother' => implode(',', $keys) ?: null,
            'whyothertime' => $now, 'timemodified' => $now]);
        $household->whyother = implode(',', $keys);
        $household->whyothertime = $now;
        $lang = $centre->lang ?: 'es';
        $body = get_string_manager()->get_string('whyother_told', 'local_oksigeniaeguahe', self::words($household, $lang), $lang) .
            ($text !== '' ? "\n\n«" . $text . '»' : '');
        households::received($household, 'page', $body, false, $now, 0, '');
        return true;
    }

    /**
     * What the family marked, in words of the team: «the cost and the hours».
     *
     * @param \stdClass $household
     * @param string $lang
     * @return string
     */
    public static function words(\stdClass $household, string $lang = ''): string {
        $lang = $lang ?: current_language();
        $keys = array_filter(explode(',', (string) ($household->whyother ?? '')));
        $words = array_map(
            fn($k) => get_string_manager()->get_string('whyother_' . $k, 'local_oksigeniaeguahe', null, $lang),
            array_values(array_intersect(self::REASONS, $keys))
        );
        if (!$words) {
            return get_string_manager()->get_string('whyother_none', 'local_oksigeniaeguahe', null, $lang);
        }
        $last = array_pop($words);
        $and = get_string_manager()->get_string('whyother_and', 'local_oksigeniaeguahe', null, $lang);
        return $words ? implode(', ', $words) . ' ' . $and . ' ' . $last : $last;
    }

    /**
     * The reasons told in a centre since a moment, added up: never which family.
     *
     * @param int $centreid
     * @param int $since
     * @return array reason => families, the most told first; and told, the families that answered.
     */
    public static function counts(int $centreid, int $since): array {
        global $DB;
        $counts = array_fill_keys(self::REASONS, 0);
        $told = 0;
        $rows = $DB->get_records_select(
            households::T,
            'centreid = :centreid AND whyothertime > :since',
            ['centreid' => $centreid, 'since' => $since],
            '',
            'id, whyother'
        );
        foreach ($rows as $row) {
            $told++;
            foreach (array_intersect(self::REASONS, explode(',', (string) $row->whyother)) as $key) {
                $counts[$key]++;
            }
        }
        arsort($counts);
        return ['reasons' => array_filter($counts), 'told' => $told];
    }

    /**
     * The question on the page of the family, or its thanks once told.
     *
     * @param \stdClass $household
     * @param string $lang
     * @param string $action Where it is sent.
     * @return array
     */
    public static function context(\stdClass $household, string $lang, string $action): array {
        $s = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        return [
            'action' => $action,
            'sesskey' => sesskey(),
            'title' => $s('pub_whyother_title'),
            'lead' => $s('pub_whyother_lead'),
            'options' => array_map(fn($k) => ['key' => $k, 'label' => $s('pub_whyother_' . $k)], self::REASONS),
            'othertext' => $s('pub_whyother_write'),
            'button' => $s('pub_whyother_send'),
            'done' => false,
        ];
    }
}
