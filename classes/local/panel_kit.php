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
 * The data of the page of the kit of an act for the networks, apart from its template: when to post, the moments in
 * the channel of Telegram (with a click or at a time), the link of each network, the pictures, the texts (by the AI of
 * the centre or by rules) and the script.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_kit {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The page.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $event, \stdClass $centre, bool $canact, int $now): array {
        $c = self::C;
        $url = new \moodle_url('/local/oksigeniaeguahe/kit.php', ['id' => $event->id]);
        $texts = kit::texts($event, $centre);
        $pictures = [];
        foreach (array_keys(kit::SHAPES) as $shape) {
            [$w, $h] = kit::SHAPES[$shape];
            $pictures[] = ['name' => get_string('kit_shape_' . $shape, $c), 'size' => $w . ' × ' . $h,
                'url' => (new \moodle_url($url, ['img' => $shape]))->out(false),
                'download' => (new \moodle_url($url, ['img' => $shape, 'bajar' => 1]))->out(false), 'w' => $w, 'h' => $h];
        }
        $shots = [];
        $total = 0;
        foreach ($texts['script'] as $i => $shot) {
            $total += (int) $shot['seconds'];
            $shots[] = ['n' => $i + 1, 'shot' => $shot['shot'], 'seconds' => get_string('kit_seconds', $c, (int) $shot['seconds']),
                'onscreen' => $shot['onscreen']];
        }
        $ai = ai::ready($centre);
        $channel = self::channel($event, $centre, $canact, $now);
        return [
            'head' => panel::head([
                'title' => get_string('kit_title', $c),
                'ante' => format_string($event->name),
                'sub' => get_string('kit_sub', $c),
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false),
                    'name' => format_string($event->name)],
            ]),
            'when' => self::days(kit::when($event, (string) $centre->lang, $now), $channel),
            'channel' => $channel,
            'links' => kit::links($event, $centre),
            'pictures' => $pictures,
            'posterurl' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id, 'poster' => 1]))->out(false),
            'post' => $texts['post'],
            'forward' => $texts['forward'],
            'shots' => $shots,
            'total' => get_string('kit_seconds', $c, $total),
            'byai' => ($texts['by'] ?? '') === 'ai' ? get_string('kit_by_ai', $c, ai::name($centre) ?: get_string('kit_ai', $c)) :
                '',
            'canwrite' => $canact && $ai,
            'writelabel' => get_string(($texts['by'] ?? '') === 'ai' ? 'kit_write_again' : 'kit_write', $c),
            'canforget' => $canact && ($texts['by'] ?? '') === 'ai',
            'noai' => !$ai ? get_string('kit_noai', $c) : '',
            'action' => $url->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * When to post and the channel, as one line to the act: each day of the kit carries its moment in the channel
     * (post), so the team reads in one place what to post by hand and what the channel does. The kit leaves out the
     * days already gone; with a channel, those come back (gone), because their post may be out and can be taken out.
     *
     * @param array $when Of kit::when().
     * @param array|false $channel Of self::channel().
     * @return array Of key, day, what, gone and post (a moment of the channel, or false).
     */
    protected static function days(array $when, $channel): array {
        $kit = array_column($when, null, 'key');
        $posts = $channel && $channel['ready'] ? array_column($channel['moments'], null, 'moment') : [];
        $out = [];
        foreach (array_keys(kit::WHEN) as $key) {
            if (!isset($kit[$key]) && !isset($posts[$key])) {
                continue;
            }
            $day = $kit[$key] ?? ['key' => $key, 'day' => $posts[$key]['day'], 'what' => '', 'gone' => true];
            $out[] = $day + ['gone' => false, 'post' => $posts[$key] ?? false];
        }
        return $out;
    }

    /**
     * The moments of the act in the channel of Telegram: when each would go by itself, where its post is, and what a
     * person can do: post it now, at a time, not post it or take it out, or leave it as the centre says. False without
     * Marandu.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array|false
     */
    protected static function channel(\stdClass $event, \stdClass $centre, bool $canact, int $now) {
        $c = self::C;
        if (!messaging::available()) {
            return false;
        }
        $info = channel::info();
        $out = ['ready' => channel::ready(), 'title' => $info['title'] ?? '', 'url' => $info['url'] ?? '',
            'none' => get_string('kit_channel_none', $c), 'moments' => []];
        if (!$out['ready']) {
            return $out;
        }
        $when = fn(int $time) => userdate($time, get_string('strftimedaydatetime', 'langconfig'));
        $can = $canact && channel::public($event, $now);
        $known = channel::knows_state();
        $start = (int) $event->timestart;
        foreach (channel::moments($event, $centre, $now) as $m) {
            $status = (string) ($m['state']['status'] ?? '');
            $time = (int) ($m['state']['time'] ?? 0);
            $waiting = in_array($status, ['review', 'ready'], true);
            $published = $status === 'published';
            $choice = (string) ($m['choice']['do'] ?? '');
            // Not going out: decided so, by the centre or by whoever reviews, or too late. A failed one too, until
            // someone posts it again.
            $gone = !$published && !$waiting && (in_array($status, ['skipped', 'expired', 'failed', 'cancelled', 'remove',
                'removed'], true) || $choice === 'off' || ($m['day'] && $m['day'] < $now));
            if ($waiting) {
                $state = $time ? get_string('kit_channel_' . $status, $c, $when($time)) :
                    get_string($status === 'review' ? 'kit_channel_review_notime' : 'kit_channel_nofit', $c);
            } else if ($published) {
                $state = get_string('kit_channel_published', $c, $when($time));
            } else if (in_array($status, ['skipped', 'expired', 'failed'], true)) {
                $state = get_string('kit_channel_' . $status, $c);
            } else if (in_array($status, ['cancelled', 'remove', 'removed'], true) || $choice === 'off') {
                $state = get_string('kit_channel_cancelled', $c);
            } else if ($m['day'] && $m['day'] < $now) {
                $state = get_string('kit_channel_past', $c);
            } else if (!$known) {
                // An older Marandu does not tell where a post is: what was asked, and no more.
                $state = get_string($m['auto'] || $choice !== '' ? 'kit_channel_asked' : 'kit_channel_click', $c);
            } else {
                $state = get_string($m['auto'] ? 'kit_channel_auto_none' : 'kit_channel_click', $c);
            }
            $moment = $m['moment'];
            // To schedule: its day at ten, or within the hour; never after the act starts.
            $value = min(max($m['day'] ? $m['day'] + 10 * HOURSECS : 0, $now + HOURSECS), $start - HOURSECS);
            $out['moments'][] = [
                'moment' => $moment,
                'name' => get_string('kit_channel_moment_' . $moment, $c),
                'day' => $m['day'] ? userdate($m['day'], get_string('strftimedaydate', 'langconfig')) :
                    get_string('kit_channel_day_now', $c),
                'alone' => $m['auto'] && $choice === '' ? get_string('kit_channel_alone', $c) : '',
                'state' => $state,
                'tone' => $published ? 'hecho' : ($waiting ? 'espera' : ($gone ? 'fuera' : 'libre')),
                'done' => $published,
                'waits' => $waiting,
                'out' => $gone,
                'free' => !$published && !$waiting && !$gone,
                'cannow' => $can && !$published,
                'canat' => $can && !$published,
                'canoff' => $can && ($waiting || $published || (!$known && ($m['auto'] || in_array($choice, ['now', 'at'], true)))),
                'offlabel' => get_string($published ? 'kit_channel_remove' : 'kit_channel_off', $c),
                'canreset' => $can && $choice !== '',
                'min' => userdate($now + 5 * MINSECS, '%Y-%m-%dT%H:%M'),
                'max' => userdate($start - MINSECS, '%Y-%m-%dT%H:%M'),
                'value' => userdate($value, '%Y-%m-%dT%H:%M'),
                // The day in three fields in the order of the centre, and the hour by its clock.
                'atdate' => ['labelled' => false, 'arialabel' => get_string('kit_channel_at_label', $c)] +
                    panel::date_input('at', userdate($value, '%Y-%m-%d'), $centre, 'kit-at-' . $moment),
                'attime' => userdate($value, '%H:%M'),
            ];
        }
        return $out;
    }
}
