// This file is part of Moodle - https://moodle.org/ (GNU GPL v3 or later).

/**
 * Before the book: where the voice says «you» as a boy or as a girl (Arabic), the family chooses once how the story is
 * read to them, and this browser remembers it. Never a field of a form: a sheet when the book first opens. Without a
 * choice, the book reads as for a boy, the form used when it is not known (Gaudí).
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var box = document.getElementById('libro-datos');
    var sheet = document.getElementById('elegir-voz');
    if (!box || !sheet) {
        return;
    }
    var book = JSON.parse(box.textContent);
    var key = 'eguahe-cuento-voz-' + book.childKey;
    var stored = null;
    try {
        stored = window.localStorage.getItem(key);
    } catch (err) {
        stored = null;
    }
    var params = new URLSearchParams(window.location.search);
    var asked = params.get('lectura');
    var chosen = asked === 'm' || asked === 'f' ? asked : stored;
    if (chosen === 'm' || chosen === 'f') {
        // Chosen before, or carried by the address when this browser keeps nothing: the book reads so.
        book.childSex = chosen;
        box.textContent = JSON.stringify(book);
        return;
    }
    sheet.hidden = false;
    var first = sheet.querySelector('button');
    if (first) {
        window.setTimeout(function() {
            first.focus();
        }, 0);
    }
    sheet.addEventListener('click', function(e) {
        var button = e.target.closest ? e.target.closest('[data-nino]') : null;
        if (!button) {
            return;
        }
        var sex = button.getAttribute('data-nino');
        try {
            window.localStorage.setItem(key, sex);
        } catch (err) {
            // Only a convenience: the address carries it this time.
        }
        params.set('lectura', sex);
        window.location.replace(window.location.pathname + '?' + params.toString() + window.location.hash);
    });
})();
