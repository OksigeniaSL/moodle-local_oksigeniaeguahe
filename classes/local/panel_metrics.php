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
 * The numbers of a centre in the panel, whatever they look like, in sentences first: how fast families get an answer;
 * how far they got, with the campaign before at the same week behind and each channel's part inside; where they came
 * from in three voices; the campaign week by week; the places of each course with what may still come; the school year
 * as a ledger; and the roll year by year.
 *
 * Every chart comes from one list of the families of a campaign, the one under way in Eguahe or one imported, each with
 * when it first came, how far it got, when it enrolled, its course and where it came from. Never a number about one
 * family: only counts.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_metrics {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The stops of the way, in order. */
    const STEPS = ['inquiry', 'visit', 'application', 'admitted', 'enrolled'];

    /** @var string[] Where families came from, said three ways. */
    const VOICES = ['first', 'last', 'said'];

    /** @var int The channels drawn one by one; the rest go together. */
    const CHANNELS = 7;

    /** @var int The backpacks drawn on the bench, at most. */
    const BENCH = 12;

    /** @var string The label of the spend of the campaign under way. */
    const LIVE = 'eguahe';

    /** @var float How wide the range of what may come is: 80 % of the times, it falls inside. */
    const Z = 1.2816;

    /**
     * The campaigns of a centre: the one under way in Eguahe, then the imported ones, the newest first.
     *
     * @param \stdClass $centre
     * @return array key => [key, name, start, import]
     */
    public static function campaigns(\stdClass $centre): array {
        $out = ['live' => ['key' => 'live', 'name' => get_string('panel_this_campaign', self::C),
            'start' => (int) ($centre->campaignstart ?? 0), 'import' => null]];
        $imports = imports::for_centre((int) $centre->id, 'contacts');
        usort($imports, fn($a, $b) => [(int) ($b->campaignstart ?? 0), (int) $b->id] <=> [(int) ($a->campaignstart ?? 0),
            (int) $a->id]);
        foreach ($imports as $import) {
            $out['i' . $import->id] = ['key' => 'i' . $import->id, 'name' => format_string($import->label),
                'start' => (int) ($import->campaignstart ?? 0), 'import' => $import];
        }
        return $out;
    }

    /**
     * The families of a campaign, each with when it first came, how far it got, whether it closed, when it enrolled, its
     * course and where it came from, said three ways.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @return array
     */
    public static function families(\stdClass $centre, array $campaign): array {
        return $campaign['import'] ? self::imported($campaign['import']) : self::live($centre);
    }

    /**
     * The families of the campaign under way in Eguahe, those already of the centre apart.
     *
     * @param \stdClass $centre
     * @return array
     */
    protected static function live(\stdClass $centre): array {
        global $DB;
        $c = self::C;
        $id = ['centreid' => $centre->id];
        $offers = $DB->get_records_menu(centres::OFFERS, $id, '', 'id, name');
        $out = [];
        foreach ($DB->get_records_select(households::T, 'centreid = :centreid AND incentre = 0', $id) as $h) {
            $out[$h->id] = ['first' => (int) $h->timecreated, 'step' => 1, 'closed' => true, 'enrolledat' => 0, 'course' => '',
                'coursename' => '', 'moving' => !empty($h->moving), 'voices' => ['first' => '', 'last' => '',
                'said' => $h->heardfrom ? get_string('heard_' . (in_array($h->heardfrom, lists::HEARD_FROM, true) ? $h->heardfrom :
                    'other'), $c) : '']];
        }
        foreach ($DB->get_records_select(households::CHILDREN, 'centreid = :centreid', $id, 'id ASC') as $child) {
            if (!isset($out[$child->householdid])) {
                continue;
            }
            $f = &$out[$child->householdid];
            $step = lists::STAGE_STEP[$child->stage] ?? 1;
            if ($child->stage !== 'closed') {
                $f['closed'] = false;
                if ($step >= $f['step'] || $f['course'] === '') {
                    $name = (string) ($offers[$child->offerid] ?? '');
                    $f['course'] = $name !== '' ? recognise::plain($name) : $f['course'];
                    $f['coursename'] = $name !== '' ? format_string($name) : $f['coursename'];
                }
                $f['step'] = max($f['step'], min(5, $step));
                if ($child->stage === 'enrolled') {
                    $f['enrolledat'] = max($f['enrolledat'], (int) $child->stagetime);
                }
            }
            unset($f);
        }
        // Where each family first and last came from: the entry and its place, or the channel.
        $entries = $DB->get_records_menu(entries::T, $id, '', 'id, name');
        $contacts = $DB->get_recordset_select(
            households::CONTACTS,
            "centreid = :centreid AND direction = 'in' AND channel <> 'page'",
            $id,
            'timecreated ASC, id ASC',
            'id, householdid, channel, entryid, detail, timecreated'
        );
        foreach ($contacts as $contact) {
            if (!isset($out[$contact->householdid])) {
                continue;
            }
            $name = $contact->entryid && isset($entries[$contact->entryid]) ? format_string($entries[$contact->entryid]) .
                ($contact->detail ? ' · ' . $contact->detail : '') : get_string('channel_' . (in_array(
                    $contact->channel,
                    lists::CHANNELS,
                    true
                ) ? $contact->channel : 'other'), $c);
            $f = &$out[$contact->householdid];
            if ($f['voices']['first'] === '') {
                $f['voices']['first'] = $name;
                $f['first'] = min($f['first'], (int) $contact->timecreated);
            }
            $f['voices']['last'] = $name;
            unset($f);
        }
        $contacts->close();
        foreach ($out as $key => $f) {
            if ($f['moving']) {
                $out[$key]['voices']['first'] = $out[$key]['voices']['last'] = get_string('metrics_live_moving', $c);
            }
            foreach (['first', 'last'] as $voice) {
                $out[$key]['voices'][$voice] = $out[$key]['voices'][$voice] ?: get_string('channel_other', $c);
            }
        }
        return array_values($out);
    }

    /**
     * The families of an imported campaign, those already of the centre apart.
     *
     * @param \stdClass $import
     * @return array
     */
    protected static function imported(\stdClass $import): array {
        $c = self::C;
        $out = [];
        foreach (metrics::households($import) as $h) {
            if ($h['incentre']) {
                continue;
            }
            $name = fn(\stdClass $row) => (string) ($row->sourcedetail ?: get_string('channel_' . (in_array(
                $row->channel,
                lists::CHANNELS,
                true
            ) ? $row->channel : 'other'), $c));
            $grade = trim((string) ($h['grade'] ?? ''));
            $out[] = ['first' => (int) $h['first']->timecontact, 'closed' => false,
                'step' => $h['enrolled'] ? 5 : ($h['applied'] ? 3 : ($h['visited'] ? 2 : 1)),
                'enrolledat' => $h['enrolled'] ? (int) ($h['enrolledat'] ?: $h['last']->timecontact) : 0,
                'course' => $grade !== '' ? recognise::plain($grade) : '', 'coursename' => $grade, 'moving' => false,
                'voices' => ['first' => $name($h['first']), 'last' => $name($h['last']),
                    'said' => trim((string) $h['first']->declaredsource)]];
        }
        return $out;
    }

    /**
     * Says a part of a whole in words: «una de cada cuatro».
     *
     * @param int $part
     * @param int $whole
     * @return string
     */
    public static function fraction(int $part, int $whole): string {
        if (!$whole || !$part) {
            return get_string('panel_frac_none', self::C);
        }
        $r = $part / $whole;
        if (abs($r - 1) < 0.01) {
            return get_string('panel_frac_all', self::C);
        }
        $names = [[1 / 2, '1_2'], [1 / 3, '1_3'], [1 / 4, '1_4'], [1 / 5, '1_5'], [2 / 3, '2_3'], [3 / 4, '3_4'], [1 / 6, '1_6'],
            [1 / 8, '1_8'], [1 / 10, '1_10']];
        $best = $names[0];
        foreach ($names as $name) {
            $best = abs($name[0] - $r) < abs($best[0] - $r) ? $name : $best;
        }
        return get_string('panel_frac_' . $best[1], self::C);
    }

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param array $view campaign, course, compare, voice, show and ensenar, as asked.
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, array $view, bool $canact, int $now = 0): array {
        $c = self::C;
        $now = $now ?: time();
        $campaigns = self::campaigns($centre);
        $campaign = $campaigns[$view['campaign'] ?? ''] ?? $campaigns['live'];
        $keys = array_keys($campaigns);
        $previous = $campaigns[$keys[array_search($campaign['key'], $keys, true) + 1] ?? ''] ?? null;
        $families = self::families($centre, $campaign);
        $course = (string) ($view['course'] ?? '');
        $courses = self::courses($families);
        $course = isset($courses[$course]) ? $course : '';
        $mine = array_values(array_filter($families, fn($f) => $course === '' || $f['course'] === $course));
        // The campaign before, at the same week since its start: only when the centre wrote when both started.
        $compare = ($view['compare'] ?? 1) && $previous && $campaign['start'] && $previous['start'];
        $before = $compare ? array_values(array_filter(
            self::families($centre, $previous),
            fn($f) => $course === '' || $f['course'] === $course
        )) : [];
        $end = $campaign['import'] ? max(array_merge([0], array_column($families, 'first'), array_column(
            $families,
            'enrolledat'
        ))) : $now;
        $elapsed = $campaign['start'] ? max(0, $end - $campaign['start']) : 0;
        $url = fn(array $change) => self::url($centre, array_merge($view, $change));
        $cut = get_string('panel_cut', $c, userdate($now, get_string('strftimetime', 'langconfig')));
        $total = count(array_filter($mine, fn($f) => !$f['closed']));
        $enrolled = count(array_filter($mine, fn($f) => $f['step'] >= 5));
        $voice = in_array($view['voice'] ?? '', self::VOICES, true) ? $view['voice'] : 'first';
        $show = ($view['show'] ?? '') === 'matriculadas' ? 'matriculadas' : 'llegadas';
        $context = [
            'head' => panel::head([
                'title' => $total ? get_string(
                    ($enrolled ? 'panel_metrics_title' : 'panel_metrics_title_none') .
                    ($total === 1 ? '_one' : ''),
                    $c,
                    (object) ['n' => $total, 'enrolled' => $enrolled, 'campaign' => \core_text::strtolower($campaign['name'])]
                ) :
                    get_string('panel_metrics_empty', $c),
                'sub' => $cut . '.',
            ]),
            'ensenar' => !empty($view['ensenar']),
            'ensenarurl' => $url(['ensenar' => 1])->out(false),
            'cut' => $cut,
            'importurl' => (new \moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]))->out(false),
            'importnote' => get_string('panel_import_note', $c),
            'sesskey' => sesskey(),
            'canact' => $canact,
            'filters' => [
                'campaigns' => array_values(array_map(fn($k) => ['name' => $campaigns[$k]['name'],
                    'url' => $url(['campaign' => $k, 'course' => ''])->out(false), 'current' => $k === $campaign['key']], $keys)),
                'courses' => array_merge(
                    [['name' => get_string('panel_all_courses', $c), 'key' => '',
                    'url' => $url(['course' => ''])->out(false), 'current' => $course === '']],
                    array_values(array_map(
                        fn($k, $name) => ['name' => $name, 'key' => $k, 'url' => $url(['course' => $k])->out(false),
                        'current' => $k === $course],
                        array_keys($courses),
                        $courses
                    ))
                ),
                'compare' => [['name' => get_string('panel_compare_with', $c), 'url' => $url(['compare' => 1])->out(false),
                    'current' => (bool) ($view['compare'] ?? 1)], ['name' => get_string('panel_compare_without', $c),
                    'url' => $url(['compare' => 0])->out(false), 'current' => !($view['compare'] ?? 1)]],
            ],
            'response' => self::response($centre, $campaign),
            'reach' => $mine ? self::reach($mine, $before, $compare, $elapsed, (int) ($previous['start'] ?? 0)) : null,
            'channels' => $mine ? self::channels($centre, $campaign, $mine, $voice, $url) : null,
            'weeks' => $mine ? self::weeks(
                $centre,
                $campaign,
                $mine,
                $before,
                $compare,
                $show,
                $end,
                (int) ($previous['start'] ?? 0),
                $url,
                $now,
                !empty($view['whole'])
            ) : null,
            // The places of this year only for the campaign under way: an imported one is about another year.
            'places' => $campaign['import'] ? null : self::places($centre, $families, $course, $previous, $campaign, $elapsed),
            'hasplaces' => !$campaign['import'],
            'placesdef' => get_string('panel_coming_def', $c),
            'started' => $campaign['import'] || $course !== '' ? false : self::started((int) $centre->id),
            'starts' => self::starts($campaigns, $canact),
        ];
        $context['spendaction'] = $url([])->out(false);
        $year = $course === '' ? self::year($centre) : null;
        $history = $course === '' ? self::history($centre) : null;
        // A chart without its partner in the row takes the whole row.
        foreach ([['reach', 'response'], ['channels', 'weeks'], ['weeks', 'channels']] as [$chart, $partner]) {
            if ($context[$chart]) {
                $context[$chart]['ancha'] = !$context[$partner];
            }
        }
        if ($year) {
            $year['ancha'] = !$history;
        }
        if ($history) {
            $history['ancha'] = !$year;
        }
        return $context + ['hasyear' => (bool) $year, 'year' => $year ?: false, 'hashistory' => (bool) $history,
            'history' => $history ?: false];
    }

    /**
     * The address of the page with a view.
     *
     * @param \stdClass $centre
     * @param array $view
     * @return \moodle_url
     */
    public static function url(\stdClass $centre, array $view): \moodle_url {
        $params = ['c' => $centre->id];
        foreach (['campaign' => 'campana', 'course' => 'curso', 'voice' => 'voz', 'show' => 'vista'] as $key => $name) {
            if (
                !empty($view[$key]) && !($key === 'campaign' && $view[$key] === 'live') && !($key === 'voice' &&
                    $view[$key] === 'first') && !($key === 'show' && $view[$key] === 'llegadas')
            ) {
                $params[$name] = $view[$key];
            }
        }
        if (isset($view['compare']) && !$view['compare']) {
            $params['comparar'] = 0;
        }
        if (!empty($view['whole'])) {
            $params['entera'] = 1;
        }
        if (!empty($view['ensenar'])) {
            $params['ensenar'] = 1;
        }
        return new \moodle_url('/local/oksigeniaeguahe/metrics.php', $params);
    }

    /**
     * The courses the families of a campaign asked for, by their plain name.
     *
     * @param array $families
     * @return array plain name => name
     */
    protected static function courses(array $families): array {
        $out = [];
        foreach ($families as $f) {
            if ($f['course'] !== '') {
                $out[$f['course']] = $f['coursename'];
            }
        }
        asort($out);
        return $out;
    }

    /**
     * How fast families get a first answer: the median, in words, with those answered and those waiting.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @return array|null
     */
    protected static function response(\stdClass $centre, array $campaign): ?array {
        if ($campaign['import']) {
            return null;
        }
        $c = self::C;
        $r = metrics::response((int) $centre->id);
        if ($r['median'] === null) {
            return ['hero' => '', 'sentence' => get_string('panel_response_none', $c), 'facts' => $r['waiting'] ?
                [['n' => $r['waiting'], 'text' => get_string('panel_response_waiting', $c)]] : [],
                'def' => get_string('panel_response_def', $c)];
        }
        $hours = (float) $r['median'];
        $hero = $hours < 1 ? get_string('panel_resp_minutes', $c, max(1, (int) round($hours * 60))) : ($hours < 48 ?
            get_string((int) round($hours) === 1 ? 'panel_resp_hour' : 'panel_resp_hours', $c, (int) round($hours)) :
            get_string('panel_resp_days', $c, (int) round($hours / 24)));
        return [
            'hero' => $hero,
            'sentence' => get_string('panel_response_sentence', $c, $hero),
            'facts' => [['n' => $r['answered'], 'text' => get_string('panel_response_answered', $c)],
                ['n' => $r['waiting'], 'text' => get_string('panel_response_waiting', $c)]],
            'def' => get_string('panel_response_def', $c),
        ];
    }

    /**
     * How far families got: each at the furthest stop of its children; the campaign before at the same week behind;
     * and the part of each channel inside each bar.
     *
     * @param array $mine
     * @param array $before
     * @param bool $compare
     * @param int $elapsed
     * @param int $prevstart
     * @return array
     */
    protected static function reach(array $mine, array $before, bool $compare, int $elapsed, int $prevstart): array {
        $c = self::C;
        $open = array_values(array_filter($mine, fn($f) => !$f['closed']));
        $closed = count($mine) - count($open);
        $n = [];
        foreach (self::STEPS as $k => $step) {
            $n[$k] = count(array_filter($open, fn($f) => $f['step'] >= $k + 1));
        }
        // The one before as it was at the same week: who had come by then, how far they went, and the enrolled by then.
        $g = [];
        $by = $prevstart + $elapsed;
        $then = array_values(array_filter($before, fn($f) => $f['first'] <= $by));
        foreach (self::STEPS as $k => $step) {
            $g[$k] = count(array_filter($then, fn($f) => $k < 4 ? $f['step'] >= $k + 1 : ($f['step'] >= 5 && $f['enrolledat'] &&
                $f['enrolledat'] <= $by)));
        }
        $top = max(1, $n[0], $compare ? $g[0] : 0);
        $channels = self::top(array_count_values(array_column(array_column($open, 'voices'), 'first')));
        $steps = [];
        foreach (self::STEPS as $k => $step) {
            $parts = [];
            foreach ($channels as $name => $key) {
                $part = count(array_filter($open, fn($f) => self::bucket($f['voices']['first'], $channels) === $name &&
                    $f['step'] >= $k + 1));
                $from = count(array_filter($open, fn($f) => self::bucket($f['voices']['first'], $channels) === $name));
                if ($part) {
                    $parts[] = ['canal' => $key, 'v' => round($part / $top, 4), 'n' => $part,
                        'line' => $k ? get_string('panel_far_part', $c, (object) ['from' => $from, 'name' => $name, 'n' => $part,
                            'what' => get_string('panel_far_did_' . $step, $c), 'frac' => self::fraction($part, $from)]) :
                            get_string($part === 1 ? 'panel_far_part_first_one' : 'panel_far_part_first', $c, (object) [
                            'n' => $part, 'name' => $name])];
                }
            }
            $line = $k ? get_string('panel_far_step', $c, (object) ['n' => $n[$k], 'total' => $n[0],
                'what' => get_string('panel_far_have_' . $step, $c), 'frac' => self::fraction($n[$k], $n[0])]) :
                get_string($n[0] === 1 ? 'panel_far_first_one' : 'panel_far_first', $c, $n[0]);
            $steps[] = ['key' => $step, 'name' => get_string('panel_far_name_' . $step, $c), 'n' => $n[$k],
                'v' => round($n[$k] / $top, 4), 'g' => round($g[$k] / $top, 4), 'gn' => $g[$k], 'hasghost' => $compare,
                'line' => $line . ($compare ? ' ' . get_string('panel_far_ghost', $c, $g[$k]) : ''), 'parts' => $parts];
        }
        return [
            'compare' => $compare,
            'legend' => $compare,
            'rest' => get_string(
                ($n[4] ? 'panel_far_rest' : 'panel_far_rest_none') . ($n[0] === 1 ? '_one' : ''),
                $c,
                (object) ['n' => $n[0],
                'enrolled' => $n[4],
                'frac' => self::fraction(
                    $n[4],
                    $n[0]
                )]
            ),
            'closed' => $closed ? get_string($closed === 1 ? 'panel_far_closed_one' : 'panel_far_closed', $c, $closed) : '',
            'def' => get_string($compare ? 'panel_far_def' : 'panel_far_def_alone', $c),
            'steps' => $steps,
        ];
    }

    /**
     * Where families came from, said one of three ways, with the enrolled and the cost of each new student in words.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array $mine
     * @param string $voice
     * @param callable $url
     * @return array
     */
    protected static function channels(\stdClass $centre, array $campaign, array $mine, string $voice, callable $url): array {
        $c = self::C;
        $open = array_values(array_filter($mine, fn($f) => !$f['closed']));
        $said = $voice === 'said';
        $names = array_filter(array_column(array_column($open, 'voices'), $voice), fn($v) => $v !== '');
        $channels = self::top(array_count_values($names));
        $spend = self::spend($centre, $campaign);
        $rows = [];
        $most = 0;
        foreach ($channels as $name => $key) {
            $here = array_values(array_filter($open, fn($f) => self::bucket($f['voices'][$voice], $channels) === $name));
            $rows[$name] = ['n' => count($here), 'enrolled' => count(array_filter($here, fn($f) => $f['step'] >= 5)),
                'key' => $key];
            $most = max($most, count($here));
        }
        $out = [];
        $unnoted = 0;
        foreach ($rows as $name => $row) {
            $amount = $said ? null : ($spend[$name] ?? null);
            $unnoted += !$said && $amount === null ? 1 : 0;
            $cost = $amount === null ? get_string('panel_cost_none', $c) : ($amount == 0 ? get_string('panel_cost_free', $c) :
                ($row['enrolled'] ? get_string('panel_cost_per', $c, format_float($amount / $row['enrolled'], 0, true, true)) :
                get_string('panel_cost_nobody', $c, format_float($amount, 0, true, true))));
            $line = $said ? get_string('panel_said_line', $c, (object) ['n' => $row['n'], 'name' => $name]) :
                ($voice === 'last' ? get_string('panel_last_line', $c, (object) ['n' => $row['n'], 'name' => $name]) :
                get_string('panel_first_line', $c, (object) ['n' => $row['n'], 'name' => $name, 'enrolled' => $row['enrolled'],
                'frac' => self::fraction($row['enrolled'], $row['n']), 'cost' => $cost]));
            // Inside each bar, from its base, the families of the channel that were enrolled; never for what they said.
            $en = $said ? 0 : $row['enrolled'];
            $out[] = ['key' => $row['key'], 'name' => $name, 'n' => $row['n'], 'v' => round($row['n'] / max(1, $most), 4),
                'en' => $en, 'ev' => $en ? round($en / max(1, $most), 4) : false,
                'declared' => $said, 'hasparts' => $voice === 'first',
                'enrolled' => get_string($row['enrolled'] === 1 ? 'panel_enrolled_one' : 'panel_enrolled_n', $c, $row['enrolled']),
                'cost' => $cost, 'line' => $line];
        }
        $told = count($names);
        $top = $out[0] ?? null;
        $rest = !$top ? get_string('panel_channels_none', $c) : ($said ? get_string('panel_said_rest', $c, (object) [
            'name' => $top['name'], 'n' => $top['n'], 'told' => $told]) : get_string($voice === 'last' ? 'panel_last_rest' :
            'panel_first_rest', $c, (object) ['name' => $top['name'], 'n' => $top['n'], 'total' => count($open)]));
        $spendrows = [];
        if (!$said) {
            foreach (self::spend_keys($centre, $campaign, $mine) as $key => $name) {
                $amount = $spend[$name] ?? null;
                $spendrows[] = ['key' => $key, 'name' => $name, 'value' => $amount === null ? '' : format_float(
                    $amount,
                    2,
                    true,
                    true
                )];
            }
        }
        return [
            'voices' => array_map(fn($v) => ['key' => $v, 'name' => get_string('panel_voice_' . $v, $c),
                'def' => get_string('panel_voice_' . $v . '_def', $c), 'url' => $url(['voice' => $v])->out(false),
                'current' => $v === $voice], self::VOICES),
            'def' => $said ? get_string('panel_voice_said_def', $c, (object) ['told' => $told, 'total' => count($open)]) :
                get_string('panel_voice_' . $voice . '_def', $c),
            'rest' => $rest,
            'foot' => $said ? get_string('panel_said_foot', $c, count($open) - $told) : ($unnoted ? get_string($unnoted === 1 ?
                'panel_spend_missing_one' : 'panel_spend_missing', $c, $unnoted) : ''),
            'declared' => $said,
            'rows' => $out,
            'hasenrolled' => !$said && (bool) array_filter(array_column($out, 'en')),
            'spend' => $spendrows,
            'canspend' => (bool) $spendrows,
        ];
    }

    /**
     * The most common names, each with a key for the page; the rest go together.
     *
     * @param array $counts name => families
     * @return array name => key
     */
    protected static function top(array $counts): array {
        arsort($counts);
        $out = [];
        foreach (array_slice(array_keys($counts), 0, self::CHANNELS) as $name) {
            $out[(string) $name] = 'k' . substr(md5((string) $name), 0, 8);
        }
        if (count($counts) > self::CHANNELS) {
            $out[get_string('panel_channel_rest', self::C)] = 'otros';
        }
        return $out;
    }

    /**
     * The row a name falls in: its own, or the rest.
     *
     * @param string $name
     * @param array $channels As top() gives them.
     * @return string
     */
    protected static function bucket(string $name, array $channels): string {
        return isset($channels[$name]) ? $name : get_string('panel_channel_rest', self::C);
    }

    /**
     * What each first channel of a campaign cost, by its name.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @return array name => amount
     */
    protected static function spend(\stdClass $centre, array $campaign): array {
        $label = $campaign['import'] ? (string) $campaign['import']->label : self::LIVE;
        $out = [];
        foreach (metrics::spend((int) $centre->id, $label) as $key => $amount) {
            [$channel, $source] = array_pad(explode('|', $key, 2), 2, '');
            if ($campaign['import']) {
                // An imported campaign keeps its spend by channel or by campaign of a channel.
                $out[$source !== '' ? $source : get_string('channel_' . (in_array($channel, lists::CHANNELS, true) ? $channel :
                    'other'), self::C)] = $amount;
            } else if ($channel === 'row') {
                $out[$source] = $amount;
            }
        }
        return $out;
    }

    /**
     * The first channels of a campaign whose cost can be noted, by the key of their field.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array $families
     * @return array key => name
     */
    public static function spend_keys(\stdClass $centre, array $campaign, array $families): array {
        $names = array_unique(array_filter(array_column(array_column($families, 'voices'), 'first')));
        sort($names);
        $out = [];
        foreach ($names as $name) {
            $out['k' . substr(md5((string) $name), 0, 8)] = (string) $name;
        }
        return $out;
    }

    /**
     * Notes what each first channel of a campaign cost, from what was posted: empty removes it, 0 says it cost nothing.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array $amounts key => amount as written
     * @param int $userid
     */
    public static function save_spend(\stdClass $centre, array $campaign, array $amounts, int $userid): void {
        $keys = self::spend_keys($centre, $campaign, self::families($centre, $campaign));
        $label = $campaign['import'] ? (string) $campaign['import']->label : self::LIVE;
        foreach ($amounts as $key => $written) {
            if (!isset($keys[$key])) {
                continue;
            }
            $value = trim((string) $written) === '' ? null : unformat_float((string) $written, true);
            $amount = $value === null || $value === false ? null : max(0, (float) $value);
            metrics::set_spend((int) $centre->id, $label, 'row', \core_text::substr($keys[$key], 0, 255), $amount, $userid);
        }
    }

    /**
     * The campaign week by week: arrivals or enrolments added up since its start, the one before behind, and the events
     * of each week. Drawn in a box of 100 that stretches; the labels go in percent.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array $mine
     * @param array $before
     * @param bool $compare
     * @param string $show llegadas or matriculadas.
     * @param int $end The last day of the campaign that counts: today for the one under way.
     * @param int $prevstart
     * @param callable $url
     * @param int $now
     * @param bool $whole Whether to draw the whole campaign before; else both up to today, on a scale that shows them.
     * @return array|null
     */
    protected static function weeks(
        \stdClass $centre,
        array $campaign,
        array $mine,
        array $before,
        bool $compare,
        string $show,
        int $end,
        int $prevstart,
        callable $url,
        int $now,
        bool $whole = false
    ): ?array {
        $c = self::C;
        $week = WEEKSECS;
        $field = $show === 'matriculadas' ? 'enrolledat' : 'first';
        $start = $campaign['start'] ?: min(array_column($mine, 'first'));
        $times = array_filter(array_column($mine, $field));
        if (!$start) {
            return null;
        }
        $current = max(1, (int) ceil(max(1, $end - $start) / $week));
        $prevtimes = array_filter(array_column($before, $field));
        $prevweeks = $compare && $prevtimes ? max(1, (int) ceil((max($prevtimes) - $prevstart) / $week)) : 0;
        $total = $whole ? max(4, $current, $prevweeks) : max(4, $current);
        $sum = fn(array $list, int $from, int $w) => count(array_filter($list, fn($t) => $t >= $from - 30 * DAYSECS &&
            $t < $from + $w * $week));
        // The running count of each week so far; $now stays the time of now.
        $tally = [];
        for ($w = 1; $w <= $current; $w++) {
            $tally[$w] = $sum($times, $start, $w);
        }
        $prev = [];
        for ($w = 1; $compare && $w <= $total; $w++) {
            $prev[$w] = $sum($prevtimes, $prevstart, $w);
        }
        $top = max(1, max($tally ?: [0]), max($prev ?: [0]));
        $step = self::nice($top / 3);
        $ceil = (int) ceil($top / $step) * $step;
        $x = fn(int $w) => round(100 * ($w - 0.5) / $total, 1);
        $y = fn(int $v) => round(100 - 100 * $v / $ceil, 1);
        $path = fn(array $values) => implode(' ', array_map(fn($w, $v) => ($w === array_key_first($values) ? 'M' : 'L') .
            $x($w) . ' ' . $y($v), array_keys($values), $values));
        $what = get_string($show === 'matriculadas' ? 'panel_weeks_what_enrolled' : 'panel_weeks_what_arrived', $c);
        $cols = [];
        $table = [];
        for ($w = 1; $w <= $total; $w++) {
            $from = $start + ($w - 1) * $week;
            $range = get_string('panel_week_range', $c, (object) ['from' => userdate($from, get_string(
                'strftimedateshort',
                'langconfig'
            )), 'to' => userdate($from + 6 * DAYSECS, get_string('strftimedateshort', 'langconfig'))]);
            $has = isset($tally[$w]);
            $added = $has ? $tally[$w] - ($tally[$w - 1] ?? 0) : 0;
            $line = get_string('panel_week_label', $c, $w) . ', ' . $range . ': ' . ($has ? get_string(
                'panel_week_line',
                $c,
                (object) ['added' => $added, 'n' => $tally[$w], 'what' => $what]
            ) : get_string('panel_week_future', $c)) .
                ($compare ? ' ' . get_string('panel_week_before', $c, $prev[$w] ?? 0) : '');
            $cols[] = ['left' => round(100 * ($w - 1) / $total, 2), 'width' => round(100 / $total, 2), 'cx' => $x($w),
                'line' => $line, 'future' => !$has, 'added' => max(0, $added), 'av' => 0];
            $table[] = ['week' => get_string('panel_week_label', $c, $w), 'now' => $has ? $tally[$w] : '–',
                'before' => $prev[$w] ?? '–'];
        }
        // The pulse of each week: what came in it, on its own scale.
        $most = max(array_column($cols, 'added') ?: [0]);
        foreach ($cols as $i => $col) {
            $cols[$i]['av'] = $most ? round($col['added'] / $most, 4) : 0;
        }
        $events = [];
        foreach (events::for_centre((int) $centre->id) as $event) {
            $at = (int) $event->timestart;
            if ($event->kind === 'visit' || $at < $start || $at >= $start + $total * $week) {
                continue;
            }
            $name = $event->kind === 'openday' ? get_string('panel_week_openday', $c) : format_string($event->name);
            $name = \core_text::strlen($name) > 18 ? \core_text::substr($name, 0, 17) . '…' : $name;
            $events[] = ['p' => round(100 * ($at - $start) / ($total * $week), 1), 'ahead' => $at > $now,
                'label' => $name . ' · ' . userdate($at, get_string('strftimedateshort', 'langconfig'))];
        }
        // Every event keeps its mark; its name only where it does not fall on the one before.
        usort($events, fn($a, $b) => $a['p'] <=> $b['p']);
        $named = -100;
        foreach ($events as $i => $event) {
            if ($event['p'] - $named < 16) {
                $events[$i]['label'] = '';
            } else {
                $named = $event['p'];
            }
        }
        $last = $tally[$current];
        $ticks = [];
        foreach (array_unique([1, (int) ceil($total / 2), $total]) as $w) {
            $ticks[] = ['p' => $x($w), 'label' => get_string('panel_week_label', $c, $w), 'start' => $w === 1,
                'end' => $w === $total];
        }
        $grid = [];
        for ($v = 0; $v <= $ceil; $v += $step) {
            $grid[] = ['p' => $y($v), 'label' => $v];
        }
        return [
            'wholelink' => $compare && $prevweeks > $current ? ['url' => $url(['whole' => !$whole])->out(false),
                'name' => get_string($whole ? 'panel_weeks_to_today' : 'panel_weeks_whole', $c)] : false,
            'views' => array_map(fn($k) => ['name' => get_string('panel_weeks_' . $k, $c),
                'url' => $url(['show' => $k])->out(false),
                'current' => $k === $show], ['llegadas', 'matriculadas']),
            'compare' => $compare,
            'grid' => $grid,
            'xticks' => $ticks,
            'events' => $events,
            'now' => $path($tally),
            'before' => $compare ? $path($prev) : '',
            'end' => ['left' => $x($current), 'top' => $y($last), 'label' => get_string('panel_weeks_end', $c, $last),
                'flip' => $x($current) > 70],
            'beforeend' => $compare ? ['left' => $x($total), 'top' => $y(end($prev) ?: 0),
                'label' => get_string('panel_weeks_before_end', $c)] : false,
            'cols' => $cols,
            'hasadded' => $most > 0,
            'rest' => get_string($last === 1 ? 'panel_weeks_rest_one' : 'panel_weeks_rest', $c, (object) ['n' => $last,
                'what' => $what, 'weeks' => get_string($current === 1 ? 'panel_weeks_one' : 'panel_weeks_n', $c, $current)]) .
                ($compare ? ' ' . get_string('panel_week_before', $c, $prev[$current] ?? 0) : ''),
            'def' => get_string($campaign['start'] ? 'panel_weeks_def' : 'panel_weeks_def_nostart', $c),
            'label' => get_string($show === 'matriculadas' ? 'panel_weeks_label_enrolled' : 'panel_weeks_label', $c),
            'what' => $what,
            'table' => $table,
        ];
    }

    /**
     * A round step for the lines of a scale: 1, 2, 5, 10, 20, 50…
     *
     * @param float $raw
     * @return int
     */
    protected static function nice(float $raw): int {
        $base = 10 ** floor(log10(max(1, $raw)));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $base >= $raw) {
                return (int) ($m * $base);
            }
        }
        return (int) (10 * $base);
    }

    /**
     * The places of each course of the offer under way: the rail with the enrolled and, under it, what may still come,
     * from how the families of the campaign before went on from this same week; and those waiting on the bench.
     *
     * @param \stdClass $centre
     * @param array $families Of the campaign shown.
     * @param string $course
     * @param array|null $previous
     * @param array $campaign
     * @param int $elapsed
     * @return array
     */
    protected static function places(
        \stdClass $centre,
        array $families,
        string $course,
        ?array $previous,
        array $campaign,
        int $elapsed
    ): array {
        global $DB;
        $c = self::C;
        $offers = centres::offers((int) $centre->id, true);
        if (!$offers) {
            return [];
        }
        $year = (string) reset($offers)->schoolyear;
        $counts = households::offer_counts((int) $centre->id);
        // How the families of the campaign before that were still on their way at this week ended.
        $rate = null;
        if (!$campaign['import'] && $previous && $previous['start'] && $campaign['start']) {
            $by = $previous['start'] + $elapsed;
            $rate = [];
            foreach (self::families($centre, $previous) as $f) {
                if ($f['first'] <= $by && !($f['enrolledat'] && $f['enrolledat'] <= $by)) {
                    foreach (['', $f['course']] as $key) {
                        $rate[$key]['n'] = ($rate[$key]['n'] ?? 0) + 1;
                        $rate[$key]['k'] = ($rate[$key]['k'] ?? 0) + ($f['step'] >= 5 ? 1 : 0);
                    }
                }
            }
        }
        $out = [];
        foreach ($offers as $offer) {
            $key = recognise::plain((string) $offer->name);
            if ((string) $offer->schoolyear !== $year || ($course !== '' && $key !== $course)) {
                continue;
            }
            $places = (int) $offer->places;
            $taken = (int) ($counts[$offer->id]['enrolled'] ?? 0);
            $waiting = (int) ($counts[$offer->id]['waitlist'] ?? 0);
            $active = 0;
            foreach (['inquiry', 'visit', 'application', 'admitted'] as $stage) {
                $active += (int) ($counts[$offer->id][$stage] ?? 0);
            }
            $r = $rate ? ($rate[$key] ?? null) ?? ($rate[''] ?? null) : null;
            $rows = [];
            if ($r && $r['n'] && $active) {
                [$low, $high] = self::wilson($r['k'], $r['n']);
                $rows[] = ['low' => $taken + (int) floor($active * $low),
                    'mid' => $taken + (int) round($active * $r['k'] / $r['n']),
                    'high' => $taken + (int) ceil($active * $high), 'text' => ''];
                $rows[0]['text'] = get_string('panel_range', $c, (object) ['low' => $rows[0]['low'], 'high' => $rows[0]['high']]);
            }
            $free = max(0, $places - $taken);
            if (!$places) {
                $sentence = get_string('panel_places_none_set', $c);
            } else if (!$rows) {
                $sentence = get_string($free === 1 ? 'panel_places_free_one' : 'panel_places_free', $c, $free);
            } else if ($rows[0]['low'] > $places) {
                $sentence = get_string('panel_places_over', $c, (object) ['low' => $rows[0]['low'] - $places,
                    'high' => $rows[0]['high'] - $places]);
            } else if ($rows[0]['high'] > $places) {
                $sentence = get_string('panel_places_could_fill', $c, (object) ['low' => $rows[0]['low'],
                    'high' => $rows[0]['high'], 'places' => $places]);
            } else {
                $sentence = get_string($places > 40 ? 'panel_places_left_seats' : 'panel_places_left_pegs', $c, (object) [
                    'low' => $places - $rows[0]['high'], 'high' => $places - $rows[0]['low']]);
            }
            $out[] = ['key' => 'o' . $offer->id, 'name' => format_string($offer->name),
                'free' => $places ? get_string('panel_places_free_of', $c, (object) ['free' => $free, 'places' => $places]) : '',
                'sentence' => $sentence,
                'rail' => $places ? panel::rail(
                    $places,
                    $taken,
                    $rows,
                    get_string('panel_places_rail', $c, (object) ['name' => format_string($offer->name), 'taken' => $taken,
                        'places' => $places, 'sentence' => $sentence]),
                    panel::hanging_object((string) $offer->name)
                ) : '',
                'bench' => $waiting ? ['n' => $waiting, 'bags' => array_fill(0, min(self::BENCH, $waiting), 1),
                    'text' => get_string($waiting === 1 ? 'panel_bench_one' : 'panel_bench', $c, $waiting)] : false,
                // The numbers of the rail, to point at it peg by peg. The flat rail of the desk in every skin: it reads
                // as bars and keeps the range (Gaudí, 8 November 2026); the cloakroom of the hall stays for the inbox.
                'taken' => $taken, 'places' => $places, 'low' => $rows ? $rows[0]['low'] : false,
                'mid' => $rows ? $rows[0]['mid'] : false, 'high' => $rows ? $rows[0]['high'] : false];
        }
        return $out;
    }

    /**
     * The range a share is likely in, from how many of how many: 80 % of the times it falls inside (Wilson).
     *
     * @param int $k
     * @param int $n
     * @return float[] [low, high]
     */
    public static function wilson(int $k, int $n): array {
        if (!$n) {
            return [0.0, 1.0];
        }
        $z = self::Z;
        $p = $k / $n;
        $centre = ($p + $z * $z / (2 * $n)) / (1 + $z * $z / $n);
        $half = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / (1 + $z * $z / $n);
        return [max(0.0, $centre - $half), min(1.0, $centre + $half)];
    }

    /**
     * Of the children enrolled for the school year, how many started on the first day, once the team marked anyone:
     * the first honest number of what comes after the campaign.
     *
     * @param int $centreid
     * @return array|false text, def, url.
     */
    protected static function started(int $centreid) {
        $year = settled::year($centreid);
        $counts = $year !== '' ? settled::started($centreid, $year) : [];
        if (!$counts) {
            return false;
        }
        return [
            'text' => get_string('panel_started_sentence', self::C, (object) ($counts + ['year' => $year])),
            'def' => get_string('panel_started_def', self::C),
            'url' => (new \moodle_url('/local/oksigeniaeguahe/start.php', ['c' => $centreid]))->out(false),
        ];
    }

    /**
     * The last school year as a ledger: it started with, some were new, some came during it, some left, it ended with.
     * Drawn only when the sum adds up.
     *
     * @param \stdClass $centre
     * @return array|null
     */
    protected static function year(\stdClass $centre): ?array {
        $c = self::C;
        $years = metrics::school_years((int) $centre->id);
        if (!$years) {
            return null;
        }
        $schoolyear = (string) reset($years);
        $m = metrics::movements((int) $centre->id, $schoolyear);
        $t = $m['totals'];
        if ($t['start'] === null || $t['now'] === null || $t['start'] + $t['new'] + $t['joins'] - $t['leaves'] !== $t['now']) {
            return null;
        }
        // One scale for all: the zero of the track is at 30 %, so those who left fit in the 30 % on its left.
        $most = max(1, $t['new'], $t['joins'], $t['leaves'] * 70 / 30);
        $rows = [];
        foreach (['new' => false, 'joins' => false, 'leaves' => true] as $key => $out) {
            $n = (int) $t[$key];
            $rows[] = ['key' => $key, 'sign' => ($out ? '−' : '+') . $n, 'name' => get_string('panel_ledger_' . $key, $c),
                'n' => $n, 'v' => round($n / $most, 4), 'out' => $out, 'line' => get_string('panel_ledger_line_' . $key, $c, $n)];
        }
        $net = (int) $t['net'];
        return [
            'title' => get_string('panel_ledger_title', $c, $schoolyear),
            'start' => $t['start'],
            'end' => $t['now'],
            'net' => ($net >= 0 ? '+' : '−') . abs($net),
            'rows' => $rows,
            'reasons' => array_map(fn($r) => ['name' => $r['reason'], 'n' => $r['n'] ?? $r['count'] ?? 0], $m['reasons']),
            'rest' => get_string('panel_ledger_rest', $c, (object) ['start' => $t['start'], 'end' => $t['now'],
                'new' => $t['new'], 'joins' => $t['joins'], 'leaves' => $t['leaves'], 'net' => abs($net),
                'more' => get_string($net >= 0 ? 'panel_ledger_more' : 'panel_ledger_fewer', $c)]),
            'def' => get_string('panel_ledger_def', $c),
        ];
    }

    /**
     * The roll at the start of each school year, from zero.
     *
     * @param \stdClass $centre
     * @return array|null
     */
    protected static function history(\stdClass $centre): ?array {
        $c = self::C;
        [$years, , , $totals] = metrics::history((int) $centre->id);
        if (!$years) {
            return null;
        }
        // Room above the tallest column for its number.
        $most = max(1, ...array_values($totals)) * 1.12;
        $rows = [];
        $last = null;
        foreach (array_values($years) as $i => $year) {
            $n = (int) $totals[$year];
            $diff = $last === null ? '' : ' ' . get_string(
                $n >= $last ? 'panel_years_more' : 'panel_years_fewer',
                $c,
                abs($n - $last)
            );
            // The change from the year before, written in pencil under each year: «+35», «−139».
            $delta = $last === null ? '' : ($n >= $last ? '+' : '−') . format_float(abs($n - $last), 0, true, true);
            $rows[] = ['year' => $year, 'n' => $n, 'v' => round($n / $most, 4), 'last' => $i === count($years) - 1,
                'delta' => $delta, 'line' => get_string('panel_years_line', $c, (object) ['year' => $year, 'n' => $n]) . $diff];
            $last = $n;
        }
        $first = reset($rows);
        $final = end($rows);
        return [
            'rows' => $rows,
            'rest' => count($rows) < 2 ? $final['line'] : get_string($first['n'] === $final['n'] ? 'panel_years_rest_same' :
                'panel_years_rest', $c, (object) ['years' => count($rows), 'from' => format_float($first['n'], 0, true, true),
                'to' => format_float($final['n'], 0, true, true)]),
            'def' => get_string('panel_years_def', $c),
        ];
    }

    /**
     * When each campaign started, to write: without both dates there is nothing to compare.
     *
     * @param array $campaigns
     * @param bool $canact
     * @return array|null
     */
    protected static function starts(array $campaigns, bool $canact): ?array {
        if (!$canact) {
            return null;
        }
        $rows = [];
        foreach ($campaigns as $key => $campaign) {
            $rows[] = ['key' => $key, 'name' => $campaign['name'],
                'value' => $campaign['start'] ? date('Y-m-d', $campaign['start']) : ''];
        }
        return ['rows' => $rows];
    }

    /**
     * Keeps when each campaign started, from what was posted.
     *
     * @param \stdClass $centre
     * @param array $dates key => Y-m-d
     */
    public static function save_starts(\stdClass $centre, array $dates): void {
        global $DB;
        foreach (self::campaigns($centre) as $key => $campaign) {
            if (!array_key_exists($key, $dates)) {
                continue;
            }
            $day = trim((string) $dates[$key]);
            $time = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? panel_eventform::stamp($day, '00:00') : 0;
            if ($campaign['import']) {
                $DB->set_field(imports::T, 'campaignstart', $time, ['id' => $campaign['import']->id, 'centreid' => $centre->id]);
            } else {
                $DB->set_field(centres::T, 'campaignstart', $time, ['id' => $centre->id]);
            }
        }
    }
}
