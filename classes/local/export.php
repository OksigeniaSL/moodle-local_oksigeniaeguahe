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
 * What a centre takes away in a spreadsheet (the fifth part of the plan of the first version, 7-11): its families, one
 * row for each child with the family beside it, for whoever works with them; and the figures, one row for each family of
 * a campaign without a name or a way to reach it, the same list Cifras counts from, for whoever wants to count it again.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The formats Moodle writes (dataformat plugins). */
    const FORMATS = ['excel', 'ods', 'csv'];

    /**
     * The columns of the families.
     *
     * @return string[] key => heading.
     */
    public static function family_columns(): array {
        $out = [];
        foreach (
            ['family', 'emails', 'phones', 'child', 'home', 'birth', 'course', 'stage', 'since', 'arrived', 'channel',
                'heard', 'place', 'moving', 'waiting'] as $key
        ) {
            $out[$key] = get_string('export_col_' . $key, self::C);
        }
        return $out;
    }

    /**
     * The families of a centre, one row for each child (a family without children, one row), the newest first.
     *
     * @param \stdClass $centre
     * @return array[]
     */
    public static function families(\stdClass $centre): array {
        global $DB;
        $c = self::C;
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id]);
        $date = fn(int $t) => $t ? userdate($t, '%Y-%m-%d') : '';
        $keys = array_keys(self::family_columns());
        $rows = [];
        // The adults and children of every family at once: one query each, not one per family.
        $adultsof = [];
        foreach ($DB->get_recordset_select(households::ADULTS, 'centreid = ?', [$centre->id], 'id ASC') as $adult) {
            $adultsof[(int) $adult->householdid][] = $adult;
        }
        $childrenof = [];
        foreach ($DB->get_recordset_select(households::CHILDREN, 'centreid = ?', [$centre->id], 'id ASC') as $child) {
            $childrenof[(int) $child->householdid][] = $child;
        }
        foreach ($DB->get_records(households::T, ['centreid' => $centre->id], 'timecreated DESC, id DESC') as $household) {
            $adults = $adultsof[(int) $household->id] ?? [];
            $channel = (string) $household->firstchannel;
            $family = [
                'family' => implode('; ', array_map(fn($a) => trim($a->firstname . ' ' . $a->lastname), $adults)),
                'emails' => implode('; ', array_filter(array_map(fn($a) => trim((string) $a->email), $adults))),
                'phones' => implode('; ', array_filter(array_map(fn($a) => trim((string) $a->phone), $adults))),
                'arrived' => $date((int) $household->timecreated),
                'channel' => $channel !== '' ? get_string('via_' . (in_array($channel, lists::CHANNELS, true) ||
                    $channel === lists::PAGE ? $channel : 'other'), $c) : '',
                'heard' => $household->heardfrom ? get_string('heard_' . (in_array(
                    $household->heardfrom,
                    lists::HEARD_FROM,
                    true
                ) ? $household->heardfrom : 'other'), $c) : '',
                'place' => trim((string) ($household->municipalityname ?? '')),
                'moving' => !empty($household->moving) ? get_string('yes') : '',
                'waiting' => $date((int) $household->waitingsince),
            ];
            $children = $childrenof[(int) $household->id] ?? [null];
            foreach ($children as $child) {
                $offer = $child ? ($offers[$child->offerid] ?? null) : null;
                $row = $family + [
                    'child' => $child ? trim($child->firstname . ' ' . ($child->lastname ?? '')) : '',
                    'home' => $child ? trim((string) ($child->tagname ?? '')) : '',
                    'birth' => $child ? trim((string) ($child->birthdate ?? '')) : '',
                    'course' => $offer ? format_string($offer->name) . ' · ' . $offer->schoolyear : '',
                    'stage' => $child ? get_string('stage_' . $child->stage, $c) : '',
                    'since' => $child ? $date((int) $child->stagetime) : '',
                ];
                $rows[] = array_map(fn($k) => self::cell((string) $row[$k]), array_combine($keys, $keys));
            }
        }
        return $rows;
    }

    /**
     * A cell as text, never as a formula: a name written by a family that starts with «=» would become one in the sheet
     * and could read the other rows.
     *
     * @param string $value
     * @return string
     */
    public static function cell(string $value): string {
        // A phone (+34 600…) stays as it is; «=», «@», a tab, or «+» and «-» before anything but a number, do not.
        return preg_match('/^([=@\t\r]|[+\-][^0-9\s(])/', $value) ? "'" . $value : $value;
    }

    /**
     * The columns of the figures.
     *
     * @return string[] key => heading.
     */
    public static function figure_columns(): array {
        $out = [];
        foreach (['campaign', 'arrived', 'course', 'reached', 'enrolled', 'first', 'last', 'heard', 'moving'] as $key) {
            $out[$key] = get_string('export_fig_' . $key, self::C);
        }
        return $out;
    }

    /**
     * The families Cifras counts, each campaign of the centre, without a name or a way to reach them.
     *
     * @param \stdClass $centre
     * @return array[]
     */
    public static function figures(\stdClass $centre): array {
        $c = self::C;
        $steps = [1 => 'inquiry', 2 => 'visit', 3 => 'application', 4 => 'admitted', 5 => 'enrolled'];
        $date = fn(int $t) => $t ? userdate($t, '%Y-%m-%d') : '';
        $rows = [];
        foreach (panel_metrics::campaigns($centre) as $campaign) {
            foreach (panel_metrics::families($centre, $campaign) as $family) {
                $step = (int) ($family['step'] ?? 1);
                $rows[] = array_map([self::class, 'cell'], [
                    'campaign' => (string) $campaign['name'],
                    'arrived' => $date((int) ($family['first'] ?? 0)),
                    'course' => (string) ($family['coursename'] ?? $family['course'] ?? ''),
                    'reached' => !empty($family['closed']) ? get_string('stage_closed', $c) :
                        get_string('stage_' . ($steps[min(5, max(1, $step))]), $c),
                    'enrolled' => $date((int) ($family['enrolledat'] ?? 0)),
                    'first' => (string) ($family['voices']['first'] ?? ''),
                    'last' => (string) ($family['voices']['last'] ?? ''),
                    'heard' => (string) ($family['voices']['said'] ?? ''),
                    'moving' => !empty($family['moving']) ? get_string('yes') : '',
                ]);
            }
        }
        return $rows;
    }
}
