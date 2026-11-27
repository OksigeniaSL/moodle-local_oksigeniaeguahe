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
                'entry', 'heard', 'measured', 'place', 'moving', 'ties', 'alumni', 'out', 'waiting'] as $key
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
        $date = fn(int $t) => $t ? userdate($t, '%Y-%m-%d', 99, false) : '';
        $keys = array_keys(self::family_columns());
        $rows = [];
        // The adults and children of every family at once: one query each, not one per family.
        $adultsof = [];
        foreach ($DB->get_recordset_select(households::ADULTS, 'centreid = ?', [$centre->id], 'id ASC') as $adult) {
            $adultsof[(int) $adult->householdid][] = $adult;
        }
        // Who studied at the centre, by family: their names, or «someone of the family».
        $studied = [];
        $studiedrows = $DB->get_recordset_select(
            alumni::T,
            'centreid = ? AND householdid > 0 AND said = ?',
            [$centre->id, alumni::YES],
            '',
            'id, householdid, adultid'
        );
        foreach ($studiedrows as $r) {
            $studied[(int) $r->householdid][] = (int) $r->adultid;
        }
        $studiedrows->close();
        // Who each family is for the centre, when it is not new, and the children who are already there.
        $who = who::all_of_centre((int) $centre->id);
        $inside = ties::inside((int) $centre->id);
        // The form or bridge of the centre each family first came in by, with its place («La web del colegio · Web ·
        // Infantil»).
        $entrynames = $DB->get_records_menu(entries::T, ['centreid' => $centre->id], '', 'id, name');
        $entryof = [];
        $firsts = $DB->get_recordset_select(
            households::CONTACTS,
            'centreid = ? AND entryid > 0',
            [$centre->id],
            'id ASC',
            'id, householdid, entryid, detail'
        );
        foreach ($firsts as $first) {
            if (!isset($entryof[(int) $first->householdid]) && isset($entrynames[(int) $first->entryid])) {
                $entryof[(int) $first->householdid] = format_string($entrynames[(int) $first->entryid]) .
                    (trim((string) $first->detail) !== '' ? ' · ' . trim((string) $first->detail) : '');
            }
        }
        $firsts->close();
        $childrenof = [];
        foreach ($DB->get_recordset_select(households::CHILDREN, 'centreid = ?', [$centre->id], 'id ASC') as $child) {
            $childrenof[(int) $child->householdid][] = $child;
        }
        foreach ($DB->get_records(households::T, ['centreid' => $centre->id, 'guest' => 0], 'timecreated DESC, id DESC') as $household) {
            $adults = $adultsof[(int) $household->id] ?? [];
            $channel = (string) $household->firstchannel;
            $family = [
                'family' => implode('; ', array_map(fn($a) => trim($a->firstname . ' ' . $a->lastname), $adults)),
                'emails' => implode('; ', array_filter(array_map(fn($a) => trim((string) $a->email), $adults))),
                'phones' => implode('; ', array_filter(array_map(fn($a) => trim((string) $a->phone), $adults))),
                'arrived' => $date((int) $household->timecreated),
                'channel' => $channel !== '' ? get_string('via_' . (in_array($channel, lists::CHANNELS, true) ||
                    $channel === lists::PAGE ? $channel : 'other'), $c) : '',
                'entry' => $entryof[(int) $household->id] ?? '',
                'heard' => $household->heardfrom ? get_string('heard_' . (in_array(
                    $household->heardfrom,
                    lists::HEARD_FROM,
                    true
                ) ? $household->heardfrom : 'other'), $c) : '',
                'measured' => arrival::shown(arrival::of($household)[0]),
                'place' => trim((string) ($household->municipalityname ?? '')),
                'moving' => !empty($household->moving) ? get_string('yes') : '',
                'alumni' => implode('; ', array_unique(array_map(function ($id) use ($adults, $c) {
                    foreach ($adults as $a) {
                        if ((int) $a->id === $id) {
                            return trim($a->firstname . ' ' . $a->lastname);
                        }
                    }
                    return get_string('alumni_someone', $c);
                }, $studied[(int) $household->id] ?? []))),
                'out' => outs::is_out($household) ? get_string('out_' . $household->outreason, $c) : '',
                'waiting' => $date((int) $household->waitingsince),
            ];
            $ties = array_map([who::class, 'name'], $who[(int) $household->id] ?? []);
            $children = $childrenof[(int) $household->id] ?? [null];
            foreach ($children as $child) {
                $offer = $child ? ($offers[$child->offerid] ?? null) : null;
                $row = $family + [
                    'ties' => implode('; ', array_merge($child && isset($inside[(int) $child->id]) ?
                        [get_string('tie_already', $c)] : [], $ties)),
                    'child' => $child ? trim($child->firstname . ' ' . ($child->lastname ?? '')) : '',
                    'home' => $child ? trim((string) ($child->tagname ?? '')) : '',
                    'birth' => $child ? trim((string) ($child->birthdate ?? '')) : '',
                    'course' => $offer ? format_string($offer->name) . ' · ' . $offer->schoolyear : '',
                    'stage' => $child ? (isset($inside[(int) $child->id]) && !(int) ($child->roster ?? 0) ?
                        continuing::word((string) $child->stage) : get_string('stage_' . $child->stage, $c)) : '',
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
        foreach (
            ['campaign', 'counts', 'arrived', 'course', 'who', 'channel', 'basis', 'came', 'applied', 'reached', 'enrolled', 'students',
                'out', 'first', 'last', 'heard', 'measured', 'moving', 'source', 'dated'] as $key
        ) {
            $out[$key] = get_string('export_fig_' . $key, self::C);
        }
        return $out;
    }

    /**
     * The families Cifras counts, each campaign of the centre, without a name or a way to reach them: each with all it did, so
     * every figure of Numbers can be worked out again from here. Those its totals leave out (already of the centre, or that
     * came for another school year) go too, and say it.
     *
     * @param \stdClass $centre
     * @return array[]
     */
    public static function figures(\stdClass $centre): array {
        $rows = [];
        $date = fn(int $t) => $t ? userdate($t, '%Y-%m-%d', 99, false) : '';
        $keys = array_keys(self::figure_columns());
        $yes = fn(bool $is) => $is ? get_string('yes') : '';
        $c = self::C;
        $files = array_map(fn($i) => format_string($i->label), imports::for_centre((int) $centre->id, 'contacts'));
        foreach (panel_metrics::campaigns($centre) as $campaign) {
            foreach (panel_metrics::all_families($centre, $campaign) as $group => $families) {
                foreach ($families as $family) {
                    $row = array_map([self::class, 'cell'], [
                        'campaign' => (string) $campaign['name'] . ($campaign['year'] !== '' && $campaign['key'] === 'live' ? ' · ' .
                            $campaign['year'] : ''),
                        'counts' => get_string('export_counts_' . $group, $c),
                        'arrived' => $date((int) ($family['first'] ?? 0)),
                        'course' => (string) ($family['coursename'] ?? ''),
                        'who' => implode('; ', array_map([who::class, 'name'], $family['also'] ?? ['new'])),
                        'channel' => (string) ($family['voices']['channel'] ?? ''),
                        'basis' => ($family['voices']['basis'] ?? '') !== '' ? get_string('export_basis_' . $family['voices']['basis'], $c) : '',
                        'came' => $yes(!empty($family['came'])),
                        'applied' => $yes(!empty($family['applied'])),
                        'reached' => get_string('export_reached_' . self::reached($family), $c),
                        'enrolled' => $date((int) ($family['enrolledat'] ?? 0)),
                        'students' => (string) (int) ($family['students'] ?? 0),
                        'out' => !empty($family['closed']) && $family['why'] !== '' ? get_string('out_' . $family['why'], $c) : '',
                        'first' => (string) ($family['voices']['first'] ?? ''),
                        'last' => (string) ($family['voices']['last'] ?? ''),
                        'heard' => (string) ($family['voices']['said'] ?? ''),
                        'measured' => (string) ($family['voices']['measured'] ?? ''),
                        'moving' => $yes(!empty($family['moving'])),
                        'dated' => $yes(!empty($family['dated'])),
                        'source' => implode('; ', array_map(fn($s) => $s === 'eguahe' ? 'Eguahe' : ($files[$s] ?? ''), $family['sources'])),
                    ]);
                    $rows[] = array_combine($keys, array_map(fn($k) => $row[$k], $keys));
                }
            }
        }
        return $rows;
    }

    /**
     * Where a family is, as the download says it: one thing only.
     *
     * @param array $family Of facts.
     * @return string enrolled, went, closed, place, waitlist, applied, came or wrote.
     */
    public static function reached(array $family): string {
        foreach (['enrolled', 'went', 'closed', 'place', 'waitlist', 'applied', 'came'] as $key) {
            if (!empty($family[$key])) {
                return $key;
            }
        }
        return 'wrote';
    }
}
