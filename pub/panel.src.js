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
    // Without minding accents: «Perez» finds «Pérez».
    var plain = function(text) {
        text = String(text || '').toLowerCase();
        return text.normalize ? text.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : text;
    };
    var cutList = one('[data-lista-cortada]');
    var searchInput = one('#buscar-f input[name="q"]');
    // «Search every family of the centre» carries what is written and what is chosen on the page.
    var everywhere = function() {
        all('a[data-buscar-todas]').forEach(function(link) {
            if (!window.URL) {
                return;
            }
            var url = new window.URL(window.location.href);
            url.searchParams.set('all', '1');
            url.searchParams.delete('n');
            if (searchInput && searchInput.value.trim()) {
                url.searchParams.set('q', searchInput.value.trim());
            }
            link.href = url.toString();
        });
    };
    var applyFilters = function(input) {
        var selector = input ? input.getAttribute('data-filtrar') : '.fila';
        var shown = 0;
        all(selector).forEach(function(row) {
            if (row.plainText === undefined) {
                row.plainText = plain(row.getAttribute('data-buscar'));
            }
            var text = row.plainText;
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
        everywhere();
    };
    all('input[data-filtrar]').forEach(function(input) {
        var words = function() {
            return plain(input.value).trim().split(/\s+/).filter(Boolean);
        };
        on(input, 'input', function() {
            filterState.text = words();
            applyFilters(input);
        });
        // What came searched from the server is already the list: the page does not filter it again.
        filterState.text = input.value && input.form ? [] : words();
    });
    // Changing between «Waiting» and «All» keeps what is written in the search.
    on(doc, 'click', function(e) {
        var tab = e.target.closest && e.target.closest('.segmentos a');
        if (!tab || !searchInput || !searchInput.value.trim() || !window.URL || e.ctrlKey || e.metaKey || e.shiftKey) {
            return;
        }
        var url = new window.URL(tab.href, window.location.href);
        url.searchParams.set('q', searchInput.value.trim());
        tab.href = url.toString();
    });
    on(doc, 'click', function(e) {
        var pill = e.target.closest && e.target.closest('a[data-filtro]');
        // A list cut short filters on the server: what is not on the page may be what is asked for.
        if (!pill || cutList || e.ctrlKey || e.metaKey || e.shiftKey) {
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
    // The code of an act or of a place, to take away: a picture for a document or a network, or the drawing for the printer.
    var codeOf = function(box, size) {
        var shown = one('.qr svg, .qr img', box);
        var drawn = null;
        var text = '';
        if (shown.tagName.toLowerCase() === 'img') {
            // The one of the shortener: a picture kept in the page, with its own margin.
            var bytes = window.atob(shown.src.split(',')[1]);
            var codes = new Uint8Array(bytes.length);
            for (var i = 0; i < bytes.length; i++) {
                codes[i] = bytes.charCodeAt(i);
            }
            text = new TextDecoder().decode(codes);
            drawn = new DOMParser().parseFromString(text, 'image/svg+xml').documentElement;
            if (!drawn.getAttribute('viewBox')) {
                drawn.setAttribute('viewBox', '0 0 ' + parseFloat(drawn.getAttribute('width')) + ' ' +
                    parseFloat(drawn.getAttribute('height')));
            }
        } else {
            // The one drawn here: with its white and the four modules it needs around it.
            drawn = shown.cloneNode(true);
            var n = parseFloat(drawn.getAttribute('viewBox').split(' ')[2]);
            var paper = doc.createElementNS('http://www.w3.org/2000/svg', 'path');
            paper.setAttribute('fill', '#fff');
            paper.setAttribute('d', 'M-4-4h' + (n + 8) + 'v' + (n + 8) + 'h-' + (n + 8) + 'z');
            drawn.insertBefore(paper, drawn.firstChild);
            drawn.setAttribute('viewBox', '-4 -4 ' + (n + 8) + ' ' + (n + 8));
            drawn.removeAttribute('aria-hidden');
        }
        drawn.setAttribute('width', size);
        drawn.setAttribute('height', size);
        return new XMLSerializer().serializeToString(drawn);
    };
    var takeAway = function(blob, name) {
        var link = doc.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = name;
        doc.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function() {
            URL.revokeObjectURL(link.href);
        }, 10000);
    };
    all('[data-qr-bajar]').forEach(function(button) {
        button.hidden = false;
        on(button, 'click', function() {
            var size = 1200;
            var kind = button.getAttribute('data-qr-bajar');
            var name = 'qr ' + button.getAttribute('data-nombre');
            name = name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '') + '.' + kind;
            var drawn = codeOf(button.closest('[data-qr]'), size);
            if (kind === 'svg') {
                takeAway(new Blob([drawn], {type: 'image/svg+xml'}), name);
                return;
            }
            var picture = new Image();
            on(picture, 'load', function() {
                var canvas = doc.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                var pen = canvas.getContext('2d');
                pen.fillStyle = '#fff';
                pen.fillRect(0, 0, size, size);
                pen.drawImage(picture, 0, 0, size, size);
                canvas.toBlob(function(png) {
                    takeAway(png, name);
                }, 'image/png');
            });
            // As data: the policy of the panel takes no blob in a picture.
            picture.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(drawn);
        });
    });
    // Splitting two families: one line says who moves to the new record and who stays, and the button waits until
    // somebody moves.
    var splitForm = one('#separar form');
    if (splitForm) {
        var andList = function(list) {
            return list.length < 2 ? list.join('') : say('panel_js_and', {list: list.slice(0, -1).join(', '),
                last: list[list.length - 1]});
        };
        on(splitForm, 'change', function() {
            var go = [];
            var stay = [];
            all('[data-separar-quien]', splitForm).forEach(function(row) {
                var picked = one('input:checked', row);
                var value = picked ? picked.value : 'stay';
                var name = row.getAttribute('data-separar-quien');
                if (value !== 'stay') {
                    go.push(name);
                }
                if (value !== 'go') {
                    stay.push(name);
                }
            });
            var line = one('[data-separar-resumen]', splitForm);
            if (line) {
                line.textContent = go.length ? say('panel_js_split_go', andList(go)) +
                    (stay.length ? ' ' + say('panel_js_split_stay', andList(stay)) : '') : say('panel_js_split_nothing');
            }
            var button = one('[data-separar-boton]', splitForm);
            if (button) {
                button.disabled = !go.length;
            }
        });
    }
    // Inviting to an act: the numbers follow the stamps, the chips leave in sight only some families (and only those go),
    // and the words and the test go without leaving. Without this it is one form, where every button keeps what is there.
    var inviteForm = one('[data-invitar]');
    if (inviteForm) {
        var inviteT = JSON.parse(inviteForm.getAttribute('data-textos') || '{}');
        var fill = function(text, values) {
            return Object.keys(values).reduce(function(s, k) {
                return s.split('{' + k + '}').join(values[k]);
            }, text || '');
        };
        var inviteRows = all('[data-sobre]', inviteForm);
        var inviteTick = function(row) {
            return one('input', row);
        };
        var inviteFind = one('[data-buscar-sobre]', inviteForm);
        var plain = function(s) {
            return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        };
        // Inside a row any of its chips; every row with one pressed has to agree. The name that is searched for only
        // finds: it never leaves anybody out.
        var inviteShow = function() {
            var rows = all('[role="group"]', inviteForm).map(function(group) {
                return all('[data-grupo][aria-pressed="true"]', group).map(function(chip) {
                    return chip.getAttribute('data-grupo').split(',');
                });
            }).filter(function(chips) {
                return chips.length;
            });
            var words = inviteFind ? plain(inviteFind.value.trim()) : '';
            var seen = 0;
            inviteRows.forEach(function(row) {
                var id = row.getAttribute('data-sobre');
                var inside = rows.every(function(chips) {
                    return chips.some(function(ids) {
                        return ids.indexOf(id) !== -1;
                    });
                });
                var found = !words || plain(row.getAttribute('data-buscar')).indexOf(words) !== -1;
                row.classList.toggle('fuera', !inside);
                row.hidden = !inside || !found;
                seen += row.hidden ? 0 : 1;
            });
            one('[data-nadie]', inviteForm).hidden = seen > 0;
            inviteCount();
        };
        var inviteGoing = function() {
            return inviteRows.filter(function(row) {
                return !row.classList.contains('fuera') && inviteTick(row).checked;
            });
        };
        var inviteCount = function() {
            var inside = inviteRows.filter(function(row) {
                return !row.classList.contains('fuera');
            }).length;
            var going = inviteGoing();
            var by = function(way) {
                return going.filter(function(row) {
                    return inviteTick(row).hasAttribute(way);
                }).length;
            };
            var n = going.length;
            var ways = fill(by('data-movil') ? inviteT.ways : inviteT.email, {email: by('data-correo'), phone: by('data-movil')});
            one('[data-cuenta]', inviteForm).textContent = fill(inviteT.count, {n: n, of: inside});
            one('[data-va]', inviteForm).textContent = fill(n > 1 ? inviteT.goes : (n ? inviteT.goesone : inviteT.goesnone),
                {n: n, ways: ways});
            var send = one('[data-mandar]', inviteForm);
            send.textContent = fill(n > 1 ? inviteT.send : (n ? inviteT.sendone : inviteT.sendnone), {n: n});
            send.disabled = !n;
        };
        all('[data-con-guion]', inviteForm).forEach(function(el) {
            el.hidden = false;
        });
        all('[data-sin-guion]', inviteForm).forEach(function(el) {
            el.hidden = true;
        });
        // What is kept: the words, and every stamp, also those out of sight.
        var inviteData = function(action) {
            var data = new FormData();
            ['sesskey', 'invitar', 'gusto', 'invitewords'].forEach(function(name) {
                data.append(name, inviteForm.elements[name].value);
            });
            data.append('action', action);
            inviteRows.forEach(function(row) {
                if (inviteTick(row).checked) {
                    data.append('para[]', inviteTick(row).value);
                }
            });
            return data;
        };
        var inviteAsk = function(action) {
            return ask(inviteForm.getAttribute('action'), {method: 'POST', body: inviteData(action)}).then(function(r) {
                return r.text();
            }).then(function(html) {
                return one('[data-aviso]', new DOMParser().parseFromString(html, 'text/html'));
            });
        };
        var inviteWords = one('#invitewords', inviteForm);
        var inviteKept = one('[data-guardado]', inviteForm);
        var inviteGrow = function() {
            inviteWords.style.height = 'auto';
            inviteWords.style.height = inviteWords.scrollHeight + 'px';
        };
        on(inviteWords, 'input', function() {
            inviteGrow();
            inviteKept.textContent = '';
        });
        on(inviteWords, 'change', function() {
            inviteAsk('invitewords').then(function() {
                inviteKept.textContent = inviteT.kept;
            }).catch(function() {
                inviteKept.textContent = inviteT.notkept;
            });
        });
        on(inviteForm, 'change', function(e) {
            if (e.target.type === 'checkbox') {
                inviteCount();
            }
        });
        if (inviteFind) {
            on(inviteFind, 'input', inviteShow);
            on(inviteFind, 'keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });
        }
        on(inviteForm, 'click', function(e) {
            var button = e.target.closest && e.target.closest('button');
            if (!button) {
                return;
            }
            if (button.hasAttribute('data-grupo')) {
                e.preventDefault();
                button.setAttribute('aria-pressed', button.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
                inviteShow();
            } else if (button.hasAttribute('data-marcar')) {
                // Only those in sight: with a chip pressed or a name searched for, the rest stay as they are.
                e.preventDefault();
                inviteRows.forEach(function(row) {
                    if (!row.hidden) {
                        inviteTick(row).checked = button.getAttribute('data-marcar') === '1';
                    }
                });
                inviteCount();
            } else if (button.hasAttribute('data-mandarmela')) {
                e.preventDefault();
                var told = one('[data-mandada]', inviteForm);
                button.disabled = true;
                inviteAsk('invitetest').then(function(said) {
                    if (!said) {
                        throw new Error('said');
                    }
                    told.textContent = said.textContent.trim();
                    told.classList.toggle('con-error', said.classList.contains('con-error'));
                }).catch(function() {
                    told.textContent = inviteT.testno;
                    told.classList.add('con-error');
                }).then(function() {
                    told.hidden = false;
                    button.disabled = false;
                });
            } else if (button.hasAttribute('data-mandar')) {
                // Whoever a chip leaves out of sight does not go.
                inviteRows.forEach(function(row) {
                    inviteTick(row).disabled = row.classList.contains('fuera');
                });
            }
        });
        // For whom the act is: chosen when it opens, and to let go as any other.
        all('[data-de-serie]', inviteForm).forEach(function(chip) {
            chip.setAttribute('aria-pressed', 'true');
        });
        // Back from the page before it goes, as it was.
        on(window, 'pageshow', function() {
            inviteRows.forEach(function(row) {
                inviteTick(row).disabled = false;
            });
            inviteShow();
        });
        inviteGrow();
        inviteShow();
    }
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
        // The pad that answers; the slip and the sticky note under the conversation have their own text.
        var area = one('[data-redactor] textarea[name="body"]', root);
        if (area) {
            area.value = text;
            // Whatever listens to what is written (what they asked, who is writing) hears it too.
            area.dispatchEvent(new Event('input', {bubbles: true}));
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
        // What they asked: each question ticks itself when the draft speaks of it, and a tap ticks or unticks it by hand.
        all('[data-preguntaron]', root).forEach(function(box) {
            var form = box.closest('form');
            var area = form && one('textarea[name="body"]', form);
            var items = all('li[data-palabras]', box);
            if (!area || !items.length) {
                return;
            }
            var normal = function(s) {
                s = (s || '').toLowerCase();
                return s.normalize ? s.normalize('NFD').replace(/[\u0300-\u036f\u0610-\u061a\u064b-\u065f\u0670]/g, '') : s;
            };
            var count = one('[data-cuenta]', box);
            var update = function() {
                var text = normal(area.value);
                var done = 0;
                items.forEach(function(li) {
                    var words = li.getAttribute('data-palabras').split('|').filter(Boolean);
                    var hits = words.filter(function(w) {
                        return text.indexOf(w) !== -1;
                    }).length;
                    var hand = li.getAttribute('data-a-mano');
                    var yes = hand ? hand === 'si' : words.length > 0 && hits >= Math.ceil(words.length / 2);
                    one('button', li).setAttribute('aria-pressed', yes ? 'true' : 'false');
                    li.classList.toggle('hecha', yes);
                    done += yes ? 1 : 0;
                });
                if (count) {
                    count.textContent = say('panel_js_asked_count', {done: done, all: items.length});
                }
            };
            items.forEach(function(li) {
                var button = doc.createElement('button');
                button.type = 'button';
                button.className = 'pr-pregunta';
                while (li.firstChild) {
                    button.appendChild(li.firstChild);
                }
                li.appendChild(button);
                on(button, 'click', function() {
                    li.setAttribute('data-a-mano', button.getAttribute('aria-pressed') === 'true' ? 'no' : 'si');
                    update();
                });
            });
            var help = one('[data-pr-ayuda]', box);
            if (help) {
                help.hidden = false;
            }
            on(area, 'input', update);
            update();
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
        // A family that asked not to be written to: the answer and its button wait until a person says to write anyway
        // (never a block: a warning); a call written down is never held.
        all('[data-sin-mensajes]', root).forEach(function(box) {
            var form = box.form;
            var area = one('textarea[name="body"]', form);
            var send = one('button[name="action"][value="send"]', form);
            var hold = function() {
                var way = one('input[name="channel"]:checked', form);
                var held = !box.checked && (!way || way.value !== 'phone');
                if (area) {
                    area.disabled = held;
                }
                if (send) {
                    send.disabled = held;
                }
            };
            on(box, 'change', hold);
            all('input[name="channel"]', form).forEach(function(radio) {
                on(radio, 'change', hold);
            });
            hold();
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
            all('.nombres', r).forEach(function(a) {
                a.removeAttribute('aria-current');
            });
        });
        openRow = row;
        if (row) {
            row.classList.add('abierta');
            // Which one is open, for a reader too.
            all('.nombres', row).forEach(function(a) {
                a.setAttribute('aria-current', 'true');
            });
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
        // The line under it says who waits longest now: asked to the server, as the page would write it.
        var subline = one('[data-sub]');
        if (subline && inbox && inbox.getAttribute('data-comprobar')) {
            ask(inbox.getAttribute('data-comprobar')).then(function(r) {
                return r.json();
            }).then(function(data) {
                if (typeof data.sub === 'string') {
                    subline.textContent = data.sub;
                }
                return data;
            }).catch(function() {
                return null;
            });
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
                    // And the line under it («The one waiting longest since…»), which may have changed with the list.
                    var sub = one('[data-sub]', page);
                    var mysub = one('[data-sub]');
                    if (sub && mysub) {
                        mysub.textContent = sub.textContent;
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
        // The course of each child: a select, or its chips (a group of radios).
        all('[data-curso-hijo]', form).forEach(function(select) {
            var slot = one('[data-plazas-mini="' + select.getAttribute('data-curso-hijo') + '"]', form);
            var update = function() {
                var chosen = select.tagName === 'SELECT' ? select : one('input:checked', select);
                var source = one('[data-plazas-de="' + (chosen ? chosen.value : '') + '"]', form);
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
            // Items that are buttons make their list a group of buttons: a list holds only its items.
            if (stop.parentNode && /^(OL|UL)$/.test(stop.parentNode.tagName)) {
                stop.parentNode.setAttribute('role', 'group');
            }
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
            // A span names itself only with a role: each week is a picture of its numbers.
            c.setAttribute('role', 'img');
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
                // What is hidden from screen readers (its table says the same) takes no focus either: only the mouse.
                if (!mark.hasAttribute('tabindex') && !mark.closest('[aria-hidden="true"]')) {
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
            if (row.parentNode && /^(OL|UL)$/.test(row.parentNode.tagName)) {
                row.parentNode.setAttribute('role', 'group');
            }
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

    // «Quién entra y quién sale». Every number of the sheet is a button (.an-toque, data-explica
    // = the id of its definition in «Cómo se cuenta cada cifra»): pointed at, it fills the box of its sheet while the
    // pointer stays; touched or focused, it stays. Pointing at a shelf or a part lights its backpacks. The switch «Con o
    // sin los que ya eran del centro» only swaps the class .sin-centro, the pill and the address: both versions are in
    // the page.
    var countSwitch = false;
    var initNewStudents = function(root) {
        var sheet = one('[data-alumnos-nuevos]', root);
        if (!sheet || sheet.eguaheListo) {
            return;
        }
        sheet.eguaheListo = true;
        var fill = function(box, id) {
            var def = id && doc.getElementById(id);
            if (!box.eguaheReposo) {
                box.eguaheReposo = box.innerHTML;
            }
            if (!def) {
                box.innerHTML = box.eguaheReposo;
                box.classList.remove('dato-vivo');
                return;
            }
            box.innerHTML = '<p class="an-explica-t">' + def.querySelector('dt').innerHTML + '</p>' +
                def.querySelector('dd').innerHTML;
            box.classList.add('dato-vivo');
        };
        var light = function(section, button) {
            all('.senalado, .senalada', section).forEach(function(m) {
                m.classList.remove('senalado', 'senalada');
            });
            section.classList.remove('con-senal');
            section.removeAttribute('data-marca');
            if (!button) {
                return;
            }
            button.classList.add('senalado');
            var shelf = button.closest('.an-balda');
            if (shelf) {
                section.classList.add('con-senal');
                shelf.classList.add('senalada');
                var kind = button.getAttribute('data-marca');
                if (button.classList.contains('an-parte')) {
                    section.setAttribute('data-marca', kind);
                    all('.an-mochilas .m-' + kind, shelf).forEach(function(m) {
                        m.classList.add('senalada');
                    });
                }
            }
        };
        var show = function(button, keep) {
            var section = button.closest('.lamina');
            var box = section && one('[data-explica-caja]', section);
            if (!box) {
                return;
            }
            if (keep) {
                section.eguaheElegido = button;
            }
            fill(box, button.getAttribute('data-explica'));
            light(section, button);
        };
        var back = function(section) {
            var chosen = section.eguaheElegido;
            var box = one('[data-explica-caja]', section);
            if (!box) {
                return;
            }
            fill(box, chosen ? chosen.getAttribute('data-explica') : null);
            light(section, chosen || null);
        };
        all('.an-toque', sheet).forEach(function(button) {
            on(button, 'click', function() {
                show(button, true);
            });
            on(button, 'focus', function() {
                show(button, true);
            });
            on(button, 'mouseenter', function() {
                if (fine.matches) {
                    show(button, false);
                }
            });
            on(button, 'mouseleave', function() {
                if (fine.matches) {
                    back(button.closest('.lamina'));
                }
            });
        });
        all('.an-mochilas', sheet).forEach(function(bags) {
            var button = one('.an-balda-que', bags.parentNode);
            if (!button) {
                return;
            }
            on(bags, 'mouseenter', function() {
                if (fine.matches) {
                    show(button, false);
                }
            });
            on(bags, 'mouseleave', function() {
                if (fine.matches) {
                    back(button.closest('.lamina'));
                }
            });
            on(bags, 'click', function() {
                show(button, true);
            });
        });
        if (countSwitch) {
            return;
        }
        countSwitch = true;
        on(doc, 'click', function(e) {
            var link = e.target.closest && e.target.closest('a[data-cuenta]');
            var current = one('[data-alumnos-nuevos]');
            if (!link || !current || e.ctrlKey || e.metaKey || e.shiftKey) {
                return;
            }
            e.preventDefault();
            var withcentre = link.getAttribute('data-cuenta') === 'con';
            current.classList.toggle('sin-centro', !withcentre);
            all('a[data-cuenta]').forEach(function(pill) {
                if ((pill.getAttribute('data-cuenta') === 'con') === withcentre) {
                    pill.setAttribute('aria-current', 'true');
                } else {
                    pill.removeAttribute('aria-current');
                }
            });
            if (window.history && window.history.replaceState) {
                window.history.replaceState(window.history.state, '', link.href);
            }
            all('.lamina', current).forEach(function(section) {
                if (section.eguaheElegido) {
                    back(section);
                }
            });
        });
    };

    // The hours of the team of a campaign or an act: − and + on each line the centre writes, a task added from its chip,
    // and what an hour is worth, one for the whole centre. Each gesture saves itself, and the hours, the money and «por
    // alumno nuevo» above are worked out again.
    var initHours = function(root) {
        var lang = doc.documentElement.lang || 'es';
        var number = function(n, digits) {
            try {
                return n.toLocaleString(lang, {maximumFractionDigits: digits});
            } catch (e) {
                return String(n);
            }
        };
        var hours = function(h) {
            return say('panel_js_hours_n', number(Math.round(h * 10) / 10, 1));
        };
        all('[data-parte-horas]', root).forEach(function(sheet) {
            if (sheet.eguaheListo) {
                return;
            }
            sheet.eguaheListo = true;
            var card = sheet.closest('.an-campana');
            var students = Number(sheet.getAttribute('data-alumnos')) || 0;
            var money = Number(sheet.getAttribute('data-dinero')) || 0;
            var currency = sheet.getAttribute('data-moneda') || '';
            var said = one('[data-guardado]', sheet);
            var valueEl = one('[data-valor-hora]', sheet);
            var euros = function(n) {
                try {
                    return currency ? n.toLocaleString(lang, {style: 'currency', currency: currency, maximumFractionDigits: 0}) :
                        number(Math.round(n), 0);
                } catch (e) {
                    return number(Math.round(n), 0);
                }
            };
            var save = function(fields) {
                var body = new FormData();
                body.append('sesskey', sheet.getAttribute('data-sesskey'));
                Object.keys(fields).forEach(function(k) {
                    body.append(k, fields[k]);
                });
                ask(sheet.getAttribute('data-guardar'), {method: 'POST', body: body}).then(function() {
                    said.textContent = say('panel_js_saved');
                    window.clearTimeout(said.eguaheT);
                    said.eguaheT = window.setTimeout(function() {
                        said.textContent = '';
                    }, 1600);
                }).catch(function() {
                    said.textContent = '';
                });
            };
            var sum = function() {
                var h = 0;
                all('[data-horas]', sheet).forEach(function(row) {
                    h += Number(row.getAttribute('data-horas')) || 0;
                });
                var value = Number(valueEl.getAttribute('data-valor-hora')) || 0;
                one('[data-horas-total]', card).textContent = hours(h);
                // Without a value of the hour, nothing here: the note goes once, above all the campaigns.
                one('[data-horas-dinero]', card).textContent = value ? say('panel_js_hours_money',
                    {money: euros(h * value), value: euros(value)}) : '';
                if (students) {
                    one('[data-horas-alumno]', card).textContent = money ? say('panel_js_hours_each',
                        {money: euros(money / students), hours: hours(h / students)}) :
                        say('panel_js_hours_each_nomoney', hours(h / students));
                    one('[data-horas-alumno-dinero]', card).textContent = value ?
                        say('panel_js_hours_with', euros((money + h * value) / students)) : '';
                }
            };
            sheet.eguaheSuma = sum;
            on(sheet, 'click', function(e) {
                var step = e.target.closest('[data-paso]');
                if (step) {
                    var row = step.closest('[data-horas]');
                    var h = Math.max(0, (Number(row.getAttribute('data-horas')) || 0) + Number(step.getAttribute('data-paso')));
                    row.setAttribute('data-horas', h);
                    one('b', row).textContent = hours(h);
                    sum();
                    save({action: 'hours', scope: sheet.getAttribute('data-ambito'), id: sheet.getAttribute('data-id'),
                        task: row.getAttribute('data-tarea'), hours: h});
                    return;
                }
                var task = e.target.closest('[data-tarea]');
                if (task && task.classList.contains('an-tarea')) {
                    var key = task.getAttribute('data-tarea');
                    var name = task.getAttribute('data-nombre') || key;
                    var li = doc.createElement('li');
                    li.className = 'an-hora';
                    li.setAttribute('data-horas', '1');
                    li.setAttribute('data-tarea', key);
                    li.innerHTML = '<span class="an-hora-que"></span><span class="an-paso"><button type="button" ' +
                        'class="an-menos-h" data-paso="-1">−</button><b aria-live="polite"></b><button type="button" ' +
                        'class="an-mas-h" data-paso="1">+</button></span>';
                    one('.an-hora-que', li).textContent = name;
                    one('b', li).textContent = hours(1);
                    one('[data-paso="-1"]', li).setAttribute('aria-label', say('panel_js_hours_less', name));
                    one('[data-paso="1"]', li).setAttribute('aria-label', say('panel_js_hours_more', name));
                    one('[data-horas-lista]', sheet).appendChild(li);
                    task.remove();
                    sum();
                    save({action: 'hours', scope: sheet.getAttribute('data-ambito'), id: sheet.getAttribute('data-id'),
                        task: key, hours: 1});
                    one('[data-paso="1"]', li).focus();
                    return;
                }
                var v = e.target.closest('[data-valor]');
                if (v) {
                    var value = Math.max(0, (Number(valueEl.getAttribute('data-valor-hora')) || 0) +
                        Number(v.getAttribute('data-valor')));
                    all('[data-valor-hora]').forEach(function(el) {
                        el.setAttribute('data-valor-hora', value);
                        el.textContent = value ? euros(value) : say('panel_js_hours_value_none');
                    });
                    all('[data-parte-horas]').forEach(function(other) {
                        if (other.eguaheSuma) {
                            other.eguaheSuma();
                        }
                    });
                    save({action: 'hourvalue', value: value});
                }
            });
        });
    };

    if (numbers) {
        keyOfRail(numbers);
        initCharts(numbers);
        initNewStudents(numbers);
        initHours(numbers);
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
                initNewStudents(cuerpoCifras());
                initHours(cuerpoCifras());
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
            var otherOnly = one('.solo-otra', eventForm);
            if (otherOnly) {
                otherOnly.hidden = !chosen || chosen.value !== 'other';
            }
            // The name of an open day is proposed while nobody has written another.
            var actName = one('#acto-nombre', eventForm);
            if (actName && chosen && actName.getAttribute('data-propuesto') === actName.value) {
                actName.value = chosen.getAttribute('data-sugerido') || '';
                actName.setAttribute('data-propuesto', actName.value);
                setMuestra('nombre', actName.value || actName.getAttribute('placeholder') || '');
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
        // The word of the centre, on the band as it is written; the help of what is chosen, under the chips.
        var palabra = one('#acto-palabra', eventForm);
        var ayudaTipo = one('[data-ayuda-tipo]', eventForm);
        var dicho = function() {
            var chosen = one('input[name="kind"]:checked', eventForm);
            if (chosen) {
                setMuestra('tipo', (chosen.value === 'other' && palabra && palabra.value.trim()) || chosen.getAttribute('data-vivo-tipo'));
                if (ayudaTipo) {
                    ayudaTipo.textContent = chosen.getAttribute('data-ayuda') || '';
                }
            }
        };
        if (palabra) {
            on(palabra, 'input', dicho);
        }
        on(eventForm, 'change', dicho);
        dicho();
        // A picture, seen as soon as it is chosen: read here, nothing leaves until it is saved.
        var leer = function(file, then) {
            if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) || !window.FileReader) {
                return;
            }
            var reader = new FileReader();
            reader.onload = function() {
                then(reader.result);
            };
            reader.readAsDataURL(file);
        };
        var hueco = one('[data-cartel]', eventForm);
        if (hueco) {
            var cartel = one('input[type="file"]', hueco);
            var imagen = one('[data-cartel-imagen]', hueco);
            if (!imagen) {
                imagen = doc.createElement('img');
                imagen.alt = '';
                imagen.hidden = true;
                imagen.setAttribute('data-cartel-imagen', '');
                one('.acto-cartel-boton', hueco).insertBefore(imagen, one('.acto-cartel-di', hueco));
            }
            var altCampo = one('[data-cartel-alt]', hueco);
            var nada = one('[data-cartel-nada]', hueco);
            var quitar = one('[data-cartel-quitar] input', hueco);
            var antes = imagen.getAttribute('src');
            var cartelPinta = function(src) {
                imagen.hidden = !src;
                if (src) {
                    imagen.src = src;
                }
                hueco.classList.toggle('puesto', !!src);
                altCampo.hidden = !src;
            };
            var cartelElegido = function() {
                nada.hidden = !cartel.files.length;
                if (quitar) {
                    quitar.closest('label').hidden = cartel.files.length > 0;
                }
                if (cartel.files.length) {
                    leer(cartel.files[0], cartelPinta);
                } else {
                    cartelPinta(quitar && quitar.checked ? '' : antes);
                }
            };
            hueco.classList.add('con-guion');
            altCampo.hidden = !antes;
            on(cartel, 'change', cartelElegido);
            on(nada, 'click', function() {
                cartel.value = '';
                cartelElegido();
                cartel.focus();
            });
            if (quitar) {
                on(quitar, 'change', cartelElegido);
            }
            ['dragover', 'dragleave', 'drop'].forEach(function(type) {
                on(hueco, type, function(e) {
                    e.preventDefault();
                    hueco.classList.toggle('encima', type === 'dragover');
                    if (type === 'drop' && e.dataTransfer.files.length) {
                        cartel.files = e.dataTransfer.files;
                        cartelElegido();
                    }
                });
            });
        }
        // Who gives it: one badge more, up to four; one taken out goes with what it has.
        var solapas = one('[data-solapas]', eventForm);
        if (solapas) {
            var otra = one('[data-solapa-mas]', eventForm);
            var molde = one('[data-solapa-nueva]', solapas);
            var SOLAPAS = 4;
            var moldeHtml = molde ? molde.outerHTML : '';
            var moldeN = molde ? parseInt(molde.getAttribute('data-solapa-nueva'), 10) : 0;
            var siguiente = moldeN + 1;
            // With someone already, the empty one waits behind «Another person».
            if (molde && all('[data-solapa]', solapas).length > 1) {
                solapas.removeChild(molde);
            }
            var cuentaSolapas = function() {
                var list = all('[data-solapa]', solapas);
                list.forEach(function(s) {
                    one('[data-solapa-quita]', s).hidden = !one('input[name^="speakername"]', s).value && list.length < 2;
                });
                otra.hidden = !moldeHtml || list.length >= SOLAPAS;
            };
            on(solapas, 'change', function(e) {
                if (e.target.type === 'file') {
                    var tag = one('.solapa-cara label', e.target.closest('[data-solapa]'));
                    leer(e.target.files[0], function(src) {
                        var face = one('[data-cara]', tag) || tag.insertBefore(doc.createElement('img'), tag.firstChild);
                        face.alt = '';
                        face.setAttribute('data-cara', '');
                        face.src = src;
                    });
                }
                cuentaSolapas();
            });
            on(solapas, 'input', cuentaSolapas);
            on(solapas, 'click', function(e) {
                var out = e.target.closest('[data-solapa-quita]');
                if (!out) {
                    return;
                }
                var one1 = out.closest('[data-solapa]');
                one1.parentNode.removeChild(one1);
                if (!all('[data-solapa]', solapas).length) {
                    otra.hidden = false;
                }
                cuentaSolapas();
                (otra.hidden ? one('input[name^="speakername"]', solapas) : otra).focus();
            });
            on(otra, 'click', function() {
                var holder = doc.createElement('div');
                // The number of the mould, in its names and its ids, by the next one.
                holder.innerHTML = moldeHtml.replace(new RegExp('\\[' + moldeN + '\\]', 'g'), '[' + siguiente + ']')
                    .replace(new RegExp('(pon-[fnq]-)' + moldeN + '"', 'g'), '$1' + siguiente + '"');
                siguiente++;
                var row = holder.firstElementChild;
                solapas.appendChild(row);
                cuentaSolapas();
                one('input[name^="speakername"]', row).focus();
            });
            cuentaSolapas();
        }
        // What grows as it is written; the name, in as many lines as it takes, is one: Enter goes on to the next field.
        all('[data-crece]', eventForm).forEach(function(area) {
            var grow = function() {
                area.style.height = 'auto';
                area.style.height = area.scrollHeight + (area.offsetHeight - area.clientHeight) + 'px';
            };
            var oneLine = area.hasAttribute('data-una-linea');
            on(area, 'input', function() {
                if (oneLine && /\n/.test(area.value)) {
                    area.value = area.value.replace(/\s*\n\s*/g, ' ');
                }
                grow();
            });
            if (oneLine) {
                on(area, 'keydown', function(e) {
                    if (e.key === 'Enter' && !e.isComposing) {
                        e.preventDefault();
                    }
                });
            }
            on(eventForm, 'change', grow);
            on(window, 'resize', grow);
            grow();
        });
        // What each folded part holds, said again when it changes.
        var sumas = one('[data-sumas]', eventForm);
        if (sumas) {
            var st = JSON.parse(sumas.getAttribute('data-sumas') || '{}');
            var pon = function(key, text) {
                var el = one('[data-plegado="' + key + '"] [data-suma]', sumas);
                if (el && text !== undefined) {
                    el.textContent = text;
                }
            };
            var lleva = function(text, value) {
                return (text || '').split('{x}').join(value);
            };
            var elegido = function(id) {
                var s = one(id, sumas);
                return s && s.value !== '0' ? s.options[s.selectedIndex].text : '';
            };
            var sumar = function() {
                var n = all('[data-turno-fila]', sumas).filter(function(r) {
                    return one('[data-hora]', r).value;
                }).length;
                pon('turns', n ? (n === 1 ? st.turn : lleva(st.turns, n)) : st.noturns);
                var libre = one('input[name="free"]:checked', eventForm);
                var cap = parseInt(one('#acto-aforo', sumas).value, 10) || 0;
                pon('fit', libre && libre.value === '1' ? st.free : (cap ? lleva(st.limit, cap) : st.nolimit) + ' ' +
                    (one('input[name="open"]', sumas).checked ? st.open : st.closed));
                var names = all('[data-vivo-anfitrion]:checked', sumas).map(function(c) {
                    return c.getAttribute('data-vivo-anfitrion');
                });
                var list = names.length > 1 ? say('panel_js_and', {list: names.slice(0, -1).join(', '), last: names[names.length - 1]}) : names[0];
                pon('hosts', names.length ? lleva(st.hosts, list) : st.nohosts);
                var park = one('input[name="parking"]', sumas);
                pon('parking', park && park.checked ? st.parkon : st.parkoff);
                pon('asks', elegido('#acto-form') ? lleva(st.asksform, elegido('#acto-form')) : st.asks);
                pon('after', elegido('#acto-encuesta') ? lleva(st.aftersurvey, elegido('#acto-encuesta')) : st.after);
            };
            on(eventForm, 'change', sumar);
            // What is wrong is never behind a fold.
            on(eventForm, 'invalid', function(e) {
                var fold = e.target.closest('details');
                if (fold) {
                    fold.open = true;
                }
            }, true);
        }
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
    // The pots of the brand and its codes, one and the same: a pot chosen writes its code under it and in the field,
    // «+» brings the next pot and opens its colour, «×» takes one away; codes pasted colour the pots. The stripes of the
    // samples follow.
    var codes = one('[data-marca-codigos]');
    if (codes) {
        var slots = all('[data-marca-puesto]');
        var adder = one('[data-marca-anadir]');
        var potOf = function(slot) {
            return one('[data-marca-bote]', slot);
        };
        var stripes = function(list) {
            all('.franja-muestra').forEach(function(stripe) {
                stripe.innerHTML = '';
                list.forEach(function(code) {
                    var i = doc.createElement('i');
                    i.style.background = code;
                    stripe.appendChild(i);
                });
                stripe.hidden = list.length < 2;
            });
        };
        var fromPots = function() {
            var list = slots.filter(function(slot) {
                return !slot.classList.contains('vacio');
            }).map(function(slot) {
                return potOf(slot).value;
            });
            codes.value = list.join(' ');
            stripes(list);
            if (adder) {
                adder.hidden = list.length >= slots.length;
            }
        };
        var show = function(slot, code) {
            slot.classList.toggle('vacio', !code);
            if (code) {
                potOf(slot).value = code;
                potOf(slot).setAttribute('data-puesto', '1');
            } else {
                potOf(slot).removeAttribute('data-puesto');
            }
            one('[data-marca-codigo]', slot).textContent = code ? code.toUpperCase() : '';
        };
        slots.forEach(function(slot) {
            on(potOf(slot), 'input', function() {
                show(slot, potOf(slot).value);
                fromPots();
            });
            on(one('[data-marca-quitar]', slot), 'click', function() {
                show(slot, '');
                // The pots left close up, in their order.
                var left = slots.filter(function(s) {
                    return !s.classList.contains('vacio');
                }).map(function(s) {
                    return potOf(s).value;
                });
                slots.forEach(function(s, k) {
                    show(s, left[k] || '');
                });
                fromPots();
                (adder && !adder.hidden ? adder : codes).focus();
            });
        });
        if (adder) {
            on(adder, 'click', function() {
                var next = slots.filter(function(slot) {
                    return slot.classList.contains('vacio');
                })[0];
                if (!next) {
                    return;
                }
                var start = (one('[data-color-centro]') || {}).value || '#1f5a4e';
                show(next, start);
                fromPots();
                try {
                    potOf(next).focus();
                    potOf(next).click();
                } catch (e) {
                    // A browser that does not open the colour from a script: the pot is there to touch.
                }
            });
        }
        on(codes, 'input', function() {
            var found = codes.value.match(/#[0-9a-fA-F]{6}\b/g) || [];
            slots.forEach(function(slot, k) {
                show(slot, k < found.length ? found[k].toLowerCase() : '');
            });
            stripes(found.slice(0, slots.length));
            if (adder) {
                adder.hidden = found.length >= slots.length;
            }
        });
        doc.documentElement.classList.add('con-botes');
        if (adder) {
            adder.hidden = slots.every(function(slot) {
                return !slot.classList.contains('vacio');
            });
        }
    }

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
            // Two lines on the band, one line of text: Enter never breaks it.
            on(nameIn, 'keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });
            on(nameIn, 'input', function() {
                all('[data-muestra-centro]').forEach(function(el) {
                    el.textContent = nameIn.value;
                });
            });
        }
    }

    // ---- The index of the settings marks the part one is reading.
    var settingsIndex = one('.indice-ajustes');
    if (settingsIndex && 'IntersectionObserver' in window) {
        var indexLinks = all('a[href^="#"]', settingsIndex);
        var parts = indexLinks.map(function(a) {
            return document.getElementById(a.getAttribute('href').slice(1));
        });
        var mark = function(id) {
            indexLinks.forEach(function(a) {
                if (a.getAttribute('href') === '#' + id) {
                    a.setAttribute('aria-current', 'location');
                } else {
                    a.removeAttribute('aria-current');
                }
            });
        };
        var showing = {};
        var reading = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                showing[entry.target.id] = entry.isIntersecting;
            });
            // The first part, in the order of the page, that is on the screen.
            var first = parts.filter(function(part) {
                return part && showing[part.id];
            })[0];
            if (first) {
                mark(first.id);
            }
        }, {rootMargin: '-15% 0px -60% 0px'});
        parts.forEach(function(part) {
            if (part) {
                reading.observe(part);
            }
        });
    }

    // ---- A link to something inside a folded part (the split of a family, inside «Their page and their data»)
    // opens every fold around it.
    var openFolds = function() {
        var id = window.location.hash.slice(1);
        var target = id ? document.getElementById(id) : null;
        if (!target || !target.closest('details:not([open])')) {
            return;
        }
        for (var el = target; el; el = el.parentElement) {
            if (el.tagName === 'DETAILS') {
                el.open = true;
            }
        }
        target.scrollIntoView();
    };
    openFolds();
    on(window, 'hashchange', openFolds);

    // ---- «Today, also» of the inbox, folded on a phone to one line with its count: the list comes first.
    var today = one('[data-hoy]');
    if (today && window.matchMedia && window.matchMedia('(max-width: 700px)').matches) {
        today.open = false;
    }

    // ---- The settings of the centre: a photo, a logo or a choice in a select saves itself as soon as it is chosen,
    // like every other gesture of the page, with the «Save» of its card (so it comes back to that card). Without
    // JavaScript, that «Save» is there to press.
    var settingsForm = one('[data-ajustes]');
    // A link to a folded card of the settings opens it, whether it points at the card or at something inside it.
    var openTarget = function() {
        var id = window.location.hash.slice(1);
        var target = id ? document.getElementById(id) : null;
        var card = target && target.closest('details.aj-plegado');
        if (card && !card.open) {
            card.open = true;
            target.scrollIntoView();
        }
    };
    if (settingsForm) {
        openTarget();
        on(window, 'hashchange', openTarget);
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

    // ---- «How is it done?»: the sheet of the bar answers while someone types, from the same page as the guide
    // (howto.php?ajax=1). Enter goes to the first answer; sent with nothing found, the question is kept to improve the
    // guide. Without the script, the button is a link to the guide.
    var howto = one('#como');
    var howtoOpeners = all('[data-como]');
    if (howto && howtoOpeners.length && window.fetch) {
        var howtoForm = one('[data-como-form]', howto);
        var howtoField = one('#como-q', howto);
        var howtoOut = one('[data-como-respuestas]', howto);
        var howtoTimer = null;
        var howtoAsked = 0;
        var howtoFirst = null;
        var howtoBack = null;
        var howtoDraw = function(data) {
            howtoOut.innerHTML = '';
            howtoFirst = null;
            if (data.answers && data.answers.length) {
                var list = doc.createElement('ul');
                list.className = 'como-lista';
                // Each answer, a slip of the desk as panel_howto_answer draws it: one link, its stub past the perforation.
                var span = function(parent, className, text) {
                    var el = doc.createElement('span');
                    el.className = className;
                    if (text) {
                        el.textContent = text;
                    }
                    parent.appendChild(el);
                    return el;
                };
                data.answers.forEach(function(a) {
                    var item = doc.createElement('li');
                    var slip = doc.createElement('a');
                    slip.className = 'como-r' + (a.setting ? ' como-ajuste' : '');
                    slip.href = a.url;
                    item.appendChild(slip);
                    span(slip, 'como-tipo', a.kind);
                    span(slip, 'como-titulo', a.text);
                    span(slip, 'como-sub' + (a.wait ? ' como-espera' : ''), a.sub);
                    if (a.value) {
                        var value = span(slip, 'como-valor');
                        span(value, 'como-valor-l', say('panel_howto_now'));
                        value.appendChild(doc.createTextNode(' '));
                        span(value, 'como-valor-v', a.value);
                    }
                    var stub = span(slip, 'como-talon', a.go);
                    span(stub, 'como-flecha').setAttribute('aria-hidden', 'true');
                    // Its guided help, beside the slip: a link cannot hold another.
                    if (a.help) {
                        var see = doc.createElement('a');
                        see.className = 'como-ver';
                        see.href = a.help;
                        see.textContent = say('help_see');
                        item.appendChild(see);
                    }
                    list.appendChild(item);
                    howtoFirst = howtoFirst || a.url;
                });
                howtoOut.appendChild(list);
            } else if (data.none) {
                var none = doc.createElement('p');
                none.className = 'como-nada';
                none.textContent = data.none;
                howtoOut.appendChild(none);
            }
            // Nothing found and the centre's AI on: a person asks it, never the typing; it answers from the guide only.
            if (data.ask && data.ask.url) {
                var askBox = doc.createElement('p');
                askBox.className = 'como-ia-pedir';
                var askButton = doc.createElement('button');
                askButton.type = 'button';
                askButton.className = 'boton secundario';
                askButton.textContent = data.ask.label;
                askBox.appendChild(askButton);
                howtoOut.appendChild(askBox);
                on(askButton, 'click', function() {
                    askButton.disabled = true;
                    askButton.textContent = say('panel_js_howto_wait');
                    var n = howtoAsked;
                    fetch(data.ask.url + '&ajax=1', {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                        .then(function(r) {
                            return r.ok ? r.json() : null;
                        }).then(function(ai) {
                            if (n !== howtoAsked) {
                                return;
                            }
                            askBox.remove();
                            var box = doc.createElement('div');
                            box.className = 'como-ia';
                            var text = doc.createElement('p');
                            text.className = ai && ai.aitext ? 'como-ia-texto' : 'como-nada';
                            text.textContent = ai ? (ai.aitext || ai.none) : '';
                            box.appendChild(text);
                            if (ai && ai.aitext) {
                                var by = doc.createElement('p');
                                by.className = 'como-ia-por';
                                by.textContent = ai.by;
                                box.appendChild(by);
                            }
                            howtoOut.appendChild(box);
                            if (ai && ai.answers && ai.answers.length) {
                                var keep = howtoOut.innerHTML;
                                howtoDraw({answers: ai.answers});
                                howtoOut.insertAdjacentHTML('afterbegin', keep);
                            }
                        }).catch(function() {
                            askButton.disabled = false;
                        });
                });
            }
        };
        var howtoAsk = function(sent) {
            var q = howtoField.value.trim();
            var n = ++howtoAsked;
            if (!q) {
                howtoOut.innerHTML = '';
                howtoFirst = null;
                return;
            }
            var url = howtoForm.getAttribute('action') + (howtoForm.getAttribute('action').indexOf('?') === -1 ? '?' : '&') +
                'ajax=1&q=' + encodeURIComponent(q) + (sent ? '&sent=1' : '');
            fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(function(r) {
                return r.ok ? r.json() : null;
            }).then(function(data) {
                // An answer that comes late (an older question) is not drawn over a newer one.
                if (data && n === howtoAsked) {
                    howtoDraw(data);
                }
            }).catch(function() {
                return null;
            });
        };
        var howtoClose = function() {
            howto.hidden = true;
            if (howtoBack) {
                howtoBack.focus();
            }
        };
        howtoOpeners.forEach(function(opener) {
            on(opener, 'click', function(e) {
                e.preventDefault();
                howtoBack = opener;
                var more = opener.closest('details');
                if (more) {
                    more.open = false;
                }
                howto.hidden = false;
                howtoField.focus();
                howtoField.select();
            });
        });
        var howtoHere = one('[data-como-aqui]', howto);
        on(howtoField, 'input', function() {
            // Before anything is typed, the most common in this section; then, what is found.
            if (howtoHere) {
                howtoHere.hidden = howtoField.value.trim() !== '';
            }
            window.clearTimeout(howtoTimer);
            howtoTimer = window.setTimeout(function() {
                howtoAsk(false);
            }, 220);
        });
        on(howtoForm, 'submit', function(e) {
            e.preventDefault();
            if (howtoFirst) {
                window.location.href = howtoFirst;
            } else {
                window.clearTimeout(howtoTimer);
                howtoAsk(true);
            }
        });
        on(one('[data-cerrar-como]', howto), 'click', howtoClose);
        on(howto, 'click', function(e) {
            if (e.target === howto) {
                howtoClose();
            }
        });
        on(howto, 'keydown', function(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                howtoClose();
            }
        });
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
            var area = one('[data-redactor] textarea[name="body"]', scope);
            if (area) {
                area.focus();
            }
        } else {
            target.click();
        }
    });

    // How they heard of the centre: on a network, which one; the field only when it is a network, or already written.
    all('[data-cual-red]').forEach(function(select) {
        var form = select.form || select.closest('form') || doc;
        var which = one('.cual-red', form);
        if (!which) {
            return;
        }
        var shown = function() {
            var input = one('input', which);
            // A select, or its chips (a group of radios).
            var chosen = select.tagName === 'SELECT' ? select : one('input:checked', select);
            which.hidden = (chosen ? chosen.value : '') !== select.getAttribute('data-cual-red') && !(input && input.value);
        };
        on(select, 'change', shown);
        shown();
    });

    // A phone, read as phone::normalise reads it and written in groups as phone::display writes it, when it is left; a
    // word under it when it does not look like a whole number. Saving still decides: this only helps to see it.
    var telGroups = function(raw, data) {
        var region = data.region || null;
        var codes = data.codes || [];
        var text = raw.trim();
        if (!text) {
            return '';
        }
        var plus = text.charAt(0) === '+';
        var digits = text.replace(/\D+/g, '');
        var abroad = region && region.international ? new RegExp('^(?:' + region.international + ')') : null;
        var m = !plus && abroad ? digits.match(abroad) : null;
        if (m) {
            plus = true;
            digits = digits.slice(m[0].length);
        } else if (!plus && !region && digits.indexOf('00') === 0) {
            plus = true;
            digits = digits.slice(2);
        }
        if (!plus) {
            if (!region) {
                return null;
            }
            if (region.national && digits.indexOf(region.national) === 0) {
                digits = digits.slice(region.national.length);
            }
            digits = region.code + digits;
        }
        if (digits.length < 8 || digits.length > 15 || digits.charAt(0) === '0') {
            return null;
        }
        var code = '';
        [3, 2, 1].some(function(length) {
            if (codes.indexOf(digits.slice(0, length)) !== -1) {
                code = digits.slice(0, length);
                return true;
            }
            return false;
        });
        var rest = digits.slice(code.length);
        var n = rest.length;
        var groups = [];
        if (n % 3 === 2) {
            groups.push(rest.slice(0, 2));
            rest = rest.slice(2);
        }
        var tail = n % 3 === 1 && rest.length >= 4 ? rest.slice(-4) : '';
        if (tail) {
            rest = rest.slice(0, -4);
        }
        for (var i = 0; i < rest.length; i += 3) {
            groups.push(rest.slice(i, i + 3));
        }
        if (tail) {
            groups.push(tail);
        }
        return '+' + (code ? code + ' ' : '') + groups.join(' ');
    };
    all('input[type="tel"]').forEach(function(input) {
        var holder = input.hasAttribute('data-tel') ? input : input.closest('[data-tel]');
        var data = null;
        try {
            data = holder ? JSON.parse(holder.getAttribute('data-tel')) : null;
        } catch (e) {
            data = null;
        }
        if (!data || !data.codes) {
            return;
        }
        var note = null;
        on(input, 'change', function() {
            var grouped = telGroups(input.value, data);
            if (grouped === null) {
                if (!note) {
                    note = doc.createElement('span');
                    note.className = 'aviso-tel';
                    note.id = (input.id || 'tel') + '-aviso';
                    note.textContent = say('panel_tel_check');
                    input.insertAdjacentElement('afterend', note);
                    input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + note.id).trim());
                }
                return;
            }
            input.value = grouped;
            if (note) {
                input.setAttribute('aria-describedby', (input.getAttribute('aria-describedby') || '').replace(note.id, '').trim());
                note.parentNode.removeChild(note);
                note = null;
            }
        });
    });

    // ---------- The appointments: what is said of one is kept at the touch, and can be taken back; and giving one, with the
    // hour on the ruler of its day. ----------
    (function() {
        // Looking for one in the list, what says them all is not there.
        var seeking = document.querySelector('[data-filtrar=".pasada-fila"]');
        if (seeking) {
            seeking.addEventListener('input', function() {
                seeking.closest('[data-agenda]').classList.toggle('filtrando', Boolean(seeking.value.trim()));
            });
        }
        // The person chosen, in sight on the row that slides.
        var chosen = document.querySelector('.agenda-equipo [aria-current]');
        if (chosen && chosen.parentNode.scrollWidth > chosen.parentNode.clientWidth) {
            chosen.parentNode.scrollLeft += chosen.getBoundingClientRect().left - chosen.parentNode.getBoundingClientRect().left - 40;
        }
        var done = document.querySelector('[data-hecho]');
        var send = function(form, more) {
            var data = new FormData(form);
            Object.keys(more).forEach(function(key) {
                data.set(key, more[key]);
            });
            data.set('fetch', '1');
            return window.fetch(form.getAttribute('action') || window.location.href, {method: 'POST', body: data, credentials: 'same-origin'})
                .then(function(r) {
                    return r.ok ? r.json() : Promise.reject(new Error(r.status));
                }).then(function(said) {
                    return said.ok ? said : Promise.reject(new Error('agenda'));
                });
        };
        var said = {};
        var tell = function(text, key) {
            if (!done) {
                return;
            }
            done.querySelector('[data-hecho-texto]').textContent = text;
            done.querySelector('[name="key"]').value = key || '';
            done.querySelector('button').hidden = !key;
            done.hidden = !text;
        };
        var end = function(left) {
            var fin = document.querySelector('[data-fin]');
            if (fin) {
                fin.hidden = left > 0;
            }
            // How many are still to be said, beside what says them all.
            var bar = document.querySelector('[data-resto]');
            if (bar) {
                bar.hidden = !left;
                bar.querySelector('[data-quedan]').textContent = left === 1 ? bar.getAttribute('data-una') :
                    bar.getAttribute('data-varias').replace('NUM', left);
            }
        };
        document.addEventListener('submit', function(e) {
            var form = e.target;
            if (!window.fetch || !form.matches('[data-marca]') || !e.submitter) {
                return;
            }
            e.preventDefault();
            var button = e.submitter;
            var ids = Array.prototype.map.call(form.querySelectorAll('[name="ids[]"], [name="idlist"]'), function(input) {
                return input.value;
            }).join(',').split(',');
            var more = {};
            more[button.name] = button.value;
            button.disabled = true;
            send(form, more).then(function(r) {
                // Several at once never change what was said of one.
                var rows = ids.map(function(id) {
                    return document.querySelector('[data-cita="' + id + '"]');
                }).filter(function(row) {
                    return row && (form.querySelector('[name="ids[]"]') || !row.classList.contains('dicha'));
                });
                said[r.key] = rows;
                rows.forEach(function(row) {
                    row.classList.add('dicha');
                    Array.prototype.forEach.call(row.querySelectorAll('[data-marca] button'), function(b) {
                        b.disabled = true;
                        if (b.value === button.value) {
                            b.setAttribute('aria-pressed', 'true');
                        }
                    });
                });
                button.disabled = !button.closest('header, [data-resto]');
                tell(r.said, r.key);
                end(r.left);
            }).catch(function() {
                // As always, with its page.
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = button.name;
                hidden.value = button.value;
                form.appendChild(hidden);
                form.submit();
            });
        });
        if (done && window.fetch) {
            done.addEventListener('submit', function(e) {
                var key = done.querySelector('[name="key"]').value;
                if (!said[key]) {
                    return;
                }
                e.preventDefault();
                send(done, {}).then(function(r) {
                    said[key].forEach(function(row) {
                        row.classList.remove('dicha');
                        Array.prototype.forEach.call(row.querySelectorAll('[data-marca] button'), function(b) {
                            b.disabled = false;
                            b.removeAttribute('aria-pressed');
                        });
                    });
                    delete said[key];
                    tell('', '');
                    end(r.left);
                }).catch(function() {
                    done.submit();
                });
            });
        }

        var give = document.querySelector('[data-dar-cita]');
        if (!give) {
            return;
        }
        var days = JSON.parse(give.getAttribute('data-dias') || '{}');
        var range = give.querySelector('[name="min"]');
        var track = give.querySelector('[data-pista]');
        var marks = give.querySelector('[data-marcas]');
        var clash = give.querySelector('[data-choque]');
        var one = function(name) {
            var input = give.querySelector('[name="' + name + '"]:checked');
            return input || null;
        };
        var hour = function(m) {
            return Math.floor(m / 60) + ':' + (m % 60 < 10 ? '0' : '') + m % 60;
        };
        var draw = function() {
            var day = one('day');
            var what = (day && days[day.value]) || {open: [], items: []};
            var long = +((one('minutes') || {}).value || 45);
            var guide = (one('guide') || {}).value || '';
            var open = what.open.length ? what.open : [[480, 1080]];
            var lo = Math.min.apply(null, open.map(function(x) {
                return x[0];
            }).concat(what.items.map(function(x) {
                return x.from;
            })));
            var hi = Math.max.apply(null, open.map(function(x) {
                return x[1];
            }).concat(what.items.map(function(x) {
                return x.to;
            })));
            range.min = lo;
            range.max = hi - 15;
            var at = +range.value;
            var pos = function(m) {
                return Math.round((m - lo) / (hi - 15 - lo) * 1000) / 10;
            };
            track.textContent = '';
            var piece = function(cls, from, to, text) {
                var i = document.createElement('i');
                i.className = cls;
                i.style.setProperty('--de', pos(from) + '%');
                i.style.setProperty('--a', Math.min(100, pos(to)) + '%');
                i.textContent = text || '';
                track.appendChild(i);
            };
            open.forEach(function(x) {
                piece('abierto', x[0], x[1]);
            });
            what.items.forEach(function(x) {
                piece('ocupado' + (String(x.guide) === guide && guide ? ' suyo' : ''), x.from, x.to, x.what);
            });
            piece('puesta', at, at + long);
            marks.textContent = '';
            for (var m = Math.ceil(lo / 60) * 60; m <= hi - 15; m += 60) {
                var span = document.createElement('span');
                span.style.setProperty('--de', pos(m) + '%');
                span.textContent = m / 60;
                marks.appendChild(span);
            }
            var mine = null;
            var other = null;
            what.items.forEach(function(x) {
                if (x.from < at + long && x.to > at) {
                    if (guide && String(x.guide) === guide) {
                        mine = x;
                    } else {
                        other = x;
                    }
                }
            });
            var shut = !open.some(function(x) {
                return at >= x[0] && at + long <= x[1];
            });
            var name = (one('guide') || document.body).getAttribute('data-nombre') || '';
            clash.classList.toggle('no', Boolean(mine || shut));
            clash.textContent = mine ? give.getAttribute('data-ocupada').replace('QUIEN', name) : shut ? give.getAttribute('data-cerrado')
                : other ? give.getAttribute('data-otra') : '';
            give.querySelector('[data-hora-dicha]').textContent = hour(at) + ' – ' + hour(at + long);
            range.setAttribute('aria-valuetext', hour(at) + ' – ' + hour(at + long));
            var told = give.querySelector('[type="checkbox"][name="tell"]');
            var when = give.getAttribute('data-alas').replace('DIA', day ? day.getAttribute('data-cuando') : '').replace('HORA', hour(at));
            give.querySelector('[data-boton-texto]').textContent = give.getAttribute(told && told.checked ? 'data-boton-avisa' : 'data-boton-calla')
                .replace('CUANDO', when);
        };
        give.addEventListener('input', function(e) {
            if (e.target.matches('[data-sitio-otro]') && e.target.value) {
                Array.prototype.forEach.call(give.querySelectorAll('[data-sitio]'), function(chip) {
                    chip.checked = false;
                });
            }
            draw();
        });
        give.addEventListener('change', function(e) {
            if (e.target.matches('[data-sitio]')) {
                give.querySelector('[data-sitio-otro]').value = '';
            }
            if (e.target.name === 'day') {
                // Another day: its first free hour with who receives.
                var what = days[e.target.value];
                var long = +((one('minutes') || {}).value || 45);
                var guide = (one('guide') || {}).value || '';
                var free = what ? what.open.map(function(x) {
                    for (var m = x[0]; m + long <= x[1]; m += 15) {
                        if (m >= 540 && !what.items.some(function(y) {
                            return (!guide || !y.guide || String(y.guide) === guide) && y.from < m + long && y.to > m;
                        })) {
                            return m;
                        }
                    }
                    return 0;
                }).filter(Boolean)[0] : 0;
                range.min = 0;
                range.max = 1440;
                range.value = free || range.value;
            }
            draw();
        });
        [['[data-menos]', -15], ['[data-mas]', 15]].forEach(function(x) {
            var button = give.querySelector(x[0]);
            button.hidden = false;
            button.addEventListener('click', function() {
                range.value = +range.value + x[1];
                draw();
            });
        });
        Array.prototype.forEach.call(give.querySelectorAll('[data-gente]'), function(button) {
            var number = give.querySelector('[name="people"]');
            button.hidden = false;
            button.addEventListener('click', function() {
                number.value = Math.max(1, Math.min(+number.max || 20, (parseInt(number.value, 10) || 1) + (+button.getAttribute('data-gente'))));
            });
        });
        draw();
    })();

    // ---------- The families that may already be answered: the button says how many of them ----------
    Array.prototype.forEach.call(document.querySelectorAll('[data-contestadas]'), function(form) {
        var button = form.querySelector('[data-boton]');
        if (!button) {
            return;
        }
        var count = function() {
            var n = form.querySelectorAll('[name="ids[]"]:checked').length;
            var all = form.querySelectorAll('[name="ids[]"]').length;
            // Those left waiting are not asked again: saying it of all of them is an answer too.
            button.textContent = !n ? form.getAttribute(all === 1 ? 'data-ninguna' : 'data-nada').replace('NUM', all) :
                n === 1 ? form.getAttribute('data-una') : form.getAttribute('data-varias').replace('NUM', n);
        };
        form.addEventListener('change', count);
        window.addEventListener('pageshow', count);
        count();
    });
})();
