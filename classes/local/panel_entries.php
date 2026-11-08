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
 * What the entries of the panel show, whatever they look like: by how many doors families arrive, the arrivals against
 * the families, which brought most this month; the forms of the centre and the bridges, each with how many it brought
 * and its part of the one that brought most; the kinds not used yet; and a new entry.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_entries {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var array The icon of each kind of entry. */
    const ICONS = ['form' => 'formulario', 'google' => 'hoja', 'meta' => 'persona', 'email' => 'correo', 'hook' => 'puente'];

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param string $kind A kind chosen to start a new entry with.
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact, string $kind = '', int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $c = self::C;
        $list = entries::for_centre((int) $centre->id);
        $most = max(1, ...array_map(fn($e) => (int) $e->received, $list ?: [(object) ['received' => 0]]));
        $groups = ['formularios' => [], 'puentes' => []];
        $used = [];
        foreach ($list as $entry) {
            $used[$entry->kind] = true;
            $sites = [];
            foreach ($entry->kind === 'form' ? forms::origins($entry) : [] as $origin) {
                $sites[] = is_array($origin) ? (string) ($origin['name'] ?? '') : (string) $origin;
            }
            $sites = array_values(array_filter($sites));
            $n = (int) $entry->received;
            $groups[$entry->kind === 'form' ? 'formularios' : 'puentes'][] = [
                'url' => (new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id]))->out(false),
                'name' => format_string($entry->name),
                'kindname' => get_string('entry_kind_' . $entry->kind, $c),
                'icon' => panel::icon(self::ICONS[$entry->kind] ?? 'formulario'),
                'open' => !empty($entry->active),
                'statetext' => get_string(!empty($entry->active) ? 'panel_open' : 'panel_closed', $c),
                'n' => $n,
                'broughttext' => get_string($n === 1 ? 'panel_brought_one' : 'panel_brought', $c, $n),
                'hasn' => $n > 0,
                'v' => round($n / $most, 3),
                'last' => $entry->lastreceived ? get_string('panel_last_one', $c, panel::when_words(
                    (int) $entry->lastreceived,
                    $now
                )) : '',
                'missing' => !$n && $entry->kind !== 'form' ? get_string('panel_missing_' . $entry->kind, $c) : '',
                'sites' => $sites,
                'hassites' => (bool) $sites,
            ];
        }
        // The surveys after a visit: never a door, so apart, with the families that told and the events that ask them.
        $groups['encuestas'] = [];
        foreach (surveys::for_centre((int) $centre->id) as $survey) {
            $told = (int) $DB->count_records_sql('SELECT COUNT(DISTINCT a.householdid)
                                                    FROM {' . surveys::T . '} a
                                                    JOIN {' . events::T . '} e ON e.id = a.eventid
                                                   WHERE e.survey = ?', [$survey->id]);
            $asked = (int) $DB->count_records(events::T, ['survey' => $survey->id]);
            $groups['encuestas'][] = [
                'url' => (new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $survey->id]))->out(false),
                'name' => format_string($survey->name),
                'kindname' => get_string('entry_kind_survey', $c),
                'icon' => panel::icon('formulario'),
                'open' => $asked > 0,
                'statetext' => get_string(!$asked ? 'panel_survey_events_none' : ($asked === 1 ? 'panel_survey_events_one' :
                    'panel_survey_events'), $c, $asked),
                'n' => $told,
                'broughttext' => get_string($told === 1 ? 'panel_survey_told_one' : 'panel_survey_told', $c, $told),
                'hasn' => $told > 0,
                'v' => round($told / max(1, $most, $told), 3),
                'last' => '',
                'missing' => '',
                'sites' => [],
                'hassites' => false,
            ];
        }
        $out = [];
        foreach (['formularios' => 'forms', 'puentes' => 'bridges', 'encuestas' => 'surveys'] as $key => $string) {
            if ($groups[$key]) {
                $out[] = ['key' => $key, 'title' => get_string('panel_group_' . $string, $c),
                    'sub' => get_string('panel_group_' . $string . '_sub', $c), 'rows' => $groups[$key]];
            }
        }
        $notused = [];
        $kinds = [];
        $chosen = isset(entries::KINDS[$kind]) ? $kind : 'form';
        foreach (array_keys(entries::KINDS) as $each) {
            $item = ['value' => $each, 'name' => get_string('entry_kind_' . $each, $c),
                'help' => get_string('entry_kind_' . $each . '_help', $c),
                'icon' => panel::icon(self::ICONS[$each] ?? 'formulario')];
            $kinds[] = $item + ['checked' => $each === $chosen];
            if (empty($used[$each])) {
                $notused[] = $item + ['url' => (new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id,
                    'kind' => $each], 'nueva'))->out(false)];
            }
        }
        // Arrivals against families: a family may come in by several doors.
        $arrivals = array_sum(array_map(fn($e) => (int) $e->received, $list));
        $families = (int) $DB->count_records_sql('SELECT COUNT(DISTINCT householdid) FROM {' . households::CONTACTS . '}
                                                   WHERE centreid = ? AND entryid > 0 AND direction = ?', [$centre->id, 'in']);
        $sub = [];
        if ($arrivals) {
            $sub[] = get_string('panel_entries_sub', $c, (object) ['arrivals' => $arrivals, 'families' => $families]);
        }
        if (($best = self::best((int) $centre->id, $now)) !== '') {
            $sub[] = $best;
        }
        return [
            'head' => panel::head([
                'title' => $list ? get_string(count($list) === 1 ? 'panel_entries_one' : 'panel_entries', $c, count($list)) :
                    get_string('panel_entries_none', $c),
                'sub' => implode(' ', $sub),
            ]),
            'groups' => $out,
            'notused' => $canact ? $notused : [],
            'hasnotused' => $canact && $notused,
            'def' => get_string('panel_entries_def', $c),
            'kinds' => $kinds,
            'templates' => $canact ? array_map(fn($t) => ['value' => $t, 'name' => get_string('tpl_' . $t, $c),
                'help' => get_string('panel_tpl_' . $t . '_help', $c)], [...array_keys(surveys::TEMPLATES), surveys::KIND]) : [],
            'canact' => $canact,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The entry that brought most families this month, with the place it was shared from: «La que más ha traído este
     * mes, «Pidan información» por Instagram».
     *
     * @param int $centreid
     * @param int $now
     * @return string Empty when none brought any this month.
     */
    protected static function best(int $centreid, int $now): string {
        global $DB;
        $month = make_timestamp((int) userdate($now, '%Y'), (int) userdate($now, '%m'), 1);
        $rows = $DB->get_recordset_sql("SELECT c.entryid, COALESCE(c.detail, '') AS detail, COUNT(DISTINCT c.householdid) AS n
                                          FROM {" . households::CONTACTS . "} c
                                         WHERE c.centreid = :centreid AND c.entryid > 0 AND c.direction = 'in'
                                               AND c.timecreated >= :month
                                      GROUP BY c.entryid, COALESCE(c.detail, '')
                                      ORDER BY n DESC", ['centreid' => $centreid, 'month' => $month], 0, 1);
        $row = $rows->valid() ? $rows->current() : null;
        $rows->close();
        if (!$row) {
            return '';
        }
        $name = format_string((string) $DB->get_field(entries::T, 'name', ['id' => $row->entryid]));
        return get_string($row->detail !== '' ? 'panel_entries_best_site' : 'panel_entries_best', self::C, (object) [
            'name' => $name, 'site' => s($row->detail)]);
    }
}
