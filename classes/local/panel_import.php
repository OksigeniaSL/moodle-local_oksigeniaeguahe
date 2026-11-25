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
 * Importing what already happened, in the panel, whatever it looks like: the file as it comes, dropped or chosen; what
 * was imported before, to take out; and, before anything comes in, what Eguahe makes of each sheet, with its columns in
 * three lists: what stays, what becomes a key and what is taken out.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_import {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string The files a sheet can come in. */
    const ACCEPT = '.xlsx,.xls,.ods,.csv,.tsv,.txt';

    /** @var int The contacts several students share said one by one, before how many more there are. */
    const SHARED = 8;

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param \stdClass|null $preview The file given, with what was chosen for each sheet when looking again.
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, ?\stdClass $preview = null, int $now = 0): array {
        $c = self::C;
        $now = $now ?: time();
        $url = new \moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]);
        $imports = [];
        foreach (imports::for_centre((int) $centre->id) as $import) {
            $imports[] = [
                'id' => (int) $import->id,
                'label' => s($import->label),
                'kind' => get_string('import_kind_' . $import->kind, $c),
                'rows' => get_string((int) $import->rowcount === 1 ? 'panel_rows_one' : 'panel_rows', $c, (int) $import->rowcount),
                'file' => s(trim((string) $import->filename . ($import->sheet ? ' · ' . $import->sheet : ''))),
                'when' => panel::when_words((int) $import->timecreated, $now),
                'shared' => self::shared_line($import),
                'deleteurl' => $url->out(false),
                'question' => get_string('import_delete_confirm', $c, (object) ['label' => s($import->label),
                    'rows' => (int) $import->rowcount]),
            ];
        }
        $sheets = $preview ? self::sheets($centre, $preview, $now) : [];
        $bring = count(array_filter($sheets, fn($s) => !$s['none'] && !$s['health']));
        return [
            'head' => panel::head([
                'title' => get_string('panel_import_title', $c),
                'sub' => get_string($preview ? 'panel_import_sub_file' : 'panel_import_sub', $c, $preview ?
                    s($preview->filename) : null),
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id]))->out(false),
                    'name' => get_string('tab_metrics', $c)],
            ]),
            'preview' => (bool) $preview,
            'action' => $url->out(false),
            'sesskey' => sesskey(),
            'draft' => $preview ? $preview->draft : 0,
            'accept' => self::ACCEPT,
            'kinds' => array_map(fn($k) => ['value' => $k, 'name' => get_string('import_kind_' . $k, $c)], recognise::KINDS),
            'hasimports' => (bool) $imports,
            'imports' => $imports,
            'sheets' => $sheets,
            'count' => $bring ? get_string($bring === 1 ? 'panel_import_bring_one' : 'panel_import_bring', $c, $bring) :
                get_string('panel_import_bring_none', $c),
            'canbring' => $bring > 0,
            'keyshelp' => get_string('panel_import_keys_help', $c),
        ];
    }

    /**
     * What Eguahe makes of each sheet of the file given: what it is, how it is called, its school year and its columns.
     *
     * @param \stdClass $centre
     * @param \stdClass $preview filename, path, draft, and what was chosen (kinds, labels, years) when looking again.
     * @param int $now
     * @return array
     */
    protected static function sheets(\stdClass $centre, \stdClass $preview, int $now): array {
        $c = self::C;
        $chosen = array_filter($preview->kinds ?? [], fn($k) => $k === 'none' || in_array($k, recognise::KINDS, true));
        $out = [];
        $list = fn(array $columns) => array_map(fn($col) => ['c' => $col], array_values($columns));
        $read = imports::preview($preview->path, $preview->filename, $chosen, (int) $centre->id, $preview->labels ?? []);
        foreach ($read as $sheet) {
            $kind = $sheet['kind'] ?? 'none';
            $shared = self::shared($sheet['shared']);
            $twice = $sheet['twice'] ? self::twice($sheet['twice'], $preview->twice[$sheet['index']] ?? '', $now) : false;
            $out[] = [
                'index' => $sheet['index'],
                'name' => $sheet['name'],
                'rows' => get_string((int) $sheet['rows'] === 1 ? 'panel_rows_one' : 'panel_rows', $c, (int) $sheet['rows']),
                'none' => $kind === 'none',
                'sentence' => $sheet['health'] ? get_string('panel_health_never', $c) :
                    get_string('panel_import_sentence_' . $kind, $c),
                'kinds' => array_map(fn($k) => ['value' => $k, 'name' => get_string('import_kind_' . $k, $c),
                    'selected' => $k === $kind], array_merge(recognise::KINDS, ['none'])),
                'label' => $preview->labels[$sheet['index']] ?? $sheet['label'],
                'schoolyear' => $preview->years[$sheet['index']] ?? (string) $sheet['schoolyear'],
                // A list of a year with only a part of the centre (its nursery): known to be there, not the whole list.
                'isroll' => $kind === 'roll',
                'part' => !empty($preview->parts[$sheet['index']]),
                'kept' => $list($sheet['kept']),
                'keys' => $list($sheet['keys']),
                'removed' => $list($sheet['removed']),
                'haskept' => (bool) $sheet['kept'],
                'haskeys' => (bool) $sheet['keys'],
                'hasremoved' => (bool) $sheet['removed'],
                'health' => (bool) $sheet['health'],
                'shared' => $shared,
                'hasshared' => (bool) $shared,
                'twice' => $twice,
            ];
        }
        return $out;
    }

    /**
     * The contacts several students share, said before they come in: what is done with each. Only the first ones, and how
     * many more there are.
     *
     * @param array[] $shared As imports::preview() gives them.
     * @return array[]
     */
    protected static function shared(array $shared): array {
        $out = [];
        foreach (array_slice($shared, 0, self::SHARED) as $one) {
            $out[] = ['text' => get_string('panel_import_shared_' . $one['what'] . ($one['never'] ? '_never' : ''), self::C, (object) [
                'value' => $one['what'] === 'phone' ? phone::display($one['value']) : $one['value'], 'n' => (int) $one['students']])];
        }
        $more = count($shared) - count($out);
        if ($more > 0) {
            $out[] = ['text' => get_string($more === 1 ? 'panel_import_shared_more_one' : 'panel_import_shared_more', self::C, $more)];
        }
        return $out;
    }

    /**
     * What an import already there says of a sheet that would bring the same contacts again: it takes its place, unless the
     * centre wants both.
     *
     * @param array $twice The import and whether its rows are the same.
     * @param string $chosen both, or anything else.
     * @param int $now
     * @return array
     */
    protected static function twice(array $twice, string $chosen, int $now): array {
        [$import, $same] = $twice;
        return ['id' => (int) $import->id, 'both' => $chosen === 'both', 'text' => get_string(
            $same ? 'panel_import_twice_same' : 'panel_import_twice_like',
            self::C,
            (object) ['label' => $import->label, 'rows' => (int) $import->rowcount,
                'when' => panel::when_words((int) $import->timecreated, $now)]
        )];
    }

    /**
     * How many contacts of an import join no family, or only with something else in common.
     *
     * @param \stdClass $import
     * @return string
     */
    protected static function shared_line(\stdClass $import): string {
        $info = imports::info($import);
        $parts = [];
        foreach (['never', 'doubt'] as $what) {
            $n = (int) ($info[$what] ?? 0);
            if ($n) {
                $parts[] = get_string('panel_import_' . $what . ($n === 1 ? '_one' : ''), self::C, $n);
            }
        }
        return implode(' ', $parts);
    }
}
