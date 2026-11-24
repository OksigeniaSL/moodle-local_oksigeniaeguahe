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
 * Where any tool sends families to an entry of a centre: the script of a Google form or sheet, a connector, the web
 * of the centre. A JSON body signed with the key of the entry, in the header X-Eguahe-Signature («sha256=» and the
 * HMAC-SHA256 of the body in hexadecimal):
 *
 *     {"id": "the identifier of the answer", "time": "2026-11-21T10:00:00Z", "past": false,
 *      "answers": {"Nombre": "Marta", "Teléfono": "+34 600 000 000", "Curso": "1.º de Primaria"}}
 *
 * Answers may also be a list of {"question": …, "answer": …}, and a body may carry up to 200 of them as
 * {"items": [{…}, {…}]}. The same id never comes in twice. To an entry of kind email, an item may be an email as it came:
 * {"raw": "the whole message in base64"}.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Tools have no account: the key of the entry signs what they send.
define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\pulse;
use local_oksigeniaeguahe\local\recognise;

/**
 * Answers and ends.
 *
 * @param int $status
 * @param array $data
 */
function local_oksigeniaeguahe_hook_reply(int $status, array $data): void {
    http_response_code($status);
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');
    echo json_encode($data);
    die();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    local_oksigeniaeguahe_hook_reply(405, ['ok' => false, 'error' => 'post']);
}
$entry = entries::by_token(optional_param('e', '', PARAM_ALPHANUM));
if (!$entry) {
    local_oksigeniaeguahe_hook_reply(404, ['ok' => false, 'error' => 'entry']);
}
$body = (string) file_get_contents('php://input', false, null, 0, 1024 * 1024);
if (!entries::signed($entry, $body, (string) ($_SERVER['HTTP_X_EGUAHE_SIGNATURE'] ?? ''))) {
    // It calls, and what it brings is not seen: that is said to the team, not only to the tool.
    pulse::refused($entry);
    local_oksigeniaeguahe_hook_reply(401, ['ok' => false, 'error' => 'signature']);
}
$data = json_decode($body, true);
$items = is_array($data) && isset($data['items']) && is_array($data['items']) ? $data['items'] : [$data];
// A script that calls every night though it has nothing to bring, and says which of its tabs it did not find.
$beat = is_array($data) && !empty($data['beat']);
// And that says too when it brings only the rows that are new or have changed.
$some = is_array($data) && array_key_exists('beat', $data) && !$beat;
if ((!$items && !$beat) || count($items) > 200) {
    local_oksigeniaeguahe_hook_reply(400, ['ok' => false, 'error' => 'items']);
}
$centre = centres::get((int) $entry->centreid);
if (!$centre || !empty($centre->practice)) {
    // A practice or demo centre takes nothing from outside.
    http_response_code(404);
    die();
}
$counts = ['new' => 0, 'already' => 0, 'nobody' => 0, 'invalid' => 0];
$headless = 0;
$tested = (int) ($entry->testedat ?? 0);
$allpast = (bool) $items;
foreach ($items as $item) {
    $allpast = $allpast && is_array($item) && !empty($item['past']);
    if (is_array($item) && isset($item['raw']) && is_string($item['raw']) && $entry->kind === 'email') {
        // An email as it came, from the address of the centre.
        [$household, $new] = entries::from_email($entry, (string) base64_decode($item['raw']), !empty($item['past']));
        $counts[$household ? ($new ? 'new' : 'already') : 'nobody']++;
        continue;
    }
    if (!is_array($item) || !isset($item['answers']) || !is_array($item['answers'])) {
        $counts['invalid']++;
        continue;
    }
    $answers = [];
    foreach ($item['answers'] as $question => $answer) {
        if (is_array($answer) && array_key_exists('question', $answer)) {
            [$question, $answer] = [(string) $answer['question'], $answer['answer'] ?? ''];
        }
        $answers[\core_text::substr((string) $question, 0, 255)] = is_array($answer) ? implode(', ', $answer) :
            (string) $answer;
    }
    $time = 0;
    $dayonly = false;
    if (isset($item['time'])) {
        [$time, $dayonly] = is_numeric($item['time']) ? [(int) $item['time'], false] :
            (recognise::sent((string) $item['time']) ?? [0, false]);
    }
    $past = !empty($item['past']);
    [$household, $new, $token] = entries::receive(
        $entry,
        $answers,
        \core_text::substr((string) ($item['id'] ?? ''), 0, 255),
        $time ?: null,
        $past,
        true,
        $dayonly
    );
    $headless += entries::$headless ? 1 : 0;
    $counts[$household ? ($new ? 'new' : 'already') : 'nobody']++;
    if ($household && $new && $token && !$past && !empty($entry->acknowledge)) {
        // A family that just wrote gets, by email, that it was received and its page.
        ob_start();
        try {
            familymail::send('received', $household, $centre, null, $household->lang ?: $centre->lang, $token);
        } catch (\Throwable $e) {
            // The family is in the inbox: the team answers it anyway.
            $token = null;
        } finally {
            ob_end_clean();
        }
    }
}
// A test that came through was understood: whoever put a column right and tried it sees it at once.
$understood = $counts['new'] + $counts['already'] + ((int) ($entry->testedat ?? 0) > $tested ? 1 : 0);
$unread = $counts['nobody'] + $counts['invalid'];
$missing = $beat && isset($data['missing']) && is_array($data['missing']) ? array_slice($data['missing'], 0, 20) : [];
pulse::called($entry, $understood, $unread, $beat || ($allpast && !$some), $missing, 0, $headless > 0);
local_oksigeniaeguahe_hook_reply(200, ['ok' => true] + $counts + ($headless ? ['headless' => $headless] : []));
