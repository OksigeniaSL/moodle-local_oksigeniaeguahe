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
 * Eguahe: the pieces of the guided helps. Each one is the script of its steps over the real screen of the practice
 * centre; their captions are strings of the plugin, in the order of the steps (help_<piece>_01, 02…), and h.say(n) or
 * h.show(selector, n) says the step n, from 0.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
window.EgAyudaPiezas = {
    // The inbox: how many wait and since when, the order, finding and filtering, and a family opened beside it.
    inbox: function(h) {
        'use strict';
        return h.show('h1.titular', 0).then(function() {
            return h.show('.fila', 1);
        }).then(function() {
            return h.show('input[data-filtrar=".fila"]', 2);
        }).then(function() {
            return h.show('a.pastilla[data-filtro="curso"]', 3);
        }).then(function() {
            return h.you(4, '.fila a.nombres');
        }).then(function() {
            // Beside the inbox the record is a sheet, whose ids end in «-h».
            return h.show('#conv-t-h, #conv-t', 5);
        });
    },
    // Answering a family: the conversation, what they asked, the answer written after the greeting, the way, what is
    // pending, and sending it (nothing leaves the practice centre).
    answer: function(h) {
        'use strict';
        return h.show('#conv-t', 0).then(function() {
            // Only when the family asked something.
            return h.has('.pr-pregunta') ? h.show('.pr-pregunta', 1) : null;
        }).then(function() {
            return h.type('#borrador', h.text('answer'), true);
        }).then(function() {
            return h.say(2);
        }).then(function() {
            return h.show('label[for="canal-email"]', 3);
        }).then(function() {
            return h.show('#siguiente', 4);
        }).then(function() {
            // Sent without leaving the page: the record is drawn again, with the answer in its conversation.
            return h.you(5, 'button[data-enviar]');
        }).then(function() {
            return h.wait('[data-hoja] #conv-t, #conv-t');
        }).then(function() {
            return h.show('#conv-t', 6);
        });
    },
    // A new event: what it is, when and where, how many fit, what is asked after, the families' view, and creating it.
    newevent: function(h) {
        'use strict';
        var tomorrow = new Date(Date.now() + 86400000);
        return h.show('h1.titular', 0).then(function() {
            return h.tap('label[for="tipo-openday"]');
        }).then(function() {
            return h.say(1);
        }).then(function() {
            return h.type('#acto-diad', String(tomorrow.getDate()));
        }).then(function() {
            return h.choose('#acto-diam', String(tomorrow.getMonth() + 1));
        }).then(function() {
            return h.type('#acto-diay', String(tomorrow.getFullYear()));
        }).then(function() {
            return h.type('#acto-donde', h.text('event'));
        }).then(function() {
            return h.say(2);
        }).then(function() {
            return h.type('#acto-aforo', '80');
        }).then(function() {
            return h.say(3);
        }).then(function() {
            return h.show('#acto-encuesta', 4);
        }).then(function() {
            return h.show('#muestra-t', 5);
        }).then(function() {
            return h.you(6, 'button[name="save"]', true);
        });
    },
    // The door on the day: finding a family, «Came», the hour it keeps, someone who did not sign up, and the labels.
    door: function(h) {
        'use strict';
        return h.show('#acto-t', 0).then(function() {
            return h.type('input[data-filtrar=".llegada"]', h.text('door'));
        }).then(function() {
            return h.say(1);
        }).then(function() {
            return h.you(2, '.llegada button.vino');
        }).then(function() {
            return h.show('.llegada', 3);
        }).then(function() {
            return h.show('a[data-tecla="a"]', 4);
        }).then(function() {
            return h.show('a.boton[href*="labels.php"]', 5);
        });
    }
};
