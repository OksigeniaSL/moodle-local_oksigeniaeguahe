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
 * The form of Eguahe, inside the web of a centre and on its own page: each field that is ready draws its mark, what was
 * wrong and no longer is goes back to its name, the number of who come goes up and down, and the button goes quiet while
 * it is sent. How much of what is needed is ready is told to the page (eguahe:lleno), never what it says.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var all = function(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    };
    // How many: one less, one more, never out of what the act takes.
    var step = function(box) {
        var number = box.querySelector('input');
        var less = box.querySelector('[data-fb-menos]');
        var more = box.querySelector('[data-fb-mas]');
        var least = parseInt(number.min, 10) || 1;
        var most = parseInt(number.max, 10) || 99;
        var put = function(value, by) {
            number.value = Math.max(least, Math.min(most, value || least));
            less.disabled = +number.value <= least;
            more.disabled = +number.value >= most;
            if (by) {
                number.dispatchEvent(new Event('change', {bubbles: true}));
            }
        };
        less.hidden = more.hidden = false;
        less.addEventListener('click', function() {
            put(+number.value - 1, true);
        });
        more.addEventListener('click', function() {
            put(+number.value + 1, true);
        });
        ['change', 'eguahe:pon'].forEach(function(name) {
            number.addEventListener(name, function() {
                put(parseInt(number.value, 10));
            });
        });
        put(parseInt(number.value, 10));
    };
    // A long name of a field (a language, a narrow phone): smaller until it fits with air at its side, and in two lines
    // if not even so.
    var pen = document.createElement('canvas').getContext('2d');
    var fit = function(input) {
        var label = input.parentNode.querySelector('label');
        var text = label ? (label.getAttribute('data-nombre') || label.textContent).trim() : '';
        var style = window.getComputedStyle(input);
        var room = input.clientWidth - parseFloat(style.paddingInlineStart) - 12;
        if (!pen || !text || !(room > 0)) {
            return;
        }
        var size = window.innerWidth > 420 ? 16 : 15;
        var first = size;
        var wide = function() {
            pen.font = '500 ' + size + 'px ' + style.fontFamily;
            return pen.measureText(text).width > room;
        };
        while (wide() && size > 13) {
            size -= 0.5;
        }
        if (size < first) {
            input.parentNode.style.setProperty('--fb-rotulo', size + 'px');
        } else {
            input.parentNode.style.removeProperty('--fb-rotulo');
        }
        input.parentNode.classList.toggle('fb-dos', wide());
    };
    // An email whose domain looks like a slip of a common one (gmial.com): «Did you mean…?», and one touch uses it.
    var distance = function(a, b) {
        var row = [];
        for (var j = 0; j <= b.length; j++) {
            row[j] = j;
        }
        for (var i = 1; i <= a.length; i++) {
            var before = row[0];
            row[0] = i;
            for (var k = 1; k <= b.length; k++) {
                var keep = row[k];
                row[k] = Math.min(row[k] + 1, row[k - 1] + 1, before + (a[i - 1] === b[k - 1] ? 0 : 1));
                before = keep;
            }
        }
        return row[b.length];
    };
    var mail = function(form) {
        var domains = (form.getAttribute('data-dominios') || '').split(' ').filter(Boolean);
        var input = form.querySelector('input[type="email"]');
        if (!domains.length || !input) {
            return;
        }
        var words = (form.getAttribute('data-quisieron') || '').split('{$a}');
        var note = document.createElement('p');
        note.className = 'fb-quisieron';
        note.hidden = true;
        note.setAttribute('aria-live', 'polite');
        input.parentNode.insertAdjacentElement('afterend', note);
        var meant = function(email) {
            var at = email.lastIndexOf('@');
            var domain = at === -1 ? '' : email.slice(at + 1).trim().toLowerCase();
            var best = '';
            var least = Infinity;
            if (!domain || domains.indexOf(domain) !== -1) {
                return '';
            }
            domains.forEach(function(known) {
                var d = distance(domain, known);
                if (d < least) {
                    least = d;
                    best = known;
                }
            });
            return least <= (domain.length < 8 ? 1 : 2) ? email.slice(0, at) + '@' + best : '';
        };
        var check = function() {
            var better = meant(input.value);
            note.textContent = '';
            note.hidden = !better;
            if (!better) {
                return;
            }
            var shown = document.createElement('bdi');
            shown.dir = 'ltr';
            shown.textContent = better;
            var use = document.createElement('button');
            use.type = 'button';
            use.textContent = form.getAttribute('data-usar');
            use.addEventListener('click', function() {
                input.value = better;
                input.dispatchEvent(new Event('input', {bubbles: true}));
                check();
                input.focus();
            });
            [words[0] || '', shown, (words[1] || '') + ' ', use].forEach(function(piece) {
                note.appendChild(typeof piece === 'string' ? document.createTextNode(piece) : piece);
            });
        };
        input.addEventListener('change', check);
        check();
    };
    all('[data-fb]').forEach(function(form) {
        var names = function() {
            all('.fb-campo input:not([type="number"])', form).forEach(fit);
        };
        names();
        window.addEventListener('resize', names);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(names);
        }
        var send = form.querySelector('.fb-enviar');
        form.addEventListener('submit', function() {
            send.disabled = true;
        });
        window.addEventListener('pageshow', function() {
            send.disabled = false;
        });
        var needed = all('[data-hace-falta]', form);
        var options = all('.fb-opciones input', form);
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
                field.parentNode.classList.toggle('fb-esta', is && field.tagName === 'INPUT');
                needs[need] = needs[need] || is;
            });
            needed.forEach(function(field) {
                var label = field.parentNode.querySelector('label.fb-mal');
                if (label && needs[field.getAttribute('data-hace-falta')] && (ready(field) || !field.value.trim())) {
                    label.textContent = label.getAttribute('data-nombre');
                    label.classList.remove('fb-mal');
                    field.removeAttribute('aria-invalid');
                }
            });
            if (options.length) {
                needs.opcion = options.some(function(option) {
                    return option.checked;
                });
            }
            var keys = Object.keys(needs);
            var n = keys.filter(function(need) {
                return needs[need];
            }).length;
            form.classList.toggle('fb-falta', n < keys.length);
            if (before !== n + '/' + keys.length) {
                before = n + '/' + keys.length;
                window.EguaheLleno = {n: n, of: keys.length};
                document.dispatchEvent(new CustomEvent('eguahe:lleno', {detail: window.EguaheLleno}));
            }
        };
        form.addEventListener('input', filled);
        form.addEventListener('change', filled);
        window.addEventListener('pageshow', filled);
        filled();
        all('[data-fb-paso]', form).forEach(step);
        mail(form);
        // What is wrong, first: where to write.
        var wrong = form.querySelector('[aria-invalid="true"]');
        if (wrong) {
            wrong.focus({preventScroll: true});
        }
    });
    // Whoever is known: what they say is kept at the touch, and so are how many and how they are written to.
    var known = function(sheet) {
        var form = sheet.querySelector('form');
        var field = function(name) {
            return form.querySelector(name);
        };
        var says = field('[data-dice]');
        var done = field('[data-hecho]');
        var full = field('[data-lleno]');
        var detail = field('[data-detalle]');
        var number = field('[data-fb-paso] input');
        var buttons = all('button[name="reply"]', form);
        var post = function(more) {
            var data = new FormData(form);
            Object.keys(more).forEach(function(key) {
                data.set(key, more[key]);
            });
            data.set('ajax', '1');
            return window.fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin'}).then(function(r) {
                return r.ok ? r.json() : Promise.reject(r.status);
            });
        };
        // What the server kept, always: never what was pressed.
        var show = function(said) {
            full.textContent = said.full || '';
            done.textContent = said.done || '';
            detail.hidden = !said.coming;
            buttons.forEach(function(button) {
                button.setAttribute('aria-pressed', button.value === said.answer ? 'true' : 'false');
            });
            form.querySelector('.unir-botones').classList.toggle('dijo-no', said.answer === 'no');
            number.value = said.people;
            number.dispatchEvent(new Event('eguahe:pon'));
        };
        var failed = function() {
            full.textContent = form.getAttribute('data-fallo');
        };
        var busy = false;
        buttons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (busy) {
                    return;
                }
                busy = true;
                says.textContent = '';
                post({reply: button.value}).then(show).catch(failed).then(function() {
                    busy = false;
                });
            });
        });
        // Enter in the number keeps it, as leaving it does; it never answers by itself.
        form.addEventListener('submit', function(e) {
            e.preventDefault();
        });
        var wait = 0;
        detail.addEventListener('change', function() {
            window.clearTimeout(wait);
            says.textContent = '';
            full.textContent = '';
            wait = window.setTimeout(function() {
                post({}).then(function(said) {
                    show(said);
                    says.textContent = said.ok ? form.getAttribute('data-guardado') : '';
                }).catch(failed);
            }, 450);
        });
        all('[data-fb-paso]', form).forEach(step);
        all('[data-sin-guion]', form).forEach(function(el) {
            el.hidden = true;
        });
        // Looked at for a moment, with the page in sight: that is what counts as opened, and it is said once.
        var looking = 0;
        var looked = function() {
            window.clearTimeout(looking);
            if (document.visibilityState === 'visible') {
                looking = window.setTimeout(function() {
                    document.removeEventListener('visibilitychange', looked);
                    post({opened: '1'}).catch(function() {
                        return false;
                    });
                }, 1500);
            }
        };
        document.addEventListener('visibilitychange', looked);
        looked();
    };
    if (window.fetch) {
        all('[data-conocida]').forEach(known);
    }
    // The whole page on one screen: the poster takes what the rest leaves it, never less than what can be read; if not even
    // so, what it is about is cut, and the rest is a touch away.
    var page = document.querySelector('.unir');
    var poster = page && page.querySelector('.unir-cartel img');
    var about = page && page.querySelector('[data-leer]');
    var more = null;
    var cut = function(lines) {
        about.classList.toggle('recortado', lines > 0);
        about.style.setProperty('--lineas', lines);
        if (lines && !more) {
            more = document.createElement('button');
            more.type = 'button';
            more.className = 'unir-leer';
            more.textContent = about.getAttribute('data-leer');
            more.addEventListener('click', function() {
                about.classList.remove('recortado');
                about.removeAttribute('data-leer');
                more.remove();
                about.setAttribute('tabindex', '-1');
                about.focus({preventScroll: true});
            });
            about.insertAdjacentElement('afterend', more);
        }
        if (more) {
            more.hidden = !lines;
        }
    };
    // What is left of the screen under what is told, with the foot in sight; below zero, what does not fit.
    var left = function() {
        var bottom = 0;
        all('.unir-cartel, .unir-que, .unir-mas', page).forEach(function(el) {
            var style = window.getComputedStyle(el);
            bottom = Math.max(bottom, el.getBoundingClientRect().bottom + parseFloat(style.marginBottom));
        });
        var main = document.querySelector('main').getBoundingClientRect();
        var foot = document.documentElement.scrollHeight - (main.bottom + window.pageYOffset);
        return window.innerHeight - (bottom + window.pageYOffset) - parseFloat(window.getComputedStyle(page).paddingBottom) - foot;
    };
    var fitpage = function() {
        var wide = window.matchMedia('(min-width: 960px)').matches;
        var reads = about && about.hasAttribute('data-leer');
        page.style.removeProperty('--cartel-alto');
        if (reads) {
            cut(0);
        }
        if (!wide) {
            return;
        }
        for (var i = 0; poster && i < 3; i++) {
            var room = left();
            if (Math.abs(room) < 2 || (room > 0 && i > 1)) {
                break;
            }
            page.style.setProperty('--cartel-alto', Math.round(Math.max(page.classList.contains('apaisado') ? 230 : 300,
                poster.offsetHeight + room)) + 'px');
        }
        var over = left();
        if (reads && over < -1) {
            var line = parseFloat(window.getComputedStyle(about).lineHeight) || 24;
            var lines = Math.round(about.offsetHeight / line);
            var keep = Math.max(3, lines - Math.ceil((40 - over) / line));
            if (keep < lines) {
                cut(keep);
            }
        }
    };
    if (page) {
        fitpage();
        window.addEventListener('resize', fitpage);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(fitpage);
        }
    }
})();
