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
 * The preview image of a piece shared, as a chat or a network shows it: the story or the drawing of a child, by its
 * signed link; an open act, by its public token. In the colour and the name of the centre; nothing else of the family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
// phpcs:ignore moodle.Files.RequireLogin.Missing -- The networks that build a preview have no account: the signed link is enough.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\drawings;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;
use local_oksigeniaeguahe\local\share;
use local_oksigeniaeguahe\local\story;

$piece = optional_param('p', '', PARAM_ALPHA);
$png = '';
if ($piece === 'acto') {
    $event = events::by_token(optional_param('t', '', PARAM_ALPHANUM));
    $centre = $event ? centres::get((int) $event->centreid) : null;
    if ($event && $centre && share::act_open($event)) {
        $lang = publiclang::choose($centre);
        $when = publicpage::date_parts((int) $event->timestart, $lang);
        $png = share::card($centre, 'acto', ['weekday' => $when['weekday'], 'date' => $when['daymonth'],
            'hours' => familypage::hours((int) $event->timestart, (int) $event->timeend, $lang),
            'title' => format_string($event->name)]);
    }
} else if ($piece === 'cuento' || $piece === 'dibujo') {
    $householdid = optional_param('h', 0, PARAM_INT);
    $childid = optional_param('n', 0, PARAM_INT);
    $signature = optional_param('s', '', PARAM_ALPHANUM);
    $found = $piece === 'cuento' ? story::from_link($householdid, $childid, $signature) :
        (share::signed('dibujo', $householdid, $childid, $signature) ? story::from_ids($householdid, $childid) : null);
    if ($found) {
        [, $child, $centre] = $found;
        $lang = publiclang::choose($centre);
        $name = familypage::label_name($child) ?: (string) $child->firstname;
        $file = drawings::file((int) $child->id);
        $picture = $file ? $file->get_content() : null;
        if ($piece === 'cuento') {
            // The story as its cover: its title and the label with the name.
            $png = share::card($centre, 'cuento', ['title' => publiclang::str('share_story_kicker', $lang), 'name' => $name]);
        } else if ($picture !== null) {
            $png = share::card($centre, 'dibujo', ['name' => $name], $picture);
        }
    }
}
if ($png === '') {
    send_header_404();
    die();
}
@header('Content-Type: image/png');
@header('Content-Length: ' . strlen($png));
@header('Cache-Control: public, max-age=3600');
@header('X-Content-Type-Options: nosniff');
echo $png;
