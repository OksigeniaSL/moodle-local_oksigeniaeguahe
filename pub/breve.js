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
 * The brief form inside the web of a centre: it tells the web how tall it is and, once sent, that it was; and the button
 * goes quiet while it is sent.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var tell = function() {
        window.parent.postMessage({eguahe: 'height', height: Math.ceil(document.body.getBoundingClientRect().height)}, '*');
    };
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            form.querySelector('button').disabled = true;
        });
        window.addEventListener('pageshow', function() {
            form.querySelector('button').disabled = false;
        });
        // How much of what is needed is ready (who writes, who would come, a way to answer, the option), never what it says:
        // the web can paint it as it fills; and each field that is ready draws its mark.
        var needed = Array.prototype.slice.call(form.querySelectorAll('[data-hace-falta]'));
        var options = Array.prototype.slice.call(form.querySelectorAll('.opciones input'));
        var ready = function(field) {
            var value = field.value.trim();
            if (!value) {
                return false;
            }
            if (field.type === 'email') {
                return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
            }
            return field.type !== 'tel' || value.replace(/\D/g, '').length >= 7;
        };
        var before = '';
        var filled = function() {
            var needs = {};
            needed.forEach(function(field) {
                var need = field.getAttribute('data-hace-falta');
                var is = ready(field);
                field.parentNode.classList.toggle('esta', is && field.tagName === 'INPUT');
                needs[need] = needs[need] || is;
            });
            // What was wrong and no longer is goes back to its name.
            needed.forEach(function(field) {
                var label = field.parentNode.querySelector('label.mal');
                if (label && needs[field.getAttribute('data-hace-falta')] && (ready(field) || !field.value.trim())) {
                    label.textContent = label.getAttribute('data-nombre');
                    label.classList.remove('mal');
                    field.removeAttribute('aria-invalid');
                }
            });
            if (options.length) {
                needs.opcion = options.some(function(option) {
                    return option.checked;
                });
            }
            var all = Object.keys(needs);
            var n = all.filter(function(need) {
                return needs[need];
            }).length;
            form.classList.toggle('falta', n < all.length);
            if (window.parent !== window && before !== n + '/' + all.length) {
                before = n + '/' + all.length;
                window.parent.postMessage({eguahe: 'filled', n: n, of: all.length}, '*');
            }
        };
        form.addEventListener('input', filled);
        form.addEventListener('change', filled);
        window.addEventListener('pageshow', filled);
        filled();
        // What is wrong, first: where to write.
        var wrong = form.querySelector('[aria-invalid="true"]');
        if (wrong) {
            wrong.focus({preventScroll: true});
        }
    }
    if (window.parent !== window) {
        tell();
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
