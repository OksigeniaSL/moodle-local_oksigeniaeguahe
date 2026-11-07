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
 * For the web of a centre that shows a form of Oksigenia Eguahe inside it: the form is as tall as it is, without a
 * scroll bar of its own, and after sending, the page goes to its top.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    window.addEventListener('message', function(event) {
        var data = event.data;
        if (!data || (data.eguahe !== 'height' && data.eguahe !== 'top')) {
            return;
        }
        var frames = document.querySelectorAll('iframe[src*="/local/oksigeniaeguahe/w.php"]');
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow !== event.source) {
                continue;
            }
            if (data.eguahe === 'height') {
                frames[i].style.height = Math.max(300, Math.min(20000, Number(data.height) || 0)) + 'px';
                frames[i].style.minHeight = '0';
            } else if (frames[i].getBoundingClientRect().top < 0) {
                frames[i].scrollIntoView({block: 'start'});
            }
        }
    });
})();
