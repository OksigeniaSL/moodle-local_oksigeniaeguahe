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
 * The report to share (report_share): an A4 document, printed from the browser, for someone who does not know Eguahe (the
 * head of the centre, the agency that runs its social networks, the school board). It wears the logo and the colours of the
 * centre, carries no name of a family, only figures; every figure says in plain words what it counts, and what is not known
 * is written where it is missing. Each family is a mark: full, it did it; empty, it did not; a small pencil dot, nobody noted
 * it. Made with the same numbers as Numbers (act_report), never others.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sharereport {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int From how many marks a line of the path is a bar instead. */
    const BARS = 400;

    /**
     * A string of the report.
     *
     * @param string $id Without its prefix.
     * @param mixed $a
     * @return string
     */
    protected static function s(string $id, $a = null): string {
        return get_string('share_' . $id, self::C, $a);
    }

    /**
     * The report of one act: the open day or the other act, with what happened to its families since.
     *
     * @param \stdClass $centre
     * @param string $key Of act_report::all().
     * @param int $now
     * @return array|null The context of report_share; null, there is no such act.
     */
    public static function act(\stdClass $centre, string $key, int $now = 0): ?array {
        $now = $now ?: time();
        $acts = act_report::all($centre, $now, true);
        $act = $acts[$key] ?? null;
        if (!$act) {
            return null;
        }
        // The families of its campaign are worked out once, from how they are now: first what this report needs, then the report
        // of Numbers, which finds them done (and forgets them when it ends).
        act_report::forget();
        $people = act_report::people($centre, $act, $now);
        $lists = act_report::lists($centre, $act, $now, $people);
        $days = max(1, panel::days_between($act['time'], $now));
        $early = [];
        foreach ($acts as $other => $one) {
            if ($other !== $key && $one['talk'] === $act['talk'] && $one['time'] <= $now && count($early) < act_report::OTHERS) {
                $early[$other] = self::early($centre, $one, $days, $now);
            }
        }
        $report = act_report::build($centre, $key, $now);
        $rows = $people['lists'];
        $past = $act['time'] <= $now;
        $openday = !$act['talk'];
        $signed = count($rows['signed']);
        $came = count($rows['came']);
        $walkin = count($rows['walkin']);
        $sheetcame = count($rows['sheetcame'] ?? []);
        $allcame = $came + $walkin + $sheetcame;
        $persons = array_sum(array_map(fn($r) => $r['state'] === 'came' ? (int) $r['people'] : 0, $rows['all']));
        $mine = array_values(array_filter($lists['mine']));
        $applied = count(array_filter($lists['applied']));
        $enrolled = count(array_filter($lists['enrolled']));

        $when = [panel::upper_first(trim(userdate($act['time'], get_string('strftimedaydate', 'langconfig'))))];
        if ($report['hours'] !== '') {
            $when[] = $report['hours'];
        }
        if ($report['place'] !== '') {
            $when[] = $report['place'];
        }
        $out = self::frame(
            $centre,
            $openday ? self::s('kind_openday') : self::s('kind_act'),
            $act['name'],
            $act['name'] . ' · ' . userdate($act['time'], get_string('strftimedate', 'langconfig')),
            $now
        );
        $out['when'] = implode(' · ', $when);
        $out['question'] = $openday ? self::s('question_openday') : self::s('question_act');
        $out['answer'] = self::answer($past, $signed, $came, $walkin + $sheetcame, count($rows['unknown']), $applied, $enrolled);

        // The cover: every family signed up, as a mark.
        $out['field'] = $signed ? self::field(
            [['yes', $came], ['no', count($rows['notcame'])], ['unknown', count($rows['unknown'])]],
            $past ? array_values(array_filter([
                $came ? ['k' => 'yes', 'n' => $came, 'text' => self::s('came')] : null,
                count($rows['notcame']) ? ['k' => 'no', 'n' => count($rows['notcame']), 'text' => self::s('notcame')] : null,
                count($rows['unknown']) ? ['k' => 'unknown', 'n' => count($rows['unknown']), 'text' => self::s('unknowncame')] : null,
            ])) : [],
            self::s('field_signed') . ($walkin + $sheetcame ? ' ' . self::s('field_more', $walkin + $sheetcame) : '')
        ) : false;
        $figures = [['n' => (string) $signed, 'what' => self::s('fig_signed'), 'def' => self::s('fig_signed_def')]];
        if ($past) {
            $figures[] = ['n' => (string) $allcame, 'what' => self::s('fig_came'), 'def' => $walkin + $sheetcame ?
                self::s('fig_came_def_more', (object) ['signed' => $came, 'more' => $walkin + $sheetcame]) : self::s('fig_came_def')];
            if ($persons) {
                $figures[] = ['n' => (string) $persons, 'what' => self::s('fig_people'), 'def' => self::s('fig_people_def')];
            }
            $figures[] = ['n' => (string) $applied, 'what' => self::s('fig_applied'), 'def' => $enrolled ?
                self::s($enrolled === 1 ? 'fig_applied_def_one' : 'fig_applied_def_more', $enrolled) : self::s('fig_applied_def')];
        }
        $out['figures'] = $figures;

        $sections = [];
        $n = 0;
        // 1 · From the poster to the place.
        if ($mine) {
            $total = count($mine);
            $has = fn(string $list) => count(array_intersect($mine, $lists[$list]));
            $steps = [self::step(self::s('path_signed'), $total, [['yes', $total]], [], self::s('path_signed_said', $total))];
            if ($past) {
                $yes = $has('minecame');
                $no = $has('minenotcame');
                $unknown = $has('mineunknown');
                $steps[] = self::step(
                    self::s('path_came'),
                    $total,
                    [['yes', $yes], ['no', $no], ['unknown', $unknown]],
                    array_values(array_filter([['k' => 'yes', 'n' => $yes, 'text' => self::s('came')],
                        $no ? ['k' => 'no', 'n' => $no, 'text' => self::s('notcame')] : null,
                        $unknown ? ['k' => 'unknown', 'n' => $unknown, 'text' => self::s('unknownshort')] : null])),
                    self::s('path_came_said', (object) ['n' => $total, 'yes' => $yes, 'no' => $no, 'unknown' => $unknown])
                );
                foreach (['after' => 'after', 'applied' => 'applied', 'enrolled' => 'enrolled'] as $list => $word) {
                    $k = $has($list);
                    $steps[] = self::step(self::s('path_' . $word), $total, [['yes', $k]], [], self::s(
                        'path_said',
                        (object) ['n' => $k, 'of' => $total, 'what' => self::s('path_' . $word . '_said')]
                    ));
                }
            }
            $apart = [];
            foreach ($report['apart'] ?: [] as $one) {
                $apart[] = ['n' => (string) $one['n'], 'text' => \core_text::strtolower(\core_text::substr($one['name'], 0, 1)) .
                    \core_text::substr($one['name'], 1)];
            }
            if ($walkin) {
                $apart[] = ['n' => (string) $walkin, 'text' => self::s('apart_walkin')];
            }
            if ($sheetcame) {
                $apart[] = ['n' => (string) $sheetcame, 'text' => self::s('apart_sheet')];
            }
            $unknown = $past ? $has('mineunknown') : 0;
            $sections[] = self::section(++$n, 'camino', self::s('path_title'), [
                'wide' => true,
                'lead' => self::s('path_lead', $total),
                'defs' => self::texts([self::s('path_def_came'), self::s('path_def_after'), self::s('path_def_applied')]),
                'notes' => self::texts([$unknown ? self::s($unknown === 1 ? 'note_unknowncame_one' : 'note_unknowncame', $unknown) : '']),
                'path' => ['bars' => $total > self::BARS, 'apartword' => self::s('apartword'), 'rows' => $steps, 'apart' => $apart],
            ]);
        }
        // 2 · Who they were.
        if ($report['who']) {
            $kinds = array_map(fn($k) => ['name' => $k['name'], 'n' => (int) $k['n']], $report['who']['kinds'] ?? []);
            $courses = [];
            foreach ($report['who']['stages'] ?? [] as $stage) {
                foreach ($stage['courses'] ?? [] as $course) {
                    $courses[] = ['name' => $course['name'], 'n' => (int) $course['n']];
                }
            }
            $nokids = (int) ($report['who']['nokids']['n'] ?? 0);
            if ($nokids) {
                $courses[] = ['name' => self::s('who_nocourse'), 'n' => $nokids, 'unknown' => true];
            }
            $groups = array_values(array_filter([
                $kinds ? ['title' => self::s('who_families'), 'rows' => self::bars($kinds)] : null,
                $courses ? ['title' => self::s('who_courses'), 'rows' => self::bars($courses)] : null,
            ]));
            if ($groups) {
                $inscribed = array_sum(array_map(fn($r) => (int) $r['people'], $rows['signed']));
                $families = array_sum(array_column($kinds, 'n'));
                $sections[] = self::section(++$n, 'quienes', self::s('who_title'), [
                    'keep' => true,
                    'lead' => $inscribed ? self::s($past ? 'who_lead_came' : 'who_lead', (object) ['n' => $families, 'people' => $inscribed,
                        'came' => $persons]) : self::s('who_lead_families', $families),
                    'defs' => self::texts([self::s('who_def')]),
                    'notes' => self::texts([$nokids ? self::s($nokids === 1 ? 'note_nocourse_one' : 'note_nocourse', $nokids) : '']),
                    'groups' => ['cols' => count($groups), 'groups' => $groups],
                ]);
            }
        }
        // 3 · By where they came: the centre's own work against the ads, each channel over its own families.
        if ($mine) {
            $sections[] = self::section(++$n, 'canales', self::s('ways_title'), self::ways(
                $centre,
                $people,
                $lists,
                $mine,
                $report['spend'],
                $act,
                $past
            ));
        }
        // 4 · What happened since.
        if ($past && $mine) {
            $ledger = [];
            $since = [];
            foreach ($report['since']['rows'] ?? [] as $row) {
                $since[$row['key']] = (int) $row['n'];
            }
            $days = max(0, panel::days_between($act['time'], $now));
            foreach (['answered', 'tried', 'appointment', 'waiting'] as $what) {
                if (!empty($since[$what])) {
                    $ledger[] = ['n' => (string) $since[$what], 'text' => self::s('since_' . $what . ($since[$what] === 1 ? '_one' : '')),
                        'strong' => false,
                        'sub' => $what === 'waiting' ? (string) ($report['since']['longest'] ?? '') : ''];
                }
            }
            if ($applied) {
                $ledger[] = ['n' => (string) $applied, 'text' => self::s('since_applied' . ($applied === 1 ? '_one' : '')), 'strong' => true,
                    'sub' => ''];
            }
            if ($enrolled) {
                $ledger[] = ['n' => (string) $enrolled, 'text' => self::s('since_enrolled' . ($enrolled === 1 ? '_one' : '')),
                    'strong' => true, 'sub' => ''];
            }
            if (!empty($since['gone'])) {
                $why = array_map(fn($r) => $r['n'] . ' ' . \core_text::strtolower($r['name']), $report['since']['reasons'] ?? []);
                $ledger[] = ['n' => (string) $since['gone'], 'text' => self::s('since_gone' . ($since['gone'] === 1 ? '_one' : '')),
                    'strong' => false,
                    'sub' => $why ? self::s('since_gone_why', implode(', ', $why)) : ''];
            }
            if (!empty($since['nothing'])) {
                // Those that have done nothing yet, at the end: the path is read first.
                $ledger[] = ['n' => (string) $since['nothing'], 'text' => self::s('since_nothing' . ($since['nothing'] === 1 ? '_one' : '')),
                    'strong' => false, 'sub' => ''];
            }
            if ($ledger) {
                $sections[] = self::section(++$n, 'desde', self::s('since_title'), [
                    'keep' => true,
                    'lead' => self::s($days === 1 ? 'since_lead_one' : 'since_lead', (object) ['days' => $days, 'n' => count($mine)]),
                    'defs' => self::texts([self::s('since_def')]),
                    'ledger' => ['rows' => $ledger],
                ]);
            }
        }
        // 5 · What they said: the survey of after the act.
        if ($report['said'] && !empty($report['said']['survey'])) {
            $sections[] = self::section(++$n, 'dijeron', self::s('said_title'), self::said($report['said'], $allcame));
        }
        // 6 · Against the other acts of its kind.
        if ($report['others']) {
            $sections[] = self::section(
                ++$n,
                'otras',
                self::s($openday ? 'others_title' : 'others_title_act'),
                self::others($act, $report['others']['rows'], $early, $days, $openday)
            );
        }
        $out['sections'] = $sections;
        $out['sources'] = self::sources($report['sources']);
        return $out;
    }

    /**
     * How it went, in a sentence for someone who was not there: who came, and what has happened since.
     *
     * @param bool $past
     * @param int $signed
     * @param int $came Of those who signed up.
     * @param int $more Who came without signing up.
     * @param int $unknown Of those who signed up, nobody noted whether they came.
     * @param int $applied
     * @param int $enrolled
     * @return string
     */
    public static function answer(bool $past, int $signed, int $came, int $more, int $unknown, int $applied, int $enrolled): string {
        if (!$past) {
            return self::s($signed === 1 ? 'answer_ahead_one' : 'answer_ahead', $signed);
        }
        if (!$signed) {
            $first = $more ? self::s($more === 1 ? 'answer_onlymore_one' : 'answer_onlymore', $more) : self::s('answer_nobody');
        } else if (!$came && $unknown === $signed) {
            $first = self::s('answer_nonoted', $signed);
        } else {
            $first = self::s('answer_came', (object) ['came' => $came, 'signed' => $signed]) .
                ($more ? self::s($more === 1 ? 'answer_more_one' : 'answer_more', $more) : '') . '.';
        }
        if (!$applied) {
            $then = self::s('answer_applied_none');
        } else if ($applied === 1) {
            $then = self::s($enrolled ? 'answer_applied_one_enrolled' : 'answer_applied_one');
        } else {
            $then = self::s(
                $enrolled ? ($enrolled === 1 ? 'answer_applied_enrolled_one' : 'answer_applied_enrolled') : 'answer_applied',
                (object) ['applied' => $applied, 'enrolled' => $enrolled]
            );
        }
        return $first . ' ' . $then;
    }

    /**
     * What every report has: the document, the centre with its colours and logo, how the marks read and the signature.
     *
     * @param \stdClass $centre
     * @param string $kind The word over the title.
     * @param string $subject
     * @param string $running What it is, for the head of each page.
     * @param int $now
     * @return array
     */
    protected static function frame(\stdClass $centre, string $kind, string $subject, string $running, int $now): array {
        global $CFG;
        $lang = current_language();
        $name = format_string($centre->name);
        $colour = centres::colour($centre);
        $brand = centres::brand($centre) ?: [$colour];
        $vars = publicpage::centre_vars($colour, false) + publicpage::brand_vars($brand, $colour, false);
        $count = count($brand);
        $vars['--franja'] = 'linear-gradient(to right, ' . implode(', ', array_map(fn($c, $i) => $c . ' ' .
            round($i * 100 / $count, 2) . '% ' . round(($i + 1) * 100 / $count, 2) . '%', $brand, array_keys($brand))) . ')';
        $css = fn(string $s) => '"' . addcslashes(str_replace(["\r", "\n"], ' ', $s), '"\\') . '"';
        $vars['--cab-centro'] = $css($name);
        $vars['--cab-informe'] = $css($running);
        $vars['--pie'] = $css(self::s('made_line') . ' · eguahe.com');
        $logo = centres::logo((int) $centre->id);
        $aspect = $logo ? (centres::logo_aspect((int) $centre->id) ?? 1) : 1;
        $version = (int) get_config(self::C, 'version');
        $pub = $CFG->wwwroot . '/local/oksigeniaeguahe/pub/';
        return [
            'lang' => $lang,
            'dir' => lists::dir($lang),
            'doctitle' => $name . ' · ' . $running,
            'css' => $pub . 'compartir.css?v=' . $version,
            'vars' => implode(' ', array_map(fn($k, $v) => "$k: $v;", array_keys($vars), $vars)),
            'centre' => ['name' => $name, 'logo' => $logo ? ['url' => $logo->out(false), 'alt' => $name,
                'style' => 'height: ' . number_format(min(20, sqrt(1500 / max(0.1, $aspect))), 1, '.', '') . 'mm'] : false,
                'brand' => array_map(fn($c) => ['code' => $c], $brand)],
            'kind' => $kind,
            'subject' => $subject,
            'asof' => self::s('asof', userdate($now, get_string('strftimedate', 'langconfig'))),
            'reading' => ['title' => self::s('reading'), 'items' => [['k' => 'yes', 'text' => self::s('reading_yes')],
                ['k' => 'no', 'text' => self::s('reading_no')], ['k' => 'unknown', 'text' => self::s('reading_unknown')]]],
            'howcounted' => self::s('howcounted'),
            'made' => ['about' => self::s('made_about'), 'line' => self::s('made_line'), 'site' => 'eguahe.com',
                'url' => 'https://eguahe.com/?de=informe&lang=' . rawurlencode(substr($lang, 0, 2)), 'logo' => $pub . 'img/eguahe-logotipo.svg'],
        ];
    }

    /**
     * Families as marks: the full ones first, then the empty ones, then the pencil dots.
     *
     * @param array $parts [kind, how many] in order.
     * @return array[]
     */
    protected static function dots(array $parts): array {
        $out = [];
        foreach ($parts as [$k, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $out[] = ['k' => $k];
            }
        }
        return $out;
    }

    /**
     * The marks of the cover: as big as an area of 160 × 30 mm allows (from 3 to 8 mm a mark), in lines as even as can be.
     *
     * @param array $parts [kind, how many].
     * @param array $legend
     * @param string $caption
     * @return array per, paso, dots, legend and caption
     */
    protected static function field(array $parts, array $legend, string $caption): array {
        $total = max(1, array_sum(array_column($parts, 1)));
        $paso = max(3, min(8, sqrt(4800 / $total)));
        $max = (int) floor(160 / $paso);
        return ['per' => (int) ceil($total / ceil($total / $max)), 'paso' => number_format($paso, 2, '.', '') . 'mm',
            'dots' => self::dots($parts), 'legend' => $legend, 'caption' => $caption];
    }

    /**
     * A step of the path: always over the same families, the marks of «did it» first and the rest empty.
     *
     * @param string $name
     * @param int $total
     * @param array $parts [kind, how many].
     * @param array $legend
     * @param string $said The step in words, for whoever does not see the marks.
     * @return array
     */
    protected static function step(string $name, int $total, array $parts, array $legend, string $said): array {
        $full = array_sum(array_column($parts, 1));
        if ($full < $total) {
            $parts[] = ['no', $total - $full];
        }
        $max = $total <= 60 ? 26 : 50;
        $parts = array_values(array_filter($parts, fn($p) => $p[1] > 0));
        return ['name' => $name, 'n' => (string) ($parts && $parts[0][0] === 'yes' ? $parts[0][1] : 0),
            'of' => self::s('of', $total), 'per' => (int) ceil($total / max(1, ceil($total / $max))),
            'dots' => $total > self::BARS ? [] : self::dots($parts),
            'parts' => array_map(fn($p) => ['k' => $p[0], 'v' => round($p[1] / max(1, $total), 4)], $parts),
            'legend' => $legend, 'said' => $said];
    }

    /**
     * Bars over the greatest of their group.
     *
     * @param array $rows name, n and, if so, unknown.
     * @return array
     */
    protected static function bars(array $rows): array {
        $max = max(1, ...array_map(fn($r) => (int) $r['n'], $rows));
        return array_map(fn($r) => $r + ['part' => '', 'unknown' => false, 'v' => round((int) $r['n'] / $max, 4)], $rows);
    }

    /**
     * Texts as the template takes them, without the empty ones.
     *
     * @param string[] $texts
     * @return array
     */
    protected static function texts(array $texts): array {
        return array_values(array_map(fn($t) => ['text' => $t], array_filter($texts, fn($t) => $t !== '')));
    }

    /**
     * A section, with every shape it does not use off.
     *
     * @param int $num
     * @param string $id
     * @param string $title
     * @param array $more
     * @return array
     */
    protected static function section(int $num, string $id, string $title, array $more): array {
        return $more + ['num' => (string) $num, 'id' => $id, 'title' => $title, 'keep' => false, 'wide' => false, 'lead' => '',
            'defs' => [], 'notes' => [], 'path' => false, 'groups' => false, 'versus' => false, 'channels' => false,
            'ledger' => false, 'survey' => false, 'weeks' => false, 'places' => false, 'figures' => false, 'table' => false];
    }

    /**
     * By where the families came: the centre's own ways against the ads (with what they cost for each family that came and for
     * each that asked for a place), and each channel measured over its own families.
     *
     * @param \stdClass $centre
     * @param array $people Of act_report::people().
     * @param array $lists Of act_report::lists().
     * @param int[] $mine The families of the campaign of the act.
     * @param array $spend Of act_report::build().
     * @param array $act
     * @param bool $past
     * @return array The parts of the section.
     */
    protected static function ways(
        \stdClass $centre,
        array $people,
        array $lists,
        array $mine,
        array $spend,
        array $act,
        bool $past
    ): array {
        $came = array_flip(array_filter($lists['minecame']));
        $applied = array_flip(array_filter($lists['applied']));
        $enrolled = array_flip(array_filter($lists['enrolled']));
        $blocks = [];
        foreach ($mine as $id) {
            $channel = (string) ($people['facts'][$id]['channel'] ?? 'unmeasured');
            $block = arrival::block($channel);
            $blocks[$block][$channel][] = $id;
        }
        $count = function (array $ids) use ($came, $applied, $enrolled): array {
            return ['n' => count($ids), 'came' => count(array_intersect_key(array_flip($ids), $came)),
                'applied' => count(array_intersect_key(array_flip($ids), $applied)),
                'enrolled' => count(array_intersect_key(array_flip($ids), $enrolled))];
        };
        $sum = fn(string $block) => $count(array_merge([], ...array_values($blocks[$block] ?? [])));
        $own = $sum('own');
        $paid = $sum('paid');
        // What was noted for the ads of this act (its card, or the files of its day), as Numbers counts it.
        $money = $spend['known'] ? (float) ($spend['amount'] ?? 0) : null;
        $amount = $spend['known'] ? $spend['text'] : '';
        $each = fn(int $n) => $money && $n ? centres::money($centre, $money / $n) : '';
        $side = function (string $block, array $c, $money) use ($past): array {
            $lines = [['n' => (string) $c['n'], 'text' => self::s('ways_signed')]];
            if ($past) {
                foreach (['came', 'applied', 'enrolled'] as $what) {
                    $lines[] = ['n' => (string) $c[$what], 'text' => self::s('ways_' . $what . ($c[$what] === 1 ? '_one' : ''))];
                }
            }
            return ['name' => self::s('block_' . $block), 'sub' => self::s('block_' . $block . '_sub'), 'n' => (string) $c['n'],
                'what' => self::s('ways_families'), 'lines' => array_slice($lines, 1), 'money' => $money];
        };
        $hours = (string) ($spend['teamhours'] ?? '');
        $sides = [$side('own', $own, ['amount' => self::s('ways_nomoney'), 'lines' => $hours !== '' ?
            [['n' => $hours, 'text' => self::s('ways_team')]] : []])];
        $sides[] = $side('paid', $paid, $money ? ['amount' => $amount, 'lines' => array_values(array_filter([
            $past && $paid['came'] ? ['n' => $each($paid['came']), 'text' => self::s('ways_each_came')] : null,
            $past && $paid['applied'] ? ['n' => $each($paid['applied']), 'text' => self::s('ways_each_applied')] : null]))] :
            ['amount' => self::s('ways_unnoted'), 'lines' => []]);

        // Each channel, over its own families.
        $cols = $past ? ['families', 'came', 'applied', 'enrolled'] : ['families'];
        $cell = fn(int $n, int $of, bool $bar) => ['n' => (string) $n, 'of' => $bar && $of ? self::s('of', $of) : '',
            'v' => $bar && $of ? round($n / $of, 4) : 0, 'nobar' => !$bar || !$of];
        $line = fn(array $c, bool $bar) => array_map(fn($w) => $w === 'families' ? $cell($c['n'], 0, false) :
            $cell($c[$w], $c['n'], $bar), $cols);
        $tableblocks = [];
        foreach (['own', 'paid', 'other', 'unmeasured'] as $block) {
            if (empty($blocks[$block])) {
                continue;
            }
            $rows = [];
            uasort($blocks[$block], fn($a, $b) => count($b) <=> count($a));
            foreach ($blocks[$block] as $channel => $ids) {
                $rows[] = ['name' => arrival::channel_name((string) $channel), 'cells' => $line($count($ids), true)];
            }
            // A block of one channel called as the block itself («Paid ads») says it in its sum: no row repeating it.
            $alone = count($rows) === 1 && \core_text::strtolower($rows[0]['name']) === \core_text::strtolower(self::s('block_' . $block));
            $tableblocks[] = ['name' => self::s('block_' . $block), 'cells' => $line($sum($block), false),
                'rows' => $block === 'unmeasured' || $alone ? [] : $rows, 'money' => ''];
        }
        $unmeasured = $sum('unmeasured')['n'];
        $lead = self::s('ways_lead', (object) ['own' => $own['n'], 'paid' => $paid['n']]);
        if ($money && $past && $paid['came']) {
            $lead .= ' ' . self::s('ways_lead_each', $each($paid['came']));
        }
        return [
            'wide' => true,
            'lead' => $lead,
            'defs' => self::texts([self::s('ways_def_first'), self::s('ways_def_own'), $money ? self::s('ways_def_money') : '']),
            'notes' => self::texts([$unmeasured ? self::s($unmeasured === 1 ? 'note_unmeasured_one' : 'note_unmeasured', $unmeasured) : '',
                $money ? '' : self::s('note_nomoney')]),
            'versus' => ['sides' => $sides],
            'channels' => ['label' => self::s('channels_label'), 'first' => self::s('channels_first'),
                'cols' => array_map(fn($w) => ['name' => self::s('col_' . $w)], $cols), 'blocks' => $tableblocks],
        ];
    }

    /**
     * What they said in the survey of after the act: its first scale, and a few of their words, never their names.
     *
     * @param array $said Of act_report::build().
     * @param int $came
     * @return array The parts of the section.
     */
    protected static function said(array $said, int $came): array {
        $scale = false;
        $quotes = [];
        foreach ($said['survey'] as $question) {
            $rows = array_values(array_filter(array_map(fn($r) => ['name' => (string) ($r['label'] ?? $r['name'] ?? ''),
                'n' => (int) ($r['n'] ?? 0)], (array) ($question['rows'] ?? [])), fn($r) => $r['name'] !== ''));
            if (!$scale && $rows) {
                $scale = ['said' => (string) $question['label'], 'rows' => self::bars($rows)];
            }
            foreach ($question['quotes'] ?? [] as $quote) {
                $text = trim((string) $quote['text']);
                if ($text !== '' && count($quotes) < 3 && \core_text::strlen($text) <= 160) {
                    $quotes[] = ['text' => $text];
                }
            }
        }
        $told = (int) ($said['told'] ?? 0);
        return [
            'keep' => true,
            'lead' => self::s('said_lead', (object) ['n' => $told, 'came' => $came]),
            'defs' => self::texts([self::s('said_def')]),
            'survey' => ['scale' => $scale, 'quotes' => $quotes, 'asked' => false],
        ];
    }

    /**
     * This act against the others of its kind; those before also with what they had asked for in their first days, as many
     * days as this one has had, to set them side by side at the same point.
     *
     * @param array $act
     * @param array $rows Of act_report::build(), others.
     * @param array $early Act key => how many asked for a place in their first days (null, not known).
     * @param int $days How many days this one has had.
     * @param bool $openday
     * @return array The parts of the section.
     */
    protected static function others(array $act, array $rows, array $early, int $days, bool $openday): array {
        $cols = ['signed', 'came', 'applied', 'enrolled'];
        $max = [];
        foreach ($cols as $col) {
            $max[$col] = max(1, ...array_map(fn($r) => (int) ($r[$col] ?? 0), $rows));
        }
        $notes = [];
        $table = [];
        foreach ($rows as $row) {
            $first = $row['current'] ? null : ($early[$row['key']] ?? null);
            $cells = [];
            foreach ($cols as $col) {
                $value = $row[$col];
                $sub = '';
                if ($col === 'came' && $row['current'] && $row['unknown']) {
                    $sub = self::s('table_unknown', $row['unknown']);
                } else if ($col === 'applied' && $row['current'] && $value !== null) {
                    $sub = self::s($days === 1 ? 'table_days_one' : 'table_days', $days);
                } else if ($col === 'applied' && $first !== null) {
                    $sub = self::s($days === 1 ? 'table_early_one' : 'table_early', (object) ['n' => $first, 'days' => $days]);
                }
                $cells[] = $value === null ? ['missing' => self::s('table_missing'), 'sub' => '', 'n' => '', 'v' => 0] :
                    ['missing' => '', 'sub' => $sub, 'n' => (string) $value, 'v' => round((int) $value / $max[$col], 4)];
            }
            if (!$row['current'] && $row['missing'] !== '') {
                $notes[] = $row['when'] . ': ' . $row['missing'];
            }
            $table[] = ['name' => $row['name'], 'when' => $row['when'] . ($row['current'] ? ' · ' . self::s('table_this') : ''),
                'tag' => $row['imported'] ? self::s('table_file') : '', 'current' => (bool) $row['current'], 'cells' => $cells,
                'count' => count($cols)];
        }
        return [
            'keep' => true,
            'lead' => self::s($openday ? 'others_lead' : 'others_lead_act'),
            'defs' => self::texts([self::s('others_def')]),
            'notes' => self::texts($notes),
            'table' => ['label' => self::s($openday ? 'others_title' : 'others_title_act'), 'first' => self::s($openday ? 'col_openday' : 'col_act'),
                'count' => count($cols), 'cols' => array_map(fn($c) => ['name' => self::s('col_' . ($c === 'signed' ? 'signed' : $c))], $cols),
                'rows' => $table, 'foot' => self::s($days === 1 ? 'others_foot_one' : 'others_foot', $days)],
        ];
    }

    /**
     * How many of the families of an act of Eguahe asked for a place in its first days.
     *
     * @param \stdClass $centre
     * @param array|null $act Of act_report::all().
     * @param int $days
     * @param int $now
     * @return int|null Null when it is not known.
     */
    public static function early(\stdClass $centre, ?array $act, int $days, int $now): ?int {
        if (!$act || !$act['event']) {
            return null;
        }
        $people = act_report::people($centre, $act, $now);
        $lists = act_report::lists($centre, $act, $now, $people);
        $start = $act['time'];
        $until = $start + $days * DAYSECS;
        $n = 0;
        foreach (array_filter($lists['applied']) as $id) {
            $kids = $people['facts'][$id]['kids'] ?? [];
            foreach ($kids as $kid) {
                $stage = $kid['stage'] === 'closed' ? ($kid['far'] ?? '') : $kid['stage'];
                if (
                    !empty($kid['mine']) && in_array($stage, array_merge(facts::APPLIED, ['enrolled']), true) &&
                        $kid['stagetime'] >= $start && $kid['stagetime'] < $until
                ) {
                    $n++;
                    break;
                }
            }
        }
        return $n;
    }

    /**
     * Where the figures come from, each with its day and what it brings.
     *
     * @param array $sources Of act_report::build().
     * @return array
     */
    protected static function sources(array $sources): array {
        return [
            'title' => self::s('sources_title'),
            'rows' => array_map(fn($s) => ['name' => (string) $s['name'], 'when' => (string) ($s['date'] ?? '') !== '' ? (string) $s['date'] :
                self::s('sources_today'), 'what' => (string) $s['what'] . (!empty($s['families']) ? ' · ' .
                self::s('sources_families', (int) $s['families']) : '')], $sources),
            'method' => self::texts([self::s('method_once'), self::s('method_centre'), self::s('method_unknown'), self::s('method_few')]),
            'privacy' => self::s('privacy'),
        ];
    }
}
