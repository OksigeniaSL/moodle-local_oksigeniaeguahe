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
 * The labels a centre prints for the children who come: the label of each backpack with the name used at home, and a
 * sticker for each bottle, on sheets of sticky labels of Apli and Avery. What a centre keeps of its sheets (its
 * favourites of each kind and how much its printer moves each) goes with the centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class labels {
    /** @var string[] What is printed: the label of a backpack, the sticker of a bottle. */
    const KINDS = ['labels', 'bottles'];

    /** @var int The most labels of one print. */
    const LIMIT = 400;

    /**
     * What a centre keeps of its sheets: its favourites of each kind, in its order, and how much it moves the print of
     * each sheet in its printer.
     *
     * @param \stdClass $centre
     * @return array favs (kind => keys) and nudge (key => [x, y]).
     */
    public static function own(\stdClass $centre): array {
        $own = json_decode((string) ($centre->labelsheets ?? ''), true) ?: [];
        $favs = [];
        foreach (self::KINDS as $kind) {
            $favs[$kind] = array_values(array_filter(
                (array) ($own['favs'][$kind] ?? []),
                fn($key) => isset(label_sheets::MODELS[$key])
            ));
        }
        $nudge = [];
        foreach ((array) ($own['nudge'] ?? []) as $key => $xy) {
            if (isset(label_sheets::MODELS[$key])) {
                $nudge[$key] = [(float) ($xy[0] ?? 0), (float) ($xy[1] ?? 0)];
            }
        }
        return ['favs' => $favs, 'nudge' => $nudge];
    }

    /**
     * Marks a sheet as a favourite of a kind, or unmarks it.
     *
     * @param \stdClass $centre
     * @param string $kind
     * @param string $key
     * @return bool Whether it is a favourite now.
     */
    public static function favourite(\stdClass $centre, string $kind, string $key): bool {
        if (!isset(label_sheets::MODELS[$key])) {
            return false;
        }
        $kind = self::kind($kind);
        $own = self::own($centre);
        $was = in_array($key, $own['favs'][$kind], true);
        $own['favs'][$kind] = $was ? array_values(array_diff($own['favs'][$kind], [$key])) :
            array_slice(array_merge([$key], $own['favs'][$kind]), 0, 12);
        self::keep($centre, $own);
        return !$was;
    }

    /**
     * Keeps how much this centre moves the print of a sheet.
     *
     * @param \stdClass $centre
     * @param string $key
     * @param float $x Millimetres to the right.
     * @param float $y Millimetres down.
     */
    public static function nudge(\stdClass $centre, string $key, float $x, float $y): void {
        if (!isset(label_sheets::MODELS[$key])) {
            return;
        }
        $own = self::own($centre);
        $clamp = fn(float $v) => round(max(-label_sheets::NUDGE, min(label_sheets::NUDGE, $v)), 1);
        $own['nudge'][$key] = [$clamp($x), $clamp($y)];
        if (!$own['nudge'][$key][0] && !$own['nudge'][$key][1]) {
            unset($own['nudge'][$key]);
        }
        self::keep($centre, $own);
    }

    /**
     * The sheet a page shows: the one asked for, else the first favourite of its kind, else the default.
     *
     * @param \stdClass $centre
     * @param string $kind
     * @param string $asked
     * @return string
     */
    public static function chosen(\stdClass $centre, string $kind, string $asked): string {
        $kind = self::kind($kind);
        if ($asked !== '') {
            return label_sheets::key($asked, $kind);
        }
        return label_sheets::key(self::own($centre)['favs'][$kind][0] ?? '', $kind);
    }

    /**
     * A kind of print, as it is known.
     *
     * @param string $kind
     * @return string
     */
    public static function kind(string $kind): string {
        return in_array($kind, self::KINDS, true) ? $kind : 'labels';
    }

    /**
     * The children to label: those of the families signed up for an event, or those of a household, each once, in
     * the order they came.
     *
     * @param \stdClass $centre
     * @param \stdClass|null $event
     * @param \stdClass|null $household
     * @return array[] name (the name used at home) and course.
     */
    public static function children(\stdClass $centre, ?\stdClass $event, ?\stdClass $household): array {
        global $DB;
        $ids = [];
        if ($household) {
            $ids = [(int) $household->id];
        } else if ($event) {
            $ids = array_map('intval', $DB->get_fieldset_sql('SELECT householdid FROM {' . events::SIGNUPS . '}
                                                                WHERE eventid = ? ORDER BY timecreated, id', [$event->id]));
        }
        $offers = $DB->get_records_menu(centres::OFFERS, ['centreid' => $centre->id], '', 'id, name');
        $out = [];
        foreach (array_unique($ids) as $id) {
            $children = $DB->get_records(households::CHILDREN, ['householdid' => $id, 'centreid' => $centre->id], 'id ASC');
            foreach ($children as $child) {
                $name = panel::tag($child);
                if ($name === '' || $child->stage === 'closed') {
                    continue;
                }
                $out[] = ['name' => $name, 'course' => (string) ($offers[$child->offerid] ?? '')];
            }
        }
        return array_slice($out, 0, self::LIMIT);
    }

    /**
     * The labels in sheets: the first ones left blank where the sheet was already used.
     *
     * @param array $labels
     * @param string $sheet A key of label_sheets::MODELS.
     * @param int $start The first label to use, from 1.
     * @return array[] Sheets, each with its labels.
     */
    public static function sheets(array $labels, string $sheet, int $start = 1): array {
        $per = label_sheets::placed($sheet)['per'];
        $start = max(1, min($per, $start));
        // A blank says so itself: in an older Mustache, a missing key is looked up in the page around it.
        $cells = array_merge(array_fill(0, $start - 1, ['blank' => true, 'name' => '', 'png' => '']), $labels);
        return array_map(fn($chunk) => ['labels' => $chunk], array_chunk($cells, $per));
    }

    /**
     * Writes what the centre keeps of its sheets.
     *
     * @param \stdClass $centre
     * @param array $own
     */
    protected static function keep(\stdClass $centre, array $own): void {
        global $DB;
        $centre->labelsheets = json_encode($own);
        $DB->update_record(centres::T, (object) ['id' => $centre->id, 'labelsheets' => $centre->labelsheets,
            'timemodified' => time()]);
    }
}
