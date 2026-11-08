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
 * while a family is written down. Common to every skin: a skin listens to the events «eguahe:*» and adds its scene.
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
            if (!soundOn || !window.AudioContext) {
                return;
            }
            // A short knock of wood: filtered noise, quiet.
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
            var text = one('[data-etiqueta-viva="' + i + '"] text', form);
            if (text) {
                text.textContent = (tag && tag.value.trim()) || (name && name.value.trim().split(/\s+/)[0]) || '';
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
})();

/**
 * The beat of the composer (Eguahe): while someone writes to a family, the others see it. Every 20 seconds at most,
 * only while writing; the beat lasts 45 seconds on the server.
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
