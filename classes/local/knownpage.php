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
 * What a family that was already known sees when it signs up again (public_known, Jujol): not the end of a form, but
 * the wall of the centre with the things of the children they just wrote hanging from the rail, and the visit they
 * signed up for with who receives them. Their page itself is not shown, since whoever filled in the form might not be
 * that family: its link went to the address the centre already had. Only what they just wrote and the act are shown.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class knownpage {
    /**
     * The context of the template public_known.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @param array $children The children just written: firstname, tagname, offerid and birthdate.
     * @param \stdClass|null $signup Their visit just booked (familymail::signup()), or null (a tailored visit, w.php).
     * @param bool $sent Whether the link went to the address the centre had.
     * @return array
     */
    public static function context(\stdClass $centre, string $lang, array $children, ?\stdClass $signup, bool $sent): array {
        global $DB;
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id]);
        $kids = [];
        foreach ($children as $child) {
            $kid = (object) ['id' => 0, 'firstname' => trim((string) ($child['firstname'] ?? '')),
                'tagname' => trim((string) ($child['tagname'] ?? '')), 'offerid' => (int) ($child['offerid'] ?? 0),
                'birthdate' => (string) ($child['birthdate'] ?? ''), 'stage' => 'visit'];
            if (familypage::label_name($kid) !== '') {
                $kids[] = $kid;
            }
        }
        $tags = array_map([familypage::class, 'label_name'], $kids);
        $names = familypage::join($tags, $lang);
        $rail = publicpage::pegs($tags, true, null, array_map(fn($k) => ages::hanging($k, $offers, $centre), $kids));
        $event = false;
        $hosts = [];
        if ($signup && (int) $signup->timestart) {
            $ids = array_filter(array_map('intval', explode(',', (string) ($signup->hosts ?? ''))));
            $hosts = $ids ? centres::hosts((int) $centre->id, $ids) : [];
            $host = $hosts[0]->first ?? '';
            $parts = publicpage::date_parts((int) $signup->timestart, $lang);
            $a = (object) ['date' => '<b>' . s($parts['long']) . '</b>', 'host' => s($host), 'names' => s($names)];
            // With an older child, the classrooms for all, as on the page of a family.
            $key = ($host !== '' ? 'pub_lead_host' : 'pub_lead_team') . (!$tags ? '_nochild' :
                (ages::older($kids, $centre, $offers) ? '_older' : '') . (count($tags) > 1 ? '_many' : ''));
            $place = trim(implode(' · ', array_filter([$signup->place ? format_string($signup->place) : '',
                (string) ($centre->address ?? '')])));
            $event = ['lead' => publiclang::str($key, $lang, $a), 'weekday' => $parts['weekday'],
                'daymonth' => $parts['daymonth'], 'name' => format_string($signup->eventname),
                'hours' => familypage::hours((int) $signup->timestart, (int) $signup->timeend, $lang), 'place' => $place];
        }
        return [
            'title' => publiclang::str('pub_known_title', $lang),
            'names' => $names,
            'text' => publiclang::str($sent ? 'pub_known_sent' : 'pub_known_nomail', $lang),
            'rail' => $rail + ['came' => false, 'live' => false],
            // Not the hall of skin A: its scene is the family's own, with what they keep there.
            'hall' => false,
            'event' => $event,
            'expect' => publiclang::str('pub_known_expect', $lang),
            'hashosts' => (bool) $hosts,
            'hosts' => familypage::hosts($hosts),
            'receives' => publiclang::str('pub_receives', $lang),
        ] + publicpage::reach($centre, $lang);
    }
}
