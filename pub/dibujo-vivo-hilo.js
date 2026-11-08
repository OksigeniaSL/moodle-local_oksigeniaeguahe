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
 * The strokes of a drawing, found out of the page (a worker), so the page never hangs while a modest phone takes its
 * few seconds: it gets the straightened sheet as a bitmap and answers with the strokes as JSON, or with what failed.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* global importScripts, DibujoVivo */
importScripts('dibujo-vivo.js' + self.location.search);
self.onmessage = function(e) {
    try {
        self.postMessage({json: JSON.stringify(DibujoVivo.toJSON(DibujoVivo.analyze(e.data.bitmap)))});
    } catch (err) {
        self.postMessage({error: String((err && err.message) || err)});
    }
};
