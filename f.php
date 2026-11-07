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
 * The page of a family, opened with its private link: what the centre keeps about it, its next visit with the file
 * for its calendar, and its data, to take with it or to erase, whenever it wants.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Families have no account: their private link lets them in.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;

$token = optional_param('k', '', PARAM_ALPHANUMEXT);
$new = optional_param('new', 0, PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);
$url = new moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]);
publicpage::setup($url);

$household = households::by_link($token);
$centre = $household ? centres::get((int) $household->centreid) : null;
if (!$household || !$centre) {
    $lang = publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en';
    publicpage::message(
        null,
        $lang,
        publiclang::str('pub_link_gone_title', $lang),
        publiclang::str('pub_link_gone', $lang),
        $url
    );
}
$lang = publiclang::choose($centre);

if ($action === 'download') {
    publicpage::headers();
    @header('Content-Type: application/json; charset=utf-8');
    @header('Content-Disposition: attachment; filename="' . clean_filename(publiclang::str('pub_data_file', $lang)) .
        '.json"');
    echo json_encode(households::export($household), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

if ($action === 'erase') {
    if (optional_param('confirm', 0, PARAM_BOOL) && data_submitted() && confirm_sesskey()) {
        households::erase($household);
        publicpage::message(
            $centre,
            $lang,
            publiclang::str('pub_erased_title', $lang),
            publiclang::str('pub_erased', $lang),
            $url
        );
    }
    publicpage::output(
        $centre,
        $lang,
        publiclang::str('pub_erase_title', $lang),
        'public_erase',
        familypage::erase_context($household, $centre, $lang, $token),
        $url
    );
}

publicpage::output(
    $centre,
    $lang,
    publiclang::str('pub_family_title', $lang),
    'public_family',
    familypage::context($household, $centre, $lang, $token, $new),
    $url
);
