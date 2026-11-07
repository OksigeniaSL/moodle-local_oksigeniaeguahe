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
 * What the page of a family shows: in plain words, without the words of the trade; the next visit first.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class familypage {
    /** @var string[] Strings the template uses. */
    const STRINGS = ['pub_family_title', 'pub_family_intro', 'pub_done_title', 'pub_done_keep', 'pub_next',
        'pub_add_calendar', 'pub_us', 'pub_students', 'pub_data', 'pub_data_hint', 'pub_data_download', 'pub_data_erase',
        'pub_none_next', 'pub_offer_unknown'];

    /**
     * The context of the template public_family.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param string $token
     * @param bool $new Whether the family has just signed up.
     * @return array
     */
    public static function context(
        \stdClass $household,
        \stdClass $centre,
        string $lang,
        string $token,
        bool $new
    ): array {
        $full = households::full($household);
        $offers = centres::offers((int) $centre->id);
        $link = fn(array $params) => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token] + $params))->out(false);
        $adults = [];
        foreach ($full->adults as $adult) {
            $adults[] = ['name' => trim($adult->firstname . ' ' . $adult->lastname),
                'reach' => implode(' · ', array_filter([$adult->phone, $adult->email]))];
        }
        $children = [];
        foreach ($full->children as $child) {
            $offer = $offers[$child->offerid] ?? null;
            $children[] = ['name' => $child->firstname,
                'offer' => $offer ? format_string($offer->name) . ' (' . s($offer->schoolyear) . ')' :
                    publiclang::str('pub_offer_unknown', $lang)];
        }
        $next = [];
        foreach ($full->signups as $signup) {
            if ($signup->timeend >= time()) {
                $event = (object) ['timestart' => $signup->timestart, 'timeend' => $signup->timeend];
                $next[] = [
                    'name' => format_string($signup->eventname),
                    'when' => mail::when($event, $lang),
                    'place' => $signup->place ? format_string($signup->place) : '',
                    'people' => publiclang::str('pub_people_count', $lang, (int) $signup->people),
                    'ics' => (new \moodle_url('/local/oksigeniaeguahe/ics.php', ['k' => $token,
                        'e' => $signup->eventid]))->out(false),
                ];
            }
        }
        $emailed = false;
        foreach ($full->adults as $adult) {
            $emailed = $emailed || !empty($adult->email);
        }
        return publicpage::strings($lang, self::STRINGS) + [
            'new' => $new,
            'emailed' => $new && $emailed ? publiclang::str('pub_done_emailed', $lang) : '',
            'centre' => format_string($centre->name),
            'adults' => $adults,
            'children' => $children,
            'next' => $next,
            'hasnext' => (bool) $next,
            'downloadurl' => $link(['action' => 'download']),
            'eraseurl' => $link(['action' => 'erase', 'lang' => $lang]),
        ];
    }

    /**
     * The context of the template public_erase: the question, with what it does in its buttons.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param string $token
     * @return array
     */
    public static function erase_context(\stdClass $household, \stdClass $centre, string $lang, string $token): array {
        return publicpage::strings($lang, ['pub_erase_title', 'pub_erase_text', 'pub_erase_yes', 'pub_erase_no']) + [
            'action' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false),
            'back' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false),
            'sesskey' => sesskey(),
            'centre' => format_string($centre->name),
        ];
    }
}
