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
 * «What they like most», in «Cifras» (Jujol's sheets): what the families do with what Eguahe gives them, who they are and
 * what the adults like doing, from the same families as the rest of the page. What is looked at again weighs most: one
 * stamp for each return of each family. Only counts, never a family; for one family, its file tells it as facts.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_likes {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int With fewer families that opened their page, it cannot be said yet what they like most. */
    const FEW = 5;

    /** @var int A thing touched by fewer families does not go into the sentence of what is looked at again. */
    const AGAIN_FAMILIES = 5;

    /** @var int[][] How many times each family opens its page, in five steps. */
    const BUCKETS = [[1, 1], [2, 3], [4, 6], [7, 10], [11, PHP_INT_MAX]];

    /** @var int The places drawn one by one; the rest go together. */
    const PLACES = 5;

    /** @var int The stamps drawn at most in a row; the figure beside them says the rest. */
    const MARKS = 6;

    /** @var int The courses drawn. */
    const COURSES = 8;

    /**
     * The sheets, for the campaign under way; an imported one has none.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param array $mine The families of the page, with their id.
     * @param int $now
     * @return array|false
     */
    public static function context(\stdClass $centre, array $campaign, array $mine, int $now) {
        if ($campaign['import']) {
            return false;
        }
        $c = self::C;
        $ids = array_values(array_filter(array_map(fn($f) => (int) ($f['id'] ?? 0), array_filter(
            $mine,
            fn($f) => !$f['closed']
        ))));
        $rows = seen::of($ids);
        $c4 = self::count_rows($rows);
        $opened = count($c4['opens']);
        $start = (int) ($campaign['start'] ?: ($rows ? $rows[0]->timecreated : 0));
        $day = get_string('strftimedate', 'langconfig');
        $cut = $start ? get_string('panel_likes_cut', $c, (object) ['from' => userdate($start, $day),
            'to' => userdate($now, $day)]) : get_string('panel_likes_cut_today', $c, userdate($now, $day));
        $pages = self::pages($c4, count($ids));
        $hobbies = self::hobbies($ids);
        $out = [
            'title' => get_string('panel_likes_title', $c),
            'campaign' => ['name' => $campaign['name']],
            'cut' => $cut,
            'def' => get_string('panel_likes_def', $c),
            'empty' => $opened < self::FEW,
            'emptytext' => get_string('panel_likes_empty', $c),
            'pages' => $pages,
        ] + $hobbies;
        if ($out['empty']) {
            return $out;
        }
        [$touches, $rest] = self::touches($c4);
        [$mails, $mailsrest] = self::mails($c4);
        [$origins, $originsrest] = self::origins($ids);
        return $out + [
            'touches' => $touches,
            'touchesrest' => $rest,
            'touchesdef' => get_string('panel_likes_touches_def', $c),
            'drawing' => self::drawing($ids, $c4),
            'mails' => $mails,
            'mailsrest' => $mailsrest,
            'mailsdef' => get_string('panel_likes_mails_def', $c),
            'howitwent' => self::howitwent($ids),
            'firstday' => self::firstday($centre, $ids, $c4),
            'origins' => $origins,
            'originsrest' => $originsrest,
            'originsdef' => get_string('panel_likes_origins_def', $c),
            'courses' => self::courses($centre, $ids),
            'coursesdef' => get_string('panel_likes_courses_def', $c),
        ];
    }

    /**
     * What was noted, by household and by thing.
     *
     * @param \stdClass[] $rows
     * @return array opens (household => times), days (household => days), parts (part => households), touches (what =>
     *               [times, households]), sent (kind => mails) and clicks (kind => [times, households]).
     */
    protected static function count_rows(array $rows): array {
        $out = ['opens' => [], 'days' => [], 'parts' => [], 'touches' => [], 'sent' => [], 'clicks' => [], 'story' => [],
            'storypage' => []];
        foreach ($rows as $row) {
            $h = (int) $row->householdid;
            if ($row->kind === 'open') {
                $out['opens'][$h] = ($out['opens'][$h] ?? 0) + 1;
                $out['days'][$h][seen::day((int) $row->timecreated)] = true;
            } else if ($row->kind === 'part') {
                $out['parts'][$row->what][$h] = true;
            } else if ($row->kind === 'touch') {
                $out['touches'][$row->what]['times'] = ($out['touches'][$row->what]['times'] ?? 0) + 1;
                $out['touches'][$row->what]['h'][$h] = true;
            } else if ($row->kind === 'mailsent') {
                $out['sent'][$row->what] = ($out['sent'][$row->what] ?? 0) + 1;
            } else if ($row->kind === 'story') {
                $out['story'][$row->what][$h] = ($out['story'][$row->what][$h] ?? 0) + 1;
                if ($row->what === 'page' && preg_match('/^p(\d)$/', (string) $row->via, $m)) {
                    $out['storypage'][$h] = max($out['storypage'][$h] ?? 0, (int) $m[1]);
                }
            }
            if ($row->via && !in_array($row->kind, ['mailsent', 'story'], true)) {
                $out['clicks'][$row->via]['times'] = ($out['clicks'][$row->via]['times'] ?? 0) + 1;
                $out['clicks'][$row->via]['h'][$h] = true;
            }
        }
        return $out;
    }

    /**
     * «1 family», «15 families».
     *
     * @param int $n
     * @return string
     */
    protected static function fams(int $n): string {
        return get_string($n === 1 ? 'panel_likes_fams_one' : 'panel_likes_fams', self::C, $n);
    }

    /**
     * «1 time», «118 times».
     *
     * @param int $n
     * @return string
     */
    protected static function times(int $n): string {
        return get_string($n === 1 ? 'panel_likes_times_one' : 'panel_likes_times', self::C, $n);
    }

    /**
     * A number with one decimal, as the language of who reads writes it.
     *
     * @param float $x
     * @return string
     */
    protected static function one(float $x): string {
        return format_float($x, 1);
    }

    /**
     * A name inside a sentence: its first letter small, the rest as it is (a network keeps its capital inside).
     *
     * @param string $name
     * @return string
     */
    protected static function lower(string $name): string {
        return \core_text::strtolower(\core_text::substr($name, 0, 1)) . \core_text::substr($name, 1);
    }

    /**
     * The page of the families: how many opened it, how many times each, how many came back another day and how far
     * down they go.
     *
     * @param array $c4
     * @param int $families
     * @return array
     */
    protected static function pages(array $c4, int $families): array {
        $c = self::C;
        $opens = array_values($c4['opens']);
        sort($opens);
        $opened = count($opens);
        $median = $opened ? $opens[intdiv($opened - 1, 2)] : 0;
        $max = $opened ? end($opens) : 0;
        $buckets = [];
        foreach (self::BUCKETS as $i => [$lo, $hi]) {
            $buckets[$i] = count(array_filter($opens, fn($n) => $n >= $lo && $n <= $hi));
        }
        $top = max(1, max($buckets));
        $distribution = [];
        foreach ($buckets as $i => $n) {
            $label = get_string('panel_likes_bucket_' . ($i + 1), $c);
            $distribution[] = ['label' => $label, 'families' => $n, 'v' => round($n / $top, 4),
                'line' => $i === 0 ? get_string('panel_likes_bucket_line_1', $c, self::fams($n)) :
                    get_string('panel_likes_bucket_line', $c, (object) ['fams' => self::fams($n), 'label' => $label])];
        }
        $returned = count(array_filter($c4['days'], fn($d) => count($d) > 1));
        $depth = [];
        foreach (seen::PARTS as $part) {
            $n = count($c4['parts'][$part] ?? []);
            $name = get_string('panel_likes_part_' . $part, $c);
            $depth[] = ['part' => $part, 'name' => $name, 'families' => $n, 'v' => round($n / max(1, $opened), 4),
                'line' => get_string('panel_likes_depth_line', $c, (object) ['name' => $name, 'n' => $n, 'of' => $opened])];
        }
        return [
            'families' => $families,
            'opened' => $opened,
            'openedtext' => get_string('panel_likes_opened_text', $c, (object) ['n' => $opened, 'of' => $families]),
            'visitstext' => get_string(
                !$opened ? 'panel_likes_visits_none' : ($max === 1 ? 'panel_likes_visits_allonce' :
                    ($median > 1 ? 'panel_likes_visits' : 'panel_likes_visits_once')),
                $c,
                (object) ['median' => $median, 'max' => $max]
            ),
            'distribution' => $distribution,
            'returned' => $returned,
            'returnedtext' => !$returned ? get_string('panel_likes_returned_none', $c) : get_string(
                $returned === 1 ? 'panel_likes_returned_one' : 'panel_likes_returned',
                $c,
                $returned
            ),
            'depth' => $depth,
            'depthdef' => get_string('panel_likes_depth_def', $c),
        ];
    }

    /**
     * What they touch, the most touched first, with what is looked at again: one stamp for each return of each family.
     *
     * @param array $c4
     * @return array [rows, the line at rest]
     */
    protected static function touches(array $c4): array {
        $c = self::C;
        $most = max(array_merge([1], array_column($c4['touches'], 'times')));
        $rows = [];
        foreach (seen::TOUCHES as $key) {
            $t = $c4['touches'][$key] ?? null;
            if (!$t) {
                continue;
            }
            $families = count($t['h']);
            $again = round($t['times'] / $families, 1);
            $marks = [];
            for ($left = $again; $left > 0.05 && count($marks) < self::MARKS; $left -= 1) {
                $marks[] = ['f' => min(1, round($left, 2))];
            }
            $name = get_string('panel_likes_touch_' . $key, $c);
            $a = (object) ['name' => $name, 'times' => self::times($t['times']), 'fams' => self::fams($families),
                'again' => self::one($again)];
            $rows[] = ['key' => $key, 'name' => $name, 'times' => $t['times'], 'families' => $families, 'again' => $again,
                'v' => round($t['times'] / $most, 4), 'againtext' => get_string('panel_likes_again_each', $c, self::one($again)),
                'marks' => $marks, 'vuelve' => $again >= 2,
                'line' => get_string($again < 1.05 ? 'panel_likes_touch_line_once' : 'panel_likes_touch_line', $c, $a)];
        }
        usort($rows, fn($x, $y) => [$y['times'], $y['families']] <=> [$x['times'], $x['families']]);
        $again = array_values(array_filter($rows, fn($r) => $r['families'] >= self::AGAIN_FAMILIES && $r['again'] >= 1.05));
        usort($again, fn($x, $y) => $y['again'] <=> $x['again']);
        $rest = count($again) >= 2 ? get_string('panel_likes_touches_rest', $c, (object) [
            'first' => self::lower($again[0]['name']), 'firstagain' => self::one($again[0]['again']),
            'second' => self::lower($again[1]['name']), 'secondagain' => self::one($again[1]['again'])]) :
            get_string('panel_likes_touches_def', $c);
        return [$rows, $rest];
    }

    /**
     * The drawing: how many children have theirs, how many families sent it from home, and how many times it was seen
     * appearing and enlarged.
     *
     * @param int[] $ids
     * @param array $c4
     * @return array
     */
    protected static function drawing(array $ids, array $c4): array {
        global $DB;
        $c = self::C;
        $children = 0;
        $with = 0;
        if ($ids) {
            [$in, $params] = $DB->get_in_or_equal($ids);
            $kids = $DB->get_fieldset_select(households::CHILDREN, 'id', "householdid $in AND stage <> 'closed'", $params);
            $children = count($kids);
            if ($kids) {
                [$inkids, $pk] = $DB->get_in_or_equal($kids, SQL_PARAMS_NAMED);
                $with = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT itemid) FROM {files}
                      WHERE contextid = :ctx AND component = :component AND filearea = :area AND filename <> '.'
                            AND itemid $inkids",
                    ['ctx' => \context_system::instance()->id, 'component' => self::C, 'area' => drawings::AREA] + $pk
                );
            }
        }
        $get = fn(string $key) => [(int) ($c4['touches'][$key]['times'] ?? 0), count($c4['touches'][$key]['h'] ?? [])];
        [$seen, $seenf] = $get('drawing_seen');
        [$big, $bigf] = $get('drawing_enlarged');
        [$home] = $get('drawing_sent');
        $a = (object) ['with' => $with, 'children' => $children, 'home' => $home, 'seen' => self::times($seen),
            'big' => self::times($big)];
        return [
            'children' => $children,
            'withdrawing' => $with,
            'fromhome' => $home,
            'seen' => $seen,
            'seenfamilies' => $seenf,
            'enlarged' => $big,
            'enlargedfamilies' => $bigf,
            'text' => get_string($children ? ($home ? 'panel_likes_drawing_text' : 'panel_likes_drawing_text_nohome') :
                'panel_likes_drawing_none', $c, $a),
        ];
    }

    /**
     * The mails: how many went, how many families pressed something inside and how many presses, all on one scale.
     *
     * @param array $c4
     * @return array [rows, the line at rest]
     */
    protected static function mails(array $c4): array {
        $c = self::C;
        $kinds = array_values(array_intersect(familymail::KINDS, array_keys($c4['sent'] + $c4['clicks'])));
        $most = max(array_merge([1], array_values($c4['sent'])));
        $rows = [];
        foreach ($kinds as $kind) {
            $sent = (int) ($c4['sent'][$kind] ?? 0);
            $clicked = count($c4['clicks'][$kind]['h'] ?? []);
            $clicks = (int) ($c4['clicks'][$kind]['times'] ?? 0);
            $name = get_string('mail_name_' . $kind, $c);
            $rows[] = ['kind' => $kind, 'name' => $name, 'sent' => $sent, 'clicked' => $clicked, 'clicks' => $clicks,
                'sv' => round($sent / $most, 4), 'cv' => round(min(1, $clicked / $most), 4),
                'oftext' => get_string('panel_likes_of', $c, $sent),
                'line' => get_string('panel_likes_mail_line', $c, (object) ['name' => $name, 'clicked' => $clicked,
                    'sent' => $sent, 'clicks' => $clicks])];
        }
        usort($rows, fn($x, $y) => $y['sent'] <=> $x['sent']);
        $rated = array_values(array_filter($rows, fn($r) => $r['sent'] > 0));
        usort($rated, fn($x, $y) => $y['clicked'] / $y['sent'] <=> $x['clicked'] / $x['sent']);
        $rest = $rated ? get_string('panel_likes_mails_rest', $c, (object) ['name' => $rated[0]['name'],
            'clicked' => $rated[0]['clicked'], 'sent' => $rated[0]['sent']]) : get_string('panel_likes_mails_none', $c);
        return [$rows, $rest];
    }

    /**
     * «How it went»: of the families that came, how many told it, and how many wrote what they were left to ask.
     *
     * @param int[] $ids
     * @return array|false
     */
    protected static function howitwent(array $ids) {
        global $DB;
        if (!$ids) {
            return false;
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $came = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT householdid) FROM {" . households::SIGNUPS . "} WHERE attended > 0 AND householdid $in",
            $params
        );
        if (!$came) {
            return false;
        }
        $answered = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT householdid) FROM {" . surveys::T . "} WHERE fieldid > 0 AND householdid $in",
            $params
        );
        $wrote = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT householdid) FROM {" . surveys::T . "} WHERE fieldid = 0 AND householdid $in",
            $params
        );
        $a = (object) ['answered' => $answered, 'came' => $came, 'wrote' => $wrote];
        return ['answered' => $answered, 'of' => $came, 'openquestion' => $wrote,
            'title' => get_string('panel_likes_howitwent', self::C),
            'text' => get_string($answered || $wrote ? 'panel_likes_howitwent_text' : 'panel_likes_howitwent_none', self::C, $a)];
    }

    /**
     * «Your first day»: of the families whose child has the story, how many opened it, heard it whole and again, where
     * they leave it (the last page each reached), and those who switch the song off or read it without the voice.
     *
     * @param \stdClass $centre
     * @param int[] $ids
     * @param array $c4
     * @return array|false
     */
    protected static function firstday(\stdClass $centre, array $ids, array $c4) {
        global $DB;
        $c = self::C;
        if (!$ids) {
            return false;
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id]);
        $with = [];
        $select = "householdid $in AND stage = 'enrolled' AND lefttime = 0";
        foreach ($DB->get_records_select(households::CHILDREN, $select, $params, '', 'id, householdid, offerid') as $child) {
            $offer = $offers[$child->offerid] ?? null;
            if ($offer && story::offer_gives($offer, (int) $centre->id)) {
                $with[$child->householdid] = true;
            }
        }
        if (!$with) {
            return false;
        }
        $s = $c4['story'];
        $opened = count($s['opened'] ?? []);
        $whole = $s['whole'] ?? [];
        // How many times each family that heard it whole heard it: the first and every repeat.
        $times = array_map(fn($h) => 1 + (int) ($s['repeated'][$h] ?? 0), array_keys($whole));
        sort($times);
        $median = $times ? $times[intdiv(count($times) - 1, 2)] : 0;
        $left = array_count_values($c4['storypage']);
        $most = max(array_merge([1], array_values($left)));
        $strip = [];
        for ($i = 0; $i <= 9; $i++) {
            $n = (int) ($left[$i] ?? 0);
            $strip[] = ['label' => $i === 0 ? get_string('panel_likes_strip_cover', $c) : ($i === 9 ?
                get_string('panel_likes_strip_whole', $c) : (string) $i), 'families' => $n, 'hasfamilies' => $n > 0,
                'v' => round($n / $most, 4), 'last' => $i === 9];
        }
        $a = (object) ['opened' => $opened, 'of' => count($with), 'whole' => count($whole), 'median' => $median];
        $musicoff = count($s['musicoff'] ?? []);
        $read = count($s['read'] ?? []);
        return [
            'opened' => $opened,
            'of' => count($with),
            'whole' => count($whole),
            'repeatsmedian' => $median,
            'pages' => 10,
            'strip' => $strip,
            'text' => get_string('panel_likes_firstday_text', $c, $a) . ($median > 1 ?
                ' ' . get_string('panel_likes_firstday_repeat', $c, $a) : ''),
            'musicofftext' => $musicoff ? get_string('panel_likes_musicoff', $c, $musicoff) : false,
            'readwithoutaudiotext' => $read ? get_string('panel_likes_read', $c, $read) : false,
        ];
    }

    /**
     * Where the families come from, and how many asked for a visit of their own from each place.
     *
     * @param int[] $ids
     * @return array [rows, the line at rest]
     */
    protected static function origins(array $ids): array {
        global $DB;
        $c = self::C;
        if (!$ids) {
            return [[], get_string('panel_likes_origins_def', $c)];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $asked = array_flip($DB->get_fieldset_select(visits::REQUESTS, 'DISTINCT householdid', "householdid $in", $params));
        $places = [];
        $unknown = ['families' => 0, 'appointments' => 0];
        $moving = get_string('panel_likes_place_moving', $c);
        foreach ($DB->get_records_select(households::T, "id $in", $params, '', 'id, municipalityname, moving') as $h) {
            $place = !empty($h->moving) ? $moving : trim((string) $h->municipalityname);
            $row = &$unknown;
            if ($place !== '') {
                $places[$place] = $places[$place] ?? ['families' => 0, 'appointments' => 0];
                $row = &$places[$place];
            }
            $row['families']++;
            $row['appointments'] += isset($asked[$h->id]) ? 1 : 0;
            unset($row);
        }
        uasort($places, fn($x, $y) => $y['families'] <=> $x['families']);
        $shown = array_slice($places, 0, self::PLACES, true);
        $others = ['families' => 0, 'appointments' => 0];
        foreach (array_slice($places, self::PLACES, null, true) as $row) {
            $others['families'] += $row['families'];
            $others['appointments'] += $row['appointments'];
        }
        if ($others['families']) {
            $shown[get_string('panel_likes_place_others', $c)] = $others;
        }
        // Not a place: always last, in pencil.
        $nowhere = get_string('panel_likes_place_unknown', $c);
        if ($unknown['families']) {
            $shown[$nowhere] = $unknown;
        }
        $top = max(array_merge([1], array_column($shown, 'families')));
        $rows = [];
        foreach ($shown as $place => $row) {
            $a = (object) ['place' => $place, 'fams' => self::fams($row['families']), 'n' => $row['appointments']];
            $rows[] = ['place' => (string) $place, 'unknown' => $unknown['families'] && (string) $place === $nowhere,
                'families' => $row['families'],
                'appointments' => $row['appointments'] ?: false,
                'appointmentstext' => get_string('panel_likes_appointments', $c, $row['appointments']),
                'v' => round($row['families'] / $top, 4), 'av' => round($row['appointments'] / $top, 4),
                'line' => get_string($row['appointments'] ? 'panel_likes_origin_line' : 'panel_likes_origin_line_none', $c, $a)];
        }
        $first = array_key_first($places);
        $rest = $first !== null ? get_string('panel_likes_origins_rest', $c, (object) ['n' => $places[$first]['families'],
            'of' => count($ids), 'place' => (string) $first]) : get_string('panel_likes_origins_def', $c);
        return [$rows, $rest];
    }

    /**
     * The courses asked for, the most asked first, with the age of each when it is known.
     *
     * @param \stdClass $centre
     * @param int[] $ids
     * @return array
     */
    protected static function courses(\stdClass $centre, array $ids): array {
        global $DB;
        $c = self::C;
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $offers = $DB->get_records_menu(centres::OFFERS, ['centreid' => $centre->id], '', 'id, name');
        $ages = $DB->get_records_menu(forecast::AGES, ['centreid' => $centre->id], '', 'grade, age');
        $asked = [];
        $select = "householdid $in AND stage <> 'closed'";
        $children = $DB->get_records_select(households::CHILDREN, $select, $params, '', 'id, offerid');
        foreach ($children as $k) {
            $name = (string) ($offers[$k->offerid] ?? '');
            if ($name !== '') {
                $asked[$name] = ($asked[$name] ?? 0) + 1;
            }
        }
        arsort($asked);
        $asked = array_slice($asked, 0, self::COURSES, true);
        $top = max(array_merge([1], array_values($asked)));
        $rows = [];
        foreach ($asked as $name => $n) {
            $age = isset($ages[$name]) ? (int) $ages[$name] : grades::age((string) $name);
            $label = format_string($name);
            $a = (object) ['name' => $label, 'age' => $age, 'n' => $n];
            $rows[] = ['name' => $label, 'age' => $age !== null ? get_string('panel_likes_age', $c, $age) : false, 'asked' => $n,
                'v' => round($n / $top, 4),
                'line' => get_string($age !== null ? 'panel_likes_course_line' : 'panel_likes_course_line_noage', $c, $a)];
        }
        return $rows;
    }

    /**
     * What the adults like doing: a chip for each thing, with how many adults say it; what they wrote of their own,
     * when two or more wrote the same; the rest, in one line.
     *
     * @param int[] $ids
     * @return array hobbies, hashobbies, hobbiesof, hobbiesextra and hobbiesdef.
     */
    protected static function hobbies(array $ids): array {
        $c = self::C;
        $counts = hobbies::counts($ids);
        $list = [];
        foreach ($counts['list'] as $key => $n) {
            $list[] = ['key' => $key, 'name' => hobbies::name($key), 'adults' => $n];
        }
        $once = [];
        foreach ($counts['others'] as $name => $n) {
            if ($n >= 2) {
                $list[] = ['key' => 'o' . count($list), 'name' => $name, 'adults' => $n];
            } else {
                $once[] = $name;
            }
        }
        usort($list, fn($x, $y) => $y['adults'] <=> $x['adults']);
        $top = max(array_merge([1], array_column($list, 'adults')));
        foreach ($list as $i => $row) {
            $list[$i]['v'] = round($row['adults'] / $top, 4);
            $key = $row['adults'] === 1 ? 'panel_likes_hobby_line_one' : 'panel_likes_hobby_line';
            $list[$i]['line'] = get_string($key, $c, (object) ['name' => $row['name'], 'n' => $row['adults']]);
        }
        return [
            'hobbies' => $list ?: false,
            'hashobbies' => (bool) $list,
            'hobbiesof' => $counts['told'] ? get_string('panel_likes_hobbies_of', $c, (object) ['adults' => $counts['adults'],
                'told' => $counts['told']]) : get_string('panel_likes_hobbies_none', $c),
            'hobbiesextra' => $once ? get_string(
                count($once) === 1 ? 'panel_likes_hobbies_once_one' : 'panel_likes_hobbies_once',
                $c,
                (object) ['n' => count($once), 'list' => implode(', ', array_slice($once, 0, 12))]
            ) : false,
            'hobbiesdef' => get_string('panel_likes_hobbies_def', $c),
        ];
    }
}
