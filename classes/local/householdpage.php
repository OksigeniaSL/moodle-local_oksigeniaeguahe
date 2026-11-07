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
 * What the page of a household shows to the team.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class householdpage {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The context of the template household.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $newlink A link just made for the family, to show once.
     * @param bool $canact Whether the person may answer and change, or only see.
     * @return array
     */
    public static function context(\stdClass $household, \stdClass $centre, string $newlink, bool $canact = true): array {
        global $DB;
        $c = self::C;
        $full = households::full($household);
        $offers = centres::offers((int) $centre->id);
        $permissions = households::permissions((int) $household->id);
        $adults = [];
        foreach ($full->adults as $adult) {
            $granted = [];
            foreach (lists::CONSENT_CHANNELS as $channel) {
                if (!empty($permissions[$adult->id][$channel])) {
                    $granted[] = get_string('consent_' . $channel, $c);
                }
            }
            $adults[] = [
                'name' => trim($adult->firstname . ' ' . $adult->lastname),
                'phone' => $adult->phone,
                'email' => $adult->email,
                'permissions' => $granted ? implode(', ', $granted) : get_string('consent_none', $c),
            ];
        }
        $children = [];
        foreach ($full->children as $child) {
            $offer = $offers[$child->offerid] ?? null;
            $stages = [];
            foreach (lists::STAGES as $stage) {
                $stages[] = ['value' => $stage, 'name' => get_string('stage_' . $stage, $c),
                    'selected' => $stage === $child->stage];
            }
            $reasons = [];
            foreach (lists::CLOSED_REASONS as $reason) {
                $reasons[] = ['value' => $reason, 'name' => get_string('closed_' . $reason, $c),
                    'selected' => $reason === $child->closedreason];
            }
            $children[] = [
                'id' => $child->id,
                'name' => $child->firstname,
                'offer' => $offer ? centres::offer_label($offer) : get_string('offer_unknown', $c),
                'stage' => get_string('stage_' . $child->stage, $c),
                'reason' => $child->stage === 'closed' && $child->closedreason ?
                    get_string('closed_' . $child->closedreason, $c) : '',
                'since' => userdate((int) $child->stagetime, get_string('strftimedate', 'langconfig')),
                'stages' => $stages,
                'reasons' => $reasons,
            ];
        }
        $userids = array_filter(array_unique(array_column($full->contacts, 'userid')));
        $people = $userids ? $DB->get_records_list('user', 'id', $userids) : [];
        $history = [];
        foreach (array_reverse($full->contacts) as $contact) {
            $history[] = [
                'in' => $contact->direction === 'in',
                'out' => $contact->direction === 'out',
                'note' => $contact->direction === 'note',
                'who' => $contact->direction === 'in' ? staff::adult_names($full->adults) :
                    (isset($people[$contact->userid]) ? fullname($people[$contact->userid]) : ''),
                'channel' => get_string('channel_' . $contact->channel, $c),
                'when' => userdate((int) $contact->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'body' => $contact->body,
            ];
        }
        $events = [];
        foreach ($full->signups as $signup) {
            $events[] = ['name' => format_string($signup->eventname),
                'when' => userdate((int) $signup->timestart, get_string('strftimedatetimeshort', 'langconfig')),
                'people' => (int) $signup->people, 'came' => (bool) $signup->attended];
        }
        $channels = [];
        foreach (lists::HAND_CHANNELS as $channel) {
            $channels[] = ['value' => $channel, 'name' => get_string('channel_' . $channel, $c)];
        }
        return [
            'tabs' => staff::tabs($centre, 'inbox'),
            'names' => staff::adult_names($full->adults),
            'waiting' => $household->waitingsince > 0,
            'waited' => $household->waitingsince ? staff::waited((int) $household->waitingsince) : '',
            'place' => $household->municipalityname,
            'heardfrom' => $household->heardfrom ? get_string('heard_' . $household->heardfrom, $c) : '',
            'channel' => get_string('channel_' . $household->firstchannel, $c),
            'lang' => lists::LANG_NAMES[$household->lang] ?? $household->lang,
            'since' => userdate((int) $household->timecreated, get_string('strftimedate', 'langconfig')),
            'adults' => $adults,
            'children' => $children,
            'history' => $history,
            'hashistory' => (bool) $history,
            'events' => $events,
            'hasevents' => (bool) $events,
            'channels' => $channels,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'editurl' => (new \moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id,
                'id' => $household->id]))->out(false),
            'sesskey' => sesskey(),
            'newlink' => $newlink,
            'canact' => $canact,
            'incentre' => !empty($household->incentre),
            'eraseurl' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id,
                'action' => 'eraseask']))->out(false),
        ];
    }
}
