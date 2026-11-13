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

namespace local_oksigeniaeguahe\output;

use local_oksigeniaeguahe\local\atycards;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\renewal;
use local_oksigeniaeguahe\local\settled;

/**
 * Eguahe in the Moodle app and in Oksigenia Aty: an adult of a family with an account answers whether each child goes on
 * next school year, with a tap, as on the page of the family. The words are those of that page; what the family and the
 * centre wrote goes as data, never compiled into the template.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * When the app starts: the page is in «More» only for whoever has something to answer.
     *
     * @param array $args What the app sends.
     * @return array
     */
    public static function mobile_init(array $args): array {
        global $USER;
        return ['templates' => [], 'javascript' => '', 'otherdata' => '',
            'disabled' => !self::sections((int) $USER->id, time())];
    }

    /**
     * Whether each child goes on: for each family of the person whose centre asks, its children, what was said and
     * the three answers.
     *
     * @param array $args What the app sends: h, a household (optional).
     * @return array
     */
    public static function mobile_renewal_view(array $args): array {
        global $OUTPUT, $USER;
        $lang = current_language();
        $reasons = array_values(array_map(
            fn($key) => ['key' => $key, 'label' => publiclang::str('pub_renewal_reason_' . $key, $lang)],
            array_diff(settled::LEFT_REASONS, ['unknown'])
        ));
        $json = fn($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [
            'templates' => [['id' => 'main', 'html' => $OUTPUT->render_from_template('local_oksigeniaeguahe/mobile_renewal', [])]],
            'javascript' => '',
            'otherdata' => ['sections' => $json(self::sections((int) $USER->id, time(), (int) ($args['h'] ?? 0))),
                'reasons' => $json($reasons), 'why' => $json(new \stdClass())],
        ];
    }

    /**
     * What a person has to answer: one section per family of theirs whose centre asks now, each child with its
     * question, what was said and the words of the saved answers.
     *
     * @param int $userid
     * @param int $now
     * @param int $only One household; 0, all.
     * @return array
     */
    public static function sections(int $userid, int $now, int $only = 0): array {
        $lang = current_language();
        $s = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $sections = [];
        foreach (atycards::households_of($userid) as $household) {
            if ($only && (int) $household->id !== $only) {
                continue;
            }
            $centre = centres::get((int) $household->centreid);
            if (!$centre || !renewal::open($centre, $now)) {
                continue;
            }
            $year = (string) $centre->renewalyear;
            $said = renewal::answers($centre, (int) $household->id);
            $children = [];
            foreach (renewal::children($centre, (int) $household->id) as $childid => [$child, , $next]) {
                $name = familypage::label_name($child) ?: (string) $child->firstname;
                $state = (string) ($said[$childid]->answer ?? '');
                $children[] = [
                    'id' => (int) $childid,
                    'key' => 'h' . $household->id . 'c' . $childid,
                    'ask' => $s('pub_renewal_ask', (object) ['name' => $name, 'year' => $year,
                        'course' => format_string($next->name)]),
                    'state' => $state,
                    'statetext' => $state !== '' ? $s('pub_renewal_' . $state . '_done', $name) : '',
                    'saved' => ['stay' => $s('pub_renewal_stay_done', $name), 'leave' => $s('pub_renewal_leave_done', $name),
                        'unsure' => $s('pub_renewal_unsure_done')],
                ];
            }
            if (!$children) {
                continue;
            }
            $until = (int) ($centre->renewaluntil ?? 0);
            $sections[] = [
                'householdid' => (int) $household->id,
                'centre' => format_string($centre->name),
                'title' => $s('pub_renewal_title', $year),
                'lead' => $s('pub_renewal_lead', $year),
                'until' => $until ? $s('pub_renewal_until', userdate($until - 1, get_string('strftimedate', 'langconfig'))) : '',
                'children' => $children,
            ];
        }
        return $sections;
    }
}
