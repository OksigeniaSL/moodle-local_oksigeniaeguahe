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
 * The panel of the team, on top of pages that work without it: one menu open at a time, the search of a list, the
 * channel of an answer, and the questions asked before something that cannot be undone. Plain script, for the
 * browsers of the classrooms too (Chrome 93, Firefox 98).
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';

    var all = function(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    };

    // The menus of the bar and «More»: one open at a time; a click elsewhere or Escape closes them.
    var menus = function() {
        return all('details.menu-barra, details.mas');
    };
    document.addEventListener('click', function(e) {
        menus().forEach(function(menu) {
            if (menu.open && !menu.contains(e.target)) {
                menu.open = false;
            }
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            menus().forEach(function(menu) {
                if (menu.open) {
                    menu.open = false;
                    menu.querySelector('summary').focus();
                }
            });
        }
    });
    menus().forEach(function(menu) {
        menu.addEventListener('toggle', function() {
            if (menu.open) {
                menus().forEach(function(other) {
                    if (other !== menu) {
                        other.open = false;
                    }
                });
            }
        });
    });

    // The search of a list: the rows that do not have what is written hide, and a line says when none is left.
    all('input[data-filtrar]').forEach(function(input) {
        var rows = all(input.getAttribute('data-filtrar'));
        var none = document.querySelector('.sin-resultados');
        input.addEventListener('input', function() {
            var words = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            var shown = 0;
            rows.forEach(function(row) {
                var text = row.getAttribute('data-buscar') || '';
                var fits = words.every(function(word) {
                    return text.indexOf(word) !== -1;
                });
                row.hidden = !fits;
                shown += fits ? 1 : 0;
            });
            if (none) {
                none.hidden = shown > 0;
            }
        });
    });

    // The answer of a household: the button says the channel chosen.
    all('[data-canales]').forEach(function(box) {
        var button = box.querySelector('[data-enviar]');
        var update = function() {
            var chosen = box.querySelector('input[name="channel"]:checked');
            if (chosen && button) {
                button.querySelector('span').textContent = chosen.getAttribute('data-boton');
            }
        };
        box.addEventListener('change', update);
        update();
    });

    // Copying a link: it says it was copied, for a moment.
    all('[data-copiar]').forEach(function(button) {
        button.addEventListener('click', function() {
            var text = button.getAttribute('data-copiar');
            var done = function() {
                button.classList.add('copiado');
                window.setTimeout(function() {
                    button.classList.remove('copiado');
                }, 1800);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var field = document.createElement('textarea');
                field.value = text;
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                document.body.removeChild(field);
                done();
            }
        });
    });

    // «No» closes the question asked before something that cannot be undone.
    all('[data-cerrar]').forEach(function(button) {
        button.addEventListener('click', function() {
            var question = button.closest('details');
            if (question) {
                question.open = false;
                question.querySelector('summary').focus();
            }
        });
    });
})();
