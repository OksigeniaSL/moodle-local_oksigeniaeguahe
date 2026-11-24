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
 * The brief form inside the web of a centre: it tells the web how tall it is, how much of it is ready and, once
 * sent, that it was. The form itself is formulario.js.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var tell = function() {
        window.parent.postMessage({eguahe: 'height', height: Math.ceil(document.body.getBoundingClientRect().height)}, '*');
    };
    if (window.parent !== window) {
        tell();
        // How much of what is needed is ready (formulario.js), for the web to paint it as it fills.
        var filled = function(of) {
            window.parent.postMessage({eguahe: 'filled', n: of.n, of: of.of}, '*');
        };
        if (window.EguaheLleno) {
            filled(window.EguaheLleno);
        }
        document.addEventListener('eguahe:lleno', function(e) {
            filled(e.detail);
        });
        window.addEventListener('load', tell);
        window.addEventListener('resize', tell);
        if (window.ResizeObserver) {
            new window.ResizeObserver(tell).observe(document.body);
        }
        // The key does not reach the web from inside the frame: its window closes as with any other.
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.parent.postMessage({eguahe: 'close'}, '*');
            }
        });
        if (document.body.hasAttribute('data-enviado')) {
            window.parent.postMessage({eguahe: 'sent'}, '*');
        }
    }
})();
