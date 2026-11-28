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
 * «Unsubscribe» from the mail program itself (the header List-Unsubscribe, RFC 8058). The mail program posts
 * List-Unsubscribe=One-Click when the person presses it, and what can be stopped stops at once: the invitations to events
 * (Enrique, 28-11-2026); what is of their admission (their visits, the answers, what they asked for) goes on, and a line
 * of it stays in their file. Opened as a page, it only leads to the page to choose, where nothing changes until they say so.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Mail programs post here without an account: the private link lets them.
define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\writeby;

$token = optional_param('k', '', PARAM_ALPHANUMEXT);
$lang = optional_param('idioma', '', PARAM_ALPHANUMEXT);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && optional_param('List-Unsubscribe', '', PARAM_RAW) === 'One-Click') {
    $household = households::by_link($token);
    $centre = $household ? centres::get((int) $household->centreid) : null;
    if ($household && $centre && empty($centre->practice)) {
        invites::wanted($household, false, 'mail');
    }
    // The mail program only needs to know it was taken, whatever the link was.
    http_response_code(200);
    die();
}

// Opened as a page (some mail programs open the address): the page to choose, with «do not write to us any more» pressed.
redirect(writeby::url($token, $lang, 'none'));
