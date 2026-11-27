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
 * The answer to a question of Numbers, as its sheet draws it (template panel_answer): what is counted elsewhere (act_report)
 * put in the shapes every answer shares, with what is not known beside its block.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_answer {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int The families up to which each one is a mark; from there on, a bar. */
    const DOTS = 150;

    /**
     * The sheet of an act.
     *
     * @param array $report Of act_report::build().
     * @param array $head Of panel::head().
     * @return array
     */
    public static function act(array $report, array $head): array {
        $c = self::C;
        $figures = array_column($report['figures'], null, 'key');
        $unknown = array_column($report['unknown'] ?: [], null, 'key');
        $todo = array_column($report['todo'] ?: [], null, 'key');
        $signed = (int) ($figures['signed']['n'] ?? 0);
        $blocks = [];
        // With nobody of the centre among them, who came and what they did after is one only way.
        $later = [];
        $one = $report['funnel'] && (int) $report['funnel']['steps'][0]['n'] === $signed;
        foreach ($report['funnel'] ? $report['funnel']['steps'] : [] as $step) {
            if (!$one || !in_array($step['key'], ['mine', 'minecame'], true)) {
                $later[] = ['name' => $step['name'], 'n' => $step['n'], 'url' => $step['url'], 'parts' => $step['n'] ? [['kind' => 'yes', 'n' => $step['n']]] : [],
                    'legend' => $step['split'] !== '' ? [['kind' => '', 'n' => '', 'text' => $step['split'], 'url' => false]] : []];
            }
        }
        if ($signed && isset($figures['came'])) {
            $parts = [];
            foreach (['came' => 'yes', 'notcame' => 'no', 'unknown' => 'unknown'] as $key => $kind) {
                if (!empty($figures[$key]['n'])) {
                    $parts[] = ['kind' => $kind, 'n' => $figures[$key]['n'], 'text' => \core_text::strtolower($figures[$key]['name']),
                        'url' => $figures[$key]['url']];
                }
            }
            $blocks[] = self::block('vinieron', get_string($one ? 'actr_h_funnel' : 'qs_came', $c), ['lead' => $signed <= self::DOTS ? get_string('qs_dot', $c) : '',
                'path' => self::path($signed, array_merge([['name' => get_string('actr_num_signed', $c), 'n' => $signed,
                    'url' => $figures['signed']['url'], 'parts' => [['kind' => 'yes', 'n' => $signed]], 'legend' => []],
                    ['name' => get_string('actr_num_came', $c), 'n' => $figures['came']['n'], 'url' => $figures['came']['url'], 'parts' => $parts,
                    'legend' => $parts]], $one ? $later : [])),
                'def' => $one ? $report['funnel']['def'] : '', 'lines' => $one ? array_values(array_filter(
                    $report['funnel']['stay'] ?: [],
                    fn($line) => $line['key'] === 'nothing'
                )) : [],
                'apart' => self::apart($report['apart'] ?: [], $figures['walkin'] ?? null, $report['sheet'] ?: null),
                'note' => isset($unknown['unknown']) ? self::note(
                    get_string('qs_note_unknown' . ($unknown['unknown']['n'] === 1 ? '_one' : ''), $c, $unknown['unknown']['n']),
                    $todo['unknown']['url'] ?? ($unknown['unknown']['url'] ?: ''),
                    $todo['unknown']['button'] ?? get_string('qs_see', $c)
                ) : false]);
        }
        if (!$one && count($later) > 1) {
            $blocks[] = self::block('camino', get_string('actr_h_funnel', $c), ['path' => self::path((int) $later[0]['n'], $later),
                'lines' => $report['funnel']['stay'] ?: [], 'def' => $report['funnel']['def']]);
        }
        if ($report['who']) {
            $groups = [self::bars(get_string('qs_families', $c), $report['who']['kinds'])];
            $courses = [];
            foreach ($report['who']['stages'] as $stage) {
                foreach ($stage['courses'] ?: [['name' => $stage['name'], 'n' => $stage['n']]] as $course) {
                    $courses[] = ['name' => $course['name'], 'n' => $course['n'], 'unknown' => !$stage['courses']];
                }
            }
            if ($courses) {
                $groups[] = self::bars(get_string('qs_children', $c, $report['who']['children']), $courses);
            }
            $blocks[] = self::block('quienes', get_string('actr_h_who', $c), ['bars' => ['groups' => $groups],
                'note' => isset($unknown['nokids']) ? self::note(get_string(
                    'qs_note_nokids' . ($unknown['nokids']['n'] === 1 ? '_one' : ''),
                    $c,
                    $unknown['nokids']['n']
                ), $unknown['nokids']['url'] ?: '', get_string('qs_see', $c)) : false]);
        }
        $groups = [];
        $defs = [];
        foreach (['heard', 'channels', 'through', 'moment'] as $key) {
            if ($report[$key]) {
                $rows = $report[$key]['rows'];
                if ($key === 'heard' && isset($unknown['noheard'])) {
                    $rows[] = ['name' => get_string('qs_nosaid', $c), 'n' => $unknown['noheard']['n'], 'url' => $unknown['noheard']['url'], 'unknown' => true];
                }
                $groups[] = self::bars(get_string('actr_h_' . $key, $c), array_map(fn($row) => $row + ['unknown' => ($row['key'] ?? '') === 'unknown' ||
                    $row['name'] === get_string('arrival_channel_unmeasured', $c)], $rows));
                $defs[] = $report[$key]['def'] ?? '';
            }
        }
        if ($groups) {
            $spend = $report['spend'];
            $blocks[] = self::block('donde', get_string('qs_ways', $c), ['bars' => ['groups' => $groups], 'def' => implode(' ', array_filter($defs)),
                'lead' => $spend && $spend['known'] ? implode(' · ', array_filter([get_string('panel_what_it_cost', $c) . ': ' . $spend['text'], $spend['each'],
                    $spend['hours']])) : ($spend['hours'] ?? ''),
                'note' => $spend && !$spend['known'] ? self::note(get_string('qs_note_spend', $c), $spend['url'], get_string('qs_note_spend_do', $c)) : false]);
        }
        if ($report['since']) {
            $rows = array_map(
                fn($row) => ['n' => $row['n'], 'what' => \core_text::strtolower(\core_text::substr($row['name'], 0, 1)) .
                \core_text::substr($row['name'], 1), 'when' => $row['key'] === 'waiting' ? $report['since']['longest'] : $row['noids'],
                'url' => $row['url'], 'do' => get_string($row['n'] === 1 ? 'qs_see_one' : 'qs_see', $c), 'strong' => $row['key'] === 'waiting'],
                $report['since']['rows']
            );
            $blocks[] = self::block('desde', get_string('actr_h_since', $c), ['list' => ['rows' => $rows], 'words' => self::words(array_map(
                fn($why) => ['text' => $why['name'], 'n' => $why['n']],
                $report['since']['reasons']
            ))]);
        }
        if ($report['said']) {
            $groups = [];
            $quotes = [];
            foreach ($report['said']['survey'] as $question) {
                if ($question['rows']) {
                    $groups[] = self::bars($question['label'], array_map(fn($row) => ['name' => $row['label'], 'n' => $row['n']], $question['rows']));
                }
                foreach ($question['quotes'] as $quote) {
                    $quotes[] = ['text' => '«' . $quote['text'] . '»', 'n' => '', 'url' => false];
                }
            }
            $asked = array_map(fn($one) => ['text' => $one['text'], 'n' => '', 'url' => $one['url']], $report['said']['asked']);
            $blocks[] = self::block('dijeron', get_string('actr_h_said', $c), ['lead' => $report['said']['told'] ? get_string('qs_told', $c, (object) [
                'told' => $report['said']['told'], 'came' => $report['said']['came']]) : '', 'bars' => $groups ? ['groups' => $groups] : false,
                'words' => self::words(array_merge($quotes, $asked))]);
        }
        if ($report['others']) {
            $blocks[] = self::block('otras', get_string('actr_h_others', $c), ['table' => self::others($report['others']['rows']),
                'def' => $report['others']['def']]);
        }
        $facts = [];
        foreach (['signed', 'came', 'walkin', 'people'] as $key) {
            if (isset($figures[$key])) {
                $facts[] = ['n' => $figures[$key]['n'], 'what' => \core_text::strtolower($figures[$key]['name']), 'url' => $figures[$key]['url'],
                    'more' => trim($figures[$key]['split'] . ' ' . $figures[$key]['noids'])];
            }
        }
        return ['head' => $head, 'print' => get_string('qs_print', $c), 'facts' => $facts, 'detail' => false,
            // The same, as a document to send out: A4, with the colours of the centre and no names.
            'share' => !empty($report['shareurl']) ? ['url' => $report['shareurl'], 'label' => get_string('share_open', $c),
                'hint' => get_string('share_open_hint', $c)] : false,
            'todo' => $report['todo'] ? ['title' => get_string('actr_h_todo', $c), 'items' => array_map(fn($one) => self::counted($one), $report['todo'])] : false,
            'blocks' => $blocks, 'hassources' => (bool) $report['sources'], 'sources' => array_map(fn($source) => ['name' => $source['name'],
            'when' => $source['date'], 'what' => $source['what'] . ' (' . $source['families'] . ')'], $report['sources'] ?: [])];
    }

    /**
     * A path on a total: each step drawn on it.
     *
     * @param int $total
     * @param array[] $steps name, n, url, parts and legend.
     * @return array
     */
    public static function path(int $total, array $steps): array {
        return ['total' => max(1, $total), 'dots' => $total <= self::DOTS, 'steps' => array_map(fn($step) => $step + ['haslegend' => (bool) $step['legend']], $steps)];
    }

    /**
     * A block with nothing in it but what is given.
     *
     * @param string $id
     * @param string $title
     * @param array $with
     * @return array
     */
    public static function block(string $id, string $title, array $with): array {
        $with += ['lead' => '', 'def' => '', 'path' => false, 'apart' => [], 'lines' => [], 'bars' => false, 'list' => false, 'columns' => false, 'table' => false,
            'words' => false, 'note' => false, 'rates' => false];
        return ['id' => $id, 'title' => $title, 'hasapart' => (bool) $with['apart']] + $with;
    }

    /**
     * Bars, each against the longest of its group.
     *
     * @param string $title
     * @param array[] $rows name, n and, if any, url, unknown and split.
     * @return array
     */
    public static function bars(string $title, array $rows): array {
        $most = max(array_merge([1], array_column($rows, 'n')));
        return ['title' => $title, 'rows' => array_map(fn($row) => ['name' => $row['name'], 'n' => $row['n'], 'v' => round($row['n'] / $most, 3),
            'part' => (string) ($row['split'] ?? ''), 'unknown' => !empty($row['unknown']), 'url' => $row['url'] ?? false], array_values($rows))];
    }

    /**
     * What is told aside, without counting in the path.
     *
     * @param array[] $apart
     * @param array|null $walkin
     * @param array|null $sheet
     * @return array[]
     */
    public static function apart(array $apart, ?array $walkin, ?array $sheet): array {
        return array_map(fn($one) => ['n' => $one['n'], 'text' => \core_text::strtolower(\core_text::substr($one['name'], 0, 1)) .
            \core_text::substr($one['name'], 1), 'url' => $one['url']], array_values(array_filter(array_merge([$walkin], $apart, [$sheet]))));
    }

    /**
     * What is not known, and where to put it.
     *
     * @param string $text
     * @param string $url
     * @param string $do
     * @return array
     */
    public static function note(string $text, string $url, string $do): array {
        return ['text' => $text, 'url' => $url, 'do' => $do];
    }

    /**
     * Words said, or nothing.
     *
     * @param array[] $rows text, n and url.
     * @return array|false
     */
    protected static function words(array $rows) {
        return $rows ? ['rows' => array_map(fn($row) => $row + ['url' => false], $rows)] : false;
    }

    /**
     * Something to do, with the number its sentence begins with apart: «18» and «families wait for an answer».
     *
     * @param array $one n, text, url and button.
     * @return array
     */
    protected static function counted(array $one): array {
        $begins = strpos($one['text'], $one['n'] . ' ') === 0;
        return ['n' => $begins ? $one['n'] : '', 'text' => $begins ? substr($one['text'], strlen($one['n'] . ' ')) : $one['text'], 'url' => $one['url'],
            'do' => $one['button']];
    }

    /**
     * The same numbers of other acts, one row each; what a file does not say is not known, never a zero.
     *
     * @param array[] $rows
     * @return array
     */
    protected static function others(array $rows): array {
        $c = self::C;
        $most = max(array_merge([1], array_column($rows, 'signed')));
        $names = ['signed' => get_string('actr_num_signed', $c), 'came' => get_string('actr_num_came', $c), 'applied' => get_string('actr_num_applied', $c),
            'enrolled' => get_string('actr_num_enrolled', $c)];
        $cell = function (array $row, string $key) use ($names, $most): array {
            $known = $key === 'signed' || !empty($row['has' . $key]);
            return ['col' => $names[$key], 'n' => $known ? $row[$key] : '', 'text' => '', 'v' => $known ? round($row[$key] / $most, 3) : 0, 'url' => $row['url'],
                'missing' => $known ? '' : get_string('qs_unnoted', self::C)];
        };
        return ['cols' => array_map(fn($name) => ['name' => $name], array_values($names)), 'rows' => array_map(
            fn($row) => ['name' => $row['name'], 'when' => $row['when'],
            'url' => $row['current'] ? false : $row['url'], 'current' => $row['current'], 'cells' => array_map(fn($key) => $cell($row, $key), array_keys($names))],
            $rows
        )];
    }
}
