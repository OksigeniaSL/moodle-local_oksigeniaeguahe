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
 * A centre, new or changed: how families see it, what it says about their data, its area, its offer and its team.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\form\centre_form;
use local_oksigeniaeguahe\local\ai;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\staff;

$id = optional_param('id', 0, PARAM_INT);
$c = 'local_oksigeniaeguahe';
$context = context_system::instance();

require_login(null, false);
require_capability('local/oksigeniaeguahe:manage', $context);
$centre = $id ? centres::get($id) : null;
if ($id && !$centre) {
    throw new moodle_exception('invalidrecord', 'error');
}
$url = new moodle_url('/local/oksigeniaeguahe/centre.php', $id ? ['id' => $id] : []);
staff::setup($url, get_string($centre ? 'centre_settings' : 'centre_new', $c), $centre);

$offers = $centre ? array_values(centres::offers((int) $centre->id)) : [];
$form = new centre_form($url, ['offers' => count($offers), 'providers' => ai::providers()]);
$draft = file_get_submitted_draft_itemid('logo');
file_prepare_draft_area($draft, $context->id, $c, 'logo', $id, ['subdirs' => 0, 'maxfiles' => 1]);
if ($centre) {
    $defaults = (array) $centre + ['logo' => $draft, 'members' => array_keys(centres::members((int) $centre->id))];
    foreach ($offers as $i => $offer) {
        $defaults["offerid[$i]"] = $offer->id;
        $defaults["offername[$i]"] = $offer->name;
        $defaults["offeryear[$i]"] = $offer->schoolyear;
        $defaults["offerplaces[$i]"] = $offer->places;
        $defaults["offeractive[$i]"] = $offer->active;
    }
    $form->set_data($defaults);
} else {
    $form->set_data(['logo' => $draft, 'lang' => current_language() === 'pt_br' ? 'pt_br' :
        (in_array(current_language(), ['es', 'en', 'pt'], true) ? current_language() : 'en'), 'retention' => 24]);
}

if ($form->is_cancelled()) {
    redirect($centre ? new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]) :
        new moodle_url('/local/oksigeniaeguahe/centres.php'));
}
if ($data = $form->get_data()) {
    $transaction = $DB->start_delegated_transaction();
    if ($centre) {
        centres::update((int) $centre->id, $data);
        $centreid = (int) $centre->id;
    } else {
        $centreid = centres::create($data);
    }
    file_save_draft_area_files($data->logo, $context->id, $c, 'logo', $centreid, ['subdirs' => 0, 'maxfiles' => 1]);
    foreach ($data->offername ?? [] as $i => $name) {
        $offerid = (int) ($data->offerid[$i] ?? 0);
        if (trim((string) $name) === '' && !$offerid) {
            continue;
        }
        if (trim((string) $name) === '') {
            // An offer whose name was emptied stops showing; families and numbers keep it.
            $DB->set_field(centres::OFFERS, 'active', 0, ['id' => $offerid, 'centreid' => $centreid]);
            continue;
        }
        centres::save_offer($centreid, (object) ['id' => $offerid, 'name' => $name, 'schoolyear' => $data->offeryear[$i],
            'places' => $data->offerplaces[$i] ?? 0, 'active' => $data->offeractive[$i] ?? 0, 'sortorder' => $i]);
    }
    $members = array_map('intval', (array) ($data->members ?? []));
    foreach (centres::members($centreid) as $userid => $member) {
        if (!in_array((int) $userid, $members, true)) {
            centres::remove_member($centreid, (int) $userid);
        }
    }
    foreach ($members as $userid) {
        centres::add_member($centreid, $userid);
    }
    $transaction->allow_commit();
    redirect(
        new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centreid]),
        get_string('changessaved'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
if ($centre) {
    echo $OUTPUT->render_from_template('local_oksigeniaeguahe/tabs', ['tabs' => staff::tabs($centre, 'settings')]);
}
echo $OUTPUT->heading(get_string($centre ? 'centre_settings' : 'centre_new', $c));
$form->display();
echo $OUTPUT->footer();
