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
 * The panel of the team, on top of pages that work without it (forms and links). It makes them faster and lets the
 * things of the entrance move: the family opens in a sheet beside the inbox, the keys, what the centre already said,
 * the answer sent without leaving, the arrivals at the door kept on the phone without coverage, the label written
 * while a family is written down; the line of the datum of every chart, «Show», the phone beside the editor of a form,
 * the poster of an event as it is written and the colour of the centre in light and dark. Common to every skin: a skin listens to the events «eguahe:*» and adds its scene.
 * Plain script (no AMD), for Chrome 93 and Firefox 98. State is written first; a gesture only follows it.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';

    var doc = document;
    var all = function(selector, root) {
        return Array.prototype.slice.call((root || doc).querySelectorAll(selector));
    };
    var one = function(selector, root) {
        return (root || doc).querySelector(selector);
    };
    var on = function(target, type, fn, opts) {
        target.addEventListener(type, fn, opts || false);
    };
    var emit = function(name, detail) {
        doc.dispatchEvent(new CustomEvent('eguahe:' + name, {detail: detail || {}}));
    };
    var box = one('#eg-textos');
    var T = box ? JSON.parse(box.getAttribute('data-textos') || '{}') : {};
    var say = function(key, a) {
        var s = T[key] || '';
        if (a !== undefined) {
            s = typeof a === 'object' ? s.replace(/\{\$a->(\w+)\}/g, function(x, k) {
                return a[k];
            }) : s.replace('{$a}', a);
        }
        return s;
    };
    var store = {
        get: function(key, fallback) {
            try {
                var v = window.localStorage.getItem(key);
                return v === null ? fallback : JSON.parse(v);
            } catch (e) {
                return fallback;
            }
        },
        set: function(key, value) {
            try {
                window.localStorage.setItem(key, JSON.stringify(value));
            } catch (e) {
                // A private window: the state stays in the page.
            }
        }
    };
    // The server: fetch, or what the mock-up puts in its place.
    var ask = function(url, init) {
        init = init || {};
        init.credentials = 'same-origin';
        init.headers = {'X-Requested-With': 'XMLHttpRequest'};
        return (window.EguaheServidor || window.fetch.bind(window))(url, init).then(function(r) {
            if (!r.ok) {
                throw new Error(r.status);
            }
            return r;
        });
    };
    var wide = window.matchMedia('(min-width: 1180px)');
    var fine = window.matchMedia('(hover: hover) and (pointer: fine)');
    var still = function() {
        return getComputedStyle(doc.documentElement).getPropertyValue('--mov-x').trim() === '0';
    };
    // At most three gestures at once (DESIGN 3.5): a fourth is drawn in its final state.
    var live = 0;
    var gesture = function(ms) {
        if (live >= 3) {
            return false;
        }
        live++;
        window.setTimeout(function() {
            live--;
        }, ms || 600);
        return true;
    };
    window.Eguahe = {gesto: gesture, quieto: still, decir: say, pedir: ask};
    var hhmm = function() {
        var d = new Date();
        return d.getHours() + ':' + ('0' + d.getMinutes()).slice(-2);
    };

    // ---- Menus of the bar and «More»: one open at a time; a click elsewhere or Escape closes them.
    var menus = function() {
        return all('details.menu-barra, details.mas');
    };
    on(doc, 'click', function(e) {
        menus().forEach(function(menu) {
            if (menu.open && !menu.contains(e.target)) {
                menu.open = false;
            }
        });
    });
    menus().forEach(function(menu) {
        on(menu, 'toggle', function() {
            if (menu.open) {
                menus().forEach(function(other) {
                    if (other !== menu) {
                        other.open = false;
                    }
                });
            }
        });
    });

    // ---- The search and the filters of a list: what does not fit hides; empty days and turns hide too.
    var filterState = {text: [], curso: '', etapa: ''};
    var applyFilters = function(input) {
        var selector = input ? input.getAttribute('data-filtrar') : '.fila';
        var shown = 0;
        all(selector).forEach(function(row) {
            var text = row.getAttribute('data-buscar') || '';
            var fits = filterState.text.every(function(word) {
                return text.indexOf(word) !== -1;
            });
            if (filterState.curso) {
                fits = fits && (' ' + row.getAttribute('data-cursos') + ' ').indexOf(' ' + filterState.curso + ' ') !== -1;
            }
            if (filterState.etapa) {
                fits = fits && (' ' + row.getAttribute('data-etapas') + ' ').indexOf(' ' + filterState.etapa + ' ') !== -1;
            }
            row.hidden = !fits;
            shown += fits ? 1 : 0;
        });
        all('.dia-g').forEach(function(day) {
            day.hidden = !one('.fila:not([hidden])', day);
        });
        if (filterState.text.length) {
            all('details.turno-p').forEach(function(turn) {
                if (one('.llegada:not([hidden])', turn)) {
                    turn.open = true;
                }
            });
        }
        all('.sin-resultados').forEach(function(p) {
            p.hidden = shown > 0;
        });
    };
    all('input[data-filtrar]').forEach(function(input) {
        on(input, 'input', function() {
            filterState.text = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            applyFilters(input);
        });
    });
    on(doc, 'click', function(e) {
        var pill = e.target.closest && e.target.closest('a[data-filtro]');
        if (!pill || e.ctrlKey || e.metaKey || e.shiftKey) {
            return;
        }
        e.preventDefault();
        var kind = pill.getAttribute('data-filtro');
        filterState[kind] = pill.getAttribute('data-clave');
        all('a[data-filtro="' + kind + '"]').forEach(function(p) {
            if (p === pill) {
                p.setAttribute('aria-current', 'true');
            } else {
                p.removeAttribute('aria-current');
            }
        });
        applyFilters();
        if (window.history.replaceState) {
            window.history.replaceState(window.history.state, '', pill.href);
        }
    });

    // ---- Small things that need a script to exist: copying a link, «No» before an erase, the epilogue folded.
    all('[data-copiar]').forEach(function(button) {
        button.hidden = false;
        on(button, 'click', function() {
            var done = function() {
                button.classList.add('copiado');
                var label = button.lastChild;
                var before = label.textContent;
                label.textContent = say('panel_js_copied') || before;
                window.setTimeout(function() {
                    label.textContent = before;
                }, 1800);
                window.setTimeout(function() {
                    button.classList.remove('copiado');
                }, 1800);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(button.getAttribute('data-copiar')).then(done);
            }
        });
    });
    on(doc, 'click', function(e) {
        var no = e.target.closest && e.target.closest('[data-cerrar]');
        if (no && no.closest('details')) {
            no.closest('details').open = false;
            no.closest('details').querySelector('summary').focus();
        }
    });
    if (!window.matchMedia('(min-width: 720px)').matches) {
        all('[data-plegar-movil]').forEach(function(d) {
            d.open = false;
        });
    }
    var hint = one('[data-pista-teclas]');
    if (hint) {
        var first = store.get('eguahe-teclas-desde', 0);
        if (!first) {
            store.set('eguahe-teclas-desde', Date.now());
        } else if (Date.now() - first > 7 * 864e5) {
            hint.hidden = true;
        }
    }

    // ---- The file of a household: what the centre already said, the draft, the proposal, the channel, the way.
    var setText = function(root, text) {
        var area = one('textarea[name="body"]', root);
        if (area) {
            area.value = text;
            area.focus();
            area.setSelectionRange(text.length, text.length);
            area.scrollIntoView({block: 'nearest'});
        }
    };
    var openRow = null;
    var answered;
    var asSheet;
    var initFile = function(root) {
        all('[data-canales]', root).forEach(function(form) {
            var button = one('[data-enviar]', form);
            var update = function() {
                var chosen = one('input[name="channel"]:checked', form);
                if (chosen && button) {
                    one('span', button).textContent = chosen.getAttribute('data-boton');
                }
            };
            on(form, 'change', update);
            update();
        });
        all('[data-texto]', root).forEach(function(button) {
            on(button, 'click', function(e) {
                e.preventDefault();
                all('.sugerencia', root).forEach(function(b) {
                    b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
                });
                setText(root, button.getAttribute('data-texto'));
            });
        });
        all('[data-propuesta]', root).forEach(function(link) {
            on(link, 'click', function(e) {
                if (!link.getAttribute('data-propuesta')) {
                    return;
                }
                e.preventDefault();
                setText(root, link.getAttribute('data-propuesta'));
            });
        });
        all('[data-abre-detalles]', root).forEach(function(link) {
            on(link, 'click', function(e) {
                var target = one('#' + link.getAttribute('data-abre-detalles'), root) ||
                    one('#' + link.getAttribute('data-abre-detalles') + '-h', root);
                if (target) {
                    e.preventDefault();
                    target.open = true;
                    target.scrollIntoView({block: 'nearest'});
                    var field = one('input:not([type="hidden"]), select', target);
                    if (field) {
                        field.focus();
                    }
                }
            });
        });
        // The way of each child: arrows move between its steps; nothing changes until one is pressed.
        all('.camino', root).forEach(function(way) {
            on(way, 'keydown', function(e) {
                var steps = all('button', way);
                var i = steps.indexOf(doc.activeElement);
                var rtl = getComputedStyle(way).direction === 'rtl';
                var next = {ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, ArrowDown: 1, ArrowUp: -1}[e.key];
                if (i !== -1 && next) {
                    e.preventDefault();
                    steps[(i + next + steps.length) % steps.length].focus();
                }
            });
        });
        // The answer goes without leaving the page: the server answers with the file as it is now.
        all('[data-redactor]', root).forEach(function(form) {
            var by = null;
            on(form, 'click', function(e) {
                var b = e.target.closest && e.target.closest('button[name="action"]');
                if (b) {
                    by = b;
                }
            });
            on(form, 'submit', function(e) {
                var submitter = e.submitter || by;
                var action = submitter && submitter.name === 'action' ? submitter.value : '';
                if ((action !== 'send' && action !== 'answer') || !window.DOMParser || !window.FormData) {
                    return;
                }
                e.preventDefault();
                var data = new FormData(form);
                data.set('action', action);
                submitter.setAttribute('aria-disabled', 'true');
                var file = form.closest('[data-hoja]');
                var url = file.getAttribute('data-url');
                // Not form.action: the buttons named «action» hide that property.
                ask(new URL(form.getAttribute('action'), window.location.href).href, {method: 'POST', body: data}).then(function(r) {
                    return r.text();
                }).then(function(html) {
                    var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-hoja]');
                    if (!fresh) {
                        throw new Error('no file');
                    }
                    fresh = doc.importNode(fresh, true);
                    var inSheet = !!file.closest('.hoja-lateral');
                    file.parentNode.replaceChild(fresh, file);
                    if (inSheet) {
                        asSheet(fresh);
                    }
                    initFile(fresh);
                    emit('contestada', {ficha: fresh, nota: one('[data-turno]', fresh), url: url});
                    answered(url);
                    var title = one('[data-turno-titulo]', fresh);
                    if (title) {
                        title.setAttribute('tabindex', '-1');
                        title.focus({preventScroll: true});
                    }
                }).catch(function() {
                    submitter.removeAttribute('aria-disabled');
                    var keep = doc.createElement('input');
                    keep.type = 'hidden';
                    keep.name = 'action';
                    keep.value = action;
                    form.appendChild(keep);
                    form.submit();
                });
            });
        });
    };
    initFile(doc);

    // ---- The two sheets: on a wide screen a family opens beside the inbox, which narrows and stays in sight.
    var desk = one('[data-mostrador]');
    var sheet = one('#hoja');
    var cache = {};
    var load = function(url) {
        if (!cache[url]) {
            cache[url] = ask(url).then(function(r) {
                return r.text();
            }).catch(function(err) {
                delete cache[url];
                throw err;
            });
        }
        return cache[url];
    };
    asSheet = function(file) {
        // Inside the sheet the page keeps its one h1: the name of the family becomes a heading of the sheet.
        var h1 = one('h1', file);
        if (h1) {
            var h2 = doc.createElement('h2');
            h2.className = h1.className;
            h2.id = 'titular-hoja';
            h2.tabIndex = -1;
            h2.innerHTML = h1.innerHTML;
            h1.parentNode.replaceChild(h2, h1);
        }
        all('[id]', file).forEach(function(el) {
            if (el.id !== 'titular-hoja' && el.id.slice(-2) !== '-h') {
                all('[for="' + el.id + '"]', file).forEach(function(label) {
                    label.setAttribute('for', el.id + '-h');
                });
                all('[aria-labelledby="' + el.id + '"]', file).forEach(function(x) {
                    x.setAttribute('aria-labelledby', el.id + '-h');
                });
                el.id += '-h';
            }
        });
    };
    var rows = function() {
        return all('.fila:not([hidden])');
    };
    var markOpen = function(row) {
        all('.fila.abierta').forEach(function(r) {
            r.classList.remove('abierta');
        });
        openRow = row;
        if (row) {
            row.classList.add('abierta');
            row.scrollIntoView({block: 'nearest'});
        }
    };
    var inboxUrl = window.location.href;
    var showSheet = function(url, row, push) {
        if (!sheet) {
            return;
        }
        markOpen(row);
        desk.classList.add('con-hoja');
        sheet.hidden = false;
        sheet.setAttribute('aria-busy', 'true');
        if (!sheet.firstChild) {
            sheet.innerHTML = '<p class="cargando" role="status"></p>';
            one('.cargando', sheet).textContent = say('panel_js_loading');
        }
        load(url).then(function(html) {
            var file = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-hoja]');
            if (!file) {
                throw new Error('no file');
            }
            file = doc.importNode(file, true);
            sheet.innerHTML = '';
            sheet.appendChild(file);
            asSheet(file);
            initFile(file);
            sheet.removeAttribute('aria-busy');
            if (!still()) {
                sheet.classList.add('entra');
                window.requestAnimationFrame(function() {
                    window.requestAnimationFrame(function() {
                        sheet.classList.remove('entra');
                    });
                });
            }
            sheet.scrollTop = 0;
            one('#titular-hoja', file).focus({preventScroll: true});
            if (push) {
                window.history.pushState({hoja: url}, '', url);
            }
            emit('hoja-abierta', {ficha: file, fila: row});
        }).catch(function() {
            window.location.href = url;
        });
    };
    var closeSheet = function(push) {
        if (!sheet || sheet.hidden) {
            return false;
        }
        desk.classList.remove('con-hoja');
        sheet.hidden = true;
        sheet.innerHTML = '';
        var row = openRow;
        markOpen(null);
        if (row && row.parentNode) {
            one('.nombres', row).focus();
        }
        if (push && window.history.state && window.history.state.hoja) {
            window.history.pushState({}, '', inboxUrl);
        }
        return true;
    };
    if (desk && sheet) {
        window.history.replaceState({}, '', inboxUrl);
        on(doc, 'click', function(e) {
            var link = e.target.closest && e.target.closest('a[data-abre-hoja]');
            if (link && wide.matches && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) {
                e.preventDefault();
                showSheet(link.href, link.closest('.fila'), true);
            }
            var shut = e.target.closest && e.target.closest('[data-cerrar-hoja]');
            if (shut && shut.closest('.hoja-lateral')) {
                e.preventDefault();
                closeSheet(true);
            }
        });
        // Fetched before the click when the pointer rests 150 ms on a row (never on a phone).
        var rest = null;
        on(doc, 'mouseover', function(e) {
            var row = e.target.closest && e.target.closest('.fila');
            window.clearTimeout(rest);
            if (row && wide.matches && fine.matches) {
                rest = window.setTimeout(function() {
                    load(one('a[data-abre-hoja]', row).href);
                }, 150);
            }
        });
        on(window, 'popstate', function(e) {
            if (e.state && e.state.hoja) {
                var link = all('a[data-abre-hoja]').filter(function(a) {
                    return a.href === e.state.hoja;
                })[0];
                showSheet(e.state.hoja, link ? link.closest('.fila') : null, false);
            } else {
                closeSheet(false);
            }
        });
    }

    // After an answer: the family leaves «Waiting» (the rows below slide into its place) and the sentence counts one
    // less, in text; in «All» it stays, quieter.
    answered = function(url) {
        var row = openRow || all('.fila').filter(function(r) {
            return one('a[data-abre-hoja]', r).href === url;
        })[0];
        if (!row || !row.closest('[data-lista]') || row.classList.contains('contestada')) {
            return;
        }
        var count = one('[data-cuenta-espera]');
        var n = count ? Math.max(0, parseInt(count.textContent, 10) - 1) : 0;
        all('[data-cuenta-espera], [data-cuenta-bandeja]').forEach(function(c) {
            c.textContent = n;
        });
        var h1 = one('h1#titular');
        if (h1) {
            h1.textContent = n === 1 ? say('inbox_waiting_one') : say('inbox_waiting', n);
        }
        var stamp = one('.sello-fecha', row);
        if (stamp) {
            stamp.parentNode.removeChild(stamp);
        }
        row.classList.add('contestada');
        if (!one('.segmentos a[aria-current] [data-cuenta-espera]')) {
            return;
        }
        var list = rows();
        var others = list.filter(function(r) {
            return r !== row;
        });
        // j goes on from where the answered family was.
        if (openRow === row) {
            openRow = list[list.indexOf(row) - 1] || null;
        }
        var before = others.map(function(r) {
            return r.getBoundingClientRect().top;
        });
        row.classList.add('sale');
        window.setTimeout(function() {
            var day = row.closest('.dia-g');
            row.parentNode.removeChild(row);
            if (day && !one('.fila', day)) {
                day.parentNode.removeChild(day);
            }
            if (still() || !Element.prototype.animate) {
                return;
            }
            others.forEach(function(r, i) {
                var dy = before[i] - r.getBoundingClientRect().top;
                if (dy && i < 8 && gesture(220)) {
                    r.animate([{transform: 'translateY(' + dy + 'px)'}, {transform: 'none'}],
                        {duration: 200, easing: 'cubic-bezier(.2,.8,.2,1)'});
                }
            });
        }, 160);
    };

    // ---- Long names on what hangs (panel_hanging): the handwriting gets smaller until it fits on its line, and a
    // capital with an accent (Í, Á, Ñ) too, so it does not climb out of it. The same rule and numbers as the label of the
    // family page (eguahe.js): every name is drawn at 13 with 35 of room and its line at 4.4, whatever carries it. Where
    // the drawing has room for a second line (data-segunda, its baseline) a name that would get small on one, and has a
    // space, goes on two, broken where the two come out most even: «María de / los Ángeles». Never squeezed.
    var SVGNS = 'http://www.w3.org/2000/svg';
    var lines = function(text, parts) {
        while (text.firstChild) {
            text.removeChild(text.firstChild);
        }
        if (parts.length === 1) {
            text.appendChild(doc.createTextNode(parts[0]));
            return;
        }
        parts.forEach(function(part, i) {
            var span = doc.createElementNS(SVGNS, 'tspan');
            span.setAttribute('x', text.getAttribute('x') || 0);
            span.setAttribute('y', i ? text.getAttribute('data-segunda') : 4.4);
            span.textContent = part;
            text.appendChild(span);
        });
    };
    var fitNames = function(scope) {
        all('svg.colgante text.mano', scope).forEach(function(text) {
            // Only the drawing the svg shows: Chrome measures the hidden ones too, Firefox gives them no length.
            var drawing = text.closest ? text.closest('.mochila') : null;
            if (drawing && window.getComputedStyle(drawing).display === 'none') {
                return;
            }
            // Once on two lines the name is kept whole beside them; written again (the form), it is the text.
            var name = one('tspan', text) ? text.getAttribute('data-nombre') || '' : text.textContent;
            lines(text, [name]);
            text.setAttribute('font-size', 13);
            var length = name ? text.getComputedTextLength() : 0;
            if (!length) {
                return;
            }
            var size = function(longest) {
                var k = Math.min(1, 35 / longest);
                var up = 4.4 - text.getBBox().y;
                return up > 10 ? Math.min(k, 10 / up) : k;
            };
            var k = size(length);
            var second = parseFloat(text.getAttribute('data-segunda'));
            var gaps = [];
            for (var at = 1; at < name.length - 1; at++) {
                if (name.charAt(at) === ' ') {
                    gaps.push(at);
                }
            }
            if (second && k < 0.62 && gaps.length) {
                var best = null;
                gaps.forEach(function(at) {
                    var left = text.getSubStringLength(0, at);
                    var right = length - text.getSubStringLength(0, at + 1);
                    if (!best || Math.max(left, right) < best.longest) {
                        best = {at: at, longest: Math.max(left, right)};
                    }
                });
                text.setAttribute('data-nombre', name);
                lines(text, [name.slice(0, best.at), name.slice(best.at + 1)]);
                // The second line may not climb into the first: its size is held by the gap between them.
                var two = Math.min(size(best.longest), (second - 4.4) / 1.1 / 13);
                if (two > k * 1.12) {
                    k = two;
                } else {
                    lines(text, [name]);
                }
            }
            text.setAttribute('font-size', (13 * k).toFixed(2));
        });
    };
    fitNames();
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function() {
            fitNames();
        });
    }

    // ---- What comes from outside does not move the list: the sentence says it, and the list changes on «Show».
    var inbox = one('[data-bandeja]');
    var news = one('[data-novedad]');
    var lastSeen = null;
    var arrivals = 0;
    var tell = function(data) {
        if (!news || !data || !data.adults) {
            return;
        }
        arrivals++;
        news.innerHTML = '';
        var text = doc.createElement('span');
        text.textContent = arrivals === 1 ? say('panel_js_arrived', data.adults) : say('panel_js_arrived_more', arrivals);
        news.appendChild(text);
        var show = doc.createElement('button');
        show.type = 'button';
        show.className = 'enlace';
        show.textContent = say('panel_js_show');
        on(show, 'click', function() {
            ask(data.listurl || window.location.href).then(function(r) {
                return r.text();
            }).then(function(html) {
                var page = new DOMParser().parseFromString(html, 'text/html');
                var list = one('[data-lista]', page);
                var mine = one('[data-lista]');
                if (list && mine) {
                    mine.parentNode.replaceChild(doc.importNode(list, true), mine);
                    var title = one('h1', page);
                    if (title) {
                        one('h1#titular').textContent = title.textContent;
                    }
                    all('[data-cuenta-espera], [data-cuenta-bandeja]').forEach(function(c) {
                        c.textContent = data.waiting;
                    });
                    news.hidden = true;
                    arrivals = 0;
                    var row = one('[data-fila="' + data.id + '"]');
                    emit('lista-recompuesta', {fila: row});
                    if (row) {
                        one('.nombres', row).focus();
                    }
                }
            });
        });
        news.appendChild(show);
        news.hidden = false;
        all('[data-cuenta-bandeja]').forEach(function(c) {
            c.textContent = data.waiting;
        });
        emit('ha-llegado', data);
    };
    window.Eguahe.novedad = tell;
    if (inbox && inbox.getAttribute('data-comprobar')) {
        var every = (parseInt(inbox.getAttribute('data-cada'), 10) || 60) * 1000;
        var check = function() {
            if (doc.visibilityState !== 'visible') {
                return;
            }
            ask(inbox.getAttribute('data-comprobar')).then(function(r) {
                return r.json();
            }).then(function(data) {
                if (data.latest && data.latest.id !== lastSeen) {
                    if (lastSeen !== null) {
                        tell(Object.assign({waiting: data.waiting}, data.latest));
                    }
                    lastSeen = data.latest.id;
                }
            }).catch(function() {
                // Next time.
            });
        };
        check();
        window.setInterval(check, every);
        window.Eguahe.comprobar = check;
    }

    // ---- The door: «Came» sticks the label; without coverage the arrivals wait on the phone until the server takes them.
    var door = one('[data-puerta]');
    if (door) {
        // On a phone the search sticks right under the band, whatever its height.
        var band = one('.banda', door);
        var fit = function() {
            if (band) {
                door.style.setProperty('--alto-banda', band.offsetHeight + 'px');
            }
        };
        fit();
        on(window, 'resize', fit);
        var key = 'eguahe-puerta-' + door.getAttribute('data-accion');
        var queue = store.get(key, []);
        var pending = one('[data-por-guardar]');
        var sound = one('[data-sonido]');
        var soundOn = store.get('eguahe-puerta-sonido', false);
        var audio = null;
        var tap = function() {
            if (navigator.vibrate) {
                navigator.vibrate(10);
            }
            if (!soundOn) {
                return;
            }
            // The two notes of wood of the art director, when the page has them; else a short knock, made here.
            var file = one('audio[data-sonido-puerta]');
            if (file) {
                try {
                    file.currentTime = 0;
                    var playing = file.play();
                    if (playing && playing.catch) {
                        playing.catch(function() {
                            return null;
                        });
                    }
                    return;
                } catch (err) {
                    file = null;
                }
            }
            if (!window.AudioContext) {
                return;
            }
            audio = audio || new window.AudioContext();
            var len = Math.floor(audio.sampleRate * 0.05);
            var buf = audio.createBuffer(1, len, audio.sampleRate);
            var ch = buf.getChannelData(0);
            for (var i = 0; i < len; i++) {
                ch[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 6);
            }
            var src = audio.createBufferSource();
            var band = audio.createBiquadFilter();
            var gain = audio.createGain();
            band.type = 'bandpass';
            band.frequency.value = 900;
            band.Q.value = 3;
            gain.gain.value = 0.25;
            src.buffer = buf;
            src.connect(band).connect(gain).connect(audio.destination);
            src.start();
        };
        if (sound) {
            sound.hidden = false;
            sound.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
            on(sound, 'click', function() {
                soundOn = !soundOn;
                store.set('eguahe-puerta-sonido', soundOn);
                sound.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
                tap();
            });
        }
        var showPending = function() {
            store.set(key, queue);
            if (!pending) {
                return;
            }
            pending.hidden = queue.length === 0;
            pending.textContent = queue.length === 1 ? say('panel_js_to_save_one') : say('panel_js_to_save', queue.length);
        };
        var counts = function() {
            var b = one('[data-vinieron]');
            if (b) {
                b.textContent = all('.llegada.ya').length;
            }
            var people = 0;
            var peopleCame = 0;
            all('.llegada').forEach(function(row) {
                var n = parseInt(one('.personas', row).textContent, 10) || 0;
                people += n;
                peopleCame += row.classList.contains('ya') ? n : 0;
            });
            var line = one('[data-personas]');
            if (line) {
                line.textContent = line.textContent.replace(/^\d+(\D+)\d+/, peopleCame + '$1' + people);
            }
            var bar = one('[data-barra-personas]');
            if (bar && people) {
                bar.style.width = Math.round(100 * peopleCame / people) + '%';
            }
            all('details.turno-p').forEach(function(turn) {
                var c = one('[data-cuenta-turno]', turn);
                if (c) {
                    c.textContent = say('panel_came_count', {came: all('.llegada.ya', turn).length,
                        households: all('.llegada', turn).length});
                }
            });
        };
        var sending = false;
        var send = function() {
            if (!queue.length || sending) {
                showPending();
                return;
            }
            sending = true;
            var item = queue[0];
            var data = new FormData();
            data.set('sesskey', door.getAttribute('data-sesskey'));
            data.set('signup', item.signup);
            data.set('came', item.came ? '1' : '0');
            data.set('at', String(Math.round(item.at / 1000)));
            data.set('mark', item.mark);
            ask(door.getAttribute('data-accion'), {method: 'POST', body: data}).then(function() {
                sending = false;
                queue.shift();
                showPending();
                send();
            }).catch(function() {
                sending = false;
                showPending();
            });
        };
        var mark = function(row, came, quiet) {
            var button = one('.vino', row);
            row.classList.toggle('ya', came);
            if (button) {
                var text = came ? say('panel_came_at', hhmm()) : say('panel_came_button');
                button.setAttribute('aria-pressed', came ? 'true' : 'false');
                one('span', button).textContent = text;
                button.setAttribute('aria-label', one('.quien a', row).textContent + ': ' + text);
                var hidden = one('input[name="came"]', row);
                if (hidden) {
                    hidden.value = came ? '0' : '1';
                }
            }
            var tags = all('.etq', row).map(function(t) {
                return t.textContent;
            });
            all('[data-pomo-de]').forEach(function(peg) {
                if (tags.indexOf(peg.getAttribute('data-pomo-de')) !== -1) {
                    peg.classList.toggle('con-mochila', came);
                    var bag = one('.colgante', peg);
                    if (came && !quiet && !still() && gesture(1600)) {
                        bag.classList.remove('llega');
                        void bag.getBoundingClientRect();
                        bag.classList.add('llega');
                    }
                }
            });
            counts();
            emit(came ? 'ha-venido' : 'no-ha-venido', {fila: row, quieto: !!quiet});
        };
        all('.llegada form').forEach(function(form) {
            var row = form.closest('.llegada');
            var lock = 0;
            on(form, 'submit', function(e) {
                e.preventDefault();
                if (Date.now() - lock < 400) {
                    return;
                }
                lock = Date.now();
                var came = !row.classList.contains('ya');
                mark(row, came);
                if (came) {
                    tap();
                }
                queue.push({signup: one('input[name="signup"]', form).value, came: came, at: Date.now(),
                    mark: Date.now().toString(36) + Math.random().toString(36).slice(2, 6)});
                showPending();
                send();
            });
        });
        // Marks made here that the server has not taken yet are shown as made.
        queue.forEach(function(item) {
            var row = one('[data-llegada="' + item.signup + '"]');
            if (row) {
                mark(row, item.came, true);
            }
        });
        showPending();
        send();
        on(window, 'online', send);
        // With two people receiving, what the other one marks comes every 30 seconds.
        if (door.getAttribute('data-comprobar')) {
            var poll = function() {
                if (doc.visibilityState !== 'visible') {
                    return;
                }
                send();
                ask(door.getAttribute('data-comprobar')).then(function(r) {
                    return r.json();
                }).then(function(data) {
                    Object.keys(data.came || {}).forEach(function(id) {
                        var row = one('[data-llegada="' + id + '"]');
                        var waits = queue.some(function(item) {
                            return item.signup === id;
                        });
                        if (row && !waits && !row.classList.contains('ya')) {
                            mark(row, true, true);
                            one('.vino span', row).textContent = say('panel_came_at', data.came[id]);
                        }
                    });
                }).catch(function() {
                    // Next time.
                });
            };
            window.setInterval(poll, (parseInt(door.getAttribute('data-cada'), 10) || 30) * 1000);
            window.Eguahe.comprobar = poll;
        }
    }

    // ---- Writing down a family: the label writes itself beside the name; the course shows its rail; a known mobile.
    var form = one('[data-apuntar]');
    if (form) {
        var label = function(i) {
            var tag = one('[data-etiqueta-hijo="' + i + '"]', form);
            var name = one('[data-nombre-hijo="' + i + '"]', form);
            var drawing = one('[data-etiqueta-viva="' + i + '"]', form);
            if (drawing) {
                // Every object of the partial carries the name; the one shown is fitted again.
                all('text.mano', drawing).forEach(function(text) {
                    text.textContent = (tag && tag.value.trim()) || (name && name.value.trim().split(/\s+/)[0]) || '';
                });
                fitNames(drawing);
            }
        };
        all('[data-etiqueta-hijo], [data-nombre-hijo]', form).forEach(function(input) {
            on(input, 'input', function() {
                label(input.getAttribute('data-etiqueta-hijo') || input.getAttribute('data-nombre-hijo'));
            });
        });
        all('[data-curso-hijo]', form).forEach(function(select) {
            var slot = one('[data-plazas-mini="' + select.getAttribute('data-curso-hijo') + '"]', form);
            var update = function() {
                var source = one('[data-plazas-de="' + select.value + '"]', form);
                slot.innerHTML = '';
                if (source) {
                    slot.appendChild(source.cloneNode(true));
                }
            };
            on(select, 'change', update);
            update();
        });
        var dup = form.getAttribute('data-duplicados');
        var wait = null;
        all('[data-movil]', form).forEach(function(input) {
            var out = one('[data-duplicado]', input.closest('.bloque-adulto, .bloque-form'));
            on(input, 'input', function() {
                window.clearTimeout(wait);
                var digits = input.value.replace(/\D/g, '');
                if (!dup || !out || digits.length < 9) {
                    if (out) {
                        out.hidden = true;
                    }
                    return;
                }
                wait = window.setTimeout(function() {
                    ask(dup + (dup.indexOf('?') === -1 ? '?' : '&') + 'phone=' + encodeURIComponent(digits)).then(function(r) {
                        return r.json();
                    }).then(function(data) {
                        var h = (data.households || [])[0];
                        out.hidden = !h;
                        if (h) {
                            out.innerHTML = '';
                            out.appendChild(doc.createTextNode(h.sentence + ' '));
                            var a = doc.createElement('a');
                            a.href = h.url;
                            a.textContent = h.open;
                            out.appendChild(a);
                        }
                    }).catch(function() {
                        out.hidden = true;
                    });
                }, 400);
            });
        });
    }

    // ---- The stops of the way of the campaign: the line of the datum says each one.
    all('[data-camino]').forEach(function(block) {
        var line = one('[data-linea-dato]', block);
        var stops = all('[data-parada]', block);
        var pick = function(stop) {
            stops.forEach(function(s) {
                s.setAttribute('aria-pressed', s === stop ? 'true' : 'false');
            });
            line.textContent = stop ? stop.getAttribute('data-linea') : line.getAttribute('data-reposo');
            emit('parada', {clave: stop ? stop.getAttribute('data-parada') : '', bloque: block});
        };
        line.setAttribute('aria-live', 'polite');
        stops.forEach(function(stop) {
            stop.tabIndex = 0;
            stop.setAttribute('role', 'button');
            stop.setAttribute('aria-pressed', 'false');
            on(stop, 'mouseenter', function() {
                pick(stop);
            });
            on(stop, 'focus', function() {
                pick(stop);
            });
            on(stop, 'click', function() {
                pick(stop);
            });
            on(stop, 'keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    pick(stop);
                }
            });
        });
        on(one('.paradas', block), 'mouseleave', function() {
            if (!block.contains(doc.activeElement)) {
                pick(null);
            }
        });
        window.Eguahe.parada = function(k) {
            pick(stops.filter(function(s) {
                return s.getAttribute('data-parada') === k;
            })[0] || null);
        };
    });

    // ---- The numbers (round 3): every chart has its line of the datum and lights what is pointed at, by mouse, finger or
    // keyboard, while the rest steps back; a channel lights its part inside each bar of «How far they got»; the weeks are
    // read with a pencil rule that follows the pointer, the finger or the arrows, with a dot on each line; the bars grow
    // once when they come into sight, the line of the weeks is drawn, the ranges open from their likeliest value and the
    // ledger is stamped; a filter moves what is the same thing from its old value to the new one; «Show» puts a sheet
    // per chart on the screen. All of it is already drawn by the server: this only lights and moves it.
    var numbers = one('[data-cifras]');
    var chosenChannel = '';
    var EASE = 'cubic-bezier(.2,.8,.2,1)';
    var NS = 'http://www.w3.org/2000/svg';
    var clips = 0;
    var mirrored = function() {
        return getComputedStyle(doc.documentElement).direction === 'rtl';
    };
    // The weeks: a pencil rule and a dot on each line at the week pointed at; its column under the line lit.
    var initWeeks = function(chart, tell) {
        var plot = one('[data-semanas]', chart);
        var cols = plot ? all('.col-semana', plot) : [];
        if (!cols.length) {
            return;
        }
        var parse = function(selector) {
            var path = one(selector, plot);
            var out = [];
            (path ? path.getAttribute('d') || '' : '').replace(/(-?[0-9.]+)[ ,]+(-?[0-9.]+)/g, function(x, a, b) {
                out.push([parseFloat(a), parseFloat(b)]);
            });
            return out;
        };
        var now = parse('.linea-ahora');
        var before = parse('.linea-antes');
        var at = function(points, x) {
            for (var k = 0; k < points.length; k++) {
                if (Math.abs(points[k][0] - x) < 0.05) {
                    return points[k];
                }
            }
            return null;
        };
        var dot = function(cls) {
            var d = doc.createElement('span');
            d.className = 'punto-semana' + cls;
            d.setAttribute('aria-hidden', 'true');
            plot.appendChild(d);
            return d;
        };
        var dotBefore = before.length ? dot(' antes') : null;
        var dotNow = dot('');
        var place = function(d, point) {
            if (!d) {
                return;
            }
            d.style.visibility = point ? '' : 'hidden';
            if (point) {
                var x = point[0] / 100 * plot.clientWidth * (mirrored() ? -1 : 1);
                d.style.transform = 'translate(' + x.toFixed(1) + 'px,' + (point[1] / 100 * plot.clientHeight).toFixed(1) + 'px)';
            }
        };
        // When both lines end at the same place, the label of the one before gives way: the key already names it.
        var mine = one('.rotulo-linea.ahora', plot);
        var theirs = one('.rotulo-linea.antes', plot);
        var giveWay = function() {
            theirs.hidden = false;
            var a = mine.getBoundingClientRect();
            var b = theirs.getBoundingClientRect();
            theirs.hidden = a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
        };
        if (mine && theirs) {
            giveWay();
            if (doc.fonts && doc.fonts.ready) {
                doc.fonts.ready.then(giveWay);
            }
        }
        var pulseBox = one('.semanas-pulso', chart);
        var pulses = all('.pulso', chart);
        var current = cols.filter(function(c) {
            return !c.classList.contains('futura');
        }).pop() || cols[0];
        cols.forEach(function(c) {
            c.tabIndex = c === current ? 0 : -1;
            c.setAttribute('aria-label', c.getAttribute('data-dato'));
        });
        var shown = null;
        chart.eguaheSemana = function(mark) {
            var lit = !!mark && mark.classList.contains('col-semana');
            plot.classList.toggle('con-semana', lit);
            if (pulseBox) {
                pulseBox.classList.toggle('con-semana', lit);
            }
            var cx = lit ? mark.getAttribute('data-cx') : null;
            pulses.forEach(function(p) {
                p.classList.toggle('senalado', p.getAttribute('data-cx') === cx);
            });
            if (lit) {
                place(dotNow, at(now, parseFloat(cx)));
                place(dotBefore, at(before, parseFloat(cx)));
            }
        };
        var point = function(mark) {
            if (mark && mark !== shown) {
                shown = mark;
                tell(mark);
            }
        };
        var fromX = function(clientX) {
            var r = plot.getBoundingClientRect();
            var k = (clientX - r.left) / r.width;
            k = Math.max(0, Math.min(0.9999, mirrored() ? 1 - k : k));
            return cols[Math.floor(k * cols.length)];
        };
        on(plot, 'pointermove', function(e) {
            point(fromX(e.clientX));
        });
        on(plot, 'pointerdown', function(e) {
            point(fromX(e.clientX));
        });
        on(plot, 'pointerleave', function() {
            shown = null;
            if (!plot.contains(doc.activeElement)) {
                tell(null);
            }
        });
        cols.forEach(function(c) {
            on(c, 'focus', function() {
                cols.forEach(function(o) {
                    o.tabIndex = o === c ? 0 : -1;
                });
                shown = c;
                tell(c);
            });
            on(c, 'keydown', function(e) {
                var i = cols.indexOf(c);
                var back = mirrored() ? 'ArrowRight' : 'ArrowLeft';
                var next = mirrored() ? 'ArrowLeft' : 'ArrowRight';
                var moves = {Home: -i, End: cols.length};
                moves[next] = 1;
                moves[back] = -1;
                if (moves[e.key] === undefined) {
                    return;
                }
                e.preventDefault();
                cols[Math.max(0, Math.min(cols.length - 1, i + moves[e.key]))].focus();
            });
        });
    };
    var initCharts = function(root) {
        all('[data-grafico]', root).forEach(function(chart) {
            var line = one('[data-linea-dato]', chart);
            if (!line) {
                return;
            }
            line.setAttribute('aria-live', 'polite');
            var rest = function() {
                return chart.getAttribute('data-reposo-canal') || line.getAttribute('data-reposo');
            };
            var tell = function(mark) {
                all('.senalado', chart).forEach(function(m) {
                    m.classList.remove('senalado');
                });
                chart.classList.toggle('con-senal', !!mark && !mark.classList.contains('col-semana'));
                if (chart.eguaheSemana) {
                    chart.eguaheSemana(mark);
                }
                if (!mark) {
                    line.textContent = rest();
                    line.classList.remove('dato-vivo');
                    return;
                }
                mark.classList.add('senalado');
                var part = chosenChannel && one('[data-parte="' + chosenChannel + '"]', mark);
                line.textContent = (part && part.getAttribute('data-dato-parte')) || mark.getAttribute('data-dato');
                line.classList.add('dato-vivo');
            };
            var lists = [];
            all('[data-dato]', chart).forEach(function(mark) {
                if (mark.classList.contains('col-semana')) {
                    return;
                }
                if (!mark.hasAttribute('tabindex')) {
                    mark.tabIndex = 0;
                }
                if (lists.indexOf(mark.parentNode) < 0) {
                    lists.push(mark.parentNode);
                }
                on(mark, 'mouseenter', function() {
                    tell(mark);
                });
                on(mark, 'focus', function() {
                    tell(mark);
                });
                on(mark, 'click', function() {
                    tell(mark);
                });
            });
            // Leaving the marks (not only the sheet) brings the resting sentence back, unless one keeps the focus.
            lists.forEach(function(list) {
                on(list, 'mouseleave', function() {
                    if (!list.contains(doc.activeElement)) {
                        tell(null);
                    }
                });
            });
            on(chart, 'focusout', function(e) {
                if (!chart.contains(e.relatedTarget)) {
                    tell(null);
                }
            });
            chart.eguaheReposo = function() {
                tell(null);
            };
            if (chart.classList.contains('grafico-semanas')) {
                initWeeks(chart, tell);
            }
            // In the ledger, those who left open why they left.
            var why = one('.motivos', chart);
            all('.libro-fila.sale', chart).forEach(function(row) {
                on(row, 'click', function() {
                    if (why && !why.open) {
                        why.open = true;
                    }
                });
            });
        });
        // A channel, touched, lights its part inside each bar of «How far they got», says how many of each stop came
        // by it, and leaves a way to turn it off there.
        var reach = one('.grafico-alcance', root);
        var light = function(key) {
            chosenChannel = key;
            all('[data-canal-elige]', root).forEach(function(r) {
                r.setAttribute('aria-pressed', r.getAttribute('data-canal-elige') === key ? 'true' : 'false');
            });
            if (!reach) {
                return;
            }
            reach.classList.toggle('con-canal', !!key);
            all('.parte', reach).forEach(function(p) {
                p.classList.toggle('encendida', p.getAttribute('data-parte') === key);
            });
            all('.barra-fila', reach).forEach(function(row) {
                var figures = one('.barra-cifras', row);
                var n = one('.barra-parte-n', figures);
                if (!n) {
                    n = doc.createElement('span');
                    n.className = 'barra-parte-n';
                    figures.insertBefore(n, figures.firstChild);
                }
                var part = key && one('[data-parte="' + key + '"]', row);
                n.textContent = part ? part.getAttribute('data-parte-n') : '0';
            });
            var chip = one('[data-canal-encendido]', reach);
            if (!chip && key) {
                chip = doc.createElement('p');
                chip.className = 'canal-encendido';
                chip.setAttribute('data-canal-encendido', '');
                chip.appendChild(doc.createElement('span'));
                var off = doc.createElement('button');
                off.type = 'button';
                off.textContent = say('panel_js_channel_off');
                on(off, 'click', function() {
                    light('');
                    var first = one('.barra-fila', reach);
                    if (first) {
                        first.focus();
                    }
                });
                chip.appendChild(off);
                var after = one('[data-linea-dato]', reach);
                after.parentNode.insertBefore(chip, after.nextSibling);
            }
            if (chip) {
                chip.hidden = !key;
                var row = key && one('[data-canal-elige="' + key + '"] .canal-nombre', root);
                chip.firstChild.textContent = row ? say('panel_js_channel_lit', row.textContent) : '';
            }
            var last = key && all('[data-parte="' + key + '"]', reach).pop();
            if (last) {
                reach.setAttribute('data-reposo-canal', last.getAttribute('data-dato-parte'));
            } else {
                reach.removeAttribute('data-reposo-canal');
            }
            if (reach.eguaheReposo) {
                reach.eguaheReposo();
            }
        };
        all('[data-canal-elige]', root).forEach(function(row) {
            row.setAttribute('role', 'button');
            row.setAttribute('aria-pressed', 'false');
            var pick = function() {
                var key = row.getAttribute('data-canal-elige');
                light(chosenChannel === key ? '' : key);
            };
            on(row, 'click', pick);
            on(row, 'keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    pick();
                }
            });
        });
    };
    // What grows: the bars and columns from their baseline, the columns of each week from below.
    var growing = function(root) {
        return all('.barra-pista > i:not(.fantasma):not(.fantasma-tope):not(.parte), .col-pista i, .pulso', root);
    };
    var tall = function(bar) {
        return bar.classList.contains('pulso') || bar.parentNode.classList.contains('col-pista');
    };
    // One gesture per chart, the first time it is seen: the bars grow one after another, the line of the weeks is
    // drawn under a clip that opens, what may come opens from its likeliest value and the ledger is stamped.
    var play = function(chart) {
        var bars = growing(chart).filter(function(b) {
            return !b.classList.contains('pulso');
        });
        var pulses = all('.pulso', chart);
        var line = one('.linea-ahora', chart);
        var ranges = all('.perchero .r-rango', chart);
        var stamp = one('.sello-cuadra', chart);
        var step = parseFloat(getComputedStyle(doc.documentElement).getPropertyValue('--paso-barras')) || 70;
        if (!gesture(Math.max(900, 600 + Math.min(bars.length, 12) * step))) {
            return;
        }
        bars.forEach(function(bar, i) {
            bar.animate([{transform: tall(bar) ? 'scaleY(0)' : 'scaleX(0)'}, {transform: 'none'}],
                {duration: 600, delay: Math.min(i, 12) * step, easing: EASE, fill: 'backwards'});
        });
        pulses.forEach(function(p, i) {
            p.animate([{transform: 'scaleY(0)'}, {transform: 'none'}],
                {duration: 300, delay: 120 + Math.round(i * 700 / pulses.length), easing: EASE, fill: 'backwards'});
        });
        if (line && line.ownerSVGElement) {
            var svg = line.ownerSVGElement;
            var clip = doc.createElementNS(NS, 'clipPath');
            var rect = doc.createElementNS(NS, 'rect');
            clip.id = 'eg-revela-' + (++clips);
            [['x', -2], ['y', -20], ['width', 104], ['height', 140]].forEach(function(a) {
                rect.setAttribute(a[0], a[1]);
            });
            clip.appendChild(rect);
            svg.insertBefore(clip, svg.firstChild);
            var drawn = all('.linea-ahora, .area-ahora', svg);
            drawn.forEach(function(p) {
                p.setAttribute('clip-path', 'url(#' + clip.id + ')');
            });
            rect.style.transformOrigin = '0 0';
            var reveal = rect.animate([{transform: 'scaleX(0)'}, {transform: 'scaleX(1)'}], {duration: 900, easing: 'ease-in-out'});
            var done = function() {
                drawn.forEach(function(p) {
                    p.removeAttribute('clip-path');
                });
                if (clip.parentNode) {
                    clip.parentNode.removeChild(clip);
                }
            };
            reveal.onfinish = done;
            reveal.oncancel = done;
            all('.punto-ahora, .rotulo-linea', chart).forEach(function(el) {
                el.animate([{opacity: 0}, {opacity: 1}], {duration: 300, delay: 800, fill: 'backwards'});
            });
        }
        ranges.forEach(function(r) {
            var y = parseFloat(r.getAttribute('y'));
            var likely = all('.r-probable', r.ownerSVGElement).filter(function(p) {
                return Math.abs(parseFloat(p.getAttribute('y')) + 3 - y) < 0.5;
            })[0];
            var x = likely ? parseFloat(likely.getAttribute('x')) + 1.5 : parseFloat(r.getAttribute('x'));
            r.style.transformOrigin = x + 'px ' + y + 'px';
            r.animate([{transform: 'scaleX(0)'}, {transform: 'none'}],
                {duration: 600, delay: 250, easing: EASE, fill: 'backwards'});
        });
        all('.perchero .r-probable, .perchero .r-rango-t', chart).forEach(function(el) {
            el.animate([{opacity: 0}, {opacity: 1}], {duration: 300, delay: 100, fill: 'backwards'});
        });
        if (stamp) {
            var cs = getComputedStyle(stamp);
            var rest = cs.transform === 'none' ? 'none' : cs.transform;
            stamp.animate([
                {transform: rest + ' scale(1.7)', opacity: 0},
                {transform: rest + ' scale(0.94)', opacity: cs.opacity, offset: 0.7},
                {transform: rest, opacity: cs.opacity}
            ], {duration: 380, delay: 700, easing: 'cubic-bezier(.3,.7,.3,1)', fill: 'backwards'});
        }
    };
    // A chart waits at zero until it comes into sight (or, while showing, until its sheet is on the screen).
    var arm = function(chart, threshold) {
        if (still() || !Element.prototype.animate || !('IntersectionObserver' in window) ||
            (!growing(chart).length && !one('.linea-ahora, .perchero .r-rango, .sello-cuadra', chart))) {
            return;
        }
        if (chart.eguaheVista) {
            chart.eguaheVista.disconnect();
        }
        chart.classList.add('por-crecer');
        var seen = new IntersectionObserver(function(entries) {
            if (!entries[0].isIntersecting) {
                return;
            }
            seen.disconnect();
            chart.eguaheVista = null;
            chart.classList.remove('por-crecer');
            play(chart);
        }, {threshold: threshold});
        seen.observe(chart);
        chart.eguaheVista = seen;
    };
    var disarm = function(chart) {
        if (chart.eguaheVista) {
            chart.eguaheVista.disconnect();
            chart.eguaheVista = null;
        }
        chart.classList.remove('por-crecer');
    };
    // The key of the rail says only what is drawn: without what may come, neither its band nor the last place.
    var keyOfRail = function(root) {
        var chart = one('.grafico-plazas', root);
        var keys = chart ? all('.leyenda-riel .clave', chart) : [];
        if (keys.length === 4 && !one('.perchero.con-rango', chart)) {
            keys[2].hidden = true;
            keys[3].hidden = true;
        }
    };
    if (numbers) {
        keyOfRail(numbers);
        initCharts(numbers);
        all('.grafico', numbers).forEach(function(chart) {
            arm(chart, 0.2);
        });
        // A filter fetches the same page with other numbers: what is the same thing keeps its place and goes from its old
        // value and position to the new ones; the rest is replaced. The previous picture stays, dimmed, while it comes.
        var cuerpoCifras = function() {
            return one('[data-cifras-cuerpo]');
        };
        var size = function(bar) {
            var r = bar.getBoundingClientRect();
            return tall(bar) ? r.height : r.width;
        };
        on(doc, 'click', function(e) {
            var link = e.target.closest && e.target.closest('a[data-filtro-cifras]');
            if (!link || e.ctrlKey || e.metaKey || e.shiftKey || !window.DOMParser) {
                return;
            }
            e.preventDefault();
            var url = link.href;
            var old = {};
            var tops = {};
            growing(cuerpoCifras()).concat(all('.barra-matricula', cuerpoCifras())).forEach(function(bar) {
                if (bar.getAnimations) {
                    bar.getAnimations().forEach(function(a) {
                        a.finish();
                    });
                }
                if (bar.id) {
                    old[bar.id] = size(bar);
                }
            });
            all('.canal-fila[id], .barra-fila[id], .columna[id]', cuerpoCifras()).forEach(function(row) {
                tops[row.id] = row.getBoundingClientRect().top;
            });
            all('.grafico', cuerpoCifras()).forEach(disarm);
            cuerpoCifras().classList.add('recargando');
            ask(url).then(function(r) {
                return r.text();
            }).then(function(html) {
                var page = new DOMParser().parseFromString(html, 'text/html');
                ['.pagina-cifras > .cabeza', '.cifras-barra', '[data-cifras-cuerpo]'].forEach(function(sel) {
                    var fresh = one(sel, page);
                    var mine = one(sel);
                    if (fresh && mine) {
                        mine.parentNode.replaceChild(doc.importNode(fresh, true), mine);
                    }
                });
                chosenChannel = '';
                keyOfRail(cuerpoCifras());
                initCharts(cuerpoCifras());
                window.history.replaceState(window.history.state, '', url);
                if (still() || !Element.prototype.animate) {
                    return;
                }
                // What is still below the screen grows when it comes into sight, as the first time.
                all('.grafico', cuerpoCifras()).forEach(function(chart) {
                    if (chart.getBoundingClientRect().top > window.innerHeight) {
                        arm(chart, 0.2);
                    }
                });
                all('.canal-fila[id], .barra-fila[id], .columna[id]', cuerpoCifras()).forEach(function(row) {
                    var dy = tops[row.id] === undefined ? 0 : tops[row.id] - row.getBoundingClientRect().top;
                    if (Math.abs(dy) > 1) {
                        row.animate([{transform: 'translateY(' + dy.toFixed(1) + 'px)'}, {transform: 'none'}],
                            {duration: 250, easing: EASE});
                    }
                });
                growing(cuerpoCifras()).concat(all('.barra-matricula', cuerpoCifras())).forEach(function(bar) {
                    if (!bar.id || old[bar.id] === undefined) {
                        return;
                    }
                    var now = size(bar);
                    if (now > 0 && Math.abs(now - old[bar.id]) > 0.5) {
                        var k = Math.max(0, old[bar.id] / now);
                        bar.animate([{transform: (tall(bar) ? 'scaleY(' : 'scaleX(') + k.toFixed(4) + ')'}, {transform: 'none'}],
                            {duration: 300, easing: EASE});
                    }
                });
            }).catch(function() {
                window.location.href = url;
            });
        });
        // «Show»: a sheet per chart, full screen; arrows pass (the weeks keep left and right for themselves when one is
        // focused), Escape leaves. Each chart grows again when its sheet comes on the screen.
        var mandosLamina = one('[data-lamina-mandos]');
        var ensenando = false;
        var laminas = function() {
            return all('.lamina', cuerpoCifras());
        };
        var laminaActual = function() {
            var list = laminas();
            var best = 0;
            list.forEach(function(s, i) {
                if (Math.abs(s.getBoundingClientRect().top) < Math.abs(list[best].getBoundingClientRect().top)) {
                    best = i;
                }
            });
            return best;
        };
        var contarLaminas = function() {
            var c = one('[data-lamina-cuenta]');
            if (c) {
                c.textContent = say('panel_js_sheet_count', {n: laminaActual() + 1, of: laminas().length});
            }
        };
        var irLamina = function(step) {
            var list = laminas();
            var next = list[Math.max(0, Math.min(list.length - 1, laminaActual() + step))];
            next.scrollIntoView({behavior: still() ? 'auto' : 'smooth', block: 'start'});
            window.setTimeout(contarLaminas, still() ? 0 : 450);
        };
        var ensenar = function(yes) {
            ensenando = yes;
            doc.body.classList.toggle('modo-lamina', yes);
            doc.documentElement.classList.toggle('modo-lamina', yes);
            if (mandosLamina) {
                mandosLamina.hidden = !yes;
            }
            try {
                if (yes && doc.documentElement.requestFullscreen && !doc.fullscreenElement) {
                    doc.documentElement.requestFullscreen().catch(function() {
                        // A window is fine too.
                    });
                } else if (!yes && doc.fullscreenElement) {
                    doc.exitFullscreen();
                }
            } catch (err) {
                // Without full screen, the window.
            }
            all('.grafico', cuerpoCifras()).forEach(function(chart) {
                if (yes) {
                    arm(chart, 0.5);
                } else {
                    disarm(chart);
                }
            });
            if (yes) {
                laminas()[0].scrollIntoView({block: 'start'});
                contarLaminas();
                var h = one('h2', laminas()[0]);
                h.tabIndex = -1;
                h.focus({preventScroll: true});
            } else {
                var link = one('[data-ensenar]');
                if (link) {
                    link.focus();
                }
            }
        };
        on(doc, 'click', function(e) {
            if (e.target.closest && e.target.closest('[data-ensenar]')) {
                e.preventDefault();
                ensenar(true);
            } else if (e.target.closest && e.target.closest('[data-salir-lamina]')) {
                ensenar(false);
            } else if (e.target.closest && e.target.closest('[data-lamina]')) {
                irLamina(parseInt(e.target.closest('[data-lamina]').getAttribute('data-lamina'), 10));
            }
        });
        on(doc, 'keydown', function(e) {
            if (!ensenando) {
                return;
            }
            var step = {ArrowRight: 1, ArrowDown: 1, PageDown: 1, ' ': 1, ArrowLeft: -1, ArrowUp: -1, PageUp: -1}[e.key];
            var weeks = e.target.classList && e.target.classList.contains('col-semana');
            if (step && !(weeks && (e.key === 'ArrowRight' || e.key === 'ArrowLeft'))) {
                e.preventDefault();
                irLamina(step);
            } else if (e.key === 'Escape') {
                ensenar(false);
            }
        }, true);
        var scrolled = null;
        on(window, 'scroll', function() {
            if (ensenando) {
                window.clearTimeout(scrolled);
                scrolled = window.setTimeout(contarLaminas, 120);
            }
        }, {passive: true});
        on(doc, 'fullscreenchange', function() {
            if (ensenando && !doc.fullscreenElement && doc.body.classList.contains('modo-lamina')) {
                contarLaminas();
            }
        });
        if (numbers.classList.contains('en-lamina') || /[?&]ensenar=1/.test(window.location.search)) {
            ensenar(true);
        }
    }

    // ---- A date in three fields, in the order of the centre: its copy without a name (data-fecha-espejo) keeps the whole
    // date for those that read it, as the preview of an act, and tells them it changed.
    on(doc, 'input', function(e) {
        if (!e.target.hasAttribute || !e.target.hasAttribute('data-fecha-parte')) {
            return;
        }
        var group = e.target.closest('.fecha-tres');
        var mirror = group ? one('[data-fecha-espejo]', group) : null;
        if (!mirror) {
            return;
        }
        var part = function(key) {
            var input = one('[data-fecha-parte="' + key + '"]', group);
            return input ? input.value.trim() : '';
        };
        var d = part('d');
        var m = part('m');
        var y = part('y');
        mirror.value = /^\d{1,2}$/.test(d) && /^\d{1,2}$/.test(m) && /^\d{4}$/.test(y) ?
            y + '-' + ('0' + m).slice(-2) + '-' + ('0' + d).slice(-2) : '';
        mirror.dispatchEvent(new Event('input', {bubbles: true}));
    });

    // ---- Creating or changing an event: the poster beside it is rewritten as it is written; a turn otroTurno without
    // posting; the tira of the day follows the turns.
    var eventForm = one('[data-acto-form]');
    if (eventForm) {
        var avisoCambio = one('[data-aviso-cambio]', eventForm);
        if (avisoCambio) {
            avisoCambio.focus();
        }
        // An hour as the team writes it, by the clock of the centre: 9, 930, 9:30, 9.30 or 21h.
        var aMinutos = function(v) {
            var t = (v || '').trim().toLowerCase();
            var m = /^(\d{1,2})(?:\s*[:.h]\s*(\d{2}))?\s*h?$/.exec(t) || /^(\d{1,2})(\d{2})$/.exec(t);
            if (!m) {
                return null;
            }
            var h = parseInt(m[1], 10);
            var min = m[2] ? parseInt(m[2], 10) : 0;
            return h <= 23 && min <= 59 ? h * 60 + min : null;
        };
        var aHora = function(m) {
            return Math.floor(m / 60) + ':' + ('0' + (m % 60)).slice(-2);
        };
        var setMuestra = function(key, text) {
            var el = one('[data-muestra="' + key + '"]', eventForm);
            if (el) {
                el.textContent = text;
            }
        };
        var idiomaPagina = doc.documentElement.lang || 'es';
        var cuandoActo = function() {
            var day = one('[data-vivo="dia"]', eventForm).value;
            var start = one('[data-vivo="empieza"]', eventForm).value;
            var end = one('[data-vivo="termina"]', eventForm).value;
            var text = '';
            if (day && window.Intl) {
                var d = new Date(day + 'T12:00:00');
                text = new Intl.DateTimeFormat(idiomaPagina, {weekday: 'long', day: 'numeric', month: 'long'}).format(d);
                text = text.charAt(0).toUpperCase() + text.slice(1);
            }
            if (start) {
                text += (text ? ' · ' : '') + (end ? say('panel_js_from_to', {from: aHora(aMinutos(start)), to: aHora(aMinutos(end))}) :
                    aHora(aMinutos(start)));
            }
            return text;
        };
        var tira = function() {
            var box = one('[data-tira]', eventForm);
            var from = aMinutos(one('[data-vivo="empieza"]', eventForm).value);
            var to = aMinutos(one('[data-vivo="termina"]', eventForm).value);
            if (!box || from === null || to === null || to <= from) {
                return;
            }
            var day = one('.tira-dia', box);
            all('[data-turno-fila]', eventForm).forEach(function(row, i) {
                var start = aMinutos(one('[data-hora]', row).value);
                var len = parseInt(one('input[name^="slotminutes"]', row).value, 10) || 0;
                var block = one('[data-tira-turno="' + i + '"]', box);
                if (!block) {
                    block = doc.createElement('span');
                    block.className = 'tira-turno';
                    block.setAttribute('data-tira-turno', i);
                    block.innerHTML = '<b></b>';
                    one('b', block).textContent = i + 1;
                    day.appendChild(block);
                }
                block.hidden = start === null;
                if (start !== null) {
                    block.style.insetInlineStart = (100 * (start - from) / (to - from)) + '%';
                    block.style.width = (100 * len / (to - from)) + '%';
                }
            });
        };
        var anfitriones = function() {
            var names = all('[data-vivo-anfitrion]:checked', eventForm).map(function(c) {
                return c.getAttribute('data-vivo-anfitrion');
            });
            var list = names.length > 1 ? say('panel_js_and', {list: names.slice(0, -1).join(', '), last: names[names.length - 1]}) : names[0] || '';
            setMuestra('reciben', !names.length ? '' : say(names.length === 1 ? 'panel_js_hosts_one' : 'panel_js_hosts_many', list));
            var pile = one('.reciben-muestra .pila', eventForm);
            if (pile) {
                pile.innerHTML = '';
                all('[data-vivo-anfitrion]:checked', eventForm).forEach(function(c) {
                    var face = one('.anfitrion-cara .cara', c.closest('.anfitrion'));
                    if (face) {
                        pile.appendChild(face.cloneNode(true));
                    }
                });
            }
        };
        var tipoActo = function() {
            var chosen = one('input[name="kind"]:checked', eventForm);
            var visitOnly = one('.solo-visita', eventForm);
            if (chosen) {
                setMuestra('tipo', chosen.getAttribute('data-vivo-tipo'));
            }
            if (visitOnly) {
                visitOnly.hidden = !chosen || chosen.value !== 'visit';
            }
        };
        on(eventForm, 'input', function(e) {
            var key = e.target.getAttribute('data-vivo');
            if (key === 'nombre') {
                setMuestra('nombre', e.target.value);
            } else if (key === 'donde') {
                setMuestra('donde', e.target.value);
            } else if (key === 'dia' || key === 'empieza' || key === 'termina') {
                setMuestra('cuando', cuandoActo());
                tira();
            } else if (e.target.closest('[data-turno-fila]')) {
                tira();
            }
        });
        on(eventForm, 'change', function(e) {
            if (e.target.name === 'kind') {
                tipoActo();
            } else if (e.target.hasAttribute('data-vivo-anfitrion')) {
                anfitriones();
            }
        });
        tipoActo();
        var otroTurno = one('[data-otro-turno]', eventForm);
        var moldeTurno = one('[data-turno-molde]', eventForm);
        if (otroTurno && moldeTurno && moldeTurno.content) {
            on(otroTurno, 'click', function(e) {
                e.preventDefault();
                var list = one('[data-turnos-lista]', eventForm);
                var n = all('[data-turno-fila]', list).length;
                var none = one('.sin-turnos', list);
                if (none) {
                    none.parentNode.removeChild(none);
                }
                var holder = doc.createElement('div');
                holder.innerHTML = moldeTurno.innerHTML.replace(/__i__/g, n).replace(/__n__/g, n + 1);
                var row = holder.firstElementChild;
                list.appendChild(row);
                one('[data-hora]', row).focus();
                tira();
            });
        }
    }

    // ---- A form: what is written appears in the phone; a condition is set by touching the answer in the phone; «Try
    // the way» walks it; each question saves by itself and says when.
    var phone = one('[data-movil]');
    var phoneScreen = phone && one('[data-movil-marco]', phone);
    var phoneOps = function(q) {
        return all('.movil-op', q);
    };
    var phoneLive = function(on) {
        if (phoneScreen) {
            phoneScreen.setAttribute('aria-hidden', on ? 'false' : 'true');
        }
        all('.movil-op', phone).forEach(function(b) {
            b.tabIndex = on ? 0 : -1;
        });
    };
    var trying = false;
    var pointing = null;
    var wayCount = function() {
        var out = one('[data-camino-cuenta]', phone);
        if (!trying) {
            if (out && out.getAttribute('data-reposo')) {
                out.textContent = out.getAttribute('data-reposo');
            }
            return;
        }
        var secs = 4 * 15 + all('.movil-label.tenue', phone).length * 10;
        var n = 4 + all('.movil-label.tenue', phone).length;
        all('[data-movil-q]', phone).forEach(function(q) {
            var cond = q.getAttribute('data-si');
            var shown = true;
            if (cond && cond !== '0') {
                var from = one('[data-movil-q="' + cond + '"]', phone);
                shown = !!from && phoneOps(from).some(function(b) {
                    return b.getAttribute('aria-pressed') === 'true' && b.getAttribute('data-valor') === q.getAttribute('data-si-valor');
                });
            }
            q.hidden = !shown;
            if (shown) {
                n++;
                secs += one('.movil-campo.largo', q) ? 30 : one('.movil-campo', q) ? 20 : one('.varias', q) ? 10 : 6;
            }
        });
        var time = secs < 45 ? say('panel_js_time_half') : secs < 75 ? say('panel_js_time_one') :
            secs < 105 ? say('panel_js_time_oneandhalf') : say('panel_js_time_n', Math.round(secs / 60));
        out.textContent = say('panel_js_way', {n: n, time: time});
    };
    if (phone) {
        var tryButton = one('[data-probar-camino]', phone);
        var countOut = one('[data-camino-cuenta]', phone);
        if (countOut) {
            countOut.setAttribute('data-reposo', countOut.textContent);
        }
        if (tryButton) {
            tryButton.hidden = false;
            on(tryButton, 'click', function() {
                trying = !trying;
                tryButton.setAttribute('aria-pressed', trying ? 'true' : 'false');
                phone.classList.toggle('probando', trying);
                phoneLive(trying);
                if (!trying) {
                    all('[data-movil-q]', phone).forEach(function(q) {
                        q.hidden = false;
                    });
                    all('.movil-op', phone).forEach(function(b) {
                        b.removeAttribute('aria-pressed');
                    });
                }
                wayCount();
            });
        }
        on(phone, 'click', function(e) {
            var op = e.target.closest && e.target.closest('.movil-op');
            if (!op) {
                return;
            }
            var q = op.closest('[data-movil-q]');
            if (pointing && !q.classList.contains('editando')) {
                pointing(q, op);
                return;
            }
            if (!trying) {
                return;
            }
            var many = !!op.closest('.varias');
            var pressed = op.getAttribute('aria-pressed') === 'true';
            if (!many) {
                phoneOps(q).forEach(function(b) {
                    b.setAttribute('aria-pressed', 'false');
                });
            }
            op.setAttribute('aria-pressed', pressed ? 'false' : 'true');
            wayCount();
        });
    }
    all('[data-vivo-movil]').forEach(function(input) {
        on(input, 'input', function() {
            var target = one('[data-movil-' + input.getAttribute('data-vivo-movil') + ']');
            if (target) {
                target.textContent = input.value;
            }
        });
    });
    all('[data-editor-pregunta]').forEach(function(form) {
        var id = form.getAttribute('data-editor-pregunta');
        var q = phone && one('[data-movil-q="' + id + '"]', phone);
        var saved = one('[data-guardado]');
        var sentence = one('[data-frase-condicion]', form);
        var askSel = one('[data-condicion-pregunta]', form);
        var valueSel = one('[data-condicion-valor]', form);
        var drawOps = function() {
            if (!q) {
                return;
            }
            var type = (one('input[name="type"]:checked', form) || {}).value;
            var box = one('[data-movil-ops]', q) || one('.movil-campo', q);
            var area = one('[data-vivo-opciones]', form);
            var fresh;
            if (type === 'text' || type === 'longtext' || type === 'date' || type === 'number') {
                fresh = doc.createElement('span');
                fresh.className = 'movil-campo' + (type === 'longtext' ? ' largo' : '');
            } else {
                var values = type === 'yesno' ? ['Sí', 'No'] : (area ? area.value.split('\n').map(function(v) {
                    return v.trim();
                }).filter(Boolean) : []);
                fresh = doc.createElement('span');
                fresh.className = 'movil-ops' + (type === 'multichoice' ? ' varias' : '');
                fresh.setAttribute('data-movil-ops', '');
                values.forEach(function(v) {
                    var b = doc.createElement('button');
                    b.type = 'button';
                    b.className = 'movil-op';
                    b.tabIndex = -1;
                    b.setAttribute('data-valor', v);
                    b.textContent = v;
                    fresh.appendChild(b);
                });
            }
            if (box) {
                box.parentNode.replaceChild(fresh, box);
            }
        };
        var condition = function() {
            if (!askSel) {
                return;
            }
            var from = askSel.value;
            var src = phone && one('[data-movil-q="' + from + '"]', phone);
            if (valueSel && document.activeElement !== valueSel) {
                var keep = valueSel.value;
                valueSel.innerHTML = '';
                (src ? phoneOps(src) : []).forEach(function(b) {
                    var o = doc.createElement('option');
                    o.value = o.textContent = b.getAttribute('data-valor');
                    o.selected = o.value === keep;
                    valueSel.appendChild(o);
                });
                valueSel.disabled = !src;
            }
            var question = askSel.options[askSel.selectedIndex].textContent;
            var text = from !== '0' && valueSel && valueSel.value ? say('panel_js_condition', {question: question, answer: valueSel.value}) :
                say('panel_js_condition_always');
            if (sentence) {
                sentence.textContent = text;
            }
            if (q) {
                q.setAttribute('data-si', from !== '0' ? from : '0');
                q.setAttribute('data-si-valor', valueSel ? valueSel.value : '');
                q.classList.toggle('condicional', from !== '0');
                var note = one('.movil-si', q);
                if (from !== '0') {
                    if (!note) {
                        note = doc.createElement('span');
                        note.className = 'movil-si';
                        q.insertBefore(note, q.firstChild);
                    }
                    note.textContent = text.replace(/\.$/, '');
                } else if (note) {
                    note.parentNode.removeChild(note);
                }
            }
        };
        // Saved by itself: a moment after the last change, without leaving the page.
        var timer = null;
        var savedAt = 0;
        var tick = null;
        var save = function() {
            if (!window.FormData) {
                return;
            }
            var data = new FormData(form);
            data.set('action', 'savefield');
            data.set('autosave', '1');
            if (saved) {
                saved.textContent = say('panel_js_saving');
            }
            ask(new URL(form.getAttribute('action'), window.location.href).href, {method: 'POST', body: data}).then(function() {
                savedAt = Date.now();
                window.clearInterval(tick);
                var show = function() {
                    var s = Math.round((Date.now() - savedAt) / 1000);
                    if (saved) {
                        saved.textContent = s < 2 ? say('panel_js_saved_now') : say('panel_js_saved_ago', s);
                    }
                    if (s > 60) {
                        window.clearInterval(tick);
                    }
                };
                show();
                tick = window.setInterval(show, 1000);
            }).catch(function() {
                if (saved) {
                    saved.textContent = '';
                }
            });
        };
        var later = function() {
            window.clearTimeout(timer);
            timer = window.setTimeout(save, 1200);
        };
        on(form, 'input', function(e) {
            var t = e.target;
            if (q && t.hasAttribute('data-vivo-pregunta')) {
                one('[data-movil-label]', q).textContent = t.value;
            } else if (q && t.hasAttribute('data-vivo-ayuda')) {
                one('[data-movil-ayuda]', q).textContent = t.value;
            } else if (t.hasAttribute('data-vivo-opciones')) {
                drawOps();
            }
            later();
        });
        on(form, 'change', function(e) {
            if (e.target.name === 'type') {
                drawOps();
            }
            if (e.target === askSel || e.target === valueSel) {
                condition();
            }
            later();
        });
        // Point at the phone: the answers of the other questions can be touched; the one touched sets the condition.
        var point = one('[data-senalar]', form);
        var help = one('[data-senalando-aviso]', form);
        var stop = function() {
            pointing = null;
            if (phone) {
                phone.classList.remove('senalando');
                phoneLive(trying);
            }
            if (help) {
                help.hidden = true;
            }
            if (point) {
                point.setAttribute('aria-pressed', 'false');
            }
        };
        if (point && phone && askSel) {
            point.hidden = false;
            point.setAttribute('aria-pressed', 'false');
            on(point, 'click', function() {
                if (pointing) {
                    stop();
                    return;
                }
                phone.classList.add('senalando');
                phoneLive(true);
                if (help) {
                    help.hidden = false;
                }
                point.setAttribute('aria-pressed', 'true');
                var first = one('[data-movil-q]:not(.editando) .movil-op', phone);
                if (first) {
                    first.focus();
                }
                pointing = function(from, op) {
                    var fid = from.getAttribute('data-movil-q');
                    var option = one('option[value="' + fid + '"]', askSel);
                    if (!option) {
                        return;
                    }
                    askSel.value = fid;
                    condition();
                    valueSel.value = op.getAttribute('data-valor');
                    condition();
                    stop();
                    if (sentence) {
                        sentence.setAttribute('tabindex', '-1');
                        sentence.focus();
                    }
                    later();
                };
            });
            on(doc, 'keydown', function(e) {
                if (e.key === 'Escape' && pointing) {
                    stop();
                    point.focus();
                }
            });
        }
    });
    // A new question: its type is proposed from its words, as a pill that can be changed.
    var newQ = one('[data-sugerir-tipo]');
    if (newQ) {
        var typeSel = one('select[name="type"]', newQ.form);
        var hintOut = one('[data-tipo-sugerido]', newQ.form);
        var touched = false;
        on(typeSel, 'change', function() {
            touched = true;
        });
        var RULES = [
            [/^¿?\s*(qué días|cuáles|en qué (días|cursos))/i, 'multichoice'],
            [/^¿?\s*(tienen|tiene|necesitan|necesita|hay|quieren|quiere|vienen|pueden|puede|es|son|están|está|han|ha)\b/i, 'yesno'],
            [/\b(cuánt[oa]s?|número|edad)\b/i, 'number'],
            [/\b(cuándo|fecha|qué día)\b/i, 'date'],
            [/\b(cuéntennos|cuéntenos|algo que|comentario|por qué|cómo es)\b/i, 'longtext'],
            [/^¿?\s*(qué|cuál|en qué|dónde)\b/i, 'choice']
        ];
        on(newQ, 'input', function() {
            if (touched) {
                return;
            }
            var hit = RULES.filter(function(r) {
                return r[0].test(newQ.value.trim());
            })[0];
            hintOut.hidden = !hit;
            if (hit) {
                typeSel.value = hit[1];
                hintOut.textContent = say('panel_js_type_suggested', typeSel.options[typeSel.selectedIndex].textContent);
            }
        });
    }

    // ---- Bridges and importing: the key seen or hidden; a file dropped on the tray says its name.
    all('[data-ver-clave]').forEach(function(button) {
        var input = one('#' + button.getAttribute('data-ver-clave'));
        button.hidden = false;
        on(button, 'click', function() {
            var shown = input.type === 'text';
            input.type = shown ? 'password' : 'text';
            button.setAttribute('aria-pressed', shown ? 'false' : 'true');
            button.lastChild.textContent = say(shown ? 'panel_js_key_show' : 'panel_js_key_hide');
        });
    });
    all('.bandeja-fichero').forEach(function(tray) {
        var input = one('input[type="file"]', tray);
        ['dragenter', 'dragover'].forEach(function(type) {
            on(tray, type, function() {
                tray.classList.add('encima');
            });
        });
        ['dragleave', 'drop'].forEach(function(type) {
            on(tray, type, function() {
                tray.classList.remove('encima');
            });
        });
        on(input, 'change', function() {
            if (input.files && input.files[0]) {
                one('b', tray).textContent = input.files[0].name;
            }
        });
    });

    // ---- The settings of the centre: the colour rewrites the band, the label and the wall, in light and dark, and a
    // sentence says what was done so that everything reads (the same sums as publicpage::centre_vars()).
    var colour = one('[data-color-centro]');
    if (colour) {
        var hexText = one('[data-color-hex]');
        var rgb = function(h) {
            return h.replace('#', '').match(/../g).map(function(x) {
                return parseInt(x, 16);
            });
        };
        var mixc = function(a, b, t) {
            var x = rgb(a);
            var y = rgb(b);
            return '#' + x.map(function(v, i) {
                return ('0' + Math.round(Math.max(0, Math.min(255, v + (y[i] - v) * t))).toString(16)).slice(-2);
            }).join('').toUpperCase();
        };
        var lum = function(h) {
            return rgb(h).reduce(function(l, v, i) {
                v /= 255;
                return l + [0.2126, 0.7152, 0.0722][i] * (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4));
            }, 0);
        };
        var contrast = function(a, b) {
            var x = lum(a);
            var y = lum(b);
            return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
        };
        var towards = function(c, target, against, min) {
            for (var t = 0; t <= 1.001; t += 0.025) {
                var x = mixc(c, target, t);
                if (contrast(x, against) >= min) {
                    return x;
                }
            }
            return target;
        };
        var vars = function(accent, dark) {
            var paper = dark ? '#211D1B' : '#FFFFFF';
            var vivid = dark ? towards(accent, '#FFFFFF', paper, 3) : accent;
            var wall = dark ? mixc('#25201D', vivid, 0.05) : mixc('#F4EADC', accent, 0.06);
            var text = towards(accent, dark ? '#FFFFFF' : '#000000', wall, 4.5);
            var onc = function(c) {
                return contrast(c, '#FFFFFF') >= contrast(c, '#1A1614') ? '#FFFFFF' : '#1A1614';
            };
            return {'--c-acento': accent, '--c-sobre-acento': onc(accent), '--c-acento-texto': text, '--c-acento-vivo': vivid,
                '--c-sobre-vivo': onc(vivid), '--c-pared': wall, '--c-banda-linea': mixc(accent, onc(accent), 0.35)};
        };
        var paint = function(value) {
            if (!/^#[0-9a-f]{6}$/i.test(value)) {
                return;
            }
            var accent = value.toUpperCase();
            var light = vars(accent, false);
            var dark = vars(accent, true);
            [['claro', light], ['oscuro', dark]].forEach(function(pair) {
                var pane = one('[data-muestra-tema="' + pair[0] + '"]');
                Object.keys(pair[1]).forEach(function(k) {
                    pane.style.setProperty(k, pair[1][k]);
                });
            });
            var c = rgb(accent);
            var key = Math.max.apply(null, c) - Math.min.apply(null, c) < 24 ? 'panel_js_colour_grey' :
                light['--c-acento-texto'] !== accent ? 'panel_js_colour_light' :
                dark['--c-acento-vivo'] !== accent || dark['--c-acento-texto'] !== accent ? 'panel_js_colour_dark' : 'panel_js_colour_ok';
            one('[data-frase-color]').textContent = say(key);
        };
        on(colour, 'input', function() {
            hexText.value = colour.value;
            paint(colour.value);
        });
        on(hexText, 'input', function() {
            var v = hexText.value.trim();
            if (/^#?[0-9a-f]{6}$/i.test(v)) {
                v = v.charAt(0) === '#' ? v : '#' + v;
                colour.value = v.toLowerCase();
                paint(v);
            }
        });
        var nameIn = one('[data-vivo-centro]');
        if (nameIn) {
            on(nameIn, 'input', function() {
                all('[data-muestra-centro]').forEach(function(el) {
                    el.textContent = nameIn.value;
                });
            });
        }
    }

    // ---- The settings of the centre: a photo, a logo or a choice in a select saves itself as soon as it is chosen,
    // like every other gesture of the page, with the «Save» of its card (so it comes back to that card). Without
    // JavaScript, that «Save» is there to press.
    var settingsForm = one('[data-ajustes]');
    if (settingsForm) {
        all('[data-envio-solo]', settingsForm).forEach(function(field) {
            on(field, 'change', function() {
                if (field.type === 'file' && !(field.files && field.files.length)) {
                    return;
                }
                var part = field.closest('.aj-persona, .aj-plegado, .apartado');
                var save = part ? one('.aj-foto-guardar, [data-guardar]', part) : null;
                if (settingsForm.requestSubmit) {
                    settingsForm.requestSubmit(save || undefined);
                } else if (save) {
                    save.click();
                } else {
                    settingsForm.submit();
                }
            });
        });
    }

    // ---- How it went (round 4): the sheets of an event once it is over. Pointing at a word of a scale (mouse, finger or
    // keyboard), its line says how many said it and the other words step back; the bars grow once when they come into
    // sight; while the survey is asked, the sheets are read again every half minute and what changed moves from its old
    // value to the new one, a new slip dropped on the desk; printed alone, it is a sheet to take away in PDF. All of it
    // is already written by the server: this only lights and moves it.
    var howItWent = one('#como-fue');
    if (howItWent) {
        var HIW_EASE = 'cubic-bezier(.2,.8,.2,1)';
        var rowNumber = function(row) {
            return parseInt(row.getAttribute('data-n'), 10) || 0;
        };
        var rowSentence = function(row) {
            var n = rowNumber(row);
            var of = 0;
            all('.fila-como', row.parentNode).forEach(function(r) {
                of += rowNumber(r);
            });
            var word = (row.getAttribute('data-palabra') || '').toLocaleLowerCase();
            var key = !n ? 'panel_js_howitwent_row_none' : (n === 1 ? 'panel_js_howitwent_row_one' : 'panel_js_howitwent_row');
            return say(key, {n: n, of: of, word: word});
        };
        var lineOf = function(list) {
            var sheet = list.closest('.pregunta-como');
            return sheet ? one('.frase-como', sheet) : null;
        };
        var pointRow = function(row) {
            var list = row.parentNode;
            var line = lineOf(list);
            all('.fila-como', list).forEach(function(r) {
                r.classList.toggle('senalado', r === row);
            });
            list.classList.add('con-senal');
            var text = rowSentence(row);
            if (line && text) {
                if (!line.hasAttribute('data-reposo')) {
                    line.setAttribute('data-reposo', line.textContent);
                }
                line.textContent = text;
                line.classList.add('dato-vivo');
            }
        };
        var restList = function(list) {
            var line = lineOf(list);
            list.classList.remove('con-senal');
            all('.senalado', list).forEach(function(r) {
                r.classList.remove('senalado');
            });
            if (line && line.hasAttribute('data-reposo')) {
                line.textContent = line.getAttribute('data-reposo');
                line.classList.remove('dato-vivo');
            }
        };
        var bindBars = function(root) {
            all('.barras-como', root).forEach(function(list) {
                var touched = false;
                on(list, 'pointerover', function(e) {
                    var row = e.target.closest('.fila-como');
                    if (row && e.pointerType !== 'touch') {
                        pointRow(row);
                    }
                });
                on(list, 'pointerleave', function(e) {
                    if (e.pointerType !== 'touch' && !list.contains(doc.activeElement)) {
                        restList(list);
                    }
                });
                // A finger: a touch lights a word, the same touch again (or one elsewhere) puts it back.
                on(list, 'pointerdown', function(e) {
                    touched = e.pointerType === 'touch';
                });
                on(list, 'click', function(e) {
                    var row = e.target.closest('.fila-como');
                    if (!row || !touched) {
                        return;
                    }
                    if (row.classList.contains('senalado')) {
                        restList(list);
                    } else {
                        pointRow(row);
                    }
                });
                on(list, 'focusin', function(e) {
                    var row = e.target.closest('.fila-como');
                    if (row) {
                        pointRow(row);
                    }
                });
                on(list, 'focusout', function(e) {
                    if (!e.relatedTarget || !list.contains(e.relatedTarget)) {
                        restList(list);
                    }
                });
                // The arrows go along the words of the same question.
                on(list, 'keydown', function(e) {
                    var step = {ArrowDown: 1, ArrowUp: -1}[e.key];
                    var rows = all('.fila-como', list);
                    var at = rows.indexOf(e.target);
                    if (!step || at === -1) {
                        return;
                    }
                    e.preventDefault();
                    rows[Math.max(0, Math.min(rows.length - 1, at + step))].focus();
                });
            });
            all('[data-imprimir-seccion]', root).forEach(function(button) {
                button.hidden = false;
            });
        };
        // The bars grow once, one after another, the first time they are seen.
        var growBars = function(list) {
            var bars = all('.barra-como', list);
            list.classList.remove('por-crecer');
            if (still() || !Element.prototype.animate || !bars.length || !gesture(600 + bars.length * 70)) {
                return;
            }
            bars.forEach(function(bar, i) {
                bar.animate([{transform: 'scaleX(0)'}, {transform: 'scaleX(1)'}],
                    {duration: 600, delay: i * 70, easing: HIW_EASE, fill: 'backwards'});
            });
        };
        var watchBars = function(root) {
            var lists = all('.barras-como', root);
            if (still() || !Element.prototype.animate || !('IntersectionObserver' in window)) {
                return;
            }
            var seen = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        seen.unobserve(entry.target);
                        growBars(entry.target);
                    }
                });
            }, {threshold: 0.4});
            lists.forEach(function(list) {
                list.classList.add('por-crecer');
                seen.observe(list);
            });
        };
        // The date falls on the first sheet like a stamp, once; the families are counted on it stroke by stroke.
        var stampDate = function(root) {
            var stamp = one('.sello-como', root);
            if (stamp && !still() && gesture(450)) {
                stamp.classList.add('cae');
            }
            var strokes = all('.palotes i', root);
            if (strokes.length && !still() && Element.prototype.animate && gesture(300 + strokes.length * 45)) {
                strokes.forEach(function(stroke, i) {
                    stroke.animate([{opacity: 0}, {opacity: 1}],
                        {duration: 220, delay: 120 + i * 45, easing: 'ease-out', fill: 'backwards'});
                });
            }
        };
        bindBars(howItWent);
        watchBars(howItWent);
        stampDate(howItWent);

        // Printed alone: everything else hidden, then back as it was.
        on(howItWent, 'click', function(e) {
            var button = e.target.closest('[data-imprimir-seccion]');
            var section = button ? doc.getElementById(button.getAttribute('data-imprimir-seccion')) : null;
            if (!section) {
                return;
            }
            doc.body.classList.add('imprime-seccion');
            section.classList.add('a-imprimir');
            var done = function() {
                doc.body.classList.remove('imprime-seccion');
                section.classList.remove('a-imprimir');
                window.removeEventListener('afterprint', done);
            };
            window.addEventListener('afterprint', done);
            window.print();
        });

        // While the survey is asked: read again, only with the tab in sight; nothing under what is being written moves.
        var alive = one('[data-releer]', howItWent);
        if (alive && window.DOMParser) {
            var every = Math.max(15, parseInt(alive.getAttribute('data-cada'), 10) || 30) * 1000;
            var snapshot = function() {
                var bars = {};
                var quotes = {};
                all('.pregunta-como', alive).forEach(function(sheet, q) {
                    all('.fila-como', sheet).forEach(function(row, r) {
                        bars[q + ':' + r] = parseFloat(row.style.getPropertyValue('--v')) || 0;
                    });
                });
                all('.cita-como', alive).forEach(function(slip) {
                    quotes[slip.textContent.replace(/\s+/g, ' ').trim()] = true;
                });
                return {bars: bars, quotes: quotes};
            };
            var reread = function() {
                if (doc.hidden) {
                    return;
                }
                ask(alive.getAttribute('data-releer')).then(function(r) {
                    return r.text();
                }).then(function(html) {
                    var fresh = html ? new window.DOMParser().parseFromString(html, 'text/html').querySelector('[data-releer]') :
                        null;
                    if (!fresh || fresh.innerHTML === alive.innerHTML) {
                        return;
                    }
                    var before = snapshot();
                    var focused = doc.activeElement && alive.contains(doc.activeElement) ?
                        all('.fila-como', alive).indexOf(doc.activeElement) : -1;
                    alive.innerHTML = fresh.innerHTML;
                    bindBars(alive);
                    if (focused !== -1 && all('.fila-como', alive)[focused]) {
                        all('.fila-como', alive)[focused].focus();
                    }
                    var moving = !still() && Element.prototype.animate;
                    all('.pregunta-como', alive).forEach(function(sheet, q) {
                        all('.fila-como', sheet).forEach(function(row, r) {
                            var was = before.bars[q + ':' + r];
                            var now = parseFloat(row.style.getPropertyValue('--v')) || 0;
                            if (moving && was !== undefined && now && was !== now) {
                                one('.barra-como', row).animate([{transform: 'scaleX(' + (was / now) + ')'}, {transform: 'none'}],
                                    {duration: 300, easing: HIW_EASE});
                            }
                        });
                    });
                    all('.cita-como', alive).forEach(function(slip) {
                        if (!before.quotes[slip.textContent.replace(/\s+/g, ' ').trim()] && moving && gesture(500)) {
                            slip.classList.add('llega');
                        }
                    });
                }).catch(function() {
                    // The next turn will try again.
                });
            };
            window.setInterval(reread, every);
            window.Eguahe.releerComoFue = reread;
        }
    }

    // ---- Keys (DESIGN 3.1): none sends or erases, none acts while a field has the focus. «?» lists them.
    var sheetKeys = one('#teclas');
    var keyButton = one('[data-abrir-teclas]');
    var typing = function(el) {
        return el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.isContentEditable);
    };
    var armed = null;
    var showKeys = function() {
        var list = one('[data-teclas-lista]', sheetKeys);
        list.innerHTML = '';
        var add = function(k, what) {
            var dt = doc.createElement('dt');
            var dd = doc.createElement('dd');
            dt.textContent = k;
            dd.textContent = what;
            list.appendChild(dt);
            list.appendChild(dd);
        };
        if (one('.fila')) {
            add('J', say('panel_js_key_next'));
            add('K', say('panel_js_key_prev'));
            add('Intro', say('panel_js_key_open'));
        }
        var seen = {};
        all('[data-tecla]').forEach(function(el) {
            var k = el.getAttribute('data-tecla');
            if (!seen[k] && el.offsetParent !== null) {
                seen[k] = true;
                add(k.toUpperCase(), el.getAttribute('data-tecla-que') || el.textContent.trim());
            }
        });
        add('Esc', say('panel_js_key_close'));
        add('?', say('panel_js_key_help'));
        sheetKeys.hidden = false;
        one('[data-cerrar-teclas]', sheetKeys).focus();
    };
    if (sheetKeys) {
        on(one('[data-cerrar-teclas]', sheetKeys), 'click', function() {
            sheetKeys.hidden = true;
            if (keyButton && !keyButton.hidden) {
                keyButton.focus();
            }
        });
        if (keyButton && fine.matches && (one('[data-tecla]') || one('.fila'))) {
            keyButton.hidden = false;
            on(keyButton, 'click', showKeys);
        }
    }
    var move = function(step) {
        var list = rows();
        if (!list.length) {
            return;
        }
        var current = openRow || (doc.activeElement && doc.activeElement.closest && doc.activeElement.closest('.fila'));
        var i = list.indexOf(current);
        var next = i === -1 ? list[0] : list[Math.max(0, Math.min(list.length - 1, i + step))];
        var link = one('a[data-abre-hoja]', next);
        if (desk && sheet && !sheet.hidden) {
            showSheet(link.href, next, true);
        } else {
            link.focus();
            next.scrollIntoView({block: 'nearest'});
        }
    };
    on(doc, 'keydown', function(e) {
        if (e.key === 'Escape') {
            if (sheetKeys && !sheetKeys.hidden) {
                sheetKeys.hidden = true;
                return;
            }
            if (armed) {
                armed = null;
                if (news) {
                    news.hidden = true;
                }
                return;
            }
            var openMenu = menus().filter(function(m) {
                return m.open;
            })[0];
            if (openMenu) {
                openMenu.open = false;
                one('summary', openMenu).focus();
                return;
            }
            if (!typing(e.target) && closeSheet(true)) {
                e.preventDefault();
            }
            return;
        }
        if (e.ctrlKey || e.metaKey || e.altKey || typing(e.target)) {
            return;
        }
        if (armed && e.key !== 'Enter') {
            armed = null;
        }
        var k = e.key.length === 1 ? e.key.toLowerCase() : '';
        if (k === 'j' || k === 'k') {
            e.preventDefault();
            move(k === 'j' ? 1 : -1);
            return;
        }
        if (e.key === '?' && sheetKeys) {
            e.preventDefault();
            showKeys();
            return;
        }
        if (!k) {
            return;
        }
        var visible = function(el) {
            return el.offsetParent !== null;
        };
        var scope = (sheet && !sheet.hidden) ? sheet : doc;
        var target = all('[data-tecla="' + k + '"]', scope).filter(visible)[0] || all('[data-tecla="' + k + '"]').filter(visible)[0];
        if (!target) {
            return;
        }
        e.preventDefault();
        if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
            target.focus();
        } else if (target.getAttribute('data-tecla-intro')) {
            // It asks for Enter: the button takes the focus and a line says so.
            armed = target;
            target.focus();
            if (news) {
                news.textContent = say('panel_js_press_enter');
                news.hidden = false;
            }
        } else if (target.getAttribute('data-ancla')) {
            var area = one('textarea[name="body"]', scope);
            if (area) {
                area.focus();
            }
        } else {
            target.click();
        }
    });

    // How they heard of the centre: on a network, which one; the field only when it is a network, or already written.
    all('[data-cual-red]').forEach(function(select) {
        var form = select.form || doc;
        var which = one('.cual-red', form);
        if (!which) {
            return;
        }
        var shown = function() {
            var input = one('input', which);
            which.hidden = select.value !== select.getAttribute('data-cual-red') && !(input && input.value);
        };
        on(select, 'change', shown);
        shown();
    });
})();
