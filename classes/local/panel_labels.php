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
 * The sheet of labels in the panel, whatever it looks like: the label of each backpack, with the name used at home
 * as the families see it, or the sticker of each bottle, for the children of an event or of a household, on the sheet
 * the centre buys; where to start on a sheet already used, the favourites, a test sheet and how much the printer moves.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_labels {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param \stdClass|null $event
     * @param \stdClass|null $household
     * @param array $view kind, sheet, start and test.
     * @return array
     */
    public static function context(\stdClass $centre, ?\stdClass $event, ?\stdClass $household, array $view): array {
        $c = self::C;
        $kind = labels::kind((string) ($view['kind'] ?? 'labels'));
        $sheet = labels::chosen($centre, $kind, (string) ($view['sheet'] ?? ''));
        $own = labels::own($centre);
        [$nudgex, $nudgey] = $own['nudge'][$sheet] ?? [0.0, 0.0];
        $format = label_sheets::placed($sheet, $nudgex, $nudgey);
        $start = max(1, min($format['per'], (int) ($view['start'] ?? 1)));
        $test = !empty($view['test']);
        $children = labels::children($centre, $event, $household);
        $where = $event ? ['event' => $event->id] : ['household' => $household->id];
        $here = ['c' => $centre->id] + $where + ['kind' => $kind, 'sheet' => $sheet];
        $url = fn(array $more = []) => (new \moodle_url('/local/oksigeniaeguahe/labels.php', $here + $more))->out(false);
        $colour = (string) ($centre->colour ?: panel_centre::COLOUR);
        $items = [];
        foreach ($children as $child) {
            $png = !sticker::available() ? '' : ($kind === 'labels' ? sticker::png($child['name'], $colour, 2) :
                sticker::bottle($child['name'], $colour, 2));
            $items[] = ['blank' => false, 'name' => $child['name'], 'png' => $png !== '' ? 'data:image/png;base64,' .
                base64_encode($png) : ''];
        }
        $mm = fn(float $v) => format_float($v, 1, true, true);
        $name = $event ? format_string($event->name) : panel::adults_line(households::full($household)->adults);
        $count = count($children);
        $pages = (int) ceil(($count + $start - 1) / $format['per']);
        return [
            'head' => panel::head([
                'title' => get_string(($kind === 'bottles' ? 'panel_labels_title_bottles' : 'panel_labels_title') .
                    ($event ? '_event' : ''), $c, $name),
                'sub' => $count ? get_string($count === 1 ? 'panel_labels_sub_one' : 'panel_labels_sub', $c, $count) :
                    get_string('panel_labels_sub_none', $c),
                'back' => ['url' => ($event ? new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]) :
                    new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
                    'name' => $name],
            ]),
            'kinds' => array_map(fn($k) => ['name' => get_string('panel_labels_kind_' . $k, $c),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/labels.php', ['c' => $centre->id] + $where +
                ['kind' => $k]))->out(false), 'current' => $k === $kind], labels::KINDS),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/labels.php'))->out(false),
            'hidden' => array_map(fn($k, $v) => ['name' => $k, 'value' => $v], array_keys(['c' => $centre->id] + $where +
                ['kind' => $kind]), ['c' => $centre->id] + $where + ['kind' => $kind]),
            'postaction' => $url(['start' => $start]),
            'sesskey' => sesskey(),
            'sheetgroups' => self::groups($own['favs'][$kind], $sheet),
            'isfav' => in_array($sheet, $own['favs'][$kind], true),
            'start' => $start,
            'per' => $format['per'],
            'nudgex' => $nudgex,
            'nudgey' => $nudgey,
            'nudged' => $nudgex || $nudgey ? get_string('panel_labels_nudged', $c, (object) ['x' => $mm($nudgex),
                'y' => $mm($nudgey)]) : '',
            'testurl' => $url(['test' => 1]),
            'backurl' => $url(['start' => $start]),
            'test' => $test,
            'count' => $count ? get_string($pages === 1 ? 'panel_labels_pages_one' : 'panel_labels_pages', $c, (object) [
                'n' => $count, 'pages' => $pages]) : '',
            'pages' => $test ? [['labels' => array_map(
                fn($n) => ['blank' => true, 'name' => (string) $n, 'png' => ''],
                range(1, $format['per'])
            )]] : labels::sheets($items, $sheet, $start),
            'haspages' => $test || (bool) $items,
            'bottles' => $kind === 'bottles',
            'letter' => $format['paper'] === 'letter',
            'style' => sprintf(
                '--cols: %d; --rows: %d; --w: %smm; --h: %smm; --top: %smm; --left: %smm; --gx: %smm; --gy: %smm; ' .
                '--acento-etiqueta: %s;',
                $format['cols'],
                $format['rows'],
                $format['w'],
                $format['h'],
                $format['top'],
                $format['left'],
                $format['gx'],
                $format['gy'],
                $colour
            ),
        ];
    }

    /**
     * The sheets to choose from: the favourites first, then by brand, each with its measures in words.
     *
     * @param string[] $favs
     * @param string $chosen
     * @return array
     */
    protected static function groups(array $favs, string $chosen): array {
        $c = self::C;
        $option = function (string $key) use ($chosen, $c): array {
            $m = label_sheets::MODELS[$key];
            $mm = fn(float $v) => format_float($v, $v == floor($v) ? 0 : 1, true, true);
            return ['value' => $key, 'selected' => $key === $chosen, 'label' => get_string('panel_labels_sheet', $c, (object) [
                'brand' => $m['brand'], 'ref' => $m['ref'] . ($m['also'] !== '' ? ' · ' . $m['also'] : ''),
                'w' => $mm($m['w']), 'h' => $mm($m['h']), 'n' => $m['cols'] * $m['rows']]) .
                ($m['paper'] === 'letter' ? ' · ' . get_string('panel_labels_letter', $c) : '')];
        };
        $groups = [];
        if ($favs) {
            $groups[] = ['label' => get_string('panel_labels_favourites', $c), 'options' => array_map($option, $favs)];
        }
        $brands = [];
        foreach (array_keys(label_sheets::MODELS) as $key) {
            if (!in_array($key, $favs, true) && label_sheets::fits($key)) {
                $brands[label_sheets::MODELS[$key]['brand']][] = $option($key);
            }
        }
        foreach ($brands as $brand => $options) {
            $groups[] = ['label' => $brand, 'options' => $options];
        }
        return $groups;
    }
}
