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
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\panel_familyform;
use local_oksigeniaeguahe\local\phone;

require_login(null, false);

$centreid = required_param('c', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$eventid = optional_param('eventid', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';

$centre = centres::require_act($centreid);
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
$url = new moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id] + ($id ? ['id' => $id] : []));
panel::setup($url, get_string($household ? 'household_edit' : 'household_new', $c));
$back = $household ? new moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]) :
    new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]);

$form = new household_form($url, ['centre' => $centre, 'householdid' => $id, 'eventid' => $eventid]);
if ($household) {
    $full = households::full($household);
    $defaults = ['c' => $centre->id, 'id' => $household->id, 'lang' => $household->lang, 'heardfrom' => $household->heardfrom,
        'heardnet' => $household->heardnet, 'incentre' => $household->incentre];
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
}

if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    $places = centres::municipalities($centre);
    $about = (object) ['lang' => $data->lang, 'heardfrom' => $data->heardfrom, 'municipality' => null,
        'heardnet' => $data->heardfrom === 'social' ? centres::network($centre, (string) ($data->heardnet ?? '')) : '',
        'municipalityname' => null, 'firstchannel' => $data->channel ?? 'other', 'incentre' => !empty($data->incentre)];
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
    if ($household) {
        $now = time();
        $DB->update_record(households::T, (object) ['id' => $household->id, 'lang' => $about->lang,
            'heardfrom' => $about->heardfrom ?: null, 'heardnet' => $about->heardnet !== '' ? $about->heardnet : null,
            'municipality' => $about->municipality,
            'municipalityname' => $about->municipalityname, 'incentre' => $about->incentre ? 1 : 0, 'timemodified' => $now]);
        foreach ($adults as $adult) {
            if ($adult->id && $DB->record_exists(households::ADULTS, ['id' => $adult->id, 'householdid' => $household->id])) {
                $adult->timemodified = $now;
                $DB->update_record(households::ADULTS, $adult);
            } else {
                households::add_adult($household, $adult);
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
        } else {
            $household = households::get(households::create((int) $centre->id, $about), (int) $centre->id);
        }
        foreach ($adults as $i => $adult) {
            $adultid = households::add_adult($household, $adult);
            if ($i === 0) {
                foreach (['email', 'whatsapp', 'phone'] as $channel) {
                    if (!empty($data->{'consent' . $channel})) {
                        households::consent($household, $adultid, $channel, true, 'team', $about->lang, (int) $USER->id);
                    }
                }
            }
        }
        $body = trim((string) ($data->body ?? ''));
        households::received(
            $household,
            $data->channel,
            $body !== '' ? $body : get_string('noted_by_team', $c),
            !empty($data->awaits)
        );
    }
    for ($i = 0; $i < household_form::CHILDREN; $i++) {
        $name = trim((string) $data->{"childname{$i}"});
        $childid = (int) $data->{"childid{$i}"};
        $offerid = (int) $data->{"offerid{$i}"};
        $tag = trim((string) ($data->{"childtag{$i}"} ?? ''));
        if ($childid && $DB->record_exists(households::CHILDREN, ['id' => $childid, 'householdid' => $household->id])) {
            if ($name !== '') {
                $DB->update_record(households::CHILDREN, (object) ['id' => $childid, 'firstname' => $name,
                    'tagname' => $tag === '' ? null : core_text::substr($tag, 0, 100), 'offerid' => $offerid,
                    'timemodified' => time()]);
            }
        } else if ($name !== '') {
            $childid = households::add_child($household, $name, $offerid, $tag);
        }
        if ($childid && $name !== '') {
            // The date of birth; empty takes it out.
            households::set_birthdate($household, $childid, publicpage::date_param("childbirth{$i}"));
        }
    }
    if (!empty($data->eventid) && ($event = events::get((int) $data->eventid, (int) $centre->id))) {
        $signupid = events::sign_up($event, (int) $household->id, max(1, (int) $data->people));
        if (!empty($data->came)) {
            events::attend($event, $signupid, true);
        }
    }
    $transaction->allow_commit();
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
    panel_familyform::context($form, $centre, $household, $url, $back)
);
