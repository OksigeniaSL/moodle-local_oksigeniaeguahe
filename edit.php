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

/**
 * A household noted by the team (a call, the desk, the door of an open day), or changed. A family that was already
 * there, found by its phone or email, is not created twice: what is new goes to its household.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\form\household_form;
use local_oksigeniaeguahe\local\alumni;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\panel_familyform;
use local_oksigeniaeguahe\local\phone;
use local_oksigeniaeguahe\local\ties;
use local_oksigeniaeguahe\local\writeby;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$eventid = optional_param('eventid', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_act($centreid);
// At the door of an act, who comes without having signed up: short, and back to it.
$door = !$id && optional_param('puerta', 0, PARAM_BOOL) ? events::get($eventid, $centreid) : null;
// From the week: whoever is written down is given an appointment next.
$give = [];
if (!$id && optional_param('cita', 0, PARAM_BOOL)) {
    $day = optional_param('dia', '', PARAM_RAW_TRIMMED);
    $give = ['cita' => 1] + (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? ['dia' => $day] : []);
}
if (optional_param('dup', 0, PARAM_BOOL)) {
    // While writing one down: whether the family is there already, by its phone or its email.
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');
    echo json_encode(panel_familyform::duplicates(
        $centre,
        optional_param('phone', '', PARAM_RAW_TRIMMED),
        optional_param('email', '', PARAM_RAW_TRIMMED)
    ));
    die();
}
$household = $id ? households::get($id, (int) $centre->id) : null;
if ($id && !$household) {
    throw new moodle_exception('invalidrecord', 'error');
}
$url = new moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id] + ($id ? ['id' => $id] : []) + $give +
    ($door ? ['eventid' => $door->id, 'puerta' => 1] : []));
panel::setup($url, get_string($household ? 'household_edit' : 'household_new', $c));
if ($door) {
    $back = new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $door->id], 'puerta');
} else if ($household) {
    $back = new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]);
} else {
    $back = new moodle_url('/local/oksigeniaeguahe/' . ($give ? 'cita.php' : 'index.php'), ['c' => $centre->id]);
}

$form = new household_form($url, ['centre' => $centre, 'householdid' => $id, 'eventid' => $eventid, 'door' => (bool) $door,
    'firstchannel' => $household ? (string) $household->firstchannel : '']);
if ($household) {
    $full = households::full($household);
    $defaults = ['c' => $centre->id, 'id' => $household->id, 'lang' => $household->lang, 'heardfrom' => $household->heardfrom,
        'heardnet' => $household->heardnet, 'incentre' => $household->incentre, 'firstchannel' => $household->firstchannel];
    $places = centres::municipalities($centre);
    if ($household->municipality && isset($places[$household->municipality])) {
        $defaults['municipality'] = $household->municipality;
    } else if ($household->municipalityname) {
        $code = array_search($household->municipalityname, $places, true);
        $defaults['municipality'] = $code !== false ? $code : 'other';
        $defaults['municipalityother'] = $code !== false ? '' : $household->municipalityname;
    }
    foreach (array_values($full->adults) as $i => $adult) {
        if ($i < household_form::ADULTS) {
            $defaults += ["adultid{$i}" => $adult->id, "firstname{$i}" => $adult->firstname,
                "lastname{$i}" => $adult->lastname, "phone{$i}" => $adult->phone, "email{$i}" => $adult->email];
        }
    }
    foreach (array_values($full->children) as $i => $child) {
        if ($i < household_form::CHILDREN) {
            $defaults += ["childid{$i}" => $child->id, "childname{$i}" => $child->firstname, "offerid{$i}" => $child->offerid,
                "childtag{$i}" => $child->tagname];
            $defaults["childbirth{$i}"] = (string) ($child->birthdate ?? '');
        }
    }
    $form->set_data($defaults);
} else if (optional_param('fromchat', 0, PARAM_BOOL) && confirm_sesskey()) {
    // A family nobody knew, from a conversation of the chat of the centre: its name, phone and email already written.
    [$first, $last] = array_pad(preg_split('/\s+/', trim(optional_param('chatname', '', PARAM_TEXT)), 2), 2, '');
    // And the way it came, when the chat says it (a conversation of WhatsApp, Telegram, email or the chat of the web).
    $way = optional_param('chatchannel', '', PARAM_ALPHA);
    $form->set_data(['c' => $centre->id, 'firstname0' => $first, 'lastname0' => $last,
        'phone0' => optional_param('chatphone', '', PARAM_RAW_TRIMMED),
        'email0' => optional_param('chatemail', '', PARAM_RAW_TRIMMED)] +
        (in_array($way, lists::HAND_CHANNELS, true) ? ['channel' => $way] : []));
}

if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    $places = centres::municipalities($centre);
    // An ad of Meta noted by hand: the channel is Meta, and its network is kept as where they arrived from, as a contact
    // of the ads would bring it (entries::ad_arrival()).
    $channel = (string) ($data->channel ?? 'other');
    $adnetwork = lists::HAND_ADS[$channel] ?? '';
    $channel = $adnetwork !== '' ? 'meta' : $channel;
    $act = !empty($data->eventid) ? events::get((int) $data->eventid, (int) $centre->id) : null;
    $about = (object) ['lang' => $data->lang ?: $centre->lang, 'heardfrom' => $data->heardfrom, 'municipality' => null,
        'heardnet' => $data->heardfrom === 'social' ? centres::network($centre, (string) ($data->heardnet ?? '')) : '',
        'municipalityname' => null, 'firstchannel' => $channel, 'incentre' => !empty($data->incentre),
        // Noted at a talk or a party, with no child: of that act, not of the admissions.
        'guest' => !$household && $act && $act->kind === 'other' && trim((string) ($data->childname0 ?? '')) === ''];
    if ($adnetwork !== '') {
        $about->arrival = ['src' => $adnetwork, 'med' => 'paid'];
    }
    if (isset($places[$data->municipality])) {
        $about->municipality = strpos($data->municipality, 'name:') === 0 ? null : $data->municipality;
        $about->municipalityname = $places[$data->municipality];
    } else if ($data->municipality === 'other' && trim($data->municipalityother) !== '') {
        $about->municipalityname = trim($data->municipalityother);
    }
    $adults = [];
    for ($i = 0; $i < household_form::ADULTS; $i++) {
        $adult = (object) ['id' => (int) $data->{"adultid{$i}"}, 'firstname' => trim($data->{"firstname{$i}"}),
            'lastname' => trim($data->{"lastname{$i}"}), 'email' => households::email($data->{"email{$i}"}),
            'phone' => phone::normalise((string) $data->{"phone{$i}"}, $centre->country)];
        if ($adult->firstname !== '' || $adult->email || $adult->phone) {
            $adults[] = $adult;
        }
    }
    $transaction = $DB->start_delegated_transaction();
    $notice = get_string('household_saved', $c);
    $existing = null;
    if ($household) {
        $now = time();
        // «Already of the centre» is not taken out while a mark of the file, a list or the roll says it: there it is changed.
        $marked = ties::any((int) $household->id) || (int) ($household->roster ?? 0) ||
            $DB->record_exists(listmatch::T, ['householdid' => $household->id, 'kind' => 'incentre']);
        if (!$about->incentre && (int) $household->incentre && $marked) {
            $about->incentre = true;
            $notice = get_string('household_incentre_marked', $c);
        }
        if ($about->heardnet === '' && $about->heardfrom === $household->heardfrom && $about->heardfrom !== 'social') {
            // Which centre recommended them is not in this form: it stays.
            $about->heardnet = (string) $household->heardnet;
        }
        $DB->update_record(households::T, (object) ['id' => $household->id, 'lang' => $about->lang,
            'heardfrom' => $about->heardfrom ?: null, 'heardnet' => $about->heardnet !== '' ? $about->heardnet : null,
            'municipality' => $about->municipality,
            'municipalityname' => $about->municipalityname, 'incentre' => $about->incentre ? 1 : 0, 'timemodified' => $now]);
        $first = (string) ($data->firstchannel ?? '');
        if ($first !== '' && $first !== $household->firstchannel && in_array($first, household_form::first_channels((string) $household->firstchannel), true)) {
            // How it came in, put right: and the line of who wrote it down, with it.
            $DB->set_field(households::T, 'firstchannel', $first, ['id' => $household->id]);
            $lines = $DB->get_records(households::CONTACTS, ['householdid' => $household->id], 'timecreated ASC, id ASC', '*', 0, 1);
            $line = reset($lines);
            if ($line && $line->direction === 'in' && (int) $line->userid && $line->channel === $household->firstchannel) {
                $DB->set_field(households::CONTACTS, 'channel', $first, ['id' => $line->id]);
            }
        }
        foreach ($adults as $adult) {
            if ($adult->id && $DB->record_exists(households::ADULTS, ['id' => $adult->id, 'householdid' => $household->id])) {
                $adult->timemodified = $now;
                $DB->update_record(households::ADULTS, $adult);
                alumni::match($household, $adult);
            } else {
                households::write_down($household, $adult, (int) $USER->id);
            }
        }
    } else {
        // The same family, if any of its adults was there already.
        $existing = null;
        foreach ($adults as $adult) {
            $existing = $existing ?? households::find((int) $centre->id, $adult->email, $adult->phone);
        }
        if ($existing) {
            $household = households::get($existing, (int) $centre->id);
            households::complete($household, $about);
            $notice = get_string('household_found', $c);
            if (empty($about->guest)) {
                // Whoever had only come to a talk, and now is written down for anything else, is of the admissions.
                households::admit((int) $household->id);
            }
        } else {
            $household = households::get(households::create((int) $centre->id, $about), (int) $centre->id);
        }
        foreach ($adults as $i => $adult) {
            $adultid = $existing ? households::write_down($household, $adult, (int) $USER->id) : households::add_adult($household, $adult);
            if ($i === 0) {
                foreach (['email', 'whatsapp', 'phone'] as $way) {
                    if (!empty($data->{'consent' . $way})) {
                        households::consent($household, $adultid, $way, true, 'team', $about->lang, (int) $USER->id);
                    }
                }
            }
        }
        if (!empty($data->consenttelegram)) {
            // Telegram as well: they are written to by the ways marked, and their page asks them to join it.
            $ways = array_keys(array_filter(['email' => !empty($data->consentemail),
                'whatsapp' => !empty($data->consentwhatsapp), 'telegram' => true]));
            writeby::set($household, $ways, 'team', (int) $USER->id);
        }
        $body = trim((string) ($data->body ?? ''));
        households::received(
            $household,
            $channel,
            $body !== '' ? $body : get_string('noted_by_team', $c),
            !empty($data->awaits),
            null,
            0,
            '',
            '',
            true,
            false,
            (int) $USER->id
        );
    }
    for ($i = 0; $i < household_form::CHILDREN; $i++) {
        $name = trim((string) $data->{"childname{$i}"});
        $childid = (int) $data->{"childid{$i}"};
        $offerid = (int) ($data->{"offerid{$i}"} ?? 0);
        $tag = trim((string) ($data->{"childtag{$i}"} ?? ''));
        if ($childid && $DB->record_exists(households::CHILDREN, ['id' => $childid, 'householdid' => $household->id])) {
            if ($name !== '') {
                $DB->update_record(households::CHILDREN, (object) ['id' => $childid, 'firstname' => $name,
                    'tagname' => $tag === '' ? null : core_text::substr($tag, 0, 100), 'offerid' => $offerid,
                    'timemodified' => time()]);
            }
        } else if ($name !== '') {
            $childid = households::add_child($household, $name, $offerid, $tag);
            // Written down by a person: one whose way had stopped, or that had left, asks again; and what is asked for one that
            // is already at the centre is told, for a person.
            $child = $DB->get_record(households::CHILDREN, ['id' => $childid]);
            if (!households::ask_again($household, $child, $offerid, (int) $USER->id) && $offerid && (int) $child->offerid !== $offerid) {
                $offers = centres::offers((int) $centre->id);
                households::note($household, (int) $USER->id, get_string('child_asks_kept', $c, (object) ['name' => format_string($child->firstname),
                    'asks' => isset($offers[$offerid]) ? centres::offer_label($offers[$offerid]) : '—',
                    'has' => isset($offers[$child->offerid]) ? centres::offer_label($offers[$child->offerid]) : '—',
                    'step' => get_string('stage_' . $child->stage, $c)]));
            }
            if ($offerid) {
                households::admit((int) $household->id);
            }
        }
        if ($childid && $name !== '') {
            // The date of birth; empty takes it out.
            $birth = publicpage::date_param("childbirth{$i}");
            households::set_birthdate($household, $childid, $birth);
            // By its date it would be in another course: said now, not only in the file.
            $offer = $offerid ? ($offers[$offerid] ?? (centres::offers((int) $centre->id)[$offerid] ?? null)) : null;
            $hint = $birth !== '' ? \local_oksigeniaeguahe\local\panel_household::course_hint($birth, $offer, $centre) : '';
            if ($hint !== '') {
                $notice .= ' ' . format_string($name) . ': ' . $hint . '.';
            }
        }
    }
    if (!empty($data->eventid) && ($event = events::get((int) $data->eventid, (int) $centre->id))) {
        // In its turn, when it has turns: the one chosen, if it is of this act.
        $slots = events::slots((int) $event->id);
        $slot = isset($slots[(int) ($data->{'slot' . $event->id} ?? 0)]) ? (int) $data->{'slot' . $event->id} : 0;
        $signupid = events::sign_up($event, (int) $household->id, max(1, (int) $data->people), $slot);
        if ($slots && !$slot && !$DB->get_field(households::SIGNUPS, 'slotid', ['id' => $signupid])) {
            $notice .= ' ' . get_string('household_noslot', $c);
        }
        if (!empty($data->came)) {
            events::attend($event, $signupid, true);
        }
    }
    $transaction->allow_commit();
    if ($door) {
        redirect(new moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $door->id], 's' . ($signupid ?? 0)), $notice);
    }
    if ($give) {
        redirect(new moodle_url('/local/oksigeniaeguahe/cita.php', ['h' => $household->id] + array_diff_key($give, ['cita' => 1])), $notice);
    }
    // With «and answer now», straight to the answer of the file.
    $anchor = optional_param('answernow', 0, PARAM_BOOL) ? 'contestar' : null;
    redirect(
        new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id], $anchor),
        $notice,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

panel::output(
    $centre,
    'inbox',
    get_string($household ? 'household_edit' : 'household_new', $c),
    'panel_familyform',
    panel_familyform::context($form, $centre, $household, $url, $back, $door)
);
