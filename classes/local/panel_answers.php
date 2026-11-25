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
 * The sheets of the questions of a campaign (numbers_questions), drawn with the shapes of panel_answer: the same sentence the
 * list says, and behind it the numbers that the detailed sheets of Numbers count. Nothing is counted here again.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_answers {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The questions with a sheet. */
    const KEYS = ['campaign', 'channels', 'answers', 'outs', 'places', 'students', 'likes'];

    /**
     * The sheet of a question.
     *
     * @param \stdClass $centre
     * @param string $key Of KEYS.
     * @param string $campaign The campaign: live, or y and its school year.
     * @param bool $canact
     * @param int $now
     * @return array|null Of panel_answer; null without an answer yet.
     */
    public static function build(\stdClass $centre, string $key, string $campaign = 'live', bool $canact = false, int $now = 0): ?array {
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }
        $now = $now ?: time();
        $c = self::C;
        $campaigns = facts::campaigns($centre, $now);
        $of = $campaigns[$campaign] ?? $campaigns['live'];
        $row = numbers_questions::find($centre, $of, $key, $now);
        if (!$row || $row['empty']) {
            return null;
        }
        $all = facts::all($centre, $of, $now);
        $view = panel_metrics::context($centre, ['campaign' => $of['key'], 'voice' => 'channel'], $canact, $now);
        $name = $of['year'] !== '' ? get_string('panel_past_campaign', $c, $of['year']) : get_string('panel_this_campaign', $c);
        $back = (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id, 'preguntas' => 1] +
            ($of['key'] === 'live' ? [] : ['campana' => $of['key']])))->out(false);
        $with = (object) ['centre' => $centre, 'campaign' => $of, 'all' => $all, 'mine' => facts::group($all, 'campaign'), 'view' => $view,
            'row' => $row, 'now' => $now, 'nums' => array_column($row['nums'], null, 'key'), 'detail' => $row['detail']];
        $blocks = self::{'of_' . $key}($with);
        $cut = panel::upper_first(get_string('panel_cut', $c, userdate($now, get_string('strftimetime', 'langconfig')))) . '.';
        // Of the campaign, the same title its sheet has, and who they are below.
        $title = $key === 'campaign' ? $view['head']['title'] : $row['answer'];
        $sub = $key === 'campaign' ? $view['head']['sub'] : ($key === 'students' ? $cut : $name . ' · ' . $cut);
        $head = panel::head(['title' => $title, 'ante' => $row['question'], 'sub' => $sub, 'back' => ['url' => $back,
            'name' => get_string('tab_metrics', $c)]]);
        return ['head' => $head, 'print' => get_string('qs_print', $c), 'facts' => [], 'todo' => false,
            'blocks' => array_values(array_filter($blocks)), 'detail' => ['url' => $row['detail'],
            'name' => get_string('qs_detail', $c)]] + self::sources($with);
    }

    /**
     * How the campaign is going.
     *
     * @param \stdClass $w
     * @return array[]
     */
    protected static function of_campaign(\stdClass $w): array {
        $c = self::C;
        $total = count($w->mine);
        $unsure = self::unsure($w);
        $steps = [];
        foreach (['campaign', 'came', 'applied', 'place', 'enrolled'] as $key) {
            $num = $w->nums[$key] ?? ['n' => 0, 'url' => false, 'name' => get_string('familylist_' . $key, $c)];
            $parts = $num['n'] ? [['kind' => 'yes', 'n' => $num['n']]] : [];
            $legend = [];
            if ($key === 'came' && $unsure) {
                $parts[] = ['kind' => 'unknown', 'n' => $unsure];
                $legend = [['kind' => 'unknown', 'n' => $unsure, 'text' => get_string('qs_unknown', $c), 'url' => false]];
            }
            $steps[] = ['name' => get_string('familylist_' . $key, $c), 'n' => $num['n'], 'url' => $num['url'], 'parts' => $parts,
                'legend' => $legend];
        }
        $apart = array_values(array_filter([$w->nums['centre'] ?? null, $w->nums['other'] ?? null, $w->nums['nokids'] ?? null]));
        $blocks = [$total ? panel_answer::block('camino', get_string('qs_way', $c), [
            'lead' => $total <= panel_answer::DOTS ? get_string('qs_dot', $c) : '',
            'path' => panel_answer::path($total, $steps), 'apart' => panel_answer::apart($apart, null, null),
            'note' => $unsure ? panel_answer::note(
                get_string('qs_note_unknown' . ($unsure === 1 ? '_one' : ''), $c, $unsure),
                (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $w->centre->id, 'pasada' => 1]))->out(false),
                get_string('qs_note_unknown_do', $c)
            ) : false,
        ]) : null];
        // Who they are, with the families of the centre beside them, and the courses they ask for.
        $courses = [];
        foreach ($w->mine as $f) {
            foreach ($f['kids'] as $kid) {
                if ($kid['mine'] && $kid['name'] !== '') {
                    $courses[$kid['name']] = ($courses[$kid['name']] ?? 0) + 1;
                }
            }
        }
        $who = array_values(array_filter([$w->nums['fresh'] ?? null, $w->nums['knew'] ?? null, $w->nums['centre'] ?? null]));
        $groups = $who ? [panel_answer::bars(get_string('qs_families', $c), $who)] : [];
        if ($courses) {
            $groups[] = panel_answer::bars(get_string('qs_children', $c, array_sum($courses)), array_map(
                fn($name, $n) => ['name' => $name, 'n' => $n],
                array_keys($courses),
                $courses
            ));
        }
        $blocks[] = $groups ? panel_answer::block('quienes', get_string('qs_who', $c), ['bars' => ['groups' => $groups]]) : null;
        // Week by week: those of each week, with those of the campaign before behind.
        $weeks = $w->view['weeks'];
        if ($weeks) {
            $rows = [];
            $most = 1;
            $last = [0, 0];
            foreach ($weeks['table'] as $i => $week) {
                if (!empty($weeks['cols'][$i]['future'])) {
                    break;
                }
                $n = (int) $week['now'] - $last[0];
                $before = $weeks['compare'] ? max(0, (int) $week['before'] - $last[1]) : 0;
                $last = [(int) $week['now'], $weeks['compare'] ? (int) $week['before'] : 0];
                $most = max($most, $n, $before);
                $rows[] = ['label' => (string) ($i + 1), 'n' => $n, 'b' => $before, 'said' => $week['week'] . ': ' . $n];
            }
            $blocks[] = panel_answer::block('semanas', get_string('qs_weeks', $c), ['lead' => $weeks['rest'], 'columns' => [
                'keys' => $weeks['compare'] ? ['now' => get_string('panel_this_campaign', $c), 'before' => get_string('qs_before', $c)] : false,
                'rows' => array_map(fn($r) => ['label' => $r['label'], 'n' => $r['n'], 'v' => round($r['n'] / $most, 3),
                    'g' => round($r['b'] / $most, 3), 'said' => $r['said']], $rows)], 'def' => trim(get_string('qs_weeks_def', $c) . ' ' .
                (isset($w->nums['undated']) ? get_string(
                    'panel_weeks_undated' . ($w->nums['undated']['n'] === 1 ? '_one' : ''),
                    $c,
                    $w->nums['undated']['n']
                ) : ''))]);
        }
        return $blocks;
    }

    /**
     * How many families of the campaign may have come, and it is not known: signed up or with a visit already past that
     * nobody noted, or only in a file that does not say it.
     *
     * @param \stdClass $w
     * @return int
     */
    protected static function unsure(\stdClass $w): int {
        global $DB;
        $open = $w->campaign['key'] === 'live' ? array_flip(numbers_questions::visit_outcomes($w->centre, $w->now)['unknown']) : [];
        $open += array_flip($DB->get_fieldset_sql(
            'SELECT DISTINCT s.householdid
               FROM {' . events::SIGNUPS . '} s
               JOIN {' . events::T . '} e ON e.id = s.eventid
          LEFT JOIN {' . events::SLOTS . '} t ON t.id = s.slotid
              WHERE e.centreid = ? AND e.kind <> ? AND s.attended = 0 AND s.missed = 0 AND COALESCE(t.timestart, e.timestart) <= ?',
            [$w->centre->id, 'other', $w->now]
        ));
        return count(array_filter($w->mine, fn($f) => !$f['came'] && ($f['id'] ? isset($open[$f['id']]) : !$f['known']['came'])));
    }

    /**
     * Where they come from, and what it cost.
     *
     * @param \stdClass $w
     * @return array[]
     */
    protected static function of_channels(\stdClass $w): array {
        $c = self::C;
        $rows = array_values(array_filter($w->view['channels']['rows'] ?? [], fn($r) => $r['n']));
        usort($rows, fn($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
        $families = [];
        $enrolled = [];
        $spent = [];
        foreach ($rows as $row) {
            $num = $w->nums['channel_' . substr($row['key'], 1)] ?? null;
            $unknown = $row['key'] === 'cunmeasured';
            $families[] = ['name' => $row['name'], 'n' => $row['n'], 'url' => $num['url'] ?? false, 'unknown' => $unknown,
                'split' => strip_tags((string) $row['cost'])];
            $enrolled[] = ['name' => $row['name'], 'n' => $row['en'], 'unknown' => $unknown];
            if ((string) $row['cost'] !== '') {
                $spent[] = ['n' => '', 'what' => $row['name'], 'when' => strip_tags((string) $row['cost']), 'url' => $w->detail,
                    'do' => get_string('qs_note_spend_do', $c), 'strong' => false];
            }
        }
        $never = outs::figures($w->centre, $w->mine, (int) $w->campaign['since'], (int) $w->campaign['until'])['never'];
        $unmeasured = $w->nums['channel_unmeasured'] ?? null;
        $apart = $never['total'] ? [['n' => $never['total'], 'text' => familylists::name('qs_never', $never['total']) . ': ' . implode(' · ', array_map(
            fn($r) => $r['name'] . ', ' . $r['n'],
            $never['rows']
        )), 'url' => false]] : [];
        return [panel_answer::block('canal', get_string('qs_ways', $c), ['bars' => ['groups' => [
            panel_answer::bars(get_string('qs_families', $c), $families),
            panel_answer::bars(get_string('familylist_enrolled', $c), $enrolled)]], 'apart' => $apart,
            'def' => $w->view['channels']['def'], 'note' => $unmeasured ? panel_answer::note(get_string(
                'panel_unknown_channel' . ($unmeasured['n'] === 1 ? '_one' : ''),
                $c,
                $unmeasured['n']
            ), $unmeasured['url'] ?: '', get_string($unmeasured['n'] === 1 ? 'qs_see_one' : 'qs_see', $c)) : false]),
            panel_answer::block('gasto', get_string('panel_what_it_cost', $c), ['list' => $spent ? ['rows' => $spent] : false, 'note' => $spent ?
                false : panel_answer::note(get_string('qs_note_spend', $c), $w->detail, get_string('qs_note_spend_do', $c))])];
    }

    /**
     * Whether they are answered in time.
     *
     * @param \stdClass $w
     * @return array[]
     */
    protected static function of_answers(\stdClass $w): array {
        $c = self::C;
        $r = metrics::response((int) $w->centre->id, facts::ids(array_filter($w->mine, [facts::class, 'lives'])), $w->now);
        $answered = $w->nums['answered'] ?? null;
        $waiting = $w->nums['unanswered'] ?? null;
        $steps = [['name' => get_string('qs_wrote', $c), 'n' => $r['asked'], 'url' => false, 'parts' => [['kind' => 'yes', 'n' => $r['asked']]],
            'legend' => []]];
        if ($r['timed'] === $r['answered'] && $r['answered']) {
            foreach (['day', 'two'] as $key) {
                $steps[] = ['name' => get_string('qs_within_' . $key, $c), 'n' => $r['within'][$key], 'url' => false, 'parts' => $r['within'][$key] ?
                    [['kind' => 'yes', 'n' => $r['within'][$key]]] : [], 'legend' => []];
            }
        }
        $parts = array_values(array_filter([['kind' => 'yes', 'n' => $r['answered']], ['kind' => 'no', 'n' => $r['waiting']]], fn($p) => $p['n']));
        $steps[] = ['name' => get_string('familylist_answered', $c), 'n' => $r['answered'], 'url' => $answered['url'] ?? false, 'parts' => $parts,
            'legend' => array_values(array_filter([
                $answered ? ['kind' => 'yes', 'n' => $answered['n'], 'text' => \core_text::strtolower($answered['name']), 'url' => $answered['url']] : null,
                $waiting ? ['kind' => 'no', 'n' => $waiting['n'], 'text' => \core_text::strtolower($waiting['name']), 'url' => $waiting['url']] : null]))];
        $open = [];
        foreach (['more', 'two', 'day'] as $key) {
            if ($r['open'][$key]) {
                $open[] = ['n' => $r['open'][$key], 'what' => get_string('panel_response_open_' . $key, $c), 'when' => '',
                    'url' => $waiting['url'] ?? false, 'do' => get_string('qs_see', $c), 'strong' => $key === 'more'];
            }
        }
        $groups = [];
        foreach (['byband' => 'qs_by_hour', 'bychannel' => 'qs_by_way'] as $key => $title) {
            $rows = [];
            foreach ($r[$key] as $name => $group) {
                $rows[] = ['name' => get_string(($key === 'byband' ? 'panel_band_' : 'via_') . $name, $c), 'n' => $group['asked'],
                    'split' => get_string('qs_with_answer', $c, $group['answered'])];
            }
            if ($key === 'bychannel') {
                usort($rows, fn($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
            }
            if ($rows) {
                $groups[] = panel_answer::bars(get_string($title, $c), $rows);
            }
        }
        $rest = count($w->mine) - $r['asked'];
        return [$r['asked'] ? panel_answer::block('contestadas', get_string('qs_wrote', $c), ['lead' => $r['asked'] <= panel_answer::DOTS ?
            get_string('qs_dot', $c) : '', 'path' => panel_answer::path($r['asked'], $steps), 'apart' => $rest > 0 ? [['n' => $rest,
            'text' => get_string('panel_response_rest', $c), 'url' => false]] : [], 'def' => get_string('panel_response_def', $c)]) : null,
            $open ? panel_answer::block('esperan', get_string('familylist_unanswered', $c), ['list' => ['rows' => $open]]) : null,
            $groups ? panel_answer::block('cuando', get_string('qs_when', $c), ['bars' => ['groups' => $groups],
                'def' => get_string('panel_resp_bands_def', $c)]) : null];
    }

    /**
     * Why they go.
     *
     * @param \stdClass $w
     * @return array[]
     */
    protected static function of_outs(\stdClass $w): array {
        $c = self::C;
        $of = fn(array $campaign, array $mine) => outs::figures($w->centre, $mine, (int) $campaign['since'], (int) $campaign['until']);
        $outs = $of($w->campaign, $w->mine);
        $steps = [];
        $rows = [];
        foreach ($outs['left']['rows'] as $row) {
            $rows[] = ['name' => $row['name'], 'n' => $row['n'], 'url' => $w->nums['why_' . $row['reason']]['url'] ?? false,
                'unknown' => false];
            foreach ($row['steps'] as $step) {
                $steps[$step['name']] = ($steps[$step['name']] ?? 0) + $step['n'];
            }
        }
        $bars = fn(array $list) => array_map(fn($name, $n) => ['name' => panel::upper_first((string) $name), 'n' => $n], array_keys($list), $list);
        $groups = $rows ? [panel_answer::bars(get_string('outs_col_reason', $c), $rows), panel_answer::bars(get_string('qs_reached', $c), $bars($steps)),
            panel_answer::bars(get_string('qs_ways', $c), array_map(fn($r) => ['name' => $r['name'], 'n' => $r['n'], 'split' => get_string(
                'qs_of',
                $c,
                $r['of']
            )], $outs['left']['bychannel']))] : [];
        $never = $outs['never'];
        // The same of the campaigns before.
        $table = [];
        $most = 1;
        foreach (facts::campaigns($w->centre, $w->now) as $campaign) {
            $now = $campaign['key'] === $w->campaign['key'];
            $mine = $now ? $w->mine : facts::group(facts::all($w->centre, $campaign, $w->now), 'campaign');
            $known = array_filter($mine, fn($f) => $f['id']);
            if ($mine) {
                $most = max($most, count($mine));
                $table[] = ['name' => $campaign['year'] !== '' ? $campaign['year'] : $campaign['name'], 'mine' => count($mine), 'current' => $now,
                    'left' => $known ? ($now ? $outs : $of($campaign, $mine))['left']['total'] : null,
                    'url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $w->centre->id, 'pregunta' => 'outs'] +
                        ($campaign['key'] === 'live' ? [] : ['campana' => $campaign['key']])))->out(false)];
            }
        }
        $cell = fn(string $col, ?int $n) => ['col' => get_string($col, $c), 'n' => $n ?? '', 'text' => '', 'v' => $n ? round($n / $most, 3) : 0, 'url' => '',
            'missing' => $n === null ? get_string('qs_unnoted', $c) : ''];
        return [$groups ? panel_answer::block('motivo', get_string('familylist_closed', $c), ['bars' => ['groups' => $groups], 'apart' => $never['total'] ?
            [['n' => $never['total'], 'text' => familylists::name('qs_never', $never['total']), 'url' => false]] : [], 'def' => $w->view['outs']['def'] ?? '']) : null,
            $never['total'] ? panel_answer::block('nunca', panel::upper_first(get_string('qs_never', $c)), ['bars' => ['groups' => [
                panel_answer::bars(get_string('outs_col_reason', $c), $never['rows']), panel_answer::bars(get_string('qs_ways', $c), $never['channels'])]]]) : null,
            count($table) > 1 ? panel_answer::block('antes', get_string('qs_others', $c), ['table' => ['cols' => [['name' => get_string(
                'familylist_closed',
                $c
            )], ['name' => get_string('familylist_campaign', $c)]], 'rows' => array_map(fn($row) => ['name' => $row['name'], 'when' => '',
                'url' => $row['current'] || $row['left'] === null ? false : $row['url'], 'current' => $row['current'], 'cells' => [
                    $cell('familylist_closed', $row['left']), $cell('familylist_campaign', $row['mine'])]], $table)]]) : null];
    }

    /**
     * Whether there is room.
     *
     * @param \stdClass $w
     * @return array[]
     */
    protected static function of_places(\stdClass $w): array {
        $c = self::C;
        $asked = array_column(panel_inbox::places($w->centre, '', $w->now)['list'], null, 'name');
        $rows = [];
        $most = max(array_merge([1], array_column($w->view['places'] ?: [], 'places')));
        foreach (array_filter($w->view['places'] ?: [], fn($p) => $p['places']) as $course) {
            $cell = fn(string $col, int $n) => ['col' => get_string($col, $c), 'n' => $n, 'text' => '', 'v' => round($n / $most, 3), 'url' => '', 'missing' => ''];
            $one = $asked[$course['name']] ?? ['interested' => 0, 'visited' => 0];
            $rows[] = ['name' => $course['name'], 'when' => '', 'url' => false, 'current' => false, 'cells' => [
                $cell('qs_places', (int) $course['places']), $cell('qs_taken', (int) $course['taken']), $cell('qs_asking', (int) $one['interested']),
                ['col' => get_string('qs_left', $c), 'n' => '', 'text' => $course['sentence'], 'v' => 0, 'url' => '', 'missing' => '']]];
        }
        return [$rows ? panel_answer::block('cursos', get_string('qs_by_course', $c), ['table' => ['cols' => array_map(
            fn($col) => ['name' => get_string($col, $c)],
            ['qs_places', 'qs_taken', 'qs_asking', 'qs_left']
        ), 'rows' => $rows], 'def' => get_string('qs_places_def', $c), 'note' => $w->view['noplaces'] ?
            panel_answer::note($w->view['noplaces']['text'], $w->view['noplaces']['url'] ?: '', $w->view['noplaces']['link']) : false]) : null];
    }

    /**
     * Who comes in and who leaves.
     *
     * @param \stdClass $w
     * @return array[]
     */
    protected static function of_students(\stdClass $w): array {
        $c = self::C;
        $asked = $w->campaign['key'] === 'live' ? '' : $w->campaign['year'];
        $new = panel_newstudents::context($w->centre, $asked, true, fn($view) => panel_metrics::url($w->centre, $view), false, $w->now);
        $o = $new ? $new['newstudents']['origins'] : false;
        $m = $new ? $new['newstudents']['moves'] : false;
        $blocks = [];
        if ($o) {
            $groups = [panel_answer::bars($o['cut'], array_map(fn($r) => ['name' => $r['name'], 'n' => $r['n'], 'split' => $r['sub'],
                'unknown' => $r['key'] === 'unknown'], $o['rows']))];
            foreach ($o['rows'] as $row) {
                if ($row['hasparts']) {
                    $groups[] = panel_answer::bars($row['name'], array_map(fn($p) => ['name' => panel::upper_first($p['name']), 'n' => (int) $p['n']], $row['parts']));
                }
            }
            $blocks[] = panel_answer::block('quienes', get_string('qs_from', $c), ['bars' => ['groups' => $groups]]);
        }
        if ($m) {
            $past = array_values(array_filter($m['months'], fn($month) => !$month['future']));
            $most = max(array_merge([1], array_map(fn($month) => max((int) $month['joinsn'], (int) $month['leavesn']), $past)));
            $groups = array_map(fn($list) => panel_answer::bars($list['title'], $list['rows']), $m['lists']);
            if ($m['stages']) {
                $groups[] = panel_answer::bars(get_string('qs_leave_stage', $c), array_values(array_filter(array_map(
                    fn($s) => ['name' => $s['name'], 'n' => $s['leaves']],
                    $m['stages']
                ), fn($r) => $r['n'])));
            }
            $blocks[] = panel_answer::block('meses', get_string('qs_months', $c), ['lead' => $m['cut'], 'columns' => [
                'keys' => ['now' => get_string('qs_in', $c), 'before' => get_string('qs_out', $c)],
                'rows' => array_map(fn($month) => ['label' => $month['name'], 'n' => '+' . $month['joinsn'] . ' −' . $month['leavesn'],
                    'v' => round($month['joinsn'] / $most, 3), 'g' => round($month['leavesn'] / $most, 3), 'said' => get_string('qs_month_said', $c, (object) [
                        'month' => $month['fullname'], 'in' => $month['joinsn'], 'out' => $month['leavesn']])], $past)],
                'note' => $m['undated'] ? panel_answer::note($m['undated'], '', '') : false, 'def' => get_string('qs_months_def', $c)]);
            $blocks[] = $groups ? panel_answer::block('salen', get_string('qs_leaving', $c), ['bars' => ['groups' => $groups]]) : null;
        }
        return $blocks;
    }

    /**
     * What they like and what they do.
     *
     * @param \stdClass $w
     * @return array[]
     */
    protected static function of_likes(\stdClass $w): array {
        $c = self::C;
        $likes = panel_likes::context($w->centre, $w->campaign, $w->mine, $w->now);
        $groups = [];
        if (!empty($likes['touches'])) {
            $groups[] = panel_answer::bars(get_string('qs_touched', $c), array_map(fn($r) => ['name' => $r['name'], 'n' => $r['families']], $likes['touches']));
        }
        $groups[] = panel_answer::bars(get_string('qs_depth', $c), array_map(fn($r) => ['name' => $r['name'], 'n' => $r['families']], $likes['pages']['depth']));
        $blocks = [panel_answer::block('pagina', get_string('qs_their_page', $c), ['lead' => $likes['pages']['visitstext'], 'bars' => ['groups' => $groups],
            'def' => $likes['pages']['depthdef']])];
        $blocks[] = $likes['hashobbies'] ? panel_answer::block('gustos', get_string('qs_hobbies', $c), ['lead' => $likes['hobbiesof'], 'bars' => ['groups' => [
            panel_answer::bars(get_string('qs_adults', $c), array_map(fn($r) => ['name' => $r['name'], 'n' => $r['adults']], $likes['hobbies']))]],
            'def' => $likes['hobbiesdef']]) : null;
        return $blocks;
    }

    /**
     * Where the data of a campaign comes from.
     *
     * @param \stdClass $w
     * @return array sources and hassources
     */
    protected static function sources(\stdClass $w): array {
        $c = self::C;
        $lives = count(array_filter($w->mine, [facts::class, 'lives']));
        $out = $lives ? [['name' => 'Eguahe', 'when' => userdate($w->now, get_string('strftimedate', 'langconfig')),
            'what' => get_string('actr_source_eguahe', $c) . ' (' . $lives . ')']] : [];
        foreach (imports::for_centre((int) $w->centre->id, 'contacts') as $import) {
            $n = count(array_filter($w->mine, fn($f) => in_array((int) $import->id, array_map('intval', $f['sources']), true)));
            if ($n) {
                $out[] = ['name' => format_string($import->label), 'when' => userdate($import->timecreated, get_string('strftimedate', 'langconfig')),
                    'what' => get_string('actr_source_file', $c) . ' (' . $n . ')'];
            }
        }
        return ['sources' => $out, 'hassources' => (bool) $out];
    }
}
