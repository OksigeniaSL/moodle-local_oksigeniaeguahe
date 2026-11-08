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
                'deleteurl' => $url->out(false),
                'question' => get_string('import_delete_confirm', $c, (object) ['label' => s($import->label),
                    'rows' => (int) $import->rowcount]),
            ];
        }
        $sheets = $preview ? self::sheets($preview) : [];
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
     * @param \stdClass $preview filename, path, draft, and what was chosen (kinds, labels, years) when looking again.
     * @return array
     */
    protected static function sheets(\stdClass $preview): array {
        $c = self::C;
        $chosen = array_filter($preview->kinds ?? [], fn($k) => $k === 'none' || in_array($k, recognise::KINDS, true));
        $out = [];
        $list = fn(array $columns) => array_map(fn($col) => ['c' => $col], array_values($columns));
        foreach (imports::preview($preview->path, $preview->filename, $chosen) as $sheet) {
            $kind = $sheet['kind'] ?? 'none';
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
                'kept' => $list($sheet['kept']),
                'keys' => $list($sheet['keys']),
                'removed' => $list($sheet['removed']),
                'haskept' => (bool) $sheet['kept'],
                'haskeys' => (bool) $sheet['keys'],
                'hasremoved' => (bool) $sheet['removed'],
                'health' => (bool) $sheet['health'],
            ];
        }
        return $out;
    }
}
