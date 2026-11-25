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
 * The families behind a number, to open them in the inbox: each number of Numbers has a key, worked out again when it is
 * opened, so a link always gives the families of now.
 *
 * Of an act: «act-e12-came», «act-d20260314-signed». Of a campaign: «year-2027_28-came», «year-2027_28-channel_search»,
 * «year-2027_28-why_cost». Of the visits: «visits-unknown».
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class familylists {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] What of a campaign can be opened. */
    const OFYEAR = ['campaign', 'came', 'applied', 'waitlist', 'place', 'enrolled', 'went', 'closed', 'nokids', 'undated', 'fresh', 'knew',
        'centre', 'other', 'unmeasured', 'waiting', 'answered', 'unanswered', 'tried', 'unreached', 'met', 'quiet', 'open'];

    /** @var string[] What of the visits can be opened. */
    const OFVISITS = ['came', 'notcame', 'off', 'unknown', 'ahead'];

    /**
     * The address of the inbox with the families of a number.
     *
     * @param \stdClass $centre
     * @param string $key
     * @return string
     */
    public static function url(\stdClass $centre, string $key): string {
        return (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id, 'all' => 1, 'f' => $key]))->out(false);
    }

    /**
     * The key of a number of a campaign.
     *
     * @param array $campaign
     * @param string $what Of OFYEAR, or «channel.» or «why.» and its key.
     * @return string
     */
    public static function of_year(array $campaign, string $what): string {
        return 'year-' . str_replace('-', '_', (string) $campaign['year']) . '-' . $what;
    }

    /**
     * The name of a number, in the singular when there is one.
     *
     * @param string $key Of the string, without its «_one».
     * @param int $n
     * @return string
     */
    public static function name(string $key, int $n): string {
        $one = $n === 1 && get_string_manager()->string_exists($key . '_one', self::C);
        return get_string($key . ($one ? '_one' : ''), self::C);
    }

    /**
     * A number with the families behind it.
     *
     * @param \stdClass $centre
     * @param string $list Its key.
     * @param string $name
     * @param int[] $ids Of every family; 0, one that is only in a file.
     * @return array key, name, n, ids, list, url and noids (how many have no file of their own)
     */
    public static function num(\stdClass $centre, string $list, string $name, array $ids): array {
        $known = self::clean($ids);
        $rest = count($ids) - count($known);
        return ['key' => substr((string) strrchr($list, '-'), 1), 'name' => $name, 'n' => count($ids), 'ids' => $known,
            'list' => $known ? $list : '', 'url' => $known ? self::url($centre, $list) : false, 'noids' => $rest > 0 ?
            get_string('actr_noids' . ($rest === 1 ? '_one' : ''), self::C, $rest) : ''];
    }

    /**
     * The numbers of a campaign, each with its families.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array[] $all From facts::all().
     * @param string[] $which Of OFYEAR.
     * @return array[] Only those with someone.
     */
    public static function nums(\stdClass $centre, array $campaign, array $all, array $which): array {
        $out = [];
        foreach ($which as $what) {
            $ids = array_column(self::families($centre, $all, $what, '', time()) ?? [], 'id');
            if ($ids) {
                $out[] = self::num($centre, self::of_year($campaign, $what), self::name('familylist_' . $what, count($ids)), $ids);
            }
        }
        return $out;
    }

    /**
     * The families of a key.
     *
     * @param \stdClass $centre
     * @param string $key
     * @param int $now
     * @return array|null ids and title; null, it is not a list
     */
    public static function of(\stdClass $centre, string $key, int $now = 0): ?array {
        $now = $now ?: time();
        $c = self::C;
        if (preg_match('/^act-([ed]\d+)-([a-z]+)$/', $key, $m)) {
            $act = act_report::all($centre, $now, true)[$m[1]] ?? null;
            $lists = $act ? act_report::lists($centre, $act, $now) : [];
            if (!isset($lists[$m[2]]) || !get_string_manager()->string_exists('actr_num_' . $m[2], $c)) {
                return null;
            }
            return ['ids' => self::clean($lists[$m[2]]), 'title' => get_string('familylist_act', $c, (object) ['act' => $act['name'],
                'when' => userdate($act['time'], get_string('strftimedateshort', 'langconfig')),
                'what' => get_string('actr_num_' . $m[2], $c)])];
        }
        if (preg_match('/^visits-([a-z]+)$/', $key, $m) && in_array($m[1], self::OFVISITS, true)) {
            return ['ids' => self::clean(numbers_questions::visit_outcomes($centre, $now)[$m[1]]),
                'title' => get_string('familylist_visits_' . $m[1], $c)];
        }
        if (preg_match('/^year-([0-9_]*)-([a-z]+)(?:_([a-z]+))?$/', $key, $m)) {
            $year = str_replace('_', '-', $m[1]);
            $campaign = current(array_filter(facts::campaigns($centre, $now), fn($one) => $one['year'] === $year));
            if (!$campaign) {
                return null;
            }
            $families = self::families($centre, facts::all($centre, $campaign, $now), $m[2], $m[3] ?? '', $now);
            if ($families === null) {
                return null;
            }
            $ids = array_column($families, 'id');
            $what = $m[2] === 'channel' ? arrival::channel_name($m[3]) : ($m[2] === 'why' ? get_string('out_' . $m[3], $c) :
                get_string('familylist_' . $m[2], $c));
            return ['ids' => self::clean($ids), 'title' => get_string('familylist_year', $c, (object) ['year' => $campaign['name'], 'what' => $what])];
        }
        return null;
    }

    /**
     * The families of a number of a campaign.
     *
     * @param \stdClass $centre
     * @param array[] $all From facts::all().
     * @param string $what
     * @param string $of The channel or the reason.
     * @param int $now
     * @return array[]|null
     */
    protected static function families(\stdClass $centre, array $all, string $what, string $of, int $now): ?array {
        $mine = facts::group($all, 'campaign');
        $pick = fn(callable $is) => array_values(array_filter($mine, $is));
        if ($what === 'channel' && in_array($of, arrival::CHANNELS, true)) {
            return $pick(fn($f) => $f['channel'] === $of);
        }
        if ($what === 'why' && in_array($of, outs::LEFT, true)) {
            return $pick(fn($f) => $f['closed'] && $f['why'] === $of);
        }
        if (!in_array($what, self::OFYEAR, true)) {
            return null;
        }
        switch ($what) {
            case 'campaign':
                return $mine;
            case 'centre':
            case 'other':
                return facts::group($all, $what);
            case 'fresh':
                return $pick(fn($f) => $f['who'] === 'new');
            case 'knew':
                return $pick(fn($f) => in_array($f['who'], ['former', 'alumni'], true));
            case 'undated':
                return $pick(fn($f) => !$f['dated']);
            case 'unmeasured':
                return $pick(fn($f) => $f['channel'] === 'unmeasured');
            case 'waiting':
                return $pick(fn($f) => $f['waiting'] > 0);
            case 'open':
                return $pick(fn($f) => !$f['closed'] && !$f['went']);
            case 'answered':
            case 'unanswered':
            case 'tried':
            case 'unreached':
            case 'met':
            case 'quiet':
                $who = metrics::response((int) $centre->id, facts::ids(array_filter($mine, [facts::class, 'lives'])), $now)['who'];
                $those = $who[$what === 'unanswered' ? 'waiting' : $what];
                if ($what === 'tried') {
                    $those = array_intersect_key($those, $who['waiting']);
                }
                return $pick(fn($f) => facts::lives($f) && isset($those[$f['id']]));
            default:
                return $pick(fn($f) => !empty($f[$what]));
        }
    }

    /**
     * The households of a list, once each.
     *
     * @param int[] $ids
     * @return int[]
     */
    protected static function clean(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
