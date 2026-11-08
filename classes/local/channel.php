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
 * The open days in the channel of Telegram of the organisation, with Oksigenia Marandu (its request, with the OK of
 * Enrique, 8 November 2026): an open day with its sign-up open goes to the channel with its animated card and the
 * button to sign up, after whoever reviews it; changed, it changes while it waits; closed or no longer an open day, it is
 * taken back. Only what is public of the day: never names of people nor photos. Without Marandu or its channel, nothing.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class channel {
    /** @var string */
    const COMPONENT = 'local_oksigeniaeguahe';

    /** @var string The kind of post, as lib.php declares it. */
    const KIND = 'open_day';

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
     * Posts an open day, changes it, or takes it back, as it is now.
     *
     * @param \stdClass $event
     * @param int|null $now
     * @return int The id of the post; 0 if it does not go.
     */
    public static function open_day(\stdClass $event, ?int $now = null): int {
        $now = $now ?? time();
        if (!self::ready()) {
            return 0;
        }
        $key = self::KIND . '-' . $event->id;
        try {
            $public = $event->kind === 'openday' && !empty($event->open) && empty($event->tailored) &&
                empty($event->householdid) && (int) $event->timestart > $now;
            if (!$public) {
                \local_oksigeniamarandu\api::channel_cancel(self::COMPONENT, self::KIND, $key);
                return 0;
            }
            return \local_oksigeniamarandu\api::channel_post(
                self::COMPONENT,
                self::KIND,
                self::content($event),
                null,
                false,
                $key,
                (int) $event->timestart
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * What the post says, in the language of the centre: the name of the day, when, where, and the button to sign up.
     *
     * @param \stdClass $event
     * @return array
     */
    public static function content(\stdClass $event): array {
        $centre = centres::get((int) $event->centreid);
        $lang = $centre && in_array($centre->lang, lists::LANGS, true) ? $centre->lang : 'es';
        $parts = publicpage::date_parts((int) $event->timestart, $lang);
        $ucfirst = fn(string $s) => \core_text::strtoupper(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $hours = familypage::hours((int) $event->timestart, (int) $event->timeend, $lang);
        $place = trim(implode(' · ', array_filter([$event->place ? format_string($event->place) : '',
            (string) ($centre->address ?? '')])));
        $name = format_string($event->name);
        return [
            'title' => $name,
            'lines' => array_values(array_filter([$ucfirst($parts['long']), $hours, $place])),
            'url' => events::url($event)->out(false),
            'label' => publiclang::str('channel_open_day_button', $lang),
            'card' => ['type' => 'open_day', 'zones' => [
                'kicker' => publiclang::str('channel_open_day_kicker', $lang),
                'title' => $name,
                'date' => $ucfirst($parts['weekday']) . ' ' . $parts['daymonth'],
                'detail' => $hours . ($place !== '' ? '. ' . $place : ''),
            ]],
        ];
    }
}
