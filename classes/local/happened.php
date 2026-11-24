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
 * What each line of the history of a family was, said with its verb: it wrote, it called, it came, it was noted, it was
 * answered. A date never goes alone: alone, it is read as the day of an appointment.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class happened {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * What a line was.
     *
     * @param \stdClass $line A contact.
     * @param bool $answers Whether it answers something the family had asked.
     * @return string wrote, called, camein, signed, left, spoke, noted, answered, told, invited or sent.
     */
    public static function kind(\stdClass $line, bool $answers = true): string {
        if ($line->direction === 'out') {
            $told = !$answers || (string) ($line->aim ?? '') === 'told';
            return !(int) $line->userid ? 'sent' : (self::to_many($line) ? 'invited' : ($told ? 'told' : 'answered'));
        }
        if ($line->direction !== 'in' || households::is_hand_note($line)) {
            return 'noted';
        }
        if ($line->channel === 'phone') {
            return 'called';
        }
        if ($line->channel === 'walkin') {
            return 'camein';
        }
        if (in_array($line->channel, ['openday', 'visit', 'event'], true)) {
            // By itself, it signed up; written down by a person, it spoke with the team there.
            return (int) $line->userid ? 'spoke' : 'signed';
        }
        return $line->channel === 'meta' || trim((string) $line->body) === '' ? 'left' : 'wrote';
    }

    /**
     * Whether a line is what was sent to many at once (an invitation): it answers nobody.
     *
     * @param \stdClass $line
     * @return bool
     */
    public static function to_many(\stdClass $line): bool {
        return (string) ($line->aim ?? '') === 'many';
    }

    /**
     * Which lines answered something the family had asked: the first of a person after it wrote.
     *
     * @param \stdClass[] $lines In order of time.
     * @return array Line id => true.
     */
    public static function answers(array $lines): array {
        $out = [];
        $asked = false;
        foreach ($lines as $line) {
            if ($line->direction === 'in' && !households::is_hand_note($line)) {
                $asked = true;
            } else if ($line->direction === 'out' && $asked && self::kind($line) === 'answered') {
                $out[(int) $line->id] = true;
                $asked = false;
            }
        }
        return $out;
    }

    /**
     * The verb of a line, for the side of a row or a stamp: «Escribió», «Contestada».
     *
     * @param string $kind
     * @return string
     */
    public static function verb(string $kind): string {
        return get_string('happened_' . $kind, self::C);
    }

    /**
     * When, short, beside its verb: «ayer, 12:30», «jue 5, 18:40», «3 nov»; only the hour when its day is already said.
     *
     * @param int $time
     * @param int $now
     * @param bool $dayonly Whether only its day is known.
     * @param bool $hour Whether only the hour is wanted.
     * @return string
     */
    public static function when(int $time, int $now, bool $dayonly = false, bool $hour = false): string {
        $clock = $dayonly ? '' : panel::hour($time);
        if ($hour) {
            return $clock;
        }
        $days = panel::days_between($time, $now);
        $day = $days === 0 ? get_string('panel_when_today_day', self::C) : ($days === 1 ?
            get_string('panel_when_yesterday_day', self::C) : ($days > 0 && $days < 7 ? rtrim(userdate($time, '%a %d'), '.') :
            userdate($time, get_string(userdate($time, '%Y') === userdate($now, '%Y') ? 'happened_day' : 'happened_day_year', self::C))));
        return $clock === '' ? $day : get_string('panel_day_hour', self::C, (object) ['day' => $day, 'hour' => $clock]);
    }

    /**
     * What a line was, as a sentence after who did it: «escribió por WhatsApp», «les contestó por correo».
     *
     * @param string $kind
     * @param string $via How, in lower case: «por WhatsApp».
     * @param string $channel Where it signed up, for a sign-up.
     * @return string
     */
    public static function words(string $kind, string $via, string $channel = ''): string {
        if ($kind === 'signed') {
            return get_string('happened_signed_' . $channel, self::C);
        }
        return get_string('happened_' . $kind . '_words', self::C, $via);
    }

    /**
     * The appointments of a family in its history, each in its place by the day it was or will be: whether it came, and
     * when nobody said it, that it is not known.
     *
     * @param \stdClass[] $signups As households::full() gives them.
     * @param int $now
     * @return array[] time, kind (appointment, came, unknown), next, what it says and, when it is not known, that.
     */
    public static function appointments(array $signups, int $now): array {
        $out = [];
        foreach ($signups as $signup) {
            $time = (int) $signup->timestart;
            if ($time <= 0 || (!empty($signup->eventtailored) && empty($signup->eventhousehold))) {
                // Asking for a visit is not one.
                continue;
            }
            $kind = $time > $now ? 'appointment' : ((int) $signup->attended ? 'came' : 'unknown');
            // While it lasts, nobody is late.
            $kind = $kind === 'unknown' && (int) $signup->timeend > $now ? 'appointment' : $kind;
            $out[] = ['time' => $time, 'kind' => $kind, 'next' => $kind === 'appointment',
                'what' => get_string('happened_' . $kind . '_what', self::C, (object) [
                    'when' => self::day_hour($time, $now), 'what' => format_string($signup->eventname)]),
                'note' => $kind === 'unknown' ? get_string('happened_unknown_note', self::C) : ''];
        }
        return $out;
    }

    /**
     * A day to come or gone with its hour, whole: «el jueves 19 a las 10:00», «hoy a las 10:00», «mañana a las 9:00».
     *
     * @param int $time
     * @param int $now
     * @return string
     */
    public static function day_hour(int $time, int $now): string {
        $days = panel::days_between($time, $now);
        if ($days === -1) {
            return get_string('happened_tomorrow', self::C, panel::hour($time));
        }
        if ($days >= -6 && $days < 0) {
            return get_string('panel_when_day', self::C, (object) ['day' => userdate($time, '%A %d'), 'time' => panel::hour($time)]);
        }
        return panel::when_words($time, $now);
    }
}
