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
 * What the pages of the team share: how they are set up, the way back to the inbox, and how a wait is told.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class staff {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * Sets up a page of the team.
     *
     * @param \moodle_url $url
     * @param string $title
     * @param \stdClass|null $centre The centre it is about, for the way back to its inbox.
     */
    public static function setup(\moodle_url $url, string $title, ?\stdClass $centre = null): void {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('standard');
        $PAGE->set_title($title);
        $PAGE->set_heading($centre ? format_string($centre->name) : get_string('navname', self::C));
        $PAGE->add_body_class('local-oksigeniaeguahe');
        $PAGE->set_primary_active_tab('local_oksigeniaeguahe');
        if ($centre) {
            $PAGE->navbar->add(get_string('navname', self::C), new \moodle_url(
                '/local/oksigeniaeguahe/index.php',
                ['c' => $centre->id]
            ));
        }
    }

    /**
     * How long someone has waited, in a few words: «3 h», «2 días».
     *
     * @param int $since
     * @return string
     */
    public static function waited(int $since): string {
        $seconds = max(60, time() - $since);
        return format_time($seconds - $seconds % ($seconds >= DAYSECS ? DAYSECS : ($seconds >= HOURSECS ? HOURSECS : 60)));
    }

    /**
     * The tabs of the pages of a centre.
     *
     * @param \stdClass $centre
     * @param string $current inbox, events or settings.
     * @return array For the template.
     */
    public static function tabs(\stdClass $centre, string $current): array {
        $tabs = [
            'inbox' => new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]),
            'events' => new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id]),
        ];
        if (has_capability('local/oksigeniaeguahe:manage', \context_system::instance())) {
            $tabs['settings'] = new \moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id]);
        }
        $list = [];
        foreach ($tabs as $key => $url) {
            $list[] = ['name' => get_string('tab_' . $key, self::C), 'url' => $url->out(false), 'current' => $key === $current];
        }
        return $list;
    }

    /**
     * The names of the adults of a household, for a list: «Lucas Pérez y Ana Gil».
     *
     * @param array $adults
     * @return string
     */
    public static function adult_names(array $adults): string {
        $names = [];
        foreach ($adults as $adult) {
            $names[] = trim($adult->firstname . ' ' . $adult->lastname);
        }
        if (!$names) {
            return get_string('household_noname', self::C);
        }
        $last = array_pop($names);
        return $names ? get_string('andlist', self::C, (object) ['list' => implode(', ', $names), 'last' => $last]) : $last;
    }
}
