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
 * Numbers by questions: what the centre wants to know, each with its answer in a sentence and the way to the detail. The acts
 * first, the last one the first; then the campaign, whole, of a school year. Never a file to choose and never an empty page:
 * without anything to answer with, it says what is missing and where to put it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class numbers_questions {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int The acts asked about at first; the rest, in the report of any of them. */
    const ACTS = 6;

    /** @var int The acts still to come. */
    const AHEAD = 2;

    /**
     * The questions of a centre, with their answers.
     *
     * @param \stdClass $centre
     * @param string $key The campaign: live, or y and its school year.
     * @param bool $canact
     * @param int $now
     * @return array As written in the contract.
     */
    public static function context(\stdClass $centre, string $key = 'live', bool $canact = false, int $now = 0): array {
        $now = $now ?: time();
        $c = self::C;
        $campaigns = facts::campaigns($centre, $now);
        $campaign = $campaigns[$key] ?? $campaigns['live'];
        $url = fn(array $params = [], string $anchor = '') => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', [
            'c' => $centre->id] + $params + ($campaign['key'] === 'live' ? [] : ['campana' => $campaign['key']]), $anchor ?: null))->out(false);
        $groups = [];
        $acts = $campaign['key'] === 'live' ? self::acts($centre, $now) : [];
        $last = null;
        $held = [];
        if ($campaign['key'] === 'live') {
            // The last act held goes apart, large; the rest, as leaves; and the visits, with the questions.
            $held = array_filter($acts, fn($act) => !empty($act['leaf']));
            $last = null;
            foreach ($held as $i => $act) {
                if ($act['path']) {
                    $last = $act;
                    unset($held[$i]);
                    break;
                }
            }
            $last = $last ?? array_shift($held);
            $rest = [self::visits($centre, $now)];
            if (!$last) {
                $rest = array_merge($acts, $rest);
            }
            $groups[] = ['key' => 'acts', 'name' => get_string($last ? 'nq_group_visits' : 'nq_group_acts', $c), 'questions' => $rest];
        }
        // Each question with an answer opens its own sheet.
        $sheets = array_map(fn($row) => ['url' => $row['empty'] ? $row['url'] : $url(['pregunta' => $row['key']])] + $row, self::year(
            $centre,
            $campaign,
            $now
        ));
        $groups[] = ['key' => 'year', 'name' => $campaign['year'] !== '' ? get_string('panel_past_campaign', $c, $campaign['year']) :
            get_string('panel_this_campaign', $c), 'questions' => $sheets];
        $ask = [];
        foreach ($canact ? act_files::of_centre($centre) : [] as $file) {
            if ($file['state'] === 'ask') {
                $ask[] = ['import' => $file['id'], 'label' => format_string($file['label']), 'rows' => $file['rows'],
                    'text' => get_string('nq_ask', $c, format_string($file['label'])), 'action' => $url(['preguntas' => 1]), 'sesskey' => sesskey(),
                    'date' => panel::date_input('actday' . $file['id'], '', $centre, 'acto-' . $file['id'])];
            }
        }
        return ['head' => panel::head(['title' => get_string('qs_title', $c), 'sub' => get_string('panel_cut', $c, userdate(
            $now,
            get_string('strftimetime', 'langconfig')
        )) . '.', 'action' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/report.php', ['c' => $centre->id]))->out(false),
            'name' => get_string('report_title', $c), 'icon' => '', 'anchor' => false]]), 'groups' => $groups, 'ask' => $ask,
            'sheets' => array_map(fn($key, $name) => ['name' => get_string($name, $c), 'url' => $url(['hoja' => $key])], ['campana', 'curso',
                'familias'], ['panel_sheet_campaign', 'panel_new_sheet', 'panel_sheet_families']),
            'asking' => get_string(count($ask) === 1 ? 'nq_ask_count_one' : 'nq_ask_count', $c, count($ask)),
            'hasask' => (bool) $ask, 'act' => $last ?: false, 'acts' => array_values($held), 'hasacts' => (bool) $held, 'hasyears' => count($campaigns) > 1,
            'years' => array_values(array_map(fn($one) => ['key' => $one['key'], 'name' => $one['year'] !== '' ? $one['year'] : $one['name'],
                'current' => $one['key'] === $campaign['key'], 'url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id,
                'preguntas' => 1] + ($one['key'] === 'live' ? [] : ['campana' => $one['key']])))->out(false)], $campaigns))];
    }

    /**
     * A question with its answer.
     *
     * @param string $key
     * @param string $question
     * @param string $answer Empty when there is nothing to answer with.
     * @param string $url
     * @param array|null $missing text, url and link: what is missing, and where to put it.
     * @param string $warn What is not known, in a sentence.
     * @return array
     */
    protected static function one(
        string $key,
        string $question,
        string $answer,
        string $url,
        ?array $missing = null,
        string $warn = ''
    ): array {
        return ['key' => $key, 'question' => $question, 'answer' => $answer, 'url' => $url, 'detail' => $url, 'empty' => $answer === '',
            'missing' => $answer === '' && $missing ? $missing : false, 'warn' => $warn, 'recent' => false, 'nums' => []];
    }

    /**
     * The acts, the last one the first, and those to come.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array[]
     */
    protected static function acts(\stdClass $centre, int $now): array {
        $c = self::C;
        $all = act_report::all($centre, $now, true);
        $past = array_slice(array_filter($all, fn($a) => $a['time'] <= $now), 0, self::ACTS, true);
        $ahead = array_slice(array_reverse(array_filter($all, fn($a) => $a['time'] > $now), true), 0, self::AHEAD, true);
        $out = [];
        foreach ($past + $ahead as $key => $act) {
            $people = act_report::people($centre, $act, $now);
            $lists = act_report::lists($centre, $act, $now, $people);
            $unknown = count($people['lists']['unknown']);
            $signed = count($people['lists']['signed']);
            $warn = $act['time'] <= $now && $unknown && $unknown < $signed ? get_string('actr_answer_unknown' . ($unknown === 1 ? '_one' : ''), $c, $unknown) : '';
            $row = self::one('act-' . $key, act_report::question($act, $now), act_report::answer($act, $people['lists'], $lists, $now), (new \moodle_url(
                '/local/oksigeniaeguahe/metrics.php',
                ['c' => $centre->id, 'acto' => $key]
            ))->out(false), null, $warn);
            $row['recent'] = $act['time'] <= $now && $act['time'] > $now - 14 * DAYSECS;
            $row['leaf'] = ['dow' => userdate($act['time'], '%a'), 'day' => (int) userdate($act['time'], '%d'), 'month' => userdate($act['time'], '%b'),
                'year' => userdate($act['time'], '%Y')];
            $held = $act['time'] <= $now;
            $known = $held && $unknown < $signed;
            $row['name'] = $act['name'];
            $row['counts'] = [['n' => $signed, 'what' => get_string('qs_count_signed' . ($signed === 1 ? '_one' : ''), $c)]];
            if ($known) {
                $came = count($people['lists'][$signed ? 'came' : 'walkin']);
                $row['counts'][] = ['n' => $came, 'what' => get_string('qs_count_came' . ($came === 1 ? '_one' : ''), $c)];
                if ($lists['enrolled']) {
                    $enrolled = count($lists['enrolled']);
                    $row['counts'][] = ['n' => $enrolled, 'what' => get_string('qs_count_enrolled' . ($enrolled === 1 ? '_one' : ''), $c)];
                }
            }
            $row['aside'] = $held ? ($signed && !$known ? get_string('qs_count_unnoted', $c) : '') : get_string('qs_count_ahead', $c);
            // The way of the last one, drawn: those who signed up, those who came and those who asked for a place.
            $parts = [];
            foreach ($act['time'] <= $now ? ['came' => 'yes', 'notcame' => 'no', 'unknown' => 'unknown'] : [] as $what => $kind) {
                if (count($people['lists'][$what])) {
                    $parts[] = ['kind' => $kind, 'n' => count($people['lists'][$what])];
                }
            }
            $row['path'] = $signed && $parts ? panel_answer::path($signed, [
                ['name' => get_string('actr_num_signed', $c), 'n' => $signed, 'url' => false, 'parts' => [['kind' => 'yes', 'n' => $signed]], 'legend' => []],
                ['name' => get_string('actr_num_came', $c), 'n' => count($people['lists']['came']), 'url' => false, 'parts' => $parts, 'legend' => []],
                ['name' => get_string('actr_num_applied', $c), 'n' => count($lists['applied']), 'url' => false,
                    'parts' => $lists['applied'] ? [['kind' => 'yes', 'n' => count($lists['applied'])]] : [], 'legend' => []],
            ]) : false;
            $out[] = $row;
        }
        if (!$out) {
            $out[] = self::one('act', get_string('nq_acts', $c), '', '', ['text' => get_string('nq_acts_none', $c),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id, 'new' => 1]))->out(false),
                'link' => get_string('nq_acts_new', $c)]);
        }
        return $out;
    }

    /**
     * The visits of the families that are past, by what is known of each family: marked in Eguahe or in a line of the sheet.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return int[][] came, notcame, off and unknown: household ids; and ahead
     */
    public static function visit_outcomes(\stdClass $centre, int $now): array {
        global $DB;
        $said = facts::sheet_outcomes($centre);
        $day = fn(int $t) => userdate($t, '%Y-%m-%d', 99, false);
        $acts = [];
        foreach (act_report::all($centre, $now, true) as $act) {
            if ($act['event'] && !$act['talk']) {
                $acts[$day($act['time'])] = true;
            }
        }
        $best = [];
        $out = ['came' => [], 'notcame' => [], 'off' => [], 'unknown' => [], 'ahead' => []];
        $order = ['unknown' => 0, 'off' => 1, 'notcame' => 2, 'came' => 3];
        foreach (appointments::agenda($centre, 0, $now + YEARSECS, $now) as $item) {
            // What each family has of its own; a day of the sheet without its hour that is the day of an act is that act.
            if ($item['kind'] === 'act' || ($item['dayonly'] && isset($acts[$day($item['start'])]))) {
                continue;
            }
            $hid = $item['householdid'];
            if ($item['state'] === 'coming') {
                $out['ahead'][$hid] = $hid;
                continue;
            }
            $state = in_array($item['state'], ['came', 'notcame', 'off'], true) ? $item['state'] : 'unknown';
            foreach ($said[$hid] ?? [] as $time => $one) {
                if ((!(int) $time || $day((int) $time) === $day($item['start'])) && $order[$one] > $order[$state]) {
                    $state = $one;
                }
            }
            $best[$hid] = isset($best[$hid]) && $order[$best[$hid]] > $order[$state] ? $best[$hid] : $state;
        }
        // Somebody said of its child that it visited.
        $asked = array_keys(array_filter($best, fn($state) => $state === 'unknown'));
        foreach (array_chunk($asked, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            foreach ($DB->get_fieldset_select(households::CHILDREN, 'householdid', "householdid $in AND stage = 'visit'", $params) as $hid) {
                $best[(int) $hid] = 'came';
            }
        }
        foreach ($best as $hid => $state) {
            $out[$state][$hid] = $hid;
        }
        return array_map('array_values', $out);
    }

    /**
     * How the visits are going.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array
     */
    protected static function visits(\stdClass $centre, int $now): array {
        $c = self::C;
        $who = self::visit_outcomes($centre, $now);
        $o = array_map('count', $who);
        $past = $o['came'] + $o['notcame'] + $o['off'] + $o['unknown'];
        $agenda = (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id]))->out(false);
        $said = [];
        if ($past) {
            $parts = [];
            foreach (array_filter(['came' => $o['came'], 'notcame' => $o['notcame'], 'off' => $o['off'], 'unk' => $o['unknown']]) as $key => $n) {
                $parts[] = get_string('nq_visits_' . $key, $c, $n);
            }
            $said[] = get_string('nq_visits_past' . ($past === 1 ? '_one' : ''), $c, $past) . ' ' . implode('; ', $parts) . '.';
        }
        if ($o['ahead']) {
            $said[] = get_string('nq_visits_ahead' . ($o['ahead'] === 1 ? '_one' : ''), $c, $o['ahead']);
        }
        $nums = [];
        foreach (array_filter($who) as $state => $ids) {
            $nums[] = familylists::num($centre, 'visits-' . $state, familylists::name('familylist_visits_' . $state, count($ids)), $ids);
        }
        return ['nums' => $nums] + self::one(
            'visits',
            get_string('nq_visits', $c),
            implode(' ', $said),
            $agenda,
            ['text' => get_string('nq_visits_none', $c), 'url' => $agenda, 'link' => get_string('nq_visits_give', $c)],
            ''
        );
    }

    /**
     * The questions of the campaign of a school year.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param int $now
     * @param string $only Only this one.
     * @return array[]
     */
    protected static function year(\stdClass $centre, array $campaign, int $now, string $only = ''): array {
        $c = self::C;
        $url = fn(array $params = [], string $anchor = '') => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id] + $params +
            ($campaign['key'] === 'live' ? [] : ['campana' => $campaign['key']]), $anchor ?: null))->out(false);
        $wanted = fn(string $key) => $only === '' || $only === $key;
        $all = facts::all($centre, $campaign, $now);
        $mine = facts::group($all, 'campaign');
        $sum = facts::sum($mine);
        $live = $campaign['key'] === 'live';
        $year = $campaign['year'];
        $form = ['text' => get_string('nq_none', $c), 'url' => (new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]))->out(false),
            'link' => get_string('nq_none_do', $c)];
        $out = [];
        $kinds = facts::kinds($all);
        $gone = $sum['closed'] + $sum['went'];
        $answer = $kinds['all'] ? implode(' ', array_filter([panel_metrics::kinds_sentence($all, $campaign, ''), $sum['enrolled'] ?
            get_string('nq_campaign_enrolled', $c, $sum['enrolled']) : ($mine ? get_string('nq_campaign_noenrolled', $c) : ''),
            $gone ? get_string('nq_campaign_gone', $c, $gone) : ''])) : '';
        $warn = $sum['outside'] ? get_string('panel_far_outside' . ($sum['outside'] === 1 ? '_one' : ''), $c, $sum['outside']) : '';
        $question = get_string($year === '' ? 'nq_campaign_noyear' : 'nq_campaign', $c, $year);
        $out[] = ['nums' => familylists::nums($centre, $campaign, $all, ['campaign', 'fresh', 'knew', 'centre', 'other', 'came', 'applied',
            'waitlist', 'place', 'enrolled', 'went', 'closed', 'nokids', 'undated'])] +
            self::one('campaign', $question, $answer, $url(['hoja' => 'campana']), $form, $warn);
        // Where they come from, and what it cost.
        $by = arrival::by_channel(array_map(fn($f) => ['basis' => $f['voices']['basis']] + $f, $mine));
        $rows = array_filter($by['rows'], fn($r) => $r['key'] !== 'unmeasured' && $r['n']);
        usort($rows, fn($a, $b) => $b['n'] <=> $a['n']);
        $cost = array_sum(panel_metrics::spend($centre, $campaign));
        $answer = '';
        if ($rows) {
            $top = array_column(array_filter($rows, fn($r) => $r['n'] === $rows[0]['n']), 'name');
            $answer = count($top) > 3 ? get_string('nq_channels_even', $c) : get_string('nq_channels_top' . (count($top) > 1 ? '_tie' : ''), $c, (object) [
                'name' => implode(', ', $top), 'n' => $rows[0]['n'], 'of' => count($mine)]);
            $answer .= ' ' . ($by['best'] ? get_string('nq_channels_best', $c, (object) ['name' => $by['best']['name'], 'frac' => $by['best']['frac'],
                'min' => arrival::FAIR]) : get_string('nq_channels_nobest', $c));
            $answer .= ' ' . ($cost > 0 ? get_string('nq_channels_cost', $c, (object) ['money' => centres::money($centre, $cost),
                'each' => centres::money($centre, $cost / max(1, count($mine)))]) : get_string('nq_channels_nocost', $c));
        }
        $nums = [];
        foreach ($by['rows'] as $row) {
            $nums[] = familylists::num($centre, familylists::of_year($campaign, 'channel_' . $row['key']), $row['name'], array_column(array_filter(
                $mine,
                fn($f) => $f['channel'] === $row['key']
            ), 'id'));
        }
        $warn = $by['unmeasured'] ? get_string('panel_unknown_channel' . ($by['unmeasured'] === 1 ? '_one' : ''), $c, $by['unmeasured']) : '';
        $out[] = ['nums' => array_values(array_filter($nums, fn($n) => $n['n']))] +
            self::one('channels', get_string('nq_channels', $c), $answer, $url(['hoja' => 'campana'], 'por-donde'), $form, $warn);
        // Whether they are answered in time.
        $r = panel_metrics::said(metrics::response((int) $centre->id, facts::ids(array_filter($mine, [facts::class, 'lives'])), $now));
        $out[] = ['nums' => familylists::nums($centre, $campaign, $all, ['answered', 'unanswered', 'tried', 'unreached', 'met', 'quiet'])] + self::one(
            'answers',
            get_string('nq_answers', $c),
            $r['asked'] ? panel_metrics::response_sentence($r) : '',
            $url(['hoja' => 'campana'], 'primera-respuesta'),
            ['text' => get_string('panel_response_none', $c), 'url' => '', 'link' => '']
        );
        // When it is best to send the letters of the acts: in the campaign going on, always; in one past, if it had any.
        $letters = $wanted('letters') ? letters::figures($centre, (int) $campaign['since'], $live ? 0 : (int) $campaign['until'], $now) : null;
        if ($letters && ($live || $letters['sent'])) {
            $ways = letters::ways($centre, (int) $campaign['since'], $live ? 0 : (int) $campaign['until'], $now);
            $row = self::one('letters', get_string('nq_letters', $c), letters::sentence($letters, $ways), (new \moodle_url(
                '/local/oksigeniaeguahe/events.php',
                ['c' => $centre->id]
            ))->out(false), ['text' => get_string('nq_letters_none', $c), 'url' => (new \moodle_url(
                '/local/oksigeniaeguahe/events.php',
                ['c' => $centre->id]
            ))->out(false), 'link' => get_string('nq_letters_none_do', $c)]);
            // Its sheet is all there is of it: no other to see it whole.
            $out[] = ['detail' => ''] + $row;
        }
        // Why they go.
        $outs = outs::figures($centre, $mine, (int) $campaign['since'], (int) $campaign['until']);
        $answer = '';
        if ($mine || $outs['never']['total']) {
            $summary = outs::summary($centre, $mine, (int) $campaign['since'], (int) $campaign['until']);
            $top = $outs['left']['rows'];
            $tie = count($top) > 1 && $top[0]['n'] === $top[1]['n'];
            $why = !$top || count($top) === 1 && $top[0]['n'] === 1 ? '' : ($tie ? get_string('nq_outs_tie', $c) : get_string('nq_outs_top', $c, (object) [
                'name' => $top[0]['name'], 'n' => $top[0]['n']]));
            $answer = implode(' ', array_filter([$summary['left'], $why, $summary['never']]));
        }
        $nums = [];
        foreach ($outs['left']['rows'] as $row) {
            $nums[] = familylists::num($centre, familylists::of_year($campaign, 'why_' . $row['reason']), $row['name'], array_column(array_filter(
                $mine,
                fn($f) => $f['closed'] && $f['why'] === $row['reason']
            ), 'id'));
        }
        $out[] = ['nums' => $nums] + self::one('outs', get_string('nq_outs', $c), $answer, $url(['hoja' => 'campana'], 'los-que-se-van'), $form);
        // Whether there is room.
        if ($live) {
            $free = [];
            $full = [];
            foreach (panel_inbox::places($centre, '', $now)['list'] as $course) {
                if ($course['free']) {
                    $free[] = get_string('nq_places_one', $c, (object) ['name' => $course['name'], 'free' => $course['free']]);
                } else {
                    $full[] = $course['name'];
                }
            }
            $answer = implode(' ', array_filter([$full ? get_string('nq_places_full', $c, implode(', ', $full)) : '',
                $free ? get_string('nq_places_free', $c, implode(', ', $free)) : '']));
            $missing = ['text' => get_string('nq_places_none', $c), 'link' => get_string('panel_places_unset_go', $c),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id], 'aj-oferta'))->out(false)];
            $out[] = self::one('places', get_string('nq_places', $c), $answer, $url(['hoja' => 'campana'], 'plazas'), $missing);
        }
        // Who comes in and who leaves.
        $asked = $live ? '' : $year;
        $new = !$wanted('students') ? null : panel_newstudents::context($centre, $asked, true, fn($view) => panel_metrics::url($centre, $view), false, $now);
        $new = $new && ($asked === '' || $new['year'] === $asked) ? $new : null;
        $out[] = self::one(
            'students',
            get_string('nq_students', $c),
            $new ? $new['title'] . '.' : '',
            $url(['hoja' => 'curso'] + ($asked !== '' ? ['anio' => $asked] : [])),
            ['text' => get_string('nq_students_none', $c), 'url' => (new \moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]))->out(false),
            'link' => get_string(
                'panel_import_past',
                $c
            )]
        );
        // What they like.
        $likes = $live && $wanted('likes') ? panel_likes::context($centre, $campaign, $mine, $now) : false;
        if ($likes) {
            $out[] = self::one(
                'likes',
                get_string('nq_likes', $c),
                $likes['empty'] ? '' : $likes['pages']['openedtext'] . ' ' . $likes['pages']['visitstext'],
                $url(['hoja' => 'familias']),
                ['text' => $likes['emptytext'], 'url' => '', 'link' => '']
            );
        }
        return $out;
    }

    /**
     * One question of a campaign, with its answer.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param string $key
     * @param int $now
     * @return array|null
     */
    public static function find(\stdClass $centre, array $campaign, string $key, int $now): ?array {
        return array_column(self::year($centre, $campaign, $now, $key), null, 'key')[$key] ?? null;
    }
}
