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
 * Where Mailjet tells what happened to the letters of a centre that sends by it (event notifications): what did not arrive
 * (bounce, blocked, spam) is marked in the file of the family, as the returned mail read from the mailbox. One event or a
 * list of them, as JSON; the address carries the centre and its key (Settings, «How the letters go out»).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Mailjet has no account: the key of the address of the centre is the proof.
define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\mailer;

$centreid = optional_param('c', 0, PARAM_INT);
$key = optional_param('k', '', PARAM_ALPHANUM);
@header('Content-Type: text/plain; charset=utf-8');
if (!$centreid || !mailer::hook_ok($centreid, $key)) {
    http_response_code(403);
    echo 'no';
    die();
}
$body = json_decode((string) file_get_contents('php://input'), true);
$events = is_array($body) ? (isset($body['event']) ? [$body] : array_values(array_filter($body, 'is_array'))) : [];
// Always an answer of 200 for what was understood: Mailjet tries again what is not answered so.
mailer::events($centreid, array_slice($events, 0, 500), time());
echo 'ok';
