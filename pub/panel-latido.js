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
 * The beat of the composer: while someone writes to a family, the rest of the team sees it. Every 20 seconds at most,
 * only while writing; the beat lasts 45 seconds on the server. And the name of a picture chosen in a hidden file field,
 * written where its label was, and the button that prints a sheet of labels (How it went prints and reads itself again in
 * panel.js). Apart from panel.js, which the art director builds.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var form = document.querySelector('form[data-latido]');
    if (!form || !window.fetch || !window.FormData) {
        return;
    }
    var field = form.querySelector('textarea');
    var last = 0;
    if (!field) {
        return;
    }
    field.addEventListener('input', function() {
        var now = Date.now();
        if (now - last < 20000) {
            return;
        }
        last = now;
        var data = new FormData();
        data.append('sesskey', form.querySelector('input[name="sesskey"]').value);
        fetch(form.getAttribute('data-latido'), {method: 'POST', body: data, credentials: 'same-origin'}).catch(function() {
            last = 0;
        });
    });
})();

(function() {
    'use strict';
    document.querySelectorAll('input[type="file"][data-archivo-nombre]').forEach(function(input) {
        input.addEventListener('change', function() {
            // Beside the button when the page has a place for it; else in place of the words of the button.
            var where = input.getAttribute('data-nombre-en');
            var label = where ? document.getElementById(where) : document.querySelector('label[for="' + input.id + '"] span');
            if (label && input.files && input.files.length) {
                label.textContent = input.files[0].name;
            }
        });
    });
})();
(function() {
    'use strict';
    // Print the sheets of labels: without JavaScript, the browser's own print does the same.
    document.querySelectorAll('[data-imprimir]').forEach(function(button) {
        button.hidden = false;
        button.addEventListener('click', function() {
            window.print();
        });
    });
})();
