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
 * «Where from, month by month», in «Cifras» (Jujol's sheet): the channels compared in time, the whole year round. How
 * families heard of the centre (and on which network), where their first contact came from and where the centre and
 * the families talk, each channel with the last twelve months and the twelve before; nothing folded into «Others»,
 * because what is little used is still used. Underneath, the notices to the phones and the mails, and the channel of
 * the centre with its posts. No network or channel is written here: they come from what families said, the entries of
 * the centre and Marandu.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_channels {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The three voices, in order. */
    const VOICES = ['know', 'arrive', 'talk'];

    /** @var array[] What each channel of Marandu tells of its notices, beyond sent and tapped. */
    const TELLS = ['whatsapp' => ['delivered', 'read'], 'telegram' => []];

    /** @var string[] Where the centre and the families talk: a sign-up to an event or a form that comes in is not talk. */
    const TALK = ['email', 'whatsapp', 'telegram', 'phone', 'walkin', 'page'];

    /** @var string[] The steps of a notice, in order. */
    const STAGES = ['sent', 'delivered', 'read', 'clicked'];

    /**
     * The sheet, for the campaign under way and every course; false when nothing has come in in two years.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array|false
     */
    public static function context(\stdClass $centre, int $now) {
        $c = self::C;
        $bounds = self::bounds($now);
        $months = [];
        for ($i = 12; $i < 24; $i++) {
            $months[] = ['key' => userdate($bounds[$i], '%Y-%m'), 'label' => userdate($bounds[$i], '%b'),
                'name' => userdate($bounds[$i], '%B')];
        }
        $voices = [];
        foreach (self::VOICES as $voice) {
            $events = $voice === 'know' ? self::know($centre, $bounds) : ($voice === 'arrive' ? self::arrive($centre, $bounds) :
                self::talk($centre, $bounds));
            $built = self::voice($voice, $events, $bounds, $months);
            if ($built) {
                $voices[] = $built;
            }
        }
        $notices = self::notices($centre, $bounds[12], $now);
        $channel = self::channel($centre, $bounds, $months);
        if (!$voices && !$notices && !$channel) {
            return false;
        }
        $monthyear = get_string('strftimemonthyear', 'langconfig');
        return [
            'title' => get_string('panel_ct_title', $c),
            'cut' => get_string('panel_ct_cut', $c, (object) ['from' => userdate($bounds[12], $monthyear),
                'to' => userdate($bounds[23], $monthyear)]),
            'nowlabel' => get_string('panel_ct_now', $c),
            'beforelabel' => get_string('panel_ct_before', $c),
            'uplabel' => get_string('panel_ct_up_label', $c),
            'downlabel' => get_string('panel_ct_down_label', $c),
            'tablelabel' => get_string('panel_as_table', $c),
            'voices' => $voices,
            'notices' => $notices,
            'channel' => $channel,
        ];
    }

    /**
     * The starts of the twenty-four months up to this one and the start of the next, as the centre lives them: the first
     * twelve are the year before, the last twelve this one.
     *
     * @param int $now
     * @return int[] 25 times.
     */
    public static function bounds(int $now): array {
        $tz = \core_date::get_user_timezone_object();
        $first = (new \DateTime('@' . $now))->setTimezone($tz);
        $first->setDate((int) $first->format('Y'), (int) $first->format('n'), 1)->setTime(0, 0);
        $first->modify('-23 months');
        $out = [];
        for ($i = 0; $i <= 24; $i++) {
            $out[] = $first->getTimestamp();
            $first->modify('+1 month');
        }
        return $out;
    }

    /**
     * Which of the twenty-four months a time falls in, or -1 outside them.
     *
     * @param int[] $bounds
     * @param int $time
     * @return int
     */
    protected static function month(array $bounds, int $time): int {
        if ($time < $bounds[0] || $time >= $bounds[24]) {
            return -1;
        }
        for ($i = 23; $i >= 0; $i--) {
            if ($time >= $bounds[$i]) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * How families heard of the centre, as they said it, by when they came; a network, with its name.
     *
     * @param \stdClass $centre
     * @param int[] $bounds
     * @return array[] Each with key, name, group and time.
     */
    protected static function know(\stdClass $centre, array $bounds): array {
        global $DB;
        $c = self::C;
        $out = [];
        $rows = $DB->get_records_select(
            households::T,
            'centreid = ? AND incentre = 0 AND heardfrom IS NOT NULL AND timecreated >= ? AND timecreated < ?',
            [$centre->id, $bounds[0], $bounds[24]],
            '',
            'id, heardfrom, heardnet, timecreated'
        );
        foreach ($rows as $h) {
            $heard = in_array($h->heardfrom, lists::HEARD_FROM, true) ? $h->heardfrom : 'other';
            $net = trim((string) ($h->heardnet ?? ''));
            if ($heard === 'social' && $net !== '') {
                $out[] = ['key' => 'social:' . \core_text::strtolower($net), 'name' => $net,
                    'group' => get_string('heard_social', $c), 'time' => (int) $h->timecreated];
            } else {
                $out[] = ['key' => $heard, 'name' => get_string('heard_' . $heard, $c), 'group' => '',
                    'time' => (int) $h->timecreated];
            }
        }
        return $out;
    }

    /**
     * Where the first contact of each family came from: the entry and its place, or the channel.
     *
     * @param \stdClass $centre
     * @param int[] $bounds
     * @return array[]
     */
    protected static function arrive(\stdClass $centre, array $bounds): array {
        $out = [];
        $campaign = panel_metrics::campaigns($centre)['live'];
        foreach (panel_metrics::families($centre, $campaign) as $f) {
            if ($f['first'] >= $bounds[0] && $f['first'] < $bounds[24]) {
                $name = (string) $f['voices']['first'];
                $out[] = ['key' => \core_text::strtolower($name), 'name' => $name, 'group' => '', 'time' => $f['first']];
            }
        }
        return $out;
    }

    /**
     * What the families and the centre said to each other, by channel: the messages each way, never the notes of the
     * team.
     *
     * @param \stdClass $centre
     * @param int[] $bounds
     * @return array[]
     */
    protected static function talk(\stdClass $centre, array $bounds): array {
        global $DB;
        $c = self::C;
        $out = [];
        [$in, $params] = $DB->get_in_or_equal(self::TALK);
        $rows = $DB->get_recordset_select(
            households::CONTACTS,
            "centreid = ? AND direction IN ('in', 'out') AND timecreated >= ? AND timecreated < ? AND channel $in",
            array_merge([$centre->id, $bounds[0], $bounds[24]], $params),
            '',
            'id, channel, timecreated'
        );
        foreach ($rows as $row) {
            $out[] = ['key' => $row->channel, 'name' => get_string('channel_' . $row->channel, $c), 'group' => '',
                'time' => (int) $row->timecreated];
        }
        $rows->close();
        return $out;
    }

    /**
     * One voice: a row for each channel with its twelve months and the twelve before, on the same scale for the whole
     * voice, whether it goes up or down, and the line at rest; false without anything in two years.
     *
     * @param string $voice
     * @param array[] $events
     * @param int[] $bounds
     * @param array[] $months
     * @return array|false
     */
    protected static function voice(string $voice, array $events, array $bounds, array $months) {
        $c = self::C;
        $rows = [];
        foreach ($events as $e) {
            $i = self::month($bounds, $e['time']);
            if ($i < 0) {
                continue;
            }
            $rows[$e['key']] = $rows[$e['key']] ?? ['name' => $e['name'], 'group' => $e['group'], 'n' => array_fill(0, 24, 0)];
            $rows[$e['key']]['n'][$i]++;
        }
        if (!$rows) {
            return false;
        }
        $unit = fn(int $n) => get_string($voice === 'talk' ? ($n === 1 ? 'panel_ct_msgs_one' : 'panel_ct_msgs') :
            ($n === 1 ? 'panel_likes_fams_one' : 'panel_likes_fams'), $c, $n);
        $most = 1;
        foreach ($rows as $row) {
            $most = max($most, max($row['n']));
        }
        $out = [];
        $slugs = [];
        foreach ($rows as $row) {
            $now = array_slice($row['n'], 12);
            $before = array_slice($row['n'], 0, 12);
            $total = array_sum($now);
            $last3 = array_sum(array_slice($now, 9));
            $prev3 = array_sum(array_slice($before, 9));
            $trend = $last3 > $prev3 * 1.3 + 1 ? 'sube' : ($last3 < $prev3 * 0.7 - 1 ? 'baja' : 'igual');
            $peak = array_search(max($now), $now, true);
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', \core_text::strtolower(\core_text::specialtoascii($row['name']))), '-');
            $slug = ($slug !== '' ? $slug : 'canal') . (isset($slugs[$slug]) ? '-' . count($slugs) : '');
            $slugs[$slug] = true;
            $cells = [];
            foreach ($now as $k => $n) {
                $cells[] = ['n' => $n, 'b' => $before[$k], 'v' => round($n / $most, 4), 'bv' => round($before[$k] / $most, 4),
                    'zero' => $n === 0, 'line' => get_string('panel_ct_month_line', $c, (object) ['name' => $row['name'],
                    'month' => $months[$k]['name'], 'n' => $n, 'b' => $before[$k]])];
            }
            $a = (object) ['name' => $row['name'], 'total' => $unit($total), 'before' => array_sum($before),
                'trend' => get_string('panel_ct_' . $trend, $c), 'last3' => $last3, 'prev3' => $prev3,
                'peak' => $total ? $months[$peak]['name'] : ''];
            $out[] = ['key' => $voice, 'slug' => $slug, 'name' => $row['name'], 'group' => $row['group'],
                'hasgroup' => $row['group'] !== '', 'total' => $total, 'before' => array_sum($before), 'trend' => $trend,
                'beforetext' => get_string('panel_ct_before_n', $c, array_sum($before)),
                'trendmark' => ['sube' => '↑', 'baja' => '↓', 'igual' => '='][$trend],
                'trendtext' => get_string('panel_ct_' . $trend, $c), 'months' => $cells, 'last3' => $last3, 'prev3' => $prev3,
                'line' => get_string($total ? 'panel_ct_line' : 'panel_ct_line_none', $c, $a)];
        }
        usort($out, fn($x, $y) => [$y['total'], $y['before']] <=> [$x['total'], $x['before']]);
        $up = array_values(array_filter($out, fn($r) => $r['trend'] === 'sube'));
        usort($up, fn($x, $y) => ($y['last3'] + 1) / ($y['prev3'] + 1) <=> ($x['last3'] + 1) / ($x['prev3'] + 1));
        $top = $out[0];
        $a = (object) ['up' => $up ? $up[0]['name'] : '', 'top' => $top['name'], 'n' => $unit($top['total'])];
        return ['key' => $voice, 'name' => get_string('panel_ct_' . $voice, $c), 'months' => $months, 'rows' => $out,
            'rest' => get_string($up ? 'panel_ct_rest_up' : 'panel_ct_rest', $c, $a),
            'def' => get_string('panel_ct_' . $voice . '_def', $c)];
    }

    /**
     * The notices to the phones (through Marandu) and the mails of the last twelve months: by channel, as a funnel from
     * sent to tapped, what a channel does not tell left as not told (never a zero); and which notice is tapped most in
     * each channel.
     *
     * @param \stdClass $centre
     * @param int $from
     * @param int $to
     * @return array|false
     */
    protected static function notices(\stdClass $centre, int $from, int $to) {
        global $DB;
        $c = self::C;
        $channels = [];
        $bykind = [];
        $kinds = array_flip(messaging::NOTICES);
        foreach (self::marandu_notices($centre, $from, $to) as $row) {
            $kind = $kinds[$row['notice']] ?? null;
            if ($kind === null) {
                continue;
            }
            $ch = (string) $row['channel'];
            $channels[$ch] = $channels[$ch] ?? array_fill_keys(array_merge(self::STAGES, ['failed']), 0);
            foreach (array_merge(self::STAGES, ['failed']) as $stage) {
                $channels[$ch][$stage] += (int) ($row[$stage] ?? 0);
            }
            $bykind[$kind][$ch]['sent'] = ($bykind[$kind][$ch]['sent'] ?? 0) + (int) $row['sent'];
            $bykind[$kind][$ch]['clicked'] = ($bykind[$kind][$ch]['clicked'] ?? 0) + (int) $row['clicked'];
        }
        // The mails, as Eguahe counts them: what went, and the mails with something pressed inside.
        $rows = $DB->get_records_select(
            seen::T,
            'centreid = ? AND timecreated >= ? AND timecreated < ? AND (kind = ? OR via IS NOT NULL)',
            [$centre->id, $from, $to, 'mailsent'],
            '',
            'id, householdid, kind, what, via'
        );
        $pressed = [];
        foreach ($rows as $row) {
            if ($row->kind === 'mailsent') {
                $bykind[$row->what]['email']['sent'] = ($bykind[$row->what]['email']['sent'] ?? 0) + 1;
            } else {
                $pressed[$row->via][$row->householdid] = true;
            }
        }
        foreach ($pressed as $kind => $households) {
            $bykind[$kind]['email']['clicked'] = count($households);
        }
        $sent = array_sum(array_map(fn($k) => (int) ($k['email']['sent'] ?? 0), $bykind));
        if ($sent) {
            $clicked = 0;
            foreach ($bykind as $k) {
                $clicked += min((int) ($k['email']['clicked'] ?? 0), (int) ($k['email']['sent'] ?? 0));
            }
            $channels['email'] = ['sent' => $sent, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'clicked' => $clicked];
        }
        $channels = array_filter($channels, fn($r) => $r['sent'] > 0);
        if (!$channels) {
            return false;
        }
        uasort($channels, fn($x, $y) => $y['sent'] <=> $x['sent']);
        $name = fn(string $ch) => get_string('channel_' . (in_array($ch, lists::CHANNELS, true) ? $ch : 'other'), $c);
        $rate = fn(int $part, int $whole) => get_string('panel_ct_rate', $c, $whole ? (int) round(100 * $part / $whole) : 0);
        $rows = [];
        foreach ($channels as $ch => $r) {
            $tells = $ch === 'email' ? [] : (self::TELLS[$ch] ?? []);
            $stages = [];
            foreach (self::STAGES as $stage) {
                $missing = in_array($stage, ['delivered', 'read'], true) && !in_array($stage, $tells, true);
                $stages[] = ['name' => get_string('panel_ct_stage_' . $stage, $c), 'n' => $missing ? 0 : $r[$stage],
                    'v' => $missing ? 0 : round($r[$stage] / $r['sent'], 4), 'missing' => $missing,
                    'missingtext' => $ch === 'email' ? get_string('panel_ct_not_counted', $c) :
                        get_string('panel_ct_says_not', $c, $name($ch))];
            }
            $a = (object) ['name' => $name($ch), 'sent' => $r['sent'], 'read' => $r['read'], 'clicked' => $r['clicked']];
            $line = in_array('read', $tells, true) ? 'panel_ct_notice_line_read' : 'panel_ct_notice_line';
            $rows[] = ['channel' => $ch, 'name' => $name($ch), 'sent' => $r['sent'], 'clicked' => $r['clicked'],
                'stages' => $stages, 'failed' => $r['failed'] ?: false,
                'failedtext' => $r['failed'] ? get_string('panel_ct_failed', $c, $r['failed']) : false,
                'line' => get_string($line, $c, $a)];
        }
        $table = [];
        foreach (familymail::KINDS as $kind) {
            if (empty($bykind[$kind])) {
                continue;
            }
            $cells = [];
            $best = 0;
            foreach ($rows as $row) {
                $k = $bykind[$kind][$row['channel']] ?? ['sent' => 0, 'clicked' => 0];
                $s = (int) ($k['sent'] ?? 0);
                $cl = min((int) ($k['clicked'] ?? 0), $s);
                $best = $s ? max($best, $cl / $s) : $best;
                $cells[] = ['name' => $row['name'], 'sent' => $s, 'clicked' => $cl, 'v' => $s ? round($cl / $s, 4) : 0,
                    'rate' => $s ? $rate($cl, $s) : '—', 'oftext' => $s ? get_string('panel_ct_of', $c, (object) ['clicked' => $cl,
                    'sent' => $s]) : ''];
            }
            if (!array_filter(array_column($cells, 'sent'))) {
                continue;
            }
            foreach ($cells as $i => $cell) {
                $cells[$i]['best'] = $best > 0 && $cell['sent'] && abs($cell['clicked'] / $cell['sent'] - $best) < 1e-9;
            }
            $table[] = ['kind' => $kind, 'name' => get_string('mail_name_' . $kind, $c), 'cells' => $cells];
        }
        $ordered = $rows;
        usort($ordered, fn($x, $y) => $y['clicked'] / $y['sent'] <=> $x['clicked'] / $x['sent']);
        return [
            'title' => get_string('panel_ct_notices', $c),
            'rows' => $rows,
            'channels' => array_map(fn($r) => ['key' => $r['channel'], 'name' => $r['name']], $rows),
            'bykind' => $table,
            'bykindtitle' => get_string('panel_ct_bykind', $c),
            'bykindcol' => get_string('panel_ct_notice', $c),
            'rest' => get_string('panel_ct_notices_rest', $c, (object) ['name' => $ordered[0]['name'],
                'rate' => $rate($ordered[0]['clicked'], $ordered[0]['sent'])]),
            'def' => get_string('panel_ct_notices_def', $c),
        ];
    }

    /**
     * The notices of Marandu for the families of this centre: on a site with this centre alone, all of them (also those
     * from before Marandu kept the household of each); with more centres, by the households of this one when Marandu can
     * tell them apart (since its 0.30.0), and none when it cannot, so the notices of another centre never count here.
     *
     * @param \stdClass $centre
     * @param int $from
     * @param int $to
     * @return array[] As api::notice_stats() gives them.
     */
    protected static function marandu_notices(\stdClass $centre, int $from, int $to): array {
        global $DB;
        $api = '\local_oksigeniamarandu\api';
        if (!class_exists($api) || !method_exists($api, 'notice_stats')) {
            return [];
        }
        if ($DB->count_records(centres::T) === 1) {
            return $api::notice_stats(self::C, $from, $to);
        }
        if ((new \ReflectionMethod($api, 'notice_stats'))->getNumberOfParameters() >= 5) {
            $ids = array_map('intval', $DB->get_fieldset_select(households::T, 'id', 'centreid = ?', [$centre->id]));
            return $ids ? $api::notice_stats(self::C, $from, $to, '', $ids) : [];
        }
        return [];
    }

    /**
     * The channel of the centre (through Marandu): how many people it has, month by month, and each post of an open day
     * of this centre with its taps over the people it had then and its reactions; false without posts.
     *
     * @param \stdClass $centre
     * @param int[] $bounds
     * @param array[] $months
     * @return array|false
     */
    protected static function channel(\stdClass $centre, array $bounds, array $months) {
        global $DB;
        $c = self::C;
        if (!class_exists('\local_oksigeniamarandu\api') || !method_exists('\local_oksigeniamarandu\api', 'channel_stats')) {
            return false;
        }
        $events = $DB->get_records_menu(events::T, ['centreid' => $centre->id], '', 'id, name');
        $posts = [];
        foreach (\local_oksigeniamarandu\api::channel_stats(self::C, channel::KIND) as $stat) {
            $id = (int) preg_replace('/^' . preg_quote(channel::KIND, '/') . '-/', '', (string) $stat['key']);
            if (!isset($events[$id]) || empty($stat['timepublished']) || $stat['timepublished'] < $bounds[12]) {
                continue;
            }
            $posts[] = $stat + ['name' => format_string($events[$id])];
        }
        if (!$posts) {
            return false;
        }
        // The people of the channel, the last count of each month: by day if Marandu tells it, else at each post.
        $counts = [];
        if (method_exists('\local_oksigeniamarandu\api', 'channel_members')) {
            foreach (\local_oksigeniamarandu\api::channel_members($bounds[12], $bounds[24]) as $day => $n) {
                $counts[] = [strtotime($day . ' 12:00'), (int) $n];
            }
        }
        foreach ($counts ? [] : $posts as $post) {
            if ($post['members'] !== null) {
                $counts[] = [(int) $post['timepublished'], (int) $post['members']];
            }
        }
        usort($counts, fn($x, $y) => $x[0] <=> $y[0]);
        $bymonth = array_fill(0, 12, null);
        foreach ($counts as [$time, $n]) {
            $i = self::month($bounds, $time) - 12;
            if ($i >= 0) {
                $bymonth[$i] = $n;
            }
        }
        $most = max(1, max(array_map('intval', $bymonth)));
        $members = [];
        foreach ($bymonth as $i => $n) {
            $members[] = ['month' => $months[$i]['label'], 'n' => (int) $n, 'v' => round((int) $n / $most, 4),
                'line' => $n === null ? get_string('panel_ct_members_none', $c, $months[$i]['name']) :
                    get_string('panel_ct_members_line', $c, (object) ['month' => $months[$i]['name'], 'n' => $n])];
        }
        $last = $counts ? end($counts)[1] : 0;
        usort($posts, fn($x, $y) => $y['timepublished'] <=> $x['timepublished']);
        $top = null;
        $rows = [];
        foreach ($posts as $post) {
            $reactions = $post['reactions'];
            arsort($reactions);
            $list = [];
            foreach ($reactions as $emoji => $n) {
                $list[] = ['emoji' => (string) $emoji, 'n' => (int) $n];
            }
            $people = $post['members'] !== null ? (int) $post['members'] : 0;
            $rv = $people ? round(min(1, $post['clicks'] / $people), 4) : 0;
            $rate = $people ? get_string('panel_ct_rate', $c, (int) round(100 * $post['clicks'] / $people)) : '';
            $a = (object) ['name' => $post['name'], 'clicks' => $post['clicks'], 'members' => $people, 'rate' => $rate,
                'reactions' => (int) $post['reactionstotal']];
            $row = ['name' => $post['name'], 'date' => date('Y-m-d', (int) $post['timepublished']),
                'datetext' => userdate((int) $post['timepublished'], get_string('strftimedaydate', 'langconfig')),
                'members' => $people, 'memberstext' => $people ? get_string('panel_ct_in_channel', $c, $people) : '',
                'clicks' => (int) $post['clicks'], 'rate' => $rate, 'rv' => $rv, 'reactions' => $list,
                'hasreactions' => (bool) $list,
                'reactionstext' => get_string('panel_ct_reactions', $c, implode(', ', array_map(
                    fn($r) => $r['emoji'] . ' ' . $r['n'],
                    $list
                ))),
                'line' => get_string($people ? 'panel_ct_post_line' : 'panel_ct_post_line_nopeople', $c, $a), 'top' => false];
            if ($top === null || $rv > $rows[$top]['rv'] || (!$people && $row['clicks'] > $rows[$top]['clicks'])) {
                $top = count($rows);
            }
            $rows[] = $row;
        }
        $rows[$top]['top'] = true;
        $t = $rows[$top];
        return [
            'title' => get_string('panel_ct_channel', $c),
            'members' => $members,
            'memberstext' => get_string('panel_ct_members_month', $c),
            'now' => $last,
            'nowtext' => get_string('panel_ct_members', $c),
            'posts' => $rows,
            'rest' => get_string($t['members'] ? 'panel_ct_channel_rest' : 'panel_ct_channel_rest_nopeople', $c, (object) [
                'name' => $t['name'], 'clicks' => $t['clicks'], 'members' => $t['members'], 'rate' => $t['rate']]),
            'def' => get_string('panel_ct_channel_def', $c),
            'tapstext' => get_string('panel_ct_taps', $c),
        ];
    }
}
