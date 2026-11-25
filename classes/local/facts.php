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
 * What each family of admissions did, worked out in one place: Numbers, the way of the campaign of the inbox, the reports,
 * «Your work» and the download all count from here, so a total never differs from one screen to another.
 *
 * A campaign fills a school year, and is all that is known of it: what lives in Eguahe and every file imported of that year,
 * with each family once however many it is in (by its emails and phones), and the sources it comes from. A family that is only
 * in a file has fewer facts, and what is not known of it is told apart, never as a no.
 *
 * Its families are those with a child asking for a course of that year, and those that
 * came in its time and have not said a course yet. Families already of the centre (its nursery, children inside, brothers
 * and sisters, staff) go apart: they come, but the campaign has not attracted them. So do those that came in its time for
 * another school year. Who came only to a talk, who was not a family and the roll of the centre are never here.
 *
 * Each fact is what it says: «came» is having come to see the centre, whatever was done next; a family without a child
 * noted down is not a closed one; whoever waits in the list has no place; whoever left is not enrolled.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class facts {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int Below this, only numbers are said: no share, no fraction and no median. */
    const FEW = 10;

    /** @var string[] The stages of a child that mean a place was asked for. */
    const APPLIED = ['application', 'waitlist', 'admitted', 'enrolled'];

    /**
     * The school years a centre offers courses of, the earliest first.
     *
     * @param int $centreid
     * @return string[]
     */
    public static function years(int $centreid): array {
        global $DB;
        $years = array_values(array_unique(array_filter(array_map('strval', $DB->get_fieldset_select(
            centres::OFFERS,
            'schoolyear',
            'centreid = ?',
            [$centreid]
        )))));
        sort($years);
        return $years;
    }

    /**
     * The school year the campaign of a moment fills: an open day in November fills the places of the next one.
     *
     * @param \stdClass $centre
     * @param int $time
     * @param string[]|null $years The years known; null, those offered and those of its lists.
     * @return string Empty without any.
     */
    public static function year_at(\stdClass $centre, int $time, ?array $years = null): string {
        $years = $years ?? array_values(array_unique(array_merge(
            self::years((int) $centre->id),
            metrics::school_years((int) $centre->id)
        )));
        return panel_inbox::campaign_school_year($centre, array_map('strval', $years), $time);
    }

    /**
     * The school year the campaign of a moment fills, by its own name, whether the centre offers it or not: for what was
     * imported, of any year.
     *
     * @param \stdClass $centre
     * @param int $time
     * @return string
     */
    public static function year_name(\stdClass $centre, int $time): string {
        static $short = [];
        $id = (int) $centre->id;
        if (!isset($short[$id]) || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            $years = array_merge(self::years($id), metrics::school_years($id));
            // «2026» where every year the centre writes is one of the calendar.
            $short[$id] = $years && !array_filter($years, fn($y) => strlen((string) $y) > 4);
        }
        $year = (int) userdate($time, '%Y') + ((int) userdate($time, '%m') >= 7 ? 1 : 0);
        return $short[$id] ? (string) $year : sprintf('%d-%02d', $year, ($year + 1) % 100);
    }

    /**
     * When the campaign of a moment began: the day the centre wrote, in the year of that moment; 0 when it wrote none.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return int
     */
    public static function began(\stdClass $centre, int $now): int {
        $start = (int) ($centre->campaignstart ?? 0);
        if ($start <= 0) {
            return 0;
        }
        $began = (new \DateTime('@' . $start))->setTimezone(\core_date::get_user_timezone_object());
        while ($began->getTimestamp() > $now) {
            $began->modify('-1 year');
        }
        while ((clone $began)->modify('+1 year')->getTimestamp() <= $now) {
            $began->modify('+1 year');
        }
        return $began->getTimestamp();
    }

    /**
     * When the campaigns before began, as the centre wrote it each year.
     *
     * @param \stdClass $centre
     * @return int[] School year => time.
     */
    public static function starts(\stdClass $centre): array {
        $kept = json_decode((string) ($centre->campaigns ?? ''), true);
        return is_array($kept) ? array_map('intval', $kept) : [];
    }

    /**
     * Keeps when the campaign of a school year began; 0 forgets it.
     *
     * @param \stdClass $centre Changed in place.
     * @param string $year
     * @param int $time
     */
    public static function keep_start(\stdClass $centre, string $year, int $time): void {
        global $DB;
        if ($year === '') {
            return;
        }
        $kept = self::starts($centre);
        if ($time > 0) {
            $kept[$year] = $time;
        } else {
            unset($kept[$year]);
        }
        ksort($kept);
        $centre->campaigns = $kept ? json_encode($kept) : null;
        $DB->set_field(centres::T, 'campaigns', $centre->campaigns, ['id' => $centre->id]);
    }

    /**
     * The campaign under way of a centre.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array key, name, year (the school year it fills), start (as written, 0 when not), since (from when a family
     *     without a course counts), until (0, still under way) and import (null).
     */
    public static function live(\stdClass $centre, int $now = 0): array {
        $now = $now ?: time();
        $start = self::began($centre, $now);
        return ['key' => 'live', 'name' => get_string('panel_this_campaign', self::C),
            'year' => self::year_at($centre, $now, self::years((int) $centre->id)), 'start' => $start,
            'since' => $start ?: (new \DateTimeImmutable('@' . $now))->modify('-12 months')->getTimestamp(), 'until' => 0,
            'import' => null];
    }

    /**
     * The campaigns of Eguahe of a centre: the one under way and those before it, the newest first.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array key => campaign, as live() gives it.
     */
    public static function campaigns(\stdClass $centre, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $live = self::live($centre, $now);
        $out = ['live' => $live];
        $asked = array_map('strval', $DB->get_fieldset_sql(
            'SELECT DISTINCT o.schoolyear
               FROM {' . households::CHILDREN . '} c
               JOIN {' . centres::OFFERS . '} o ON o.id = c.offerid AND o.centreid = c.centreid
               JOIN {' . households::T . '} h ON h.id = c.householdid
              WHERE c.centreid = :centreid AND c.roster = 0 AND h.roster = 0 AND h.guest = 0',
            ['centreid' => $centre->id]
        ));
        // And the years of what was imported, with when the centre wrote that its campaign started.
        $starts = [];
        foreach (self::file_years($centre) as [$years, $start]) {
            $asked = array_merge($asked, $years);
            foreach ($start ? $years : [] as $year) {
                $starts[$year] = min($starts[$year] ?? $start, $start);
            }
        }
        $asked = array_filter(array_unique($asked), fn($y) => $y !== '' && ($live['year'] === '' || strcmp($y, $live['year']) < 0));
        rsort($asked);
        $kept = self::starts($centre);
        $until = $live['since'];
        foreach ($asked as $year) {
            $start = (int) ($kept[$year] ?? $starts[$year] ?? 0);
            $out['y' . $year] = ['key' => 'y' . $year, 'name' => get_string('panel_past_campaign', self::C, $year),
                'year' => $year, 'start' => $start, 'since' => $start, 'until' => $until, 'import' => null];
            $until = $start ?: $until;
        }
        return $out;
    }

    /**
     * The families of a campaign that it attracted: those its totals count, the ones that went too.
     *
     * @param \stdClass $centre
     * @param array $campaign As campaigns() gives it.
     * @param int $now
     * @return array[]
     */
    public static function families(\stdClass $centre, array $campaign, int $now = 0): array {
        return self::group(self::all($centre, $campaign, $now), 'campaign');
    }

    /**
     * Everything known of a campaign: the families of Eguahe, with what the files say of them, and those only in a file of its
     * school year; each once.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param int $now
     * @return array By household, or «t» and the token of those only in a file; as read() gives them.
     */
    public static function all(\stdClass $centre, array $campaign, int $now = 0): array {
        return self::merge($centre, self::read($centre, $campaign, $now), self::imported($centre, (string) $campaign['year']));
    }

    /**
     * Every family that ever came, of any campaign, in Eguahe or in a file, each once: for what is counted by its date.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array As all() gives them.
     */
    public static function everyone(\stdClass $centre, int $now = 0): array {
        $out = [];
        foreach (self::prospects($centre, $now) as $f) {
            $out[$f['id']] = $f;
        }
        // The same family in files of two years: it came the first time.
        $imported = [];
        foreach (self::imported($centre) as $f) {
            $was = $imported[$f['token']] ?? null;
            $imported[$f['token']] = $was ? self::both($was, $f) : $f;
        }
        return self::merge($centre, $out, $imported);
    }

    /**
     * Puts what the files say with the families of Eguahe.
     *
     * @param \stdClass $centre
     * @param array $out Families of Eguahe, by household.
     * @param array[] $imported
     * @return array
     */
    protected static function merge(\stdClass $centre, array $out, array $imported): array {
        if (!$imported) {
            return $out;
        }
        $owner = [];
        foreach (self::owners((int) $centre->id) as $token => $hids) {
            // The one of this campaign, when there is one.
            $here = array_values(array_filter($hids, fn($id) => isset($out[$id]) && $out[$id]['group'] !== ''));
            $owner[$token] = $here[0] ?? $hids[0];
        }
        foreach ($imported as $f) {
            $hid = $owner[$f['token']] ?? 0;
            if ($hid && isset($out[$hid]) && $out[$hid]['group'] !== '') {
                $out[$hid]['first'] = min($out[$hid]['first'], $f['first']);
                $out[$hid]['sources'] = array_values(array_unique(array_merge($out[$hid]['sources'], $f['sources']), SORT_REGULAR));
                $out[$hid]['came'] = $out[$hid]['came'] || $f['came'];
                $out[$hid]['applied'] = $out[$hid]['applied'] || $f['applied'];
                $out[$hid]['step'] = max($out[$hid]['step'], min(3, $f['step']));
                $out[$hid]['adlater'] = !empty($out[$hid]['adlater']) || $f['channel'] === 'campaign';
                $out[$hid]['visits'] = array_values(array_unique(array_merge($out[$hid]['visits'], $f['visits'])));
                sort($out[$hid]['visits']);
                continue;
            }
            // Its file of Eguahe may be of another campaign: it is the same family, to open it.
            $key = 't' . $f['token'];
            $out[$key] = isset($out[$key]) ? self::both($out[$key], $f) : ['id' => $hid] + $f;
        }
        return $out;
    }

    /**
     * The households of Eguahe behind each token of the lists.
     *
     * @param int $centreid
     * @return array token => household ids
     */
    protected static function owners(int $centreid): array {
        global $DB;
        $ids = $DB->get_fieldset_select(households::T, 'id', 'centreid = ? AND guest = 0 AND roster = 0 ORDER BY id', [$centreid]);
        $out = [];
        foreach (self::tokens($centreid, array_map('intval', $ids)) as $id => $tokens) {
            foreach ($tokens as $token) {
                $out[$token][] = (int) $id;
            }
        }
        foreach ($out as $token => $hids) {
            sort($hids);
            $out[$token] = $hids;
        }
        return $out;
    }

    /**
     * The same family said twice by the files: its first contact, and the furthest it got in any.
     *
     * @param array $a
     * @param array $b
     * @return array
     */
    protected static function both(array $a, array $b): array {
        $out = $a['first'] <= $b['first'] ? $a : $b;
        foreach (['came', 'applied', 'enrolled', 'place'] as $key) {
            $out[$key] = $a[$key] || $b[$key];
        }
        foreach (['came', 'applied'] as $key) {
            $out['known'][$key] = $a['known'][$key] || $b['known'][$key];
        }
        $out['step'] = max($a['step'], $b['step']);
        $out['students'] = max($a['students'], $b['students']);
        $out['enrolledat'] = (int) min(array_filter([$a['enrolledat'], $b['enrolledat']]) ?: [0]);
        $out['sources'] = array_values(array_unique(array_merge($a['sources'], $b['sources'])));
        $out['visits'] = array_values(array_unique(array_merge($a['visits'], $b['visits'])));
        $out['nokids'] = $a['nokids'] && $b['nokids'];
        $out['adlater'] = !empty($a['adlater']) || !empty($b['adlater']) || ($out['channel'] !== 'campaign' &&
            ($a['channel'] === 'campaign' || $b['channel'] === 'campaign'));
        foreach (['course', 'coursename'] as $key) {
            $out[$key] = $out[$key] !== '' ? $out[$key] : ($a[$key] !== '' ? $a[$key] : $b[$key]);
        }
        $out['group'] = $a['group'] === 'centre' || $b['group'] === 'centre' ? 'centre' : $out['group'];
        return $out;
    }

    /**
     * The school years the families of each imported file were asking for, and when their campaign started: without reading
     * the lists of students.
     *
     * @param \stdClass $centre
     * @return array Import id => years (a list) and start (as written, or 0)
     */
    public static function file_years(\stdClass $centre): array {
        global $DB;
        $out = [];
        foreach (imports::for_centre((int) $centre->id, 'contacts') as $import) {
            $start = (int) ($import->campaignstart ?? 0);
            $years = [];
            if ($start) {
                $years[self::year_name($centre, $start)] = true;
            } else {
                $firsts = $DB->get_records_sql(
                    'SELECT household, MIN(timecontact) AS first FROM {' . metrics::CONTACTS . '} WHERE importid = ? GROUP BY household',
                    [$import->id]
                );
                foreach ($firsts as $row) {
                    $years[self::year_name($centre, (int) $row->first)] = true;
                }
            }
            $out[(int) $import->id] = [array_map('strval', array_keys($years)), $start];
        }
        return $out;
    }

    /** @var array Centre id and year => the families of its files, once per request. */
    protected static $files = [];

    /**
     * The families of every file of contacts a centre imported, each once by school year: the year it was asking for, by the
     * day the centre wrote that its campaign started or, without it, by the first contact of the family in that file. Those
     * already of the centre, as the file or the lists of students of that year say, apart.
     *
     * @param \stdClass $centre
     * @param string|null $only Only those of this school year; null, of every one.
     * @return array[] As read() gives them, with id 0, token, year, start (of the campaign, as written, or 0), sources (the ids
     *     of the files) and known (whether its file says who came and who applied).
     */
    public static function imported(\stdClass $centre, ?string $only = null): array {
        $id = (int) $centre->id;
        $kept = $id . '|' . ($only ?? '*');
        if (isset(self::$files[$kept]) && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return self::$files[$kept];
        }
        $out = [];
        $inside = [];
        $years = $only === null ? [] : self::file_years($centre);
        foreach (imports::for_centre($id, 'contacts') as $import) {
            if ($only !== null && !in_array($only, $years[(int) $import->id][0] ?? [], true)) {
                continue;
            }
            $households = metrics::households($import);
            $start = (int) ($import->campaignstart ?? 0);
            $known = ['came' => (bool) array_filter($households, fn($h) => $h['visited']),
                'applied' => (bool) array_filter($households, fn($h) => $h['applied'])];
            foreach ($households as $token => $h) {
                $first = (int) $h['first']->timecontact;
                $year = self::year_name($centre, $start ?: $first);
                if ($only !== null && $year !== $only) {
                    continue;
                }
                $inside[$year] = $inside[$year] ?? (in_array($year, metrics::school_years($id), true) ?
                    metrics::origins($id, $year)['centre'] : []);
                $family = self::of_file($import, (string) $token, $h, $year, $known);
                $family['group'] = $h['incentre'] || isset($inside[$year][$token]) ? 'centre' : 'campaign';
                $family['who'] = $family['group'] === 'centre' ? 'centre' : 'new';
                $family['also'] = [$family['who']];
                $key = $year . '|' . $token;
                $out[$key] = isset($out[$key]) ? self::both($out[$key], $family) : $family;
            }
        }
        return self::$files[$kept] = $out;
    }

    /**
     * A family as a file says it.
     *
     * @param \stdClass $import
     * @param string $token
     * @param array $h As metrics::households() gives it.
     * @param string $year
     * @param bool[] $known
     * @return array
     */
    protected static function of_file(\stdClass $import, string $token, array $h, string $year, array $known): array {
        $row = $h['first'];
        $name = fn(\stdClass $one) => (string) ($one->sourcedetail ?: get_string('channel_' . (in_array(
            $one->channel,
            lists::CHANNELS,
            true
        ) ? $one->channel : 'other'), self::C));
        $source = arrival::clean((string) ($row->utmsource ?? ''), 100, 'src');
        $said = trim((string) ($row->declaredsource ?? ''));
        // An ad is an ad though its file does not write the medium.
        $channel = $row->channel === 'meta' ? 'campaign' : arrival::channel((object) ['arrivalsource' => $source,
            'arrivalmedium' => (string) ($row->utmmedium ?? ''), 'arrivalref' => '', 'firstchannel' => (string) $row->channel,
            'heardfrom' => $said !== '' ? heard::read($said) : '']);
        $grade = trim((string) ($h['grade'] ?? ''));
        return ['id' => 0, 'token' => $token, 'year' => $year, 'start' => (int) ($import->campaignstart ?? 0),
            'group' => 'campaign', 'who' => 'new', 'also' => ['new'],
            'first' => (int) $row->timecontact, 'came' => $h['visited'], 'applied' => $h['applied'], 'waitlist' => false,
            'place' => false, 'enrolled' => $h['enrolled'], 'went' => false, 'students' => $h['enrolled'] ? max(1, (int) $h['students']) : 0,
            'enrolledat' => $h['enrolled'] ? (int) ($h['enrolledat'] ?: $h['last']->timecontact) : 0, 'closed' => false, 'why' => '',
            'step' => $h['enrolled'] ? 5 : ($h['applied'] ? 3 : ($h['visited'] ? 2 : 1)), 'kids' => [], 'visits' => [],
            'nokids' => $grade === '', 'course' => $grade !== '' ? recognise::plain($grade) : '', 'coursename' => $grade,
            'moving' => false, 'waiting' => 0, 'channel' => $channel, 'heard' => '', 'net' => '', 'out' => '', 'outstep' => 0,
            'known' => $known, 'sources' => [(int) $import->id], 'dated' => true,
            'voices' => ['first' => $name($row), 'last' => $name($h['last']), 'said' => $said,
                'measured' => $source !== '' ? arrival::label($source) : '', 'channel' => arrival::channel_name($channel),
                'basis' => $source !== '' || $row->channel === 'meta' ? 'measured' : ($said !== '' ? 'said' : 'none'),
                'campaign' => trim((string) ($row->utmcampaign ?? ''))]];
    }

    /**
     * Whether a family lives in Eguahe in the campaign it is seen from, and not only in a file.
     *
     * @param array $f
     * @return bool
     */
    public static function lives(array $f): bool {
        return in_array('eguahe', $f['sources'] ?? [], true);
    }

    /**
     * The households of some families, to open them in the inbox.
     *
     * @param array[] $families
     * @return int[]
     */
    public static function ids(array $families): array {
        return array_values(array_unique(array_filter(array_map(fn($f) => (int) ($f['id'] ?? 0), $families))));
    }

    /**
     * Those of a group: campaign, centre (already of the centre) or other (came in its time for another school year).
     *
     * @param array[] $families From read().
     * @param string $group
     * @return array[]
     */
    public static function group(array $families, string $group): array {
        return array_values(array_filter($families, fn($f) => $f['group'] === $group));
    }

    /**
     * Every family that ever came through Eguahe, each with its facts for the school year its own campaign filled: for
     * what is counted by its date, whichever campaign it was.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array[]
     */
    public static function prospects(\stdClass $centre, int $now = 0): array {
        $now = $now ?: time();
        return array_values(self::read($centre, ['key' => 'all', 'year' => '', 'since' => 0, 'until' => 0, 'import' => null], $now));
    }

    /**
     * What every family did, as a campaign counts it.
     *
     * @param \stdClass $centre
     * @param array $campaign
     * @param int $now
     * @return array Household id => id, group (campaign, centre, other, or empty: of another time), who (what it is first,
     *     before this campaign) and also (all it is), first (its first contact), came (whether it came to see the centre)
     *     and visits (the moments), applied, waitlist (in the list, without a place), place, enrolled (still), went
     *     (enrolled and left), students (enrolled and still), enrolledat, closed and why, step (how far it got, also when it
     *     went after: 1 wrote, 2 came, 3 applied, 4 place or list, 5 enrolled), kids (id, offerid, name, year, stage, left,
     *     enrolledat), course and coursename, channel (its key), voices, moving, adlater, nokids.
     */
    public static function read(\stdClass $centre, array $campaign, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $c = self::C;
        $cid = (int) $centre->id;
        $id = ['centreid' => $cid];
        $all = $campaign['key'] === 'all';
        $year = (string) $campaign['year'];
        $since = (int) $campaign['since'];
        $until = (int) $campaign['until'];
        $offers = $DB->get_records(centres::OFFERS, $id, '', 'id, name, schoolyear');
        $years = self::years($cid);
        $out = [];
        $firsts = [];
        $rows = $DB->get_records_select(households::T, 'centreid = :centreid AND guest = 0 AND roster = 0', $id, 'id ASC');
        foreach ($rows as $h) {
            $firsts[$h->id] = (string) $h->firstchannel;
            $channel = arrival::channel($h, $centre);
            $out[$h->id] = ['id' => (int) $h->id, 'group' => '', 'who' => 'new', 'also' => ['new'],
                'first' => (int) $h->timecreated, 'step' => 1, 'came' => false, 'visits' => [], 'applied' => false,
                'waitlist' => false, 'place' => false, 'enrolled' => false, 'went' => false, 'students' => 0, 'enrolledat' => 0,
                'closed' => false, 'why' => '', 'kids' => [], 'nokids' => true, 'course' => '', 'coursename' => '',
                'moving' => !empty($h->moving), 'waiting' => (int) $h->waitingsince, 'channel' => $channel,
                'heard' => (string) ($h->heardfrom ?? ''), 'net' => trim((string) ($h->heardnet ?? '')),
                'voices' => ['first' => '', 'last' => '', 'said' => self::said($h), 'measured' => '',
                    'channel' => arrival::channel_name($channel), 'basis' => arrival::basis($h),
                    'campaign' => panel_metrics::campaign_name(trim((string) ($h->arrivalcampaign ?? '')))],
                'sources' => ['eguahe'], 'dated' => true, 'known' => ['came' => true, 'applied' => true],
                'out' => in_array((string) ($h->outreason ?? ''), outs::LEFT, true) ? (string) $h->outreason : '',
                'outstep' => (int) ($h->outstep ?? 0)];
            // What was measured when it arrived: the site or the source, AI assistants together.
            $measured = arrival::of($h)[0];
            $out[$h->id]['voices']['measured'] = $measured !== '' ? arrival::label($measured) : '';
        }
        self::contacts($out, $cid, $firsts);
        // Those of a sheet without a message of theirs carry the day of their visit or of its loading, not the day they came.
        foreach (self::undated($centre) as $hid => $day) {
            if (isset($out[$hid])) {
                $out[$hid]['dated'] = false;
                $out[$hid]['first'] = $day ? min($out[$hid]['first'], $day) : $out[$hid]['first'];
            }
        }
        foreach (self::visits($centre) as $hid => $times) {
            if (isset($out[$hid])) {
                $out[$hid]['visits'] = $times;
            }
        }
        // A child brought from the roll was already at the centre: never part of a campaign.
        $children = $DB->get_records_select(households::CHILDREN, 'centreid = :centreid AND roster = 0', $id, 'id ASC');
        foreach ($children as $child) {
            if (!isset($out[$child->householdid])) {
                continue;
            }
            $offer = $offers[$child->offerid] ?? null;
            $out[$child->householdid]['kids'][] = ['id' => (int) $child->id, 'offerid' => $offer ? (int) $offer->id : 0,
                'name' => $offer ? (string) $offer->name : '', 'year' => $offer ? (string) $offer->schoolyear : '',
                'stage' => (string) $child->stage, 'far' => (string) ($child->closedstage ?? ''),
                'left' => (int) ($child->lefttime ?? 0), 'since' => (int) $child->timecreated,
                'stagetime' => (int) $child->stagetime, 'why' => (string) ($child->closedreason ?? ''),
                'mine' => true];
        }
        $who = [];
        foreach ($out as $hid => $f) {
            $inside = $f['first'] >= $since && (!$until || $f['first'] < $until);
            if ($all) {
                $f['group'] = 'campaign';
            } else {
                $asks = array_filter($f['kids'], fn($k) => $k['year'] === $year && $year !== '');
                $loose = !$f['kids'] || array_filter($f['kids'], fn($k) => $k['year'] === '');
                $f['group'] = $asks || ($inside && $loose && $since) ? 'campaign' : ($inside && $since ? 'other' : '');
                // Of its children, those that ask for this school year, or for no course yet.
                if ($f['group'] === 'campaign') {
                    foreach ($f['kids'] as $i => $k) {
                        $f['kids'][$i]['mine'] = $k['year'] === $year || $k['year'] === '';
                    }
                    // What it came to for another child, another year, is of that campaign.
                    if ($f['first'] < $since && array_filter($f['kids'], fn($k) => !$k['mine'] && strcmp($k['year'], $year) < 0)) {
                        $f['visits'] = array_values(array_filter($f['visits'], fn($t) => $t >= $since));
                    }
                }
                if ($until) {
                    $f['visits'] = array_values(array_filter($f['visits'], fn($t) => $t < $until));
                }
            }
            // What it was before the school year it asks for: the child it enrolled for this course never makes it one of the
            // centre when it came for that course.
            $its = $year;
            if ($f['group'] !== 'campaign' || $all) {
                $asked = array_filter(array_column($f['kids'], 'year'), fn($y) => $y !== '');
                $its = $all ? self::year_at($centre, $f['first'], $years) : $year;
                $its = $asked && ($its === '' || strcmp(min($asked), $its) < 0) ? (string) min($asked) : $its;
            }
            $who[$its] = $who[$its] ?? who::all_of_centre($cid, $its);
            $f['also'] = $who[$its][$hid] ?? ['new'];
            $f['who'] = $f['also'][0];
            if ($f['group'] === 'campaign' && in_array($f['who'], who::CENTRE, true)) {
                $f['group'] = 'centre';
            }
            $out[$hid] = self::way($f);
        }
        return $out;
    }

    /**
     * How far a family got, from its children, what it came to and what was said of it.
     *
     * @param array $f
     * @return array
     */
    protected static function way(array $f): array {
        $mine = array_values(array_filter($f['kids'], fn($k) => $k['mine']));
        $f['nokids'] = !$mine;
        $open = array_filter($mine, fn($k) => $k['stage'] !== 'closed');
        $f['closed'] = $f['out'] !== '' || ($mine && !$open);
        $reached = fn(array $k) => $k['stage'] === 'closed' ? $k['far'] : $k['stage'];
        $best = 0;
        foreach ($mine as $k) {
            $stage = $reached($k);
            $f['applied'] = $f['applied'] || in_array($stage, self::APPLIED, true);
            $f['came'] = $f['came'] || $stage === 'visit';
            $f['place'] = $f['place'] || $k['stage'] === 'admitted' || ($k['stage'] === 'enrolled' && !$k['left']);
            $f['waitlist'] = $f['waitlist'] || $k['stage'] === 'waitlist';
            if ($k['stage'] === 'enrolled' && !$k['left']) {
                $f['students']++;
                $f['enrolledat'] = $f['enrolledat'] ? min($f['enrolledat'], $k['stagetime']) : $k['stagetime'];
            } else if ($k['stage'] === 'enrolled') {
                $f['went'] = true;
            }
            // The course of the child that got furthest, of those still on their way.
            $step = $k['stage'] === 'closed' ? 0 : (lists::STAGE_STEP[$k['stage']] ?? 1);
            if ($k['name'] !== '' && ($f['course'] === '' || $step > $best)) {
                $f['course'] = recognise::plain($k['name']);
                $f['coursename'] = format_string($k['name']);
                $best = max($best, $step);
            }
        }
        $f['enrolled'] = $f['students'] > 0;
        $f['went'] = $f['went'] && !$f['enrolled'];
        $f['waitlist'] = $f['waitlist'] && !$f['place'];
        $f['came'] = $f['came'] || (bool) $f['visits'] || $f['outstep'] === 2;
        $f['applied'] = $f['applied'] || $f['outstep'] >= 3;
        $f['step'] = $f['enrolled'] ? 5 : ($f['place'] || $f['waitlist'] ? 4 : ($f['applied'] ? 3 : ($f['came'] ? 2 : 1)));
        $f['closed'] = $f['closed'] && !$f['enrolled'];
        if ($f['closed']) {
            $why = $f['out'];
            if ($why === '') {
                usort($mine, fn($a, $b) => $b['stagetime'] <=> $a['stagetime']);
                $why = $mine[0]['why'] === 'moved' ? 'far' : $mine[0]['why'];
                $why = in_array($why, outs::LEFT, true) ? $why : 'another';
            }
            $f['why'] = $why;
        }
        return $f;
    }

    /**
     * Where each family first and last came from (the entry and its place, or the channel), and when it first wrote.
     *
     * @param array $out Changed in place.
     * @param int $centreid
     * @param string[] $firsts Household id => the channel it was noted with.
     */
    protected static function contacts(array &$out, int $centreid, array $firsts): void {
        global $DB;
        $c = self::C;
        $entries = $DB->get_records_menu(entries::T, ['centreid' => $centreid], '', 'id, name');
        $contacts = $DB->get_recordset_select(
            households::CONTACTS,
            "centreid = :centreid AND (direction = 'in' OR (direction = 'note' AND entryid > 0)) AND channel <> 'page'",
            ['centreid' => $centreid],
            'timecreated ASC, id ASC',
            'id, householdid, direction, channel, entryid, detail, timecreated'
        );
        $channel = fn(string $name) => get_string('channel_' . (in_array($name, lists::CHANNELS, true) ? $name : 'other'), $c);
        foreach ($contacts as $contact) {
            if (!isset($out[$contact->householdid])) {
                continue;
            }
            $f = &$out[$contact->householdid];
            // A contact left in an ad, whatever came first: it shows where advertising also touched a family.
            if ($contact->channel === 'meta' && $contact->direction === 'in') {
                $f['adlater'] = true;
            }
            $name = $contact->entryid && isset($entries[$contact->entryid]) ? format_string($entries[$contact->entryid]) .
                ($contact->detail ? ' · ' . $contact->detail : '') : $channel((string) $contact->channel);
            if ($f['voices']['first'] === '') {
                $f['voices']['first'] = $name;
                $f['first'] = min($f['first'], (int) $contact->timecreated);
            }
            $f['voices']['last'] = $name;
            unset($f);
        }
        $contacts->close();
        foreach ($out as $key => $f) {
            // Without a message of theirs, where they came in by, as it was noted when the family was written down.
            $first = (string) ($firsts[$key] ?? 'other');
            if ($f['voices']['first'] === '' && $first !== 'other' && in_array($first, lists::CHANNELS, true)) {
                $out[$key]['voices']['first'] = $out[$key]['voices']['last'] = $channel($first);
            }
            if ($f['moving']) {
                $out[$key]['voices']['first'] = $out[$key]['voices']['last'] = get_string('metrics_live_moving', $c);
            }
            foreach (['first', 'last'] as $voice) {
                $out[$key]['voices'][$voice] = $out[$key]['voices'][$voice] ?: get_string('channel_other', $c);
            }
        }
    }

    /**
     * When each family of a centre came to see it: the open days and visits it came to, at their own time and not when
     * somebody marked it, and what the sheets of the team say. A talk or a party is not coming to see the centre.
     *
     * @param \stdClass $centre
     * @return array Household id => moments, the first first.
     */
    public static function visits(\stdClass $centre): array {
        global $DB;
        $out = [];
        $rows = $DB->get_recordset_sql(
            "SELECT s.id, s.householdid, s.attended, COALESCE(t.timestart, e.timestart) AS timestart
               FROM {" . events::SIGNUPS . "} s
               JOIN {" . events::T . "} e ON e.id = s.eventid
          LEFT JOIN {local_oksigeniaeguahe_slot} t ON t.id = s.slotid
              WHERE e.centreid = :centreid AND s.attended > 0 AND e.kind <> 'other' AND e.tailored = 0",
            ['centreid' => $centre->id]
        );
        foreach ($rows as $row) {
            // Marked before it starts, with the doors open: then.
            $out[(int) $row->householdid][] = min((int) $row->attended, (int) $row->timestart);
        }
        $rows->close();
        $rows = $DB->get_recordset_select(
            teamnotes::T,
            "centreid = :centreid AND state = 'came' AND visit > 0 AND replacedby = 0",
            ['centreid' => $centre->id],
            '',
            'id, householdid, visit'
        );
        foreach ($rows as $row) {
            $out[(int) $row->householdid][] = (int) $row->visit;
        }
        $rows->close();
        // The sheet says «came» with its own words, in a line of the history on the day of the visit.
        foreach (self::sheet_outcomes($centre) as $id => $days) {
            foreach ($days as $time => $state) {
                if ($state === 'came') {
                    $out[$id][] = $time;
                }
            }
        }
        foreach ($out as $id => $times) {
            sort($times);
            // The same visit, said in two places: once a day.
            $days = [];
            foreach ($times as $time) {
                $days[userdate($time, '%Y-%m-%d', 99, false)] = $days[userdate($time, '%Y-%m-%d', 99, false)] ?? $time;
            }
            $out[$id] = array_values($days);
        }
        return $out;
    }

    /**
     * What the lines of the sheets say of the visits of each family: it came, it did not or it called it off, on the day of
     * its visit. Read by their own words, whole: «No vino a la visita» has «vino a la visita» inside.
     *
     * @param \stdClass $centre
     * @return array Household id => moment (0, the sheet does not write the day) => came, notcame or off; said both, it came
     */
    public static function sheet_outcomes(\stdClass $centre): array {
        global $DB;
        $strings = get_string_manager();
        $words = [];
        foreach (array_unique(['es', 'en', 'pt', 'pt_br', (string) ($centre->lang ?: 'en')]) as $lang) {
            foreach (['attended' => 'came', 'absent' => 'notcame', 'cancelled' => 'off'] as $key => $state) {
                $words[$strings->get_string('teamnote_' . $key, self::C, null, $lang)] = $state;
            }
        }
        $like = [];
        $params = ['centreid' => $centre->id];
        foreach (array_keys($words) as $i => $word) {
            $like[] = $DB->sql_like('body', ':w' . $i);
            $params['w' . $i] = '%' . $DB->sql_like_escape((string) $word) . '%';
        }
        $rows = $DB->get_recordset_select(
            households::CONTACTS,
            "centreid = :centreid AND direction <> 'in' AND (" . implode(' OR ', $like) . ')',
            $params,
            'timecreated ASC, id ASC',
            'id, householdid, body, dayonly, timecreated'
        );
        $out = [];
        foreach ($rows as $row) {
            foreach (explode('. ', explode(' · ', (string) $row->body, 2)[0]) as $said) {
                $state = $words[trim($said, " .\t\n")] ?? '';
                // Without the day of its visit, the line carries when it was loaded.
                $time = (int) $row->dayonly ? (int) $row->timecreated : 0;
                if ($state !== '' && ($out[(int) $row->householdid][$time] ?? '') !== 'came') {
                    $out[(int) $row->householdid][$time] = $state;
                }
            }
        }
        $rows->close();
        return $out;
    }

    /**
     * The families that came in by a sheet of the team without a message of their own: the sheet has the day of their visit, or
     * none, and never the day they came.
     *
     * @param \stdClass $centre
     * @return array Household id => the first day it has a trace of, or 0
     */
    protected static function undated(\stdClass $centre): array {
        global $DB;
        $noted = $DB->get_fieldset_sql(
            "SELECT DISTINCT householdid
               FROM {" . households::CONTACTS . "}
              WHERE centreid = ? AND direction = 'note' AND entryid > 0",
            [$centre->id]
        );
        $visits = $DB->get_records_sql_menu(
            'SELECT householdid, MIN(visit) FROM {' . teamnotes::T . '} WHERE centreid = ? AND visit > 0 GROUP BY householdid',
            [$centre->id]
        );
        $ids = array_map('intval', array_unique(array_merge($noted, array_keys($visits))));
        $wrote = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $wrote += array_flip($DB->get_fieldset_select(
                households::CONTACTS,
                'householdid',
                "householdid $in AND direction = 'in' AND channel <> 'page'",
                $params
            ));
        }
        $out = [];
        foreach ($ids as $id) {
            if (!isset($wrote[$id])) {
                $out[$id] = (int) ($visits[$id] ?? 0);
            }
        }
        return $out;
    }

    /**
     * How a family said it heard of the centre; on a network or recommended by another centre, which one.
     *
     * @param \stdClass $h
     * @return string
     */
    protected static function said(\stdClass $h): string {
        if (!$h->heardfrom) {
            return '';
        }
        $said = get_string('heard_' . (in_array($h->heardfrom, lists::HEARD_FROM, true) ? $h->heardfrom : 'other'), self::C);
        $net = trim((string) ($h->heardnet ?? ''));
        return $said . (in_array($h->heardfrom, ['social', 'referral'], true) && $net !== '' ? ' · ' . $net : '');
    }

    /**
     * The totals of some families: each number is the one every screen says.
     *
     * @param array[] $families
     * @return int[] n, open (neither closed nor gone), came, applied, waitlist, place, enrolled, students, went, closed, nokids,
     *     undated (when they came is not known: they are of a sheet of the team), outside (only in a file) and, of them, notcame and notapplied (nothing says whether they did)
     */
    public static function sum(array $families): array {
        $count = fn(callable $is) => count(array_filter($families, $is));
        return ['n' => count($families),
            'open' => $count(fn($f) => !$f['closed'] && empty($f['went'])),
            'came' => $count(fn($f) => !empty($f['came'])),
            'applied' => $count(fn($f) => !empty($f['applied'])),
            'waitlist' => $count(fn($f) => !empty($f['waitlist'])),
            'place' => $count(fn($f) => !empty($f['place'])),
            'enrolled' => $count(fn($f) => !empty($f['enrolled'])),
            'students' => array_sum(array_map(fn($f) => (int) ($f['students'] ?? 0), $families)),
            'went' => $count(fn($f) => !empty($f['went'])),
            'closed' => $count(fn($f) => !empty($f['closed'])),
            'nokids' => $count(fn($f) => !empty($f['nokids'])),
            'undated' => $count(fn($f) => isset($f['dated']) && !$f['dated']),
            'outside' => $count(fn($f) => !self::lives($f)),
            'notcame' => $count(fn($f) => !self::lives($f) && empty($f['known']['came']) && empty($f['came'])),
            'notapplied' => $count(fn($f) => !self::lives($f) && empty($f['known']['applied']) && empty($f['applied']))];
    }

    /**
     * The three kinds of families that ask for a place: those that had nothing to do with the centre, those that knew it
     * (they had children there, or studied there) and those already of it.
     *
     * @param array[] $families From read(), of any group.
     * @return int[] all, fresh, knew, former, alumni and centre.
     */
    public static function kinds(array $families): array {
        $mine = self::group($families, 'campaign');
        $by = array_count_values(array_column($mine, 'who'));
        $centre = count(self::group($families, 'centre'));
        $former = (int) ($by['former'] ?? 0);
        $alumni = (int) ($by['alumni'] ?? 0);
        return ['all' => count($mine) + $centre, 'fresh' => count($mine) - $former - $alumni, 'knew' => $former + $alumni,
            'former' => $former, 'alumni' => $alumni, 'centre' => $centre];
    }

    /**
     * What came in a time, for «Your work»: the new families that came in and those that came to see the centre, on the day they
     * came whenever it was marked; the same families the reports count. Worked out once every few minutes.
     *
     * @param \stdClass $centre
     * @param int $from
     * @param int $now
     * @return int[] families that came in, and that came to see the centre
     */
    public static function week(\stdClass $centre, int $from, int $now): array {
        $cache = \cache::make(self::C, 'outs');
        $kept = $cache->get('w' . (int) $centre->id);
        if (is_array($kept) && $kept[0] > $now - 5 * MINSECS && $kept[0] <= $now && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return $kept[1];
        }
        $inside = fn(int $time) => $time >= $from && $time <= $now;
        $families = self::group(self::prospects($centre, $now), 'campaign');
        $out = [count(array_filter($families, fn($f) => $inside($f['first']))),
            count(array_filter($families, fn($f) => (bool) array_filter($f['visits'], $inside)))];
        $cache->set('w' . (int) $centre->id, [$now, $out]);
        return $out;
    }

    /**
     * The tokens some families have in the imported lists, by their emails and phones.
     *
     * @param int $centreid
     * @param int[] $ids
     * @return array Household id => tokens.
     */
    public static function tokens(int $centreid, array $ids): array {
        global $DB;
        $keys = [];
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $rows = $DB->get_recordset_select(households::ADULTS, "householdid $in", $params, '', 'id, householdid, email, phone');
            foreach ($rows as $row) {
                $keys[(int) $row->householdid][] = $row->email ? pseudonym::email((string) $row->email) : null;
                $keys[(int) $row->householdid][] = $row->phone ? pseudonym::phone((string) $row->phone) : null;
            }
            $rows->close();
        }
        return $keys ? (new pseudonym($centreid))->find_each('household', $keys) : [];
    }
}
