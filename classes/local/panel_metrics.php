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

    /** @var string[] The stops of the way, in order, each with the fact that says it. Waiting in the list is a stop apart: it is
     * not having a place. */
    const STOPS = ['inquiry' => '', 'visit' => 'came', 'application' => 'applied', 'waitlist' => 'waitlist', 'admitted' => 'place',
        'enrolled' => 'enrolled'];

    /** @var string[] Where families came from, said three ways. */
    const VOICES = ['channel', 'first', 'last', 'said', 'measured', 'campaign'];

    /** @var int The channels drawn one by one; the rest go together. */
    const CHANNELS = 7;

    /** @var int The backpacks drawn on the bench, at most. */
    const BENCH = 12;

    /** @var string The label of the spend of the campaign under way. */
    const LIVE = 'eguahe';

    /** @var string[] The sheets of the page: the campaign, the school year and the families. */
    const SHEETS = ['campana', 'curso', 'familias'];

    /** @var float How wide the range of what may come is: 80 % of the times, it falls inside. */
    const Z = 1.2816;

    /**
     * The campaigns of a centre, by the school year each fills: the one under way and those before it, the newest first. Never
     * a file: what was imported of a year is part of its campaign.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array key => [key, name, year, start, since, until, import]
     */
    public static function campaigns(\stdClass $centre, int $now = 0): array {
        return facts::campaigns($centre, $now);
    }

    /**
     * The campaign another one is set against: the one before it, when it is known when both started.
     *
     * @param array $campaigns
     * @param array $campaign
     * @return array|null
     */
    public static function before(array $campaigns, array $campaign): ?array {
        $keys = array_keys($campaigns);
        $other = $campaigns[$keys[array_search($campaign['key'], $keys, true) + 1] ?? ''] ?? null;
        return $other && $other['start'] > 0 && (!$campaign['start'] || $other['start'] < $campaign['start'] - 90 * DAYSECS) ? $other : null;
    }

    /**
     * The families of a campaign that it attracted, also those that went, each with its facts (facts::all()).
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param int $now
     * @return array
     */
    public static function families(\stdClass $centre, array $campaign, int $now = 0): array {
        return facts::families($centre, $campaign, $now);
    }

    /**
     * Every family of a campaign, by what its totals do with it: campaign (they count it), centre (already of the centre) or
     * other (it came for another school year).
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param int $now
     * @return array[] campaign, centre and other.
     */
    public static function all_families(\stdClass $centre, array $campaign, int $now = 0): array {
        $all = facts::all($centre, $campaign, $now);
        return ['campaign' => facts::group($all, 'campaign'), 'centre' => facts::group($all, 'centre'),
            'other' => facts::group($all, 'other')];
    }

    /**
     * The families of the campaign under way, with those already of the centre (a child in its nursery, or children
     * inside): only to set them against the new ones, never in the other numbers of the campaign.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array
     */
    public static function everyone(\stdClass $centre, int $now = 0): array {
        $all = facts::all($centre, facts::live($centre, $now), $now);
        return array_values(array_filter($all, fn($f) => in_array($f['group'], ['campaign', 'centre'], true)));
    }

    /**
     * What the families measured as arriving from an AI assistant said, each answer with how many said it, the most
     * said first and those who said nothing at the end: people mistake where they heard of a centre.
     *
     * @param array $open The families of the campaign, still open.
     * @return array Of name, n and untold.
     */
    protected static function ai_said(array $open): array {
        $c = self::C;
        $group = get_string('arrival_ai_group', $c);
        $untold = get_string('panel_measured_ai_untold', $c);
        $counts = [];
        foreach ($open as $f) {
            if (($f['voices']['measured'] ?? '') !== $group) {
                continue;
            }
            $said = trim((string) ($f['voices']['said'] ?? ''));
            $said = $said !== '' ? $said : $untold;
            $counts[$said] = ($counts[$said] ?? 0) + 1;
        }
        uksort($counts, fn($a, $b) => [$a === $untold, -$counts[$a], $a] <=> [$b === $untold, -$counts[$b], $b]);
        $out = [];
        foreach ($counts as $said => $n) {
            $out[] = ['name' => $said, 'n' => $n, 'untold' => $said === $untold];
        }
        return $out;
    }

    /** @var float How near a share has to be to a fraction to be said as one: «one of every four» is never 28 of 100. */
    const NEAR = 0.01;

    /**
     * What a part of a whole is, after having said both numbers: «: una de cada cuatro» when it is very nearly that, «: todas»,
     * «: ninguna» or « (0,9 %)»; nothing with fewer than ten behind, where only the numbers are said.
     *
     * @param int $part
     * @param int $whole
     * @param bool $aside In brackets, inside a list.
     * @return string
     */
    public static function share(int $part, int $whole, bool $aside = false): string {
        $words = self::fraction($part, $whole);
        if ($words !== '') {
            return $aside ? ' (' . $words . ')' : ': ' . $words;
        }
        return $whole < facts::FEW ? '' : ' (' . self::percent($part, $whole) . ')';
    }

    /**
     * A part of a whole in words («una de cada cuatro», «todas», «ninguna»), only when it is that: with ten or more behind,
     * between one in eight and three in four, and within one point of it. If not, nothing.
     *
     * @param int $part
     * @param int $whole
     * @return string
     */
    public static function fraction(int $part, int $whole): string {
        if ($whole < facts::FEW) {
            return '';
        }
        if ($part <= 0 || $part >= $whole) {
            return get_string($part <= 0 ? 'panel_frac_none' : 'panel_frac_all', self::C);
        }
        $r = $part / $whole;
        $names = [[1 / 2, '1_2'], [1 / 3, '1_3'], [1 / 4, '1_4'], [1 / 5, '1_5'], [2 / 3, '2_3'], [3 / 4, '3_4'], [1 / 6, '1_6'],
            [1 / 8, '1_8']];
        usort($names, fn($a, $b) => abs($a[0] - $r) <=> abs($b[0] - $r));
        return abs($names[0][0] - $r) <= self::NEAR ? get_string('panel_frac_' . $names[0][1], self::C) : '';
    }

    /**
     * A percentage that never says more than it is: with a decimal under ten («0,9 %»), and never 0 nor 100 when it is not.
     *
     * @param int $part
     * @param int $whole
     * @return string
     */
    public static function percent(int $part, int $whole): string {
        $p = $whole ? 100 * $part / $whole : 0;
        if ($p > 0 && $p < 0.1) {
            return '< ' . format_float(0.1, 1) . ' %';
        }
        return format_float($p > 99 && $p < 100 ? floor($p) : $p, $p < 10 && $p != floor($p) ? 1 : 0) . ' %';
    }

    /**
     * The links to take the families and the figures away in a spreadsheet (export.php), in Excel and in CSV.
     *
     * @param \stdClass $centre
     * @param bool $canact Whether the person works with the families: only then, the families.
     * @return array families (or false) and figures, each with excel and csv.
     */
    protected static function exports(\stdClass $centre, bool $canact): array {
        $url = fn(string $what, string $format) => (new \moodle_url('/local/oksigeniaeguahe/export.php', ['c' => $centre->id,
            'what' => $what, 'format' => $format, 'sesskey' => sesskey()]))->out(false);
        $both = fn(string $what) => ['excel' => $url($what, 'excel'), 'csv' => $url($what, 'csv')];
        return ['families' => $canact ? $both('families') : false, 'figures' => $both('figures')];
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
        $campaigns = self::campaigns($centre, $now);
        $campaign = $campaigns[$view['campaign'] ?? ''] ?? $campaigns['live'];
        $keys = array_keys($campaigns);
        $previous = self::before($campaigns, $campaign);
        // Every family of the campaign, with those already of the centre and those that came for another year, apart.
        $read = facts::all($centre, $campaign, $now);
        $families = facts::group($read, 'campaign');
        $insiders = facts::group($read, 'centre');
        $course = (string) ($view['course'] ?? '');
        $courses = self::courses($families);
        $course = isset($courses[$course]) ? $course : '';
        $mine = array_values(array_filter($families, fn($f) => $course === '' || $f['course'] === $course));
        // The campaign before, at the same week since its start: only when the centre wrote when both started.
        $compare = ($view['compare'] ?? 1) && $previous && $campaign['start'] && $previous['start'];
        $before = $compare ? array_values(array_filter(
            self::families($centre, $previous, $now),
            fn($f) => $course === '' || $f['course'] === $course
        )) : [];
        $insidemine = array_values(array_filter($insiders, fn($f) => $course === '' || $f['course'] === $course));
        $end = $campaign['key'] === 'live' ? $now : (int) $campaign['until'];
        $elapsed = $campaign['start'] ? max(0, $end - $campaign['start']) : 0;
        $url = fn(array $change) => self::url($centre, array_merge($view, $change));
        $cut = get_string('panel_cut', $c, userdate($now, get_string('strftimetime', 'langconfig')));
        $live = $campaign['key'] === 'live';
        $sums = facts::sum($mine);
        // First, by channel: where the families really came from, before the door they came in by (an open day, a form).
        $voice = in_array($view['voice'] ?? '', self::VOICES, true) ? $view['voice'] : 'channel';
        $show = ($view['show'] ?? '') === 'matriculadas' ? 'matriculadas' : 'llegadas';
        // Three sheets instead of one long page: the campaign (answers, how far, where from,
        // week by week, places), the school year (the ledger and the roll year by year) and the families (what they like
        // and the channels month by month). Only the blocks of the sheet shown are worked out.
        $sheet = in_array($view['sheet'] ?? '', self::SHEETS, true) ? $view['sheet'] : 'campana';
        $on = fn(string $key) => $sheet === $key;
        $sheets = [];
        foreach (self::SHEETS as $key) {
            $name = ['campana' => 'panel_sheet_campaign', 'curso' => 'panel_new_sheet', 'familias' => 'panel_sheet_families'][$key];
            $sheets[] = ['key' => $key, 'name' => get_string($name, $c), 'what' => get_string($name . '_what', $c),
                // The school year is the whole centre: its sheet leaves the course aside.
                'url' => $url(['sheet' => $key] + ($key === 'curso' ? ['course' => ''] : []))->out(false),
                'current' => $on($key)];
        }
        $placelist = !$live || !$on('campana') ? null :
            self::places($centre, $course, $compare ? $before : [], $previous, $campaign, $elapsed, $now);
        $context = [
            'sheets' => $sheets,
            'sheetcampaign' => $on('campana'),
            'sheetyear' => $on('curso'),
            'sheetfamilies' => $on('familias'),
            'head' => panel::head([
                'back' => ['name' => get_string('qs_title', $c), 'url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id,
                    'preguntas' => 1]))->out(false)],
                // The «What is this?» of a name of its own, said in one paragraph.
                'what' => get_string('panel_isthis_metrics', self::C),
                'title' => self::title($sums + ['apart' => count($insiders)], $campaign),
                'sub' => $course !== '' || !$on('campana') ? $cut . '.' : self::kinds_sentence($read, $campaign, $cut),
                // The reports, hot and cold, from the numbers.
                'action' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/report.php', ['c' => $centre->id]))->out(false),
                    'name' => get_string('report_title', $c), 'icon' => '', 'anchor' => false],
            ]),
            'ensenar' => !empty($view['ensenar']),
            'ensenarurl' => $url(['ensenar' => 1])->out(false),
            'cut' => $cut,
            'importurl' => (new \moodle_url('/local/oksigeniaeguahe/import.php', ['c' => $centre->id]))->out(false),
            'importnote' => get_string('panel_import_note', $c),
            // To take away: the families, for whoever works with them; the figures without names, for whoever sees them.
            'exports' => self::exports($centre, $canact),
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
            'response' => $on('campana') ? self::response($centre, $mine, $now) : null,
            'reach' => $mine && $on('campana') ? self::reach($centre, $campaign, $mine, $before, $compare ? $previous : null, $elapsed) : null,
            'channels' => $mine && $on('campana') ? self::channels($centre, $campaign, $mine, $insidemine, $voice, $url) : null,
            // What the totals of the campaign leave out, said: those already of the centre, and those of another year.
            'apart' => $on('campana') ? self::apart($read, $campaign, $course) : false,
            // From the open day to the enrolment: who signed up, who came, and what they did after.
            'acts' => $live && $course === '' && $on('campana') ? self::acts($centre, $read, $now) : false,
            'unknown' => $on('campana') ? self::unknown($centre, $mine, $campaign, $now) : false,
            // How they decide: those that stayed in their first word, how long they take, those that come back.
            'decide' => $mine && $on('campana') ? self::folded(decide::summary(
                $centre,
                $mine,
                (int) $campaign['since'],
                $now
            )) : null,
            // Those that go, by reason, channel and how far they had come; and the contacts that were not families.
            'outs' => $on('campana') && $course === '' ? outs::summary(
                $centre,
                $mine,
                (int) $campaign['since'],
                (int) $campaign['until']
            ) : null,
            'weeks' => $mine && $on('campana') ? self::weeks(
                $centre,
                $campaign,
                $mine,
                $before,
                $compare,
                $show,
                $end,
                $previous,
                $url,
                $now,
                !empty($view['whole'])
            ) : null,
            // The places of this year only for the campaign under way: an imported one is about another year. Each
            // course with places on its coat rail; those without, named in one line, never an empty rail each.
            'places' => $placelist === null ? null : array_values(array_filter($placelist, fn($p) => $p['places'] > 0)),
            'noplaces' => $placelist ? self::unplaced($placelist, $centre) : false,
            'hasplaces' => $live && $on('campana'),
            'placesdef' => get_string('panel_coming_def', $c),
            'started' => !$live || $course !== '' || !$on('campana') ? false : self::started((int) $centre->id, $now),
            'referred' => !$live || $course !== '' || !$on('campana') ? false : self::referred($families, (string) $campaign['year']),
            'gone' => !$live || $course !== '' || !$on('campana') ? false : self::gone($centre, ...self::course_under_way($centre, $now)),
            'chose' => !$live || $course !== '' || !$on('campana') ? false :
                self::chose((int) $centre->id, (int) $campaign['start']),
            'starts' => $on('campana') ? self::starts($campaigns, $canact, $centre) : null,
            // What they like most: what the families do with what Eguahe gives them, and who they are.
            'likes' => $on('familias') ? panel_likes::context($centre, $campaign, $mine, $now) : null,
            // The channels compared in time, the whole year round: for the campaign under way and every course.
            'channelstime' => !$live || $course !== '' || !$on('familias') ? false : panel_channels::context($centre, $now),
        ];
        $context['spendaction'] = $url([])->out(false);
        // Of one course only, the numbers are said and not opened: the lists are of the whole campaign.
        if ($course !== '' && $context['reach']) {
            $context['reach']['steps'] = array_map(fn($step) => ['url' => false] + $step, $context['reach']['steps']);
        }
        // A chart without its partner in the row takes the whole row; so does the sheet «By channel», a table, and then
        // the weeks too, alone on the row below.
        foreach ([['reach', 'response'], ['channels', 'weeks'], ['weeks', 'channels']] as [$chart, $partner]) {
            if ($context[$chart]) {
                $context[$chart]['ancha'] = !$context[$partner] || !empty($context['channels']['sheet']);
            }
        }
        // The sheet «Quién entra y quién sale»: the new students, what the ads and the acts brought, the joins and
        // leaves, the ledger and the years; with nothing to count yet, how to bring it.
        $new = $on('curso') ? panel_newstudents::context(
            $centre,
            (string) ($view['year'] ?? ''),
            (bool) ($view['withcentre'] ?? 1),
            $url,
            $canact,
            $now
        ) : null;
        $context['sheetnew'] = (bool) $new;
        $context['newstudents'] = $new ? $new['newstudents'] : false;
        $context['filters']['years'] = $new ? $new['years'] : [];
        $context['filters']['count'] = $new ? $new['count'] : [];
        if ($new) {
            $context['head']['title'] = $new['title'];
            $context['head']['sub'] = get_string('panel_new_sub', $c);
        }
        $context['yearempty'] = $on('curso') && !$new;
        // The bar of filters: the school year and the switch on this sheet; nothing while it has nothing to count.
        $context['sheetyear'] = $on('curso') && !$new;
        return $context;
    }

    /**
     * The address of the page with a view.
     *
     * @param \stdClass $centre
     * @param array $view
     * @return \moodle_url
     */
    public static function url(\stdClass $centre, array $view): \moodle_url {
        // The sheet always: without one, the page is the questions.
        $params = ['c' => $centre->id, 'hoja' => ($view['sheet'] ?? '') ?: 'campana'];
        $names = ['campaign' => 'campana', 'course' => 'curso', 'voice' => 'voz', 'show' => 'vista', 'year' => 'anio'];
        foreach ($names as $key => $name) {
            if (
                !empty($view[$key]) && !($key === 'campaign' && $view[$key] === 'live') && !($key === 'voice' &&
                    $view[$key] === 'channel') && !($key === 'show' && $view[$key] === 'llegadas')
            ) {
                $params[$name] = $view[$key];
            }
        }
        if (isset($view['compare']) && !$view['compare']) {
            $params['comparar'] = 0;
        }
        if (!empty($view['withcentre'])) {
            $params['centro'] = 1;
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
     * The title: how many families, and what came of them.
     *
     * @param int[] $sums From facts::sum().
     * @param array $campaign
     * @return string
     */
    protected static function title(array $sums, array $campaign): string {
        $c = self::C;
        if (!$sums['n']) {
            // None new, though those already of the centre ask for a place: they are told below.
            return get_string(!empty($sums['apart']) ? 'panel_title_nonew' : 'panel_metrics_empty', $c);
        }
        $parts = [];
        foreach (['enrolled', 'went', 'closed'] as $key) {
            if ($sums[$key]) {
                $parts[] = get_string('panel_title_' . $key . ($sums[$key] === 1 ? '_one' : ''), $c, $sums[$key]);
            }
        }
        if (!$sums['enrolled']) {
            array_unshift($parts, get_string('panel_title_enrolled_none' . ($sums['n'] === 1 ? '_one' : ''), $c));
        }
        return get_string('panel_title_fams' . ($sums['n'] === 1 ? '_one' : ''), $c, (object) ['n' => $sums['n'],
            'campaign' => \core_text::strtolower(\core_text::substr($campaign['name'], 0, 1)) . \core_text::substr($campaign['name'], 1),
            'parts' => implode(', ', $parts)]);
    }

    /**
     * Of the families that ask for a place, how many had nothing to do with the centre, how many knew it and how many were
     * already of it.
     *
     * @param array[] $read From facts::read().
     * @param array $campaign
     * @param string $cut
     * @return string
     */
    public static function kinds_sentence(array $read, array $campaign, string $cut): string {
        $c = self::C;
        $kinds = facts::kinds($read);
        $cut = $cut !== '' ? $cut . '.' : '';
        if (!$kinds['all']) {
            return $cut;
        }
        $part = fn(string $key) => get_string('panel_kinds_' . $key . ($kinds[$key] === 1 ? '_one' : ''), $c, (object) ($kinds + [
            'bits' => implode('; ', array_filter([
                $kinds['former'] ? get_string('panel_kinds_former', $c, $kinds['former']) : '',
                $kinds['alumni'] ? get_string('panel_kinds_alumni', $c, $kinds['alumni']) : '']))]));
        $parts = array_map($part, array_filter(['fresh', 'knew', 'centre'], fn($key) => $kinds[$key] || $key === 'fresh'));
        $head = get_string('panel_kinds_head' . ($campaign['year'] === '' ? '_noyear' : '') . ($kinds['all'] === 1 ? '_one' : ''), $c, (object) [
            'n' => $kinds['all'], 'year' => $campaign['year']]);
        return trim($head . ' ' . implode('; ', $parts) . '. ' . $cut);
    }

    /**
     * What the totals of a campaign leave out, so nobody takes them for more or for less: the families already of the
     * centre and those that came in its time for another school year, each with what came of them.
     *
     * @param array[] $read From facts::read().
     * @param array $campaign
     * @param string $course
     * @return array|false list
     */
    protected static function apart(array $read, array $campaign, string $course) {
        $c = self::C;
        $out = [];
        foreach (['centre', 'other'] as $group) {
            $those = array_filter(facts::group($read, $group), fn($f) => $course === '' || $f['course'] === $course);
            $sum = facts::sum($those);
            if (!$sum['n'] || ($group === 'other' && $course !== '')) {
                continue;
            }
            $out[] = ['text' => get_string('panel_apart_' . $group . ($sum['n'] === 1 ? '_one' : ''), $c, (object) ['n' => $sum['n'],
                'year' => $campaign['year']]) . ($sum['enrolled'] ? ' ' . get_string('panel_apart_enrolled' .
                ($sum['enrolled'] === 1 ? '_one' : ''), $c, $sum['enrolled']) : '')];
        }
        return $out ? ['list' => $out] : false;
    }

    /**
     * How fast families get a first answer: those answered and those that still wait for one, which count too. The median is
     * of all of them, and is only said when more than half have an answer and there are ten or more behind.
     *
     * @param \stdClass $centre
     * @param array[] $mine The families of the campaign.
     * @param int $now
     * @return array|null
     */
    protected static function response(\stdClass $centre, array $mine, int $now): ?array {
        $c = self::C;
        $r = self::said(metrics::response((int) $centre->id, facts::ids(array_filter($mine, [facts::class, 'lives'])), $now));
        $waiting = households::waiting_count((int) $centre->id);
        $inbox = $waiting ? get_string('panel_response_inbox' . ($waiting === 1 ? '_one' : ''), $c, $waiting) : '';
        $def = get_string('panel_response_def', $c);
        if (!$r['asked']) {
            return ['hero' => '', 'sentence' => get_string('panel_response_none', $c), 'facts' => [], 'def' => $def,
                'inbox' => $inbox];
        }
        $hero = $r['median'] === null ? '' : self::hours_words((float) $r['median']);
        // Where they wrote first and when: only where there is more than one, or it says the same as the whole.
        $known = fn(string $id) => get_string_manager()->string_exists($id, $c);
        $splits = [];
        foreach (['bychannel' => 'panel_resp_by_channel', 'byband' => 'panel_resp_by_band'] as $key => $title) {
            if (count($r[$key]) < 2) {
                continue;
            }
            $longest = max(array_filter(array_column($r[$key], 'median')) ?: [0]) ?: 1;
            if ($key === 'bychannel') {
                // The channels most families came by first; the parts of the week keep their order.
                uasort($r[$key], fn($a, $b) => [$b['asked']] <=> [$a['asked']]);
            }
            $rows = [];
            foreach ($r[$key] as $name => $group) {
                $rows[] = [
                    'name' => $key === 'bychannel' ? get_string('channel_' . ($known('channel_' . $name) ? $name : 'other'), $c) :
                        get_string('panel_band_' . $name, $c),
                    // Few, or most of them still waiting: how many have an answer, never a time that stands for all.
                    'hero' => $group['median'] === null ? get_string('panel_resp_of', $c, (object) ['n' => $group['answered'],
                        'of' => $group['asked']]) : self::hours_words((float) $group['median']),
                    'count' => get_string(
                        $group['asked'] === 1 ? 'panel_resp_families_one' : 'panel_resp_families',
                        $c,
                        $group['asked']
                    ),
                    'v' => $group['median'] === null ? 0 : round(max(0.02, (float) $group['median'] / $longest), 3),
                    'few' => $group['median'] === null,
                ];
            }
            $splits[] = ['title' => get_string($title, $c), 'rows' => $rows];
        }
        $few = (bool) array_filter(array_merge(...array_column($splits, 'rows') ?: [[]]), fn($row) => $row['few']);
        $facts = [['n' => $r['answered'], 'text' => get_string('panel_response_answered', $c)]];
        foreach (['day', 'two', 'more'] as $key) {
            if ($r['open'][$key]) {
                $facts[] = ['n' => $r['open'][$key], 'text' => get_string('panel_response_open_' . $key, $c)];
            }
        }
        if (count($mine) > $r['asked']) {
            $facts[] = ['n' => count($mine) - $r['asked'], 'text' => get_string('panel_response_rest', $c)];
        }
        return [
            'hero' => $hero,
            'sentence' => self::response_sentence($r),
            'facts' => $facts,
            'splits' => $splits,
            'hassplits' => (bool) $splits,
            'bandsdef' => $splits ? get_string('panel_resp_bands_def', $c) : false,
            'fewnote' => $few ? get_string('panel_resp_few', $c) : false,
            'inbox' => $inbox,
            'def' => $def,
        ];
    }

    /**
     * Only what can be said of the first answers: with fewer than ten behind, no median.
     *
     * @param array $r From metrics::response().
     * @return array
     */
    public static function said(array $r): array {
        $few = fn(array $group) => ['median' => ($group['timed'] ?? $group['count']) < facts::FEW ? null : $group['median']] + $group;
        return ['bychannel' => array_map($few, $r['bychannel']), 'byband' => array_map($few, $r['byband'])] + $few($r);
    }

    /**
     * The first answer in a sentence, with how many there are behind: those answered, those that wait and since when; the
     * median only when it is one.
     *
     * @param array $r From metrics::response().
     * @param bool $period Of the families that came in in a time, and not of a campaign.
     * @return string
     */
    public static function response_sentence(array $r, bool $period = false): string {
        $c = self::C;
        $a = (object) ['asked' => $r['asked'], 'answered' => $r['answered'], 'waiting' => $r['waiting'],
            'longest' => $r['longest'] === null ? '' : self::hours_words((float) $r['longest']),
            'median' => $r['median'] === null ? '' : self::hours_words((float) $r['median'])];
        $out = get_string('panel_response_of' . ($period ? '_period' : '') . ($r['asked'] === 1 ? '_one' : ''), $c, $a) . ' ';
        $parts = [get_string('panel_response_part' . ($r['answered'] ? ($r['answered'] === 1 ? '_one' : '') : '_none'), $c, $a)];
        if ($r['waiting']) {
            $parts[] = get_string('panel_response_waits' . ($r['waiting'] === 1 ? '_one' : ''), $c, $a);
        }
        $out .= implode($r['waiting'] ? get_string('panel_response_and', $c) : '', $parts) . '.';
        if ($r['tried']) {
            $out .= ' ' . get_string('panel_response_tried' . ($r['tried'] === 1 ? '_one' : ''), $c, $r['tried']);
        }
        // The time only of those whose two hours are known, and those that still wait.
        $untimed = $r['answered'] - $r['timed'];
        if ($r['median'] !== null) {
            $out .= ' ' . get_string($untimed ? 'panel_response_half_of' : 'panel_response_half', $c, (object) ((array) $a + [
                'of' => $r['timed'] + $r['waiting']]));
        }
        if ($untimed) {
            $out .= ' ' . get_string('panel_response_timed' . ($r['timed'] === 1 ? '_one' : ''), $c, (object) ['timed' => $r['timed'],
                'answered' => $r['answered']]);
        }
        return $out;
    }

    /**
     * A time in hours as people say it: minutes, hours or days.
     *
     * @param float $hours
     * @return string
     */
    public static function hours_words(float $hours): string {
        $c = self::C;
        if ($hours < 1) {
            $n = max(1, (int) round($hours * 60));
            return get_string($n === 1 ? 'panel_resp_minute' : 'panel_resp_minutes', $c, $n);
        }
        return $hours < 48 ? get_string((int) round($hours) === 1 ? 'panel_resp_hour' : 'panel_resp_hours', $c, (int) round($hours)) :
            get_string('panel_resp_days', $c, (int) round($hours / 24));
    }

    /**
     * How far the families of a campaign got, each stop with all it attracted (those that went too, at the furthest they
     * got); the campaign before at the same week behind, only where it was counted; and the part of each channel inside.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array $mine
     * @param array $before
     * @param array|null $previous The campaign before, to compare with.
     * @param int $elapsed
     * @return array
     */
    protected static function reach(\stdClass $centre, array $campaign, array $mine, array $before, ?array $previous, int $elapsed): array {
        $c = self::C;
        $sum = facts::sum($mine);
        $fields = self::STOPS;
        // The one before as it was at the same week: who had come by then, how far they went, and the enrolled by then.
        $by = $previous ? (int) $previous['start'] + $elapsed : 0;
        $then = $previous ? array_values(array_filter($before, fn($f) => $f['first'] <= $by)) : [];
        // A stop nothing is known of (no file says who came, and nothing lives in Eguahe) is not drawn as a zero.
        $said = function (array $families, string $step): bool {
            $fact = ['visit' => 'came', 'application' => 'applied'][$step] ?? '';
            return in_array($step, ['inquiry', 'enrolled'], true) || (bool) array_filter($families, fn($f) => facts::lives($f) ||
                ($fact !== '' && !empty($f['known'][$fact])));
        };
        // Behind, only what is known of every one of them: of those only in a file, not who came.
        $whole = fn(array $families, string $step) => $said($families, $step) && (in_array($step, ['inquiry', 'enrolled'], true) ||
            !array_filter($families, fn($f) => !facts::lives($f) && empty($f['known'][['visit' => 'came', 'application' => 'applied'][$step] ?? ''])));
        $n = [];
        $g = [];
        foreach ($fields as $step => $field) {
            $n[$step] = $field === '' ? $sum['n'] : $sum[$field];
            $g[$step] = !$previous || !$whole($then, $step) ? null : ($field === '' ? count($then) : count(array_filter(
                $then,
                fn($f) => !empty($f[$field]) && ($field !== 'enrolled' || ($f['enrolledat'] && $f['enrolledat'] <= $by))
            )));
        }
        $top = max(1, $n['inquiry'], (int) $g['inquiry']);
        $channels = self::top(array_count_values(array_column(array_column($mine, 'voices'), 'first')));
        $steps = [];
        foreach ($fields as $step => $field) {
            // Nobody in the list is not a stop of its own.
            if (!$said($mine, $step) || ($step === 'waitlist' && !$n[$step])) {
                continue;
            }
            $parts = [];
            foreach ($channels as $name => $key) {
                $here = array_filter($mine, fn($f) => self::bucket($f['voices']['first'], $channels) === $name);
                $part = $field === '' ? count($here) : count(array_filter($here, fn($f) => !empty($f[$field])));
                $from = count($here);
                if ($part) {
                    $parts[] = ['canal' => $key, 'v' => round($part / $top, 4), 'n' => $part,
                        'line' => $field !== '' ? get_string('panel_far_part', $c, (object) ['from' => $from,
                            'name' => $name, 'n' => $part, 'what' => get_string('panel_far_did_' . $step, $c),
                            'share' => self::share($part, $from, true)]) :
                            get_string($part === 1 ? 'panel_far_part_first_one' : 'panel_far_part_first', $c, (object) [
                            'n' => $part, 'name' => $name])];
                }
            }
            $line = $field !== '' ? get_string('panel_far_step', $c, (object) ['n' => $n[$step], 'total' => $sum['n'],
                'what' => get_string('panel_far_name_' . $step, $c), 'share' => self::share($n[$step], $sum['n'], true)]) :
                get_string($sum['n'] === 1 ? 'panel_far_first_one' : 'panel_far_first', $c, $sum['n']);
            // Of those only in a file, whether they came or applied may not be known: told, never taken for a no.
            $not = ['visit' => 'notcame', 'application' => 'notapplied'][$step] ?? '';
            $line .= $not !== '' && $sum[$not] ? ' ' . get_string('panel_far_not', $c, $sum[$not]) : '';
            $ghost = $g[$step] !== null;
            $steps[] = ['key' => $step, 'name' => get_string('panel_far_name_' . $step, $c), 'n' => $n[$step],
                'v' => round($n[$step] / $top, 4), 'g' => $ghost ? round($g[$step] / $top, 4) : 0, 'gn' => $ghost ? $g[$step] : '',
                'url' => $n[$step] ? familylists::url($centre, familylists::of_year($campaign, ['inquiry' => 'campaign', 'visit' => 'came',
                'application' => 'applied', 'waitlist' => 'waitlist', 'admitted' => 'place', 'enrolled' => 'enrolled'][$step])) : false,
                'hasghost' => $ghost, 'line' => $line . ($ghost ? ' ' . get_string('panel_far_ghost', $c, (object) ['name' =>
                $previous['name'], 'n' => $g[$step]]) : ''), 'parts' => $parts];
        }
        $gone = [];
        foreach (['closed', 'went', 'nokids', 'outside'] as $key) {
            if ($sum[$key]) {
                $gone[] = get_string('panel_far_' . $key . ($sum[$key] === 1 ? '_one' : ''), $c, $sum[$key]);
            }
        }
        return [
            'compare' => (bool) $previous,
            'legend' => (bool) $previous,
            'beforename' => $previous ? $previous['name'] : '',
            'rest' => get_string('panel_far_rest', $c, (object) ['n' => $sum['n'], 'enrolled' => $sum['enrolled'],
                'share' => self::share($sum['enrolled'], $sum['n'], true)]),
            'closed' => implode(' ', $gone),
            'def' => get_string($previous ? 'panel_far_def' : 'panel_far_def_alone', $c),
            'steps' => $steps,
        ];
    }

    /**
     * From an open day to the enrolment, act by act of the campaign: who signed up, who came (and whether anyone said it),
     * who asked for a place after and who enrolled after; the families already of the centre, those that were not families
     * and those that only came to a talk, out.
     *
     * @param \stdClass $centre
     * @param array[] $read From facts::read().
     * @param int $now
     * @return array|false rows (name, when, line, missing), def
     */
    protected static function acts(\stdClass $centre, array $read, int $now) {
        $c = self::C;
        $mine = array_column(facts::group($read, 'campaign'), null, 'id');
        $live = facts::live($centre, $now);
        $rows = [];
        foreach (events::for_centre((int) $centre->id) as $event) {
            if (
                $event->kind === 'other' || !empty($event->tailored) || !empty($event->householdid) || !empty($event->cancelled) ||
                    (int) $event->timestart < $live['since'] || (int) $event->timeend > $now
            ) {
                continue;
            }
            $way = self::act_way((int) $event->id, (int) $event->timestart, $mine);
            if (!$way['signed']) {
                continue;
            }
            // Nobody said who came: that is not that nobody came.
            $unmarked = !$way['marked'];
            $rows[] = ['name' => format_string($event->name), 'when' => userdate((int) $event->timestart, get_string('strftimedate', 'langconfig')),
                'missing' => $unmarked, 'text' => $unmarked ? get_string(
                    'panel_act_unmarked' . ($way['signed'] === 1 ? '_one' : ''),
                    $c,
                    $way['signed']
                ) : implode(' · ', array_map(fn($key) => get_string(
                    'panel_act_' . $key . ($way[$key] === 1 ? '_one' : ''),
                    $c,
                    $way[$key]
                ), ['signed', 'came', 'applied', 'enrolled']))];
        }
        return $rows ? ['rows' => $rows, 'def' => get_string('panel_act_way_def', $c)] : false;
    }

    /**
     * What the families of a campaign did from an act on.
     *
     * @param int $eventid
     * @param int $time When the act was: only what was done from then on can have to do with it.
     * @param array $mine The families that count, by their id.
     * @return int[] signed, came, applied, enrolled (families) and marked (sign-ups of anyone that were said to have come or not)
     */
    public static function act_way(int $eventid, int $time, array $mine): array {
        global $DB;
        $out = ['signed' => 0, 'came' => 0, 'applied' => 0, 'enrolled' => 0, 'marked' => 0];
        $came = [];
        $rows = $DB->get_records(events::SIGNUPS, ['eventid' => $eventid], 'id ASC', 'id, householdid, attended, missed');
        foreach ($rows as $row) {
            $out['marked'] += (int) $row->attended || (int) $row->missed ? 1 : 0;
            if (isset($mine[(int) $row->householdid])) {
                $came[(int) $row->householdid] = !empty($came[(int) $row->householdid]) || (int) $row->attended > 0;
            }
        }
        $out['signed'] = count($came);
        foreach (array_keys(array_filter($came)) as $id) {
            $out['came']++;
            $kids = array_filter($mine[$id]['kids'] ?? [], fn($k) => $k['mine'] && $k['stagetime'] >= $time);
            $stage = fn(array $k) => $k['stage'] === 'closed' ? $k['far'] : $k['stage'];
            $out['applied'] += array_filter($kids, fn($k) => in_array($stage($k), facts::APPLIED, true)) ? 1 : 0;
            $out['enrolled'] += array_filter($kids, fn($k) => $k['stage'] === 'enrolled' && !$k['left']) ? 1 : 0;
        }
        return $out;
    }

    /**
     * What is not known of a campaign, said: the families whose way here nothing measured nor they said, those that went
     * without a reason, the acts nobody said who came to and those still without a child noted down.
     *
     * @param \stdClass $centre
     * @param array[] $mine
     * @param array $campaign
     * @param int $now
     * @return array|false list
     */
    protected static function unknown(\stdClass $centre, array $mine, array $campaign, int $now) {
        global $DB;
        $c = self::C;
        $counts = ['channel' => count(array_filter($mine, fn($f) => ($f['channel'] ?? '') === 'unmeasured')),
            'nokids' => facts::sum($mine)['nokids'],
            'why' => count(array_filter($mine, fn($f) => $f['closed'] && $f['why'] === 'another')), 'acts' => 0,
            'undated' => facts::sum($mine)['undated']];
        if ($campaign['key'] === 'live') {
            $counts['acts'] = (int) $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {" . events::T . "} e
                  WHERE e.centreid = :centreid AND e.kind <> 'other' AND e.tailored = 0 AND e.cancelled = 0 AND e.timestart >= :since
                        AND e.timeend < :now
                        AND EXISTS (SELECT 1 FROM {" . events::SIGNUPS . "} s WHERE s.eventid = e.id)
                        AND NOT EXISTS (SELECT 1 FROM {" . events::SIGNUPS . "} m WHERE m.eventid = e.id
                                                                                  AND (m.attended > 0 OR m.missed > 0))",
                ['centreid' => $centre->id, 'since' => $campaign['since'], 'now' => $now]
            );
        }
        $list = [];
        foreach ($counts as $key => $n) {
            if ($n) {
                $list[] = ['text' => get_string('panel_unknown_' . $key . ($n === 1 ? '_one' : ''), $c, $n)];
            }
        }
        return $list ? ['list' => $list] : false;
    }

    /**
     * Where families came from, said one of three ways, with the enrolled and the cost of each new student in words: every
     * family that came, also those that went elsewhere, so every voice adds up to the same total.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array $mine
     * @param array $insiders The families already of the centre of the same campaign and course: only set against the others.
     * @param string $voice
     * @param callable $url
     * @return array
     */
    protected static function channels(
        \stdClass $centre,
        array $campaign,
        array $mine,
        array $insiders,
        string $voice,
        callable $url
    ): array {
        $c = self::C;
        $said = $voice === 'said';
        $measured = $voice === 'measured';
        $bycampaign = $voice === 'campaign';
        // By channel: every channel on its row, in their order, each with how far its families went; no money here (it is
        // in what was measured and in each campaign).
        $bychannel = $voice === 'channel';
        // What the families said is not a channel the centre pays for; what was measured and each campaign are, with what
        // each new student cost.
        $declared = $said;
        $names = array_filter(array_column(array_column($mine, 'voices'), $voice), fn($v) => $v !== '');
        $channels = self::top(array_count_values($names));
        $sum = null;
        $notes = [];
        if ($bychannel) {
            // The families already of the centre that came in the same campaign, by the channel they came by: set against
            // the new ones, so a channel is not given families that already knew the centre.
            $counts = array_count_values($names);
            $theirs = array_count_values(array_column(array_column($insiders, 'voices'), 'channel'));
            $channels = [];
            foreach (arrival::CHANNELS as $key) {
                $name = arrival::channel_name($key);
                if (!empty($counts[$name]) || !empty($theirs[$name])) {
                    $channels[$name] = 'c' . $key;
                }
            }
            // How many came by each channel, who they are and what each rests on.
            $basis = fn($f) => ['basis' => $f['voices']['basis'] ?? ''] + $f;
            $sum = arrival::by_channel(array_map($basis, $mine), array_map($basis, $insiders));
            $notes = array_column($sum['rows'], null, 'key');
        }
        $spend = self::spend($centre, $campaign);
        $rows = [];
        $most = 0;
        foreach ($channels as $name => $key) {
            $here = array_values(array_filter($mine, fn($f) => $f['voices'][$voice] !== '' &&
                self::bucket($f['voices'][$voice], $channels) === $name));
            $rows[$name] = facts::sum($here) + ['key' => $key];
            $most = max($most, count($here));
        }
        $out = [];
        $unnoted = 0;
        foreach ($rows as $name => $row) {
            $amount = $declared || $bychannel ? null : ($spend[$name] ?? null);
            $unnoted += !$declared && !$bychannel && $amount === null ? 1 : 0;
            $cost = $bychannel ? '' : ($amount === null ? get_string('panel_cost_none', $c) : ($amount == 0 ?
                get_string('panel_cost_free', $c) :
                ($row['students'] ? get_string('panel_cost_per', $c, centres::money($centre, $amount / $row['students'])) :
                get_string('panel_cost_nobody', $c, centres::money($centre, (float) $amount)))));
            $who = $bychannel ? arrival::row_note($notes[substr($row['key'], 1)] ?? []) : '';
            $one = $row['n'] === 1 ? '_one' : '';
            $line = $bychannel ? get_string(
                'panel_bychannel_line' . $one,
                $c,
                (object) ['name' => $name, 'n' => $row['n'], 'visited' => $row['came'], 'applied' => $row['applied'],
                'enrolled' => $row['enrolled'], 'share' => self::share($row['enrolled'], $row['n'], true)]
            ) .
                ($who !== '' ? ' ' . $who : '') : ($said || $voice === 'last' ?
                get_string('panel_' . $voice . '_line', $c, (object) ['n' => $row['n'], 'name' => $name]) :
                get_string('panel_first_line', $c, (object) ['n' => $row['n'], 'name' => $name, 'enrolled' => $row['enrolled'],
                'share' => self::share($row['enrolled'], $row['n'], true), 'cost' => $cost]));
            // In the row of the AI assistants, what those families said, each with how many.
            $aisaid = $measured && $name === get_string('arrival_ai_group', $c) ? self::ai_said($mine) : [];
            if ($aisaid) {
                $line .= ' ' . get_string('panel_measured_ai', $c, implode(', ', array_map(
                    fn($s) => $s['name'] . ' (' . $s['n'] . ')',
                    $aisaid
                )));
            }
            // Inside each bar, from its base, the families of the channel that were enrolled; never for what they said.
            $en = $said ? 0 : $row['enrolled'];
            $out[] = ['key' => $row['key'], 'name' => $name, 'n' => $row['n'], 'v' => round($row['n'] / max(1, $most), 4),
                'en' => $en, 'ev' => $en ? round($en / max(1, $most), 4) : false,
                'declared' => $said, 'hasparts' => $voice === 'first',
                'enrolled' => get_string($row['enrolled'] === 1 ? 'panel_enrolled_one' : 'panel_enrolled_n', $c, $row['enrolled']),
                'cost' => $cost, 'line' => $line, 'hassaid' => (bool) $aisaid, 'said' => $aisaid, 'group' => false,
                'who' => $who !== '' ? $who : false];
        }
        // By channel, each block with its name over its first row: the centre's own work, advertising, other ways, and
        // what was not measured.
        if ($bychannel) {
            $was = '';
            foreach ($out as $i => $row) {
                $group = arrival::block(substr((string) $row['key'], 1));
                if ($group !== $was) {
                    $out[$i]['group'] = get_string('panel_bychannel_group_' . $group, $c);
                }
                $was = $group;
            }
        }
        $told = count($names);
        $total = count($mine);
        $top = $out[0] ?? null;
        // By channel, the work of the centre itself (its profile, the search engines, the AI assistants, its networks and
        // channels, its web) against what it paid for, side by side: so the enrolments its own work brings are not put down
        // to an ad.
        $rest = !$top ? get_string('panel_channels_none', $c) : ($bychannel ? arrival::by_channel_text($sum) : get_string(
            'panel_' . ($bycampaign ? 'campaign' : $voice) . '_rest',
            $c,
            (object) ['name' => $top['name'], 'n' => $top['n'], 'told' => $told, 'total' => $total]
        ));
        $spendrows = [];
        if (!$declared && !$bychannel) {
            foreach (self::spend_keys($centre, $campaign, $mine, $voice) as $key => $name) {
                $amount = $spend[$name] ?? null;
                $spendrows[] = ['key' => $key, 'name' => $name, 'value' => $amount === null ? '' : format_float(
                    $amount,
                    2,
                    true,
                    true
                )];
            }
        }
        $untold = $total - $told;
        $foot = fn(string $key) => $untold ? get_string($key, $c, $untold) : '';
        return [
            'voices' => array_map(fn($v) => ['key' => $v, 'name' => get_string('panel_voice_' . $v, $c),
                'def' => get_string('panel_voice_' . $v . '_def', $c), 'url' => $url(['voice' => $v])->out(false),
                'current' => $v === $voice], self::VOICES),
            'def' => $said ? get_string('panel_voice_said_def', $c, (object) ['told' => $told, 'total' => $total]) :
                get_string('panel_voice_' . $voice . '_def', $c),
            'rest' => $rest,
            'foot' => $bychannel ? get_string('panel_bychannel_foot', $c) : ($said ? $foot('panel_said_foot') : ($measured ?
                $foot('panel_measured_foot') : ($bycampaign ? $foot('panel_campaign_foot') : ($unnoted ? get_string(
                    $unnoted === 1 ? 'panel_spend_missing_one' : 'panel_spend_missing',
                    $c,
                    $unnoted
                ) : '')))),
            'declared' => $said,
            'rows' => $out,
            // By channel, the sheet: the totals, who they are and each channel with how far its families went.
            'sheet' => $bychannel && $sum ? ['whatis' => get_string('panel_voice_channel_def', $c)] +
                self::folded(arrival::sheet($sum)) : false,
            'hasenrolled' => !$said && (bool) array_filter(array_column($out, 'en')),
            'spend' => $spendrows,
            'canspend' => (bool) $spendrows,
            'currency' => centres::currency_symbol($centre),
        ];
    }

    /**
     * On the screen, «How it is counted» goes folded under its name; on paper it stays open.
     *
     * @param array|false $sheet
     * @return array|false
     */
    protected static function folded($sheet) {
        return $sheet ? ['fold' => true] + $sheet : $sheet;
    }

    /**
     * A campaign as Numbers names it: a zone of the web of the centre («web-matricula») says so, never like an ad.
     *
     * @param string $campaign The utm_campaign.
     * @return string
     */
    public static function campaign_name(string $campaign): string {
        if (preg_match('/^web[-_](.+)$/', \core_text::strtolower($campaign), $m)) {
            return get_string('panel_campaign_webzone', self::C, str_replace(['-', '_'], ' ', $m[1]));
        }
        return $campaign;
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

    /** @var string[] The names of rows the plugin itself writes, by the string that says them: what they cost is kept by it, so
     * it is the same amount in every language. */
    const SPENDNAMES = ['arrival_ai_group', 'arrival_google_profile', 'metrics_live_moving', 'channel_openday', 'channel_visit',
        'channel_event', 'channel_webform', 'channel_phone', 'channel_walkin', 'channel_email', 'channel_whatsapp',
        'channel_telegram', 'channel_meta', 'channel_googleforms', 'channel_web', 'channel_other'];

    /**
     * What the spend of a row is kept by: the string that names it, or its own name.
     *
     * @param string $name As it is read, or as it was kept, in any language.
     * @return string
     */
    public static function spend_key(string $name): string {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach (['en', 'es', 'pt', 'pt_br', current_language()] as $lang) {
                foreach (self::SPENDNAMES as $id) {
                    $names[get_string_manager()->get_string($id, self::C, null, $lang)] = '@' . $id;
                }
            }
        }
        return $names[$name] ?? $name;
    }

    /**
     * The name of a row whose spend is kept by a key.
     *
     * @param string $key
     * @return string
     */
    protected static function spend_name(string $key): string {
        $key = self::spend_key($key);
        return $key !== '' && $key[0] === '@' && in_array(substr($key, 1), self::SPENDNAMES, true) ?
            get_string(substr($key, 1), self::C) : $key;
    }

    /**
     * What each first channel of a campaign cost, by its name.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @return array name => amount
     */
    public static function spend(\stdClass $centre, array $campaign): array {
        $out = [];
        // What was noted for the files of its school year, by channel or by campaign of a channel.
        foreach (self::files_of($centre, $campaign) as $label) {
            foreach (metrics::spend((int) $centre->id, $label) as $key => $amount) {
                [$channel, $source] = array_pad(explode('|', (string) $key, 2), 2, '');
                if ($channel === 'row' && $source === '') {
                    continue;
                }
                $name = $channel === 'row' ? self::spend_name($source) : ($source !== '' ? $source : get_string('channel_' .
                    (in_array($channel, lists::CHANNELS, true) ? $channel : 'other'), self::C));
                $out[$name] = ($out[$name] ?? 0) + $amount;
            }
        }
        foreach (metrics::spend((int) $centre->id, self::spend_label($campaign)) as $key => $amount) {
            [$channel, $source] = array_pad(explode('|', (string) $key, 2), 2, '');
            if ($channel === 'row' && $source !== '') {
                $out[self::spend_name($source)] = $amount;
            }
        }
        return $out;
    }

    /**
     * The files imported that are of a campaign, by their label.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @return string[]
     */
    protected static function files_of(\stdClass $centre, array $campaign): array {
        $ids = array_filter(facts::file_years($centre), fn($file) => in_array((string) $campaign['year'], $file[0], true));
        return array_values(array_unique(array_map(
            fn($i) => (string) $i->label,
            array_filter(imports::for_centre((int) $centre->id, 'contacts'), fn($i) => isset($ids[(int) $i->id]))
        )));
    }

    /**
     * What the spend of a campaign is kept under: the one under way, or the year of one before.
     *
     * @param array $campaign
     * @return string
     */
    protected static function spend_label(array $campaign): string {
        return self::LIVE . ($campaign['key'] === 'live' ? '' : ':' . $campaign['year']);
    }

    /**
     * The first channels of a campaign whose cost can be noted, by the key of their field.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array $families
     * @param string|null $voice One voice, or null for every voice the centre pays for.
     * @return array key => name
     */
    public static function spend_keys(\stdClass $centre, array $campaign, array $families, ?string $voice = null): array {
        // One voice, or every voice the centre pays for when what is posted is checked.
        $voices = $voice !== null ? [$voice] : ['first', 'last', 'measured', 'campaign'];
        $names = [];
        foreach ($voices as $v) {
            $names = array_merge($names, array_column(array_column($families, 'voices'), $v));
        }
        $names = array_unique(array_filter($names));
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
        $label = self::spend_label($campaign);
        foreach ($amounts as $key => $written) {
            if (!isset($keys[$key])) {
                continue;
            }
            $value = trim((string) $written) === '' ? null : unformat_float((string) $written, true);
            $amount = $value === null || $value === false ? null : max(0, (float) $value);
            $kept = \core_text::substr(self::spend_key($keys[$key]), 0, 255);
            // What was kept before by its name in some language goes with it.
            foreach (array_keys(metrics::spend((int) $centre->id, $label)) as $old) {
                [$channel, $source] = array_pad(explode('|', (string) $old, 2), 2, '');
                if ($channel === 'row' && $source !== $kept && $source !== '' && self::spend_key($source) === $kept) {
                    metrics::set_spend((int) $centre->id, $label, 'row', $source, null, $userid);
                }
            }
            metrics::set_spend((int) $centre->id, $label, 'row', $kept, $amount, $userid);
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
     * @param array|null $previous The campaign before, to compare with.
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
        ?array $previous,
        callable $url,
        int $now,
        bool $whole = false
    ): ?array {
        $c = self::C;
        $week = WEEKSECS;
        $enrolledview = $show === 'matriculadas';
        $field = $enrolledview ? 'enrolledat' : 'first';
        $start = $campaign['start'] ?: min(array_column($mine, 'first'));
        $times = array_filter(array_column($mine, $field));
        if (!$start) {
            return null;
        }
        $prevstart = $previous ? (int) $previous['start'] : 0;
        // Weeks of the calendar: the change of the hour never moves a day.
        $at = fn(int $from, int $w) => self::days_after($from, 7 * $w);
        $weeks = function (int $from, int $to) use ($at): int {
            $w = 1;
            while ($w < 120 && $at($from, $w) < $to) {
                $w++;
            }
            return $w;
        };
        $current = $weeks($start, max($start + 1, $end));
        $prevtimes = array_filter(array_column($before, $field));
        $prevweeks = $compare && $prevtimes ? $weeks($prevstart, max($prevtimes)) : 0;
        $total = $whole ? max(4, $current, $prevweeks) : max(4, $current);
        // Those that came before it began, and count in it, are in its first week: the last one says the total.
        $sum = fn(array $list, int $from, int $w) => count(array_filter($list, fn($t) => $t < $at($from, $w)));
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
        $word = fn(int $n) => get_string(($enrolledview ? 'panel_weeks_what_enrolled' : 'panel_weeks_what_arrived') .
            ($n === 1 ? '_one' : ''), $c);
        $what = $word((int) end($tally));
        $cols = [];
        $table = [];
        for ($w = 1; $w <= $total; $w++) {
            $range = get_string('panel_week_range', $c, (object) ['from' => userdate($at($start, $w - 1), get_string(
                'strftimedateshort',
                'langconfig'
            )), 'to' => userdate(self::days_after($start, 7 * $w - 1), get_string('strftimedateshort', 'langconfig'))]);
            $has = isset($tally[$w]);
            $added = $has ? $tally[$w] - ($tally[$w - 1] ?? 0) : 0;
            $line = get_string('panel_week_label', $c, $w) . ', ' . $range . ': ' . ($has ? get_string(
                'panel_week_line',
                $c,
                (object) ['added' => $added, 'n' => $tally[$w], 'what' => $word($tally[$w])]
            ) : get_string('panel_week_future', $c)) .
                ($compare ? ' ' . get_string('panel_week_before', $c, (object) ['name' => $previous['name'], 'n' => $prev[$w] ?? 0]) : '');
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
            $p = round(100 * ($at - $start) / ($total * $week), 1);
            // Near the right edge its stamp hangs to the left of its mark, so it never pushes the page sideways.
            $events[] = ['p' => $p, 'ahead' => $at > $now, 'end' => $p > 72,
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
        // The families of the sheet carry the day of their visit: they are in the week of it, and it is said.
        $undated = $enrolledview ? 0 : facts::sum($mine)['undated'];
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
                ($compare ? ' ' . get_string('panel_week_before', $c, (object) ['name' => $previous['name'],
                'n' => $prev[$current] ?? 0]) : ''),
            'def' => get_string($campaign['start'] ? 'panel_weeks_def' : 'panel_weeks_def_nostart', $c) . ($undated ? ' ' .
                get_string('panel_weeks_undated' . ($undated === 1 ? '_one' : ''), $c, $undated) : ''),
            'label' => get_string($show === 'matriculadas' ? 'panel_weeks_label_enrolled' : 'panel_weeks_label', $c),
            'what' => $what,
            'table' => $table,
        ];
    }

    /**
     * The same hour some days after, by the calendar of the centre: the day the hour changes has the same hour too.
     *
     * @param int $time
     * @param int $days
     * @return int
     */
    public static function days_after(int $time, int $days): int {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(\core_date::get_user_timezone_object())
            ->modify(($days < 0 ? '' : '+') . $days . ' days')->getTimestamp();
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
     * @param string $course
     * @param array[] $before The families of the campaign before, to see how those still on their way ended.
     * @param array|null $previous
     * @param array $campaign
     * @param int $elapsed
     * @param int $now
     * @return array
     */
    protected static function places(
        \stdClass $centre,
        string $course,
        array $before,
        ?array $previous,
        array $campaign,
        int $elapsed,
        int $now
    ): array {
        $c = self::C;
        $offers = centres::offers((int) $centre->id, true);
        if (!$offers) {
            return [];
        }
        $year = panel_inbox::campaign_school_year($centre, array_values(array_unique(array_map(
            fn($o) => (string) $o->schoolyear,
            $offers
        ))), $now);
        $counts = households::offer_counts((int) $centre->id);
        // How the families of the campaign before that were still on their way at this week ended.
        $rate = null;
        if ($before && $previous && $previous['start'] && $campaign['start']) {
            $by = $previous['start'] + $elapsed;
            $rate = [];
            foreach ($before as $f) {
                if ($f['first'] <= $by && !($f['enrolledat'] && $f['enrolledat'] <= $by)) {
                    foreach (array_unique(['', $f['course']]) as $key) {
                        $rate[$key]['n'] = ($rate[$key]['n'] ?? 0) + 1;
                        $rate[$key]['k'] = ($rate[$key]['k'] ?? 0) + ($f['enrolled'] ? 1 : 0);
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
                'free' => $places ? panel::free_of($free, $places) : '',
                'sentence' => $sentence,
                'rail' => $places ? panel::rail(
                    $places,
                    $taken,
                    $rows,
                    get_string('panel_places_rail', $c, (object) ['name' => format_string($offer->name), 'taken' => $taken,
                        'places' => $places, 'sentence' => $sentence]),
                    panel::hanging_object((string) $offer->name, $centre)
                ) : '',
                'bench' => $waiting ? ['n' => $waiting, 'bags' => array_fill(0, min(self::BENCH, $waiting), 1),
                    'text' => get_string($waiting === 1 ? 'panel_bench_one' : 'panel_bench', $c, $waiting)] : false,
                // The numbers of the rail, to point at it peg by peg. The flat rail of the desk in every skin: it reads
                // as bars and keeps the range; the cloakroom of the hall stays for the inbox.
                'taken' => $taken, 'places' => $places, 'low' => $rows ? $rows[0]['low'] : false,
                'mid' => $rows ? $rows[0]['mid'] : false, 'high' => $rows ? $rows[0]['high'] : false];
        }
        return $out;
    }

    /**
     * The courses of the year without places, named in one line with the way to the settings where they are put; false
     * when every course has them.
     *
     * @param array $places From places().
     * @param \stdClass $centre
     * @return array|false text, url (false without the right to change the settings), link
     */
    protected static function unplaced(array $places, \stdClass $centre) {
        $names = array_column(array_filter($places, fn($p) => !$p['places']), 'name');
        if (!$names) {
            return false;
        }
        // The way to put them, only for whoever may change the settings: Cifras is also read by who only looks.
        $manage = centres::can_manage((int) $centre->id);
        return [
            'text' => get_string('panel_places_unset', self::C, familypage::join($names, current_language())),
            'url' => $manage ? (new \moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id], 'aj-oferta'))
                ->out(false) : false,
            'link' => get_string('panel_places_unset_go', self::C),
        ];
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
     * the first honest number of what comes after the campaign. Of the school year that has begun, though there are
     * children enrolled for the next one.
     *
     * @param int $centreid
     * @param int $now
     * @return array|false text, def, url.
     */
    protected static function started(int $centreid, int $now) {
        global $DB;
        $years = $DB->get_fieldset_sql(
            'SELECT DISTINCT o.schoolyear
               FROM {' . households::CHILDREN . '} c
               JOIN {' . centres::OFFERS . '} o ON o.id = c.offerid AND o.centreid = c.centreid
              WHERE c.centreid = :centreid AND c.stage = :stage AND c.roster = 0',
            ['centreid' => $centreid, 'stage' => 'enrolled']
        );
        $begun = array_filter(array_map('strval', $years), fn($y) => $y !== '' && imports::year_start($y) <= $now + 30 * DAYSECS);
        $year = $begun ? max($begun) : settled::year($centreid);
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
     * Why the families that chose another centre did, as they told it on their page, added up and without names;
     * nothing when none told it.
     *
     * @param int $centreid
     * @param int $since The start of the campaign.
     * @return array|false text, reasons.
     */
    protected static function chose(int $centreid, int $since) {
        $told = whyother::counts($centreid, $since);
        if (!$told['told']) {
            return false;
        }
        $c = self::C;
        $reasons = [];
        foreach ($told['reasons'] as $key => $n) {
            $reasons[] = ['name' => panel::upper_first(get_string('whyother_' . $key, $c)), 'n' => $n];
        }
        return ['text' => $told['told'] === 1 ? get_string('panel_chose_sentence_one', $c) :
            get_string('panel_chose_sentence', $c, $told['told']), 'reasons' => $reasons];
    }

    /**
     * The school year under way at a moment: when it starts and ends, and its name.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array from, to and the name
     */
    public static function course_under_way(\stdClass $centre, int $now): array {
        [$from, $to] = reports::current($centre, 'cold', $now);
        return [$from, $to, self::course_name($from)];
    }

    /**
     * The name of a school year by the day it starts: «2026-27», or «2026» when it is a calendar year.
     *
     * @param int $from
     * @return string
     */
    public static function course_name(int $from): string {
        $year = (int) userdate($from, '%Y');
        return (int) userdate($from, '%m') >= 7 ? sprintf('%d-%02d', $year, ($year + 1) % 100) : (string) $year;
    }

    /**
     * The children who left the centre in a time, by why, as the team noted it, whichever school year they were enrolled for;
     * when nobody noted any, that it is not noted here.
     *
     * @param \stdClass $centre
     * @param int $from
     * @param int $to
     * @param string $year The name of that school year.
     * @return array text, reasons, def.
     */
    public static function gone(\stdClass $centre, int $from, int $to, string $year): array {
        global $DB;
        $rows = $DB->get_records_select(
            households::CHILDREN,
            'centreid = :centreid AND stage = :stage AND lefttime >= :from AND lefttime < :to',
            ['centreid' => $centre->id, 'stage' => 'enrolled', 'from' => max(1, $from), 'to' => $to],
            'id ASC',
            'id, leftreason'
        );
        $total = count($rows);
        $c = self::C;
        if (!$total) {
            return ['text' => get_string('panel_gone_none', $c, $year), 'reasons' => [], 'hasreasons' => false,
                'def' => get_string('panel_gone_def', $c)];
        }
        $counts = array_count_values(array_map(fn($r) => (string) ($r->leftreason ?: 'unknown'), $rows));
        $reasons = [];
        foreach ($counts as $reason => $n) {
            $reasons[] = ['name' => panel::upper_first(get_string('left_' . $reason, $c)), 'n' => $n];
        }
        usort($reasons, fn($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
        $key = $total === 1 ? 'panel_gone_sentence_one' : 'panel_gone_sentence';
        return ['text' => get_string($key, $c, (object) ['n' => $total, 'year' => $year]), 'reasons' => $reasons,
            'hasreasons' => true, 'def' => get_string('panel_gone_def', $c)];
    }

    /**
     * Of the new families enrolled in the campaign, how many came because another family of the centre told them, as they
     * said when signing up; no names and no link between households.
     *
     * @param array[] $families The families of the campaign.
     * @param string $year The school year it fills.
     * @return array|false text.
     */
    protected static function referred(array $families, string $year) {
        $enrolled = array_filter($families, fn($f) => $f['enrolled']);
        $told = count(array_filter($enrolled, fn($f) => ($f['heard'] ?? '') === 'centrefamilies'));
        if (!$told) {
            return false;
        }
        $key = $told === 1 ? 'panel_referred_sentence_one' : 'panel_referred_sentence';
        return ['text' => get_string($key . (count($enrolled) === 1 ? '_only' : ''), self::C, (object) ['n' => count($enrolled),
            'told' => $told, 'year' => $year])];
    }

    /**
     * The last school year as a ledger: it started with, some were new, some came during it, some left, it ended with.
     * Drawn only when the sum adds up.
     *
     * @param \stdClass $centre
     * @param string $schoolyear '' for the last one.
     * @return array|null
     */
    public static function year(\stdClass $centre, string $schoolyear = ''): ?array {
        $c = self::C;
        $years = metrics::school_years((int) $centre->id);
        if (!$years) {
            return null;
        }
        $schoolyear = in_array($schoolyear, $years, true) ? $schoolyear : (string) reset($years);
        $m = metrics::movements((int) $centre->id, $schoolyear);
        $t = $m['totals'];
        if ($t['start'] === null || $t['now'] === null) {
            return null;
        }
        // The roll of the first day already holds the new students: the ledger starts from those who were already of the
        // centre and adds the new ones on their own line, or, with no new ones written, starts from the whole roll.
        $new = (int) $t['new'];
        $base = (int) $t['start'] - $new;
        if ($base < 0 || $base + $new + $t['joins'] - $t['leaves'] !== $t['now']) {
            return null;
        }
        // One scale for all: the zero of the track is at 30 %, so those who left fit in the 30 % on its left.
        $most = max(1, $t['new'], $t['joins'], $t['leaves'] * 70 / 30);
        $rows = [];
        foreach (['new' => false, 'joins' => false, 'leaves' => true] as $key => $out) {
            $n = (int) $t[$key];
            if ($key === 'new' && !$n) {
                continue;
            }
            $rows[] = ['key' => $key, 'sign' => ($out ? '−' : '+') . $n, 'name' => get_string('panel_ledger_' . $key, $c),
                'n' => $n, 'v' => round($n / $most, 4), 'out' => $out, 'line' => get_string('panel_ledger_line_' . $key, $c, $n)];
        }
        $net = (int) $t['net'];
        return [
            'title' => get_string('panel_ledger_title', $c, $schoolyear),
            'start' => $base,
            'startlabel' => get_string($new ? 'panel_started_already' : 'panel_started_with', $c),
            'end' => $t['now'],
            'net' => ($net >= 0 ? '+' : '−') . abs($net),
            'rows' => $rows,
            'reasons' => array_map(fn($r) => ['name' => $r['reason'], 'n' => $r['n'] ?? $r['count'] ?? 0], $m['reasons']),
            'rest' => get_string($new ? 'panel_ledger_rest_new' : 'panel_ledger_rest', $c, (object) ['start' => $base,
                'end' => $t['now'],
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
    public static function history(\stdClass $centre): ?array {
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
     * @param \stdClass $centre
     * @return array|null
     */
    protected static function starts(array $campaigns, bool $canact, \stdClass $centre): ?array {
        if (!$canact) {
            return null;
        }
        $rows = [];
        foreach ($campaigns as $key => $campaign) {
            $value = $campaign['start'] ? date('Y-m-d', $campaign['start']) : '';
            // The day in three fields in the order of the centre.
            $rows[] = ['key' => $key, 'name' => $campaign['name'], 'value' => $value,
                'date' => panel::date_input('start[' . $key . ']', $value, $centre, 'inicio-' . $key)];
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
            if ($key === 'live') {
                // The campaign that ends keeps when it began: it is still there to look at and to set the new one against.
                $was = (int) ($centre->campaignstart ?? 0);
                if ($was && $time && abs($time - $was) > 90 * DAYSECS) {
                    $offered = facts::years((int) $centre->id);
                    $old = clone $centre;
                    facts::keep_start($centre, facts::year_at($old, $was + DAYSECS, $offered), $was);
                }
                $DB->set_field(centres::T, 'campaignstart', $time, ['id' => $centre->id]);
                $centre->campaignstart = $time;
                if ($time) {
                    facts::keep_start($centre, facts::year_at($centre, max($time + DAYSECS, time()), facts::years((int) $centre->id)), $time);
                }
            } else {
                facts::keep_start($centre, $campaign['year'], $time);
            }
        }
    }
}
