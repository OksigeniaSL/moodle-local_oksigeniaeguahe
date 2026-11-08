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
 * The pages of the families, the little the browser adds: the label that writes itself, the rows that wait to be
 * added, the step of the people, the chips and turns that show what is chosen, the menu of languages and the button
 * that says it is sending. Without it, the pages work all the same.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';

    var SIZE = 13;
    var ROOM = 35;
    var all = function(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    };

    // Long names: the handwriting gets smaller until it fits on the line of the label; a capital with an accent
    // (Í, Á, Ñ) too, so it does not climb into the band. The same rule as the label of the mail.
    var UP = 10;
    var BASELINE = 4.4;
    var fit = function(scope) {
        all('svg.colgante text.mano', scope).forEach(function(text) {
            text.setAttribute('font-size', SIZE);
            if (!text.textContent) {
                return;
            }
            var k = Math.min(1, ROOM / text.getComputedTextLength());
            var box = text.getBBox();
            var up = BASELINE - box.y;
            if (up > UP) {
                k = Math.min(k, UP / up);
            }
            text.setAttribute('font-size', (SIZE * k).toFixed(2));
        });
    };

    var ready = function() {
        fit();
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function() {
                fit();
            });
        }

        // The menu of languages closes with Escape and when touching elsewhere.
        var menu = document.querySelector('details.idioma');
        if (menu) {
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && menu.open) {
                    menu.open = false;
                    menu.querySelector('summary').focus();
                }
            });
            document.addEventListener('click', function(e) {
                if (menu.open && !menu.contains(e.target)) {
                    menu.open = false;
                }
            });
        }

        // Chips and turns: the box around shows what is chosen.
        var mark = function() {
            all('.ficha, .turno').forEach(function(label) {
                var input = label.querySelector('input');
                label.classList.toggle('esta', Boolean(input && input.checked));
            });
        };
        document.addEventListener('change', mark);
        mark();

        // The step of the people who come.
        all('.paso').forEach(function(step) {
            var input = step.querySelector('input');
            all('button[data-step]', step).forEach(function(button) {
                button.hidden = false;
                button.addEventListener('click', function() {
                    var value = parseInt(input.value, 10) || 1;
                    input.value = Math.max(1, Math.min(20, value + parseInt(button.getAttribute('data-step'), 10)));
                });
            });
        });

        // Where they live: another place, or moving to the area, asks where.
        var where = document.getElementById('municipality');
        var far = document.querySelector('.lejos');
        if (where && far) {
            var label = far.querySelector('label');
            var place = function() {
                var value = where.value;
                far.hidden = value !== 'other' && value !== 'moving' && !far.querySelector('input').value;
                label.textContent = label.getAttribute(value === 'moving' ? 'data-moving' : 'data-other');
            };
            where.addEventListener('change', place);
            place();
        }

        // The label writes itself: with the first letter of a name, the backpack arrives at its hook.
        var rail = document.querySelector('.perchas[data-live]');
        var visible = function(selector) {
            return all(selector).filter(function(el) {
                return !el.hidden;
            });
        };
        var labelName = function(i) {
            var tag = document.getElementById('c' + i + 'tag');
            var name = document.getElementById('c' + i + 'name');
            var written = tag ? tag.value.trim() : '';
            return written || (name ? name.value.trim().split(/\s+/)[0] : '');
        };
        var courses = function() {
            var one = visible('.nino').length === 1;
            var legend = document.querySelector('fieldset.cursos legend');
            if (legend) {
                legend.textContent = one ? (document.querySelector('[data-course-label="0"]') || legend).textContent :
                    legend.getAttribute('data-many');
            }
            all('[data-course-label]').forEach(function(courseLabel) {
                courseLabel.classList.toggle('oculto', one);
            });
        };
        var update = function(i) {
            var now = labelName(i);
            var peg = rail ? rail.querySelector('.percha[data-i="' + i + '"]') : null;
            if (peg) {
                var bag = peg.querySelector('.mochila');
                var text = peg.querySelector('text.mano');
                var before = text.textContent;
                text.textContent = now;
                if (now && !before && bag) {
                    bag.classList.remove('llega');
                    bag.getBoundingClientRect();
                    bag.classList.add('llega');
                }
                fit(peg);
            }
            var name = document.getElementById('c' + i + 'name');
            var tag = document.getElementById('c' + i + 'tag');
            if (name && tag) {
                tag.placeholder = name.value.trim().split(/\s+/)[0] || '';
            }
            var courseLabel = document.querySelector('[data-course-label="' + i + '"]');
            if (courseLabel) {
                courseLabel.textContent = now ? courseLabel.getAttribute('data-with').replace('{name}', now) :
                    courseLabel.getAttribute('data-without');
            }
            courses();
        };
        document.addEventListener('input', function(e) {
            var i = e.target.getAttribute('data-child');
            if (i !== null) {
                update(i);
            }
        });

        // Someone else: the next row that waits, with its hook and its course.
        all('[data-add]').forEach(function(button) {
            button.addEventListener('click', function() {
                var kind = button.getAttribute('data-add');
                var next = all('.' + kind).filter(function(row) {
                    return row.hidden;
                })[0];
                if (!next) {
                    return;
                }
                var i = next.getAttribute('data-i');
                next.hidden = false;
                if (kind === 'nino') {
                    var peg = rail ? rail.querySelector('.percha[data-i="' + i + '"]') : null;
                    var course = document.querySelector('.curso[data-i="' + i + '"]');
                    if (peg) {
                        peg.hidden = false;
                        rail.className = rail.className.replace(/\bn\d\b/, 'n' + visible('.nino').length);
                    }
                    if (course) {
                        course.hidden = false;
                    }
                    courses();
                    document.getElementById('c' + i + 'name').focus();
                } else {
                    next.querySelector('input').focus();
                }
                if (!all('.' + kind).some(function(row) {
                    return row.hidden;
                })) {
                    button.hidden = true;
                }
            });
        });

        // Sending: the button says so, and does not send twice.
        var form = document.getElementById('eg-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                var submit = form.querySelector('button[type="submit"]');
                if (submit.getAttribute('aria-disabled') === 'true') {
                    e.preventDefault();
                    return;
                }
                submit.setAttribute('aria-disabled', 'true');
                submit.innerHTML = '<span class="giro" aria-hidden="true"></span>';
                submit.appendChild(document.createTextNode(submit.getAttribute('data-sending')));
            });
        }
        var summary = document.getElementById('resumen');
        if (summary) {
            summary.focus();
        }

        // Questions that wait for an answer: shown when the question they depend on has it, hidden with it.
        var plain = function(text) {
            return String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
        };
        var conditions = function() {
            all('[data-si]').forEach(function(item) {
                var name = item.getAttribute('data-si');
                var parent = document.getElementById(name);
                var given = all('[name="' + name + '"], [name="' + name + '[]"]').filter(function(input) {
                    return input.type === 'radio' || input.type === 'checkbox' ? input.checked : true;
                }).map(function(input) {
                    return plain(input.value);
                });
                var show = !(parent && parent.hidden) && given.indexOf(plain(item.getAttribute('data-si-valor'))) !== -1;
                if (item.hidden === show) {
                    item.hidden = !show;
                    item.classList.toggle('aparece', show);
                }
            });
        };
        if (document.querySelector('[data-si]')) {
            var again = function() {
                // Twice: a question may wait for another that waits for a third.
                conditions();
                conditions();
            };
            again();
            document.addEventListener('change', again);
            document.addEventListener('input', again);
        }

        // After the visit: what they were left to ask goes with the button saying so, once.
        all('form[data-preguntar], form[data-encuesta]').forEach(function(sheet) {
            sheet.addEventListener('submit', function(e) {
                var send = sheet.querySelector('button[name="send"]');
                var text = sheet.querySelector('textarea[name="question"]');
                if (!send || !text || !text.value.trim()) {
                    return;
                }
                if (send.getAttribute('aria-disabled') === 'true') {
                    e.preventDefault();
                    return;
                }
                send.setAttribute('aria-disabled', 'true');
                send.lastChild.textContent = send.getAttribute('data-sending');
            });
        });
        var just = document.querySelector('[data-recien]');
        if (just) {
            just.focus();
        }

        // The survey of the visit: each answer is kept when it is touched, and says so; nothing stays in the
        // browser, which inside WhatsApp may not keep it. Without this, the button keeps them all.
        var survey = document.querySelector('form[data-encuesta]');
        if (survey && window.fetch && window.FormData) {
            survey.classList.add('al-tocar');
            var keep = function(question) {
                var data = new window.FormData();
                data.append('sesskey', survey.querySelector('[name="sesskey"]').value);
                data.append('action', 'survey');
                data.append('fetch', '1');
                data.append('q', question.getAttribute('data-q'));
                all('input, textarea', question).forEach(function(input) {
                    if ((input.type !== 'radio' && input.type !== 'checkbox') || input.checked) {
                        data.append(input.name, input.value);
                    }
                });
                var said = question.querySelector('[data-guardado]');
                window.fetch(survey.getAttribute('action'), {method: 'POST', body: data, credentials: 'same-origin'}).then(function(r) {
                    return r.json();
                }).then(function(answer) {
                    if (said && answer.saved) {
                        said.textContent = survey.getAttribute('data-guardado-texto');
                        said.classList.remove('dicho');
                        void said.offsetWidth;
                        said.classList.add('dicho');
                    }
                }).catch(function() {
                    // Without connection the button is back: it keeps them all when they press it.
                    survey.classList.remove('al-tocar');
                });
            };
            survey.addEventListener('change', function(e) {
                var question = e.target.closest ? e.target.closest('[data-q]') : null;
                if (question && e.target.name !== 'question') {
                    keep(question);
                }
            });
        }

        // Inside the web of the centre: it says how tall it is, and to go to its top after sending.
        if (window.parent !== window) {
            var tell = function() {
                window.parent.postMessage({eguahe: 'height', height: document.documentElement.scrollHeight}, '*');
            };
            tell();
            window.addEventListener('load', tell);
            window.addEventListener('resize', tell);
            if (window.ResizeObserver) {
                new window.ResizeObserver(tell).observe(document.body);
            }
            if (!form || summary) {
                window.parent.postMessage({eguahe: 'top'}, '*');
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
