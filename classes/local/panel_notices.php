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
 * What the centre sent a family by itself, on its file: each with whether it went out, when and by what way, or why not.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_notices {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int How many of those that went are in sight; the ones before, folded. */
    const RECENT = 4;

    /**
     * Those of a family.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $household, \stdClass $centre, bool $canact, int $now = 0): array {
        $now = $now ?: time();
        $ahead = [];
        $rest = [];
        $older = [];
        $seen = [];
        foreach (notices::of($household, $centre, $now) as $one) {
            // Only the last of each kind and act goes again, or is still to be looked at; the ones before it are history.
            $key = $one['kind'] . '-' . $one['eventid'];
            $last = !$one['eventid'] || !isset($seen[$key]);
            $seen[$key] = true;
            $card = self::one(['again' => $one['again'] && $last] + $one, $canact, $now) + ['at' => $one['at']];
            if (!$last) {
                $older[] = $card;
            } else if ($card['state'] === 'queued' || ($card['didnot'] && $one['start'] > $now)) {
                $ahead[] = $card;
            } else {
                $rest[] = $card;
            }
        }
        $older = array_merge($older, array_slice($rest, self::RECENT));
        usort($older, fn($x, $y) => $y['at'] <=> $x['at']);
        return ['ahead' => $ahead, 'recent' => array_slice($rest, 0, self::RECENT), 'older' => $older, 'hasolder' => (bool) $older,
            'hasnotices' => $ahead || $rest, 'sesskey' => sesskey(),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false)];
    }

    /**
     * One of them, in words.
     *
     * @param array $one As notices::of() gives it.
     * @param bool $canact
     * @param int $now
     * @return array
     */
    protected static function one(array $one, bool $canact, int $now): array {
        $why = $one['reason'] !== '' ? get_string('notice_why_' . $one['reason'], self::C) : '';
        switch ($one['state']) {
            case 'sent':
                $text = get_string('notice_sent_' . $one['channel'], self::C, panel::when_words($one['at'], $now));
                break;
            case 'inqueue':
                $text = get_string('notice_inqueue', self::C, panel::when_words($one['at'], $now));
                break;
            case 'queued':
                $text = get_string('notice_queued', self::C, panel_agenda::day($one['due']));
                break;
            case 'blocked':
                $text = get_string('notice_blocked', self::C, $why);
                break;
            case 'unknown':
                $text = get_string('notice_unknown', self::C);
                break;
            default:
                $text = get_string('notice_skipped', self::C, (object) ['when' => panel::when_words($one['at'], $now), 'why' => $why]);
        }
        return ['id' => $one['id'], 'kind' => $one['kind'], 'name' => get_string('notice_kind_' . $one['kind'], self::C),
            'what' => $one['what'], 'day' => $one['start'] ? panel::day_hour($one['start']) : '', 'state' => $one['state'],
            'is' . $one['state'] => true, 'went' => $one['state'] === 'sent',
            'didnot' => in_array($one['state'], ['skipped', 'failed', 'blocked'], true), 'text' => $text,
            'again' => $canact && $one['again']];
    }
}
