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
 * The open acts of a centre in the channel of Telegram of the organisation, with Oksigenia Marandu (its request, with
 * the OK of Enrique, 8 November 2026; the panel of the centre, 9 November). Each open act has the four moments of its
 * kit: announce it, tell something of it, the last call and the same day. Each one goes by itself when the centre says
 * so (the announcement, unless it says otherwise), or with a click or at a time a person chooses from the kit of the
 * act. Marandu decides the rest: whoever reviews the channel sees it first if the site wants it, and the pace of the
 * channel places it so nothing goes out all at once. Changed, it changes while it waits; closed, it is taken back. Only
 * what is public of the act: never names of people nor photos. Without Marandu or its channel, nothing.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class channel {
    /** @var string */
    const COMPONENT = 'local_oksigeniaeguahe';

    /** @var string The kind of post of the announcement of an open day, as lib.php declares it. */
    const KIND = 'open_day';

    /** @var string The kind of post of the rest: the other moments of an open day, and every moment of other open acts. */
    const ACT = 'act';

    /** @var string[] The moments of an act in the channel, those of its kit (kit::WHEN). */
    const MOMENTS = ['announce', 'tell', 'last', 'day'];

    /** @var string[] What goes by itself while the centre does not say otherwise. */
    const AUTO = ['announce'];

    /** @var string[] What a person may choose for a moment: now, at a time, not at all, or as the centre says. */
    const CHOICES = ['now', 'at', 'off', 'centre'];

    /**
     * Whether the site has the channel of Marandu ready.
     *
     * @return bool
     */
    public static function ready(): bool {
        try {
            return class_exists('\local_oksigeniamarandu\api') && method_exists('\local_oksigeniamarandu\api', 'channel_post') &&
                \local_oksigeniamarandu\api::channel_ready();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The channel as anyone finds it: its link, its name and how many people it has.
     *
     * @return array|null url, title and members (or null); null without a channel or when it is private.
     */
    public static function info(): ?array {
        try {
            return self::ready() && method_exists('\local_oksigeniamarandu\api', 'channel') ?
                \local_oksigeniamarandu\api::channel() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The moments that go by themselves for a centre.
     *
     * @param \stdClass $centre
     * @return string[]
     */
    public static function auto(\stdClass $centre): array {
        if (!isset($centre->channelauto)) {
            return self::AUTO;
        }
        return array_values(array_intersect(self::MOMENTS, explode(',', (string) $centre->channelauto)));
    }

    /**
     * The moments that go by themselves, as the table keeps them.
     *
     * @param string[] $moments
     * @return string Comma separated, in their order; empty, none.
     */
    public static function auto_value(array $moments): string {
        return implode(',', array_values(array_intersect(self::MOMENTS, $moments)));
    }

    /**
     * The kind and the key of the post of a moment: the announcement of an open day keeps the ones it always had.
     *
     * @param \stdClass $event
     * @param string $moment
     * @return string[] [kind, key]
     */
    public static function post_of(\stdClass $event, string $moment): array {
        if ($moment === 'announce' && $event->kind === 'openday') {
            return [self::KIND, self::KIND . '-' . $event->id];
        }
        return [self::ACT, self::ACT . '-' . $event->id . '-' . $moment];
    }

    /**
     * The act and the moment a post of Eguahe is of, from its key.
     *
     * @param string $key
     * @return array|null [event id, moment]; null if the key is not of Eguahe.
     */
    public static function of_key(string $key): ?array {
        if (preg_match('/^' . self::KIND . '-(\d+)$/', $key, $m)) {
            return [(int) $m[1], 'announce'];
        }
        if (preg_match('/^' . self::ACT . '-(\d+)-(' . implode('|', self::MOMENTS) . ')$/', $key, $m)) {
            return [(int) $m[1], $m[2]];
        }
        return null;
    }

    /**
     * Whether an act may be in the channel: open to all, with its page, and still to start.
     *
     * @param \stdClass $event
     * @param int $now
     * @return bool
     */
    public static function public(\stdClass $event, int $now): bool {
        return share::act_open($event, $now) && (int) $event->timestart > $now;
    }

    /**
     * When a moment goes by itself: the announcement, now; the others, from the start of their day, in the hours of the
     * channel. Never after the act starts.
     *
     * @param \stdClass $event
     * @param string $moment
     * @return int 0 for now.
     */
    public static function day_of(\stdClass $event, string $moment): int {
        if ($moment === 'announce') {
            return 0;
        }
        $day = (int) $event->timestart - kit::WHEN[$moment] * DAYSECS;
        return (new \DateTimeImmutable('@' . $day))->setTimezone(\core_date::get_server_timezone_object())->setTime(0, 0)
            ->getTimestamp();
    }

    /**
     * What a person chose for each moment of an act, kept with its kit.
     *
     * @param \stdClass $event
     * @return array By moment: do (now, at or off) and at (the time, for now and at).
     */
    public static function plan(\stdClass $event): array {
        $plan = kit::kept($event)['channel'] ?? [];
        return is_array($plan) ? $plan : [];
    }

    /**
     * Whether Marandu tells where each post is (since its 0.31.0).
     *
     * @return bool
     */
    public static function knows_state(): bool {
        return class_exists('\local_oksigeniamarandu\api') && method_exists('\local_oksigeniamarandu\api', 'channel_state');
    }

    /**
     * Where the post of a moment is in Marandu.
     *
     * @param \stdClass $event
     * @param string $moment
     * @return array|null status, time and expires; null if there is none.
     */
    public static function state(\stdClass $event, string $moment): ?array {
        [$kind, $key] = self::post_of($event, $moment);
        try {
            return method_exists('\local_oksigeniamarandu\api', 'channel_state') ?
                \local_oksigeniamarandu\api::channel_state(self::COMPONENT, $kind, $key) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Puts the moments of an act in the channel as they are now: posted, changed, or taken back. When an act is saved
     * and when a person chooses.
     *
     * @param \stdClass $event
     * @param int|null $now
     */
    public static function sync(\stdClass $event, ?int $now = null): void {
        $now = $now ?? time();
        if (!self::ready()) {
            return;
        }
        $centre = centres::get((int) $event->centreid);
        if (!$centre) {
            return;
        }
        $public = self::public($event, $now);
        $auto = self::auto($centre);
        $plan = self::plan($event);
        foreach (self::MOMENTS as $moment) {
            [$kind, $key] = self::post_of($event, $moment);
            try {
                $choice = $plan[$moment] ?? null;
                $do = $choice['do'] ?? (in_array($moment, $auto, true) ? 'auto' : '');
                if (!$public || $do === 'off') {
                    // Closed, or a person said no: out of the channel, also if it was in it.
                    \local_oksigeniamarandu\api::channel_cancel(self::COMPONENT, $kind, $key);
                    continue;
                }
                $state = self::state($event, $moment);
                $waiting = $state && in_array($state['status'], ['review', 'ready'], true);
                if ($do === '') {
                    // Not chosen and not by itself (the centre changed its mind): what waits does not go.
                    if ($waiting) {
                        \local_oksigeniamarandu\api::channel_cancel(self::COMPONENT, $kind, $key);
                    }
                    continue;
                }
                $when = $do === 'auto' ? self::day_of($event, $moment) : (int) ($choice['at'] ?? $now);
                if ($do === 'auto' && $when && $when < $now && !$waiting) {
                    // Its day passed before the act was saved: a moment of the past does not go by itself.
                    continue;
                }
                \local_oksigeniamarandu\api::channel_post(
                    self::COMPONENT,
                    $kind,
                    self::content($event, $moment),
                    $when ?: null,
                    false,
                    $key,
                    (int) $event->timestart
                );
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    /**
     * Posts, changes, or takes back an open day: what the save of an act did before the moments.
     *
     * @param \stdClass $event
     * @param int|null $now
     * @return int Unused, kept for whoever called it: 0.
     */
    public static function open_day(\stdClass $event, ?int $now = null): int {
        self::sync($event, $now);
        return 0;
    }

    /**
     * What a person chose for a moment of an act, from its kit: now, at a time, not at all, or as the centre says.
     *
     * @param \stdClass $event
     * @param string $moment
     * @param string $do One of CHOICES.
     * @param int $at The time, for «at».
     * @param int $now
     * @return bool Whether it was taken: a time must be still to come and before the act starts.
     */
    public static function choose(\stdClass $event, string $moment, string $do, int $at, int $now): bool {
        if (!in_array($moment, self::MOMENTS, true) || !in_array($do, self::CHOICES, true)) {
            return false;
        }
        if ($do === 'at' && ($at <= $now || $at >= (int) $event->timestart)) {
            return false;
        }
        $plan = self::plan($event);
        if ($do === 'centre') {
            unset($plan[$moment]);
        } else {
            $plan[$moment] = ['do' => $do, 'at' => $do === 'now' ? $now : ($do === 'at' ? $at : 0)];
        }
        kit::keep_channel($event, $plan);
        self::sync($event, $now);
        return true;
    }

    /**
     * What the post of a moment says, in the language of the centre: its card (what kind of moment, the name of the
     * act, the day, the hours and the place) and the button to sign up or, on the day, to know how to get there; the
     * text of the kit when it tells something of the act. The link says it came from Telegram, for the numbers.
     *
     * @param \stdClass $event
     * @param string $moment
     * @return array
     */
    public static function content(\stdClass $event, string $moment = 'announce'): array {
        $centre = centres::get((int) $event->centreid);
        $lang = $centre && in_array($centre->lang, lists::LANGS, true) ? $centre->lang : 'es';
        $parts = publicpage::date_parts((int) $event->timestart, $lang);
        $ucfirst = fn(string $s) => \core_text::strtoupper(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $hours = familypage::hours((int) $event->timestart, (int) $event->timeend, $lang);
        // A comma: a «·» would begin the second line when the line breaks.
        $place = trim(implode(', ', array_filter([$event->place ? format_string($event->place) : '',
            (string) ($centre->address ?? '')])));
        $name = format_string($event->name);
        $openday = $event->kind === 'openday';
        $kickers = ['announce' => $openday ? 'channel_open_day_kicker' : 'channel_act_kicker',
            'tell' => $openday ? 'channel_open_day_kicker' : 'channel_act_kicker',
            'last' => 'channel_last_kicker', 'day' => 'channel_day_kicker'];
        $kicker = publiclang::str($kickers[$moment] ?? $kickers['announce'], $lang);
        // A day named just «Open day» would say it twice on its card: a sentence of its own instead.
        $title = recognise::plain($name) === recognise::plain($kicker) ? publiclang::str('channel_open_day_invite', $lang) : $name;
        $label = $moment === 'day' ? 'channel_day_button' : ($openday ? 'channel_open_day_button' : 'channel_act_button');
        $content = [
            'title' => $name,
            'lines' => array_values(array_filter([$ucfirst($parts['long']), $hours, $place])),
            'url' => (new \moodle_url(events::url($event), ['d' => 'telegram']))->out(false),
            'label' => publiclang::str($label, $lang),
            'card' => ['type' => 'open_day', 'zones' => [
                'kicker' => $kicker,
                'title' => $title,
                'date' => $ucfirst($parts['weekday']) . ' ' . $parts['daymonth'],
                'detail' => $hours . ($place !== '' ? '. ' . $place : ''),
            ]],
        ];
        if ($moment === 'tell' && $centre) {
            $content['text'] = (string) kit::texts($event, $centre)['post'];
        }
        return $content;
    }

    /**
     * Each moment of an act in the channel, for its kit: its day, what was chosen, and where its post is.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $now
     * @return array By moment: moment, day (the time it would go by itself; 0 for now), auto (whether it goes by itself),
     *               choice (what a person chose, or null) and state (from Marandu, or null).
     */
    public static function moments(\stdClass $event, \stdClass $centre, int $now): array {
        $auto = self::auto($centre);
        $plan = self::plan($event);
        $out = [];
        foreach (self::MOMENTS as $moment) {
            $out[] = ['moment' => $moment, 'day' => self::day_of($event, $moment), 'auto' => in_array($moment, $auto, true),
                'choice' => $plan[$moment] ?? null, 'state' => self::state($event, $moment)];
        }
        return $out;
    }

    /**
     * The acts of a centre that may be in the channel now: open to all and still to start.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return \stdClass[]
     */
    protected static function acts(\stdClass $centre, int $now): array {
        global $DB;
        $events = $DB->get_records_select(
            events::T,
            'centreid = ? AND open = 1 AND timestart > ?',
            [$centre->id, $now],
            'timestart'
        );
        return array_filter($events, fn($event) => self::public($event, $now));
    }

    /**
     * Puts every act of a centre that may be in the channel as the centre says now: after it changes what goes by itself.
     *
     * @param \stdClass $centre
     * @param int|null $now
     */
    public static function resync(\stdClass $centre, ?int $now = null): void {
        $now = $now ?? time();
        if (!self::ready()) {
            return;
        }
        foreach (self::acts($centre, $now) as $event) {
            self::sync($event, $now);
        }
    }

    /**
     * What waits to go out to the channel from a centre, the soonest first: for its settings.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array[] Of event, moment and state (review or ready, with its time; null when it has no place).
     */
    public static function coming(\stdClass $centre, int $now): array {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        foreach (self::acts($centre, $now) as $event) {
            foreach (self::MOMENTS as $moment) {
                $state = self::state($event, $moment);
                if ($state && in_array($state['status'], ['review', 'ready'], true)) {
                    $out[] = ['event' => $event, 'moment' => $moment, 'state' => $state];
                }
            }
        }
        usort($out, fn($a, $b) => ($a['state']['time'] ?? PHP_INT_MAX) <=> ($b['state']['time'] ?? PHP_INT_MAX));
        return $out;
    }
}
