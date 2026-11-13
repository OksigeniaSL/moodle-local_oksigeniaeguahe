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
    // (Í, Á, Ñ) too, so it does not climb into the band. The same rule as the label of the mail and of the panel. Where
    // the drawing has room for a second line (data-segunda, its baseline), a name that would get small on one, and has
    // a space, goes on two, broken where the two come out most even: «María de / los Ángeles». Never squeezed.
    var UP = 10;
    var BASELINE = 4.4;
    var SVGNS = 'http://www.w3.org/2000/svg';
    var lines = function(text, parts) {
        while (text.firstChild) {
            text.removeChild(text.firstChild);
        }
        if (parts.length === 1) {
            text.appendChild(document.createTextNode(parts[0]));
            return;
        }
        parts.forEach(function(part, i) {
            var span = document.createElementNS(SVGNS, 'tspan');
            span.setAttribute('x', text.getAttribute('x') || 0);
            span.setAttribute('y', i ? text.getAttribute('data-segunda') : BASELINE);
            span.textContent = part;
            text.appendChild(span);
        });
    };
    // Inside something folded the name is not measured (Chrome can fall over measuring it there): it is, when it opens.
    var folded = function(node) {
        return Boolean(node.closest && node.closest('details:not([open])'));
    };

    var fit = function(scope) {
        all('svg.colgante text.mano, svg.sello-etiqueta text.mano', scope).forEach(function(text) {
            if (folded(text)) {
                return;
            }
            // Only the drawing the svg shows: Chrome measures the hidden ones too, Firefox gives them no length.
            var drawing = text.closest ? text.closest('.mochila') : null;
            if (drawing && window.getComputedStyle(drawing).display === 'none') {
                return;
            }
            // Once on two lines the name is kept whole beside them; written again (the sign-up), it is the text.
            var name = text.querySelector('tspan') ? text.getAttribute('data-nombre') || '' : text.textContent;
            lines(text, [name]);
            text.setAttribute('font-size', SIZE);
            var length = name ? text.getComputedTextLength() : 0;
            if (!length) {
                return;
            }
            var size = function(longest) {
                var k = Math.min(1, ROOM / longest);
                var up = BASELINE - text.getBBox().y;
                return up > UP ? Math.min(k, UP / up) : k;
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
                gaps.forEach(function(gap) {
                    var left = text.getSubStringLength(0, gap);
                    var right = length - text.getSubStringLength(0, gap + 1);
                    if (!best || Math.max(left, right) < best.longest) {
                        best = {at: gap, longest: Math.max(left, right)};
                    }
                });
                text.setAttribute('data-nombre', name);
                lines(text, [name.slice(0, best.at), name.slice(best.at + 1)]);
                // The second line may not climb into the first: its size is held by the gap between them.
                var two = Math.min(size(best.longest), (second - BASELINE) / 1.1 / SIZE);
                if (two > k * 1.12) {
                    k = two;
                } else {
                    lines(text, [name]);
                }
            }
            text.setAttribute('font-size', (SIZE * k).toFixed(2));
        });
    };

    // What the family does on its page, counted and never scored: that it opened it, once the page has been in sight
    // for a moment (the robots that read the links of a mail do not stay); how far down it goes; what it touches. Each
    // part once a visit. Without the script nothing is counted here, and the page is the same.
    var count = function() {
        return;
    };
    var measure = function() {
        var mark = document.querySelector('[data-mide]');
        if (!mark || !window.FormData) {
            return;
        }
        var where = mark.getAttribute('data-mide');
        var key = mark.getAttribute('data-sesskey');
        var from = mark.getAttribute('data-de') || '';
        var opened = false;
        var pending = [];
        count = function(kind, what, via) {
            if (kind !== 'open' && !opened) {
                pending.push([kind, what, via]);
                return;
            }
            var data = new window.FormData();
            data.append('sesskey', key);
            data.append('kind', kind);
            data.append('what', what || '');
            data.append('via', via || '');
            try {
                if (navigator.sendBeacon && navigator.sendBeacon(where, data)) {
                    return;
                }
            } catch (err) {
                // The fetch below.
            }
            if (window.fetch) {
                window.fetch(where, {method: 'POST', body: data, credentials: 'same-origin', keepalive: true})
                    .catch(function() {
                        return;
                    });
            }
        };
        var reached = {};
        var parts = function() {
            if (!window.IntersectionObserver) {
                return;
            }
            var watch = new window.IntersectionObserver(function(entries) {
                entries.forEach(function(en) {
                    var name = en.target.getAttribute('data-parte');
                    var enough = en.intersectionRatio >= 0.3 || en.intersectionRect.height >= window.innerHeight * 0.4;
                    if (en.isIntersecting && enough && !reached[name]) {
                        reached[name] = true;
                        watch.unobserve(en.target);
                        count('part', name);
                    }
                });
            }, {threshold: [0, 0.3, 0.6]});
            all('[data-parte]').forEach(function(part) {
                watch.observe(part);
            });
        };
        var open = function() {
            if (opened || document.visibilityState === 'hidden') {
                return;
            }
            window.setTimeout(function() {
                if (opened || document.visibilityState === 'hidden') {
                    return;
                }
                opened = true;
                count('open', '', from);
                // The address forgets the mail: going back or reloading is not another press.
                if (from && window.history && window.history.replaceState && window.URL) {
                    try {
                        var here = new window.URL(window.location.href);
                        here.searchParams.delete('de');
                        window.history.replaceState(window.history.state, '', here.toString());
                    } catch (err) {
                        // The address stays.
                    }
                }
                pending.splice(0).forEach(function(one) {
                    count(one[0], one[1], one[2]);
                });
                parts();
            }, 1000);
        };
        open();
        document.addEventListener('visibilitychange', open);
        document.addEventListener('click', function(e) {
            var thing = e.target.closest ? e.target.closest('[data-toca]') : null;
            if (thing) {
                count('touch', thing.getAttribute('data-toca'));
            }
        });
        // What the scenes tell: the drawing appearing on the table or under the child.
        document.addEventListener('eguahe:mide', function(e) {
            if (e.detail && e.detail.toca) {
                count('touch', e.detail.toca);
            }
        });
    };

    // The name on the label of the story's card: smaller until it fits the line of the label.
    var fitStory = function() {
        all('svg.etiqueta-cuento text').forEach(function(text) {
            if (folded(text)) {
                return;
            }
            var room = parseFloat(text.getAttribute('data-w')) || 27;
            text.setAttribute('font-size', '13.5');
            var k = text.textContent ? Math.min(1, room / text.getComputedTextLength()) : 1;
            text.setAttribute('font-size', (13.5 * k).toFixed(2));
        });
    };

    // Coming back to something folded under «Also on your page» (after answering in it): opened, and in sight.
    var openFolded = function() {
        var id = window.location.hash ? decodeURIComponent(window.location.hash.slice(1)) : '';
        var target = id ? document.getElementById(id) : null;
        var fold = target && target.closest ? target.closest('details.otra-cosa') : null;
        if (fold && !fold.open) {
            fold.open = true;
            target.scrollIntoView();
        }
    };

    var ready = function() {
        fit();
        fitStory();
        document.addEventListener('toggle', function(e) {
            if (e.target.open) {
                fit(e.target);
                fitStory();
            }
        }, true);
        openFolded();
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(fitStory);
        }
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function() {
                fit();
            });
        }
        measure();

        // What the adults like doing: each one kept as soon as it is touched; without the script, «Save» keeps them all.
        var likes = document.querySelector('[data-aficiones]');
        // The line of each adult says what they marked, as they mark it.
        var sayLikes = function(box) {
            var words = all('input[type="checkbox"]', box).filter(function(input) {
                return input.checked;
            }).map(function(input) {
                return input.parentNode.querySelector('.palabra').textContent.trim();
            });
            var own = box.querySelector('input[type="text"]');
            if (own && own.value.trim()) {
                words.push(own.value.trim().charAt(0).toUpperCase() + own.value.trim().slice(1));
            }
            var said = box.querySelector('[data-lo-dicho]');
            var change = box.querySelector('[data-cambiar]');
            if (said) {
                said.textContent = words.length ? words.join(document.documentElement.dir === 'rtl' ? '، ' : ', ') :
                    likes.getAttribute('data-contar');
                said.classList.toggle('por-contar', !words.length);
            }
            if (change) {
                change.hidden = !words.length;
            }
        };
        if (likes) {
            likes.addEventListener('change', function(e) {
                var box = e.target.closest ? e.target.closest('[data-adulto]') : null;
                if (box) {
                    sayLikes(box);
                }
            });
        }
        if (likes && window.fetch && window.FormData) {
            likes.classList.add('al-tocar');
            var keepLikes = function(box) {
                var data = new window.FormData();
                data.append('sesskey', likes.querySelector('[name="sesskey"]').value);
                data.append('fetch', '1');
                data.append('adult', box.getAttribute('data-adulto'));
                all('input', box).forEach(function(input) {
                    if (input.type !== 'checkbox' || input.checked) {
                        data.append(input.name, input.value);
                    }
                });
                var said = box.querySelector('[data-guardado]');
                window.fetch(likes.getAttribute('action'), {method: 'POST', body: data, credentials: 'same-origin'})
                    .then(function(r) {
                        return r.json();
                    }).then(function(answer) {
                        if (answer.saved && said) {
                            said.textContent = likes.getAttribute('data-guardado-texto');
                            said.classList.remove('dicho');
                            void said.offsetWidth;
                            said.classList.add('dicho');
                        }
                    }).catch(function() {
                        // Without connection the button is back.
                        likes.classList.remove('al-tocar');
                    });
            };
            var later = null;
            likes.addEventListener('change', function(e) {
                var box = e.target.closest ? e.target.closest('[data-adulto]') : null;
                if (box && e.target.type === 'checkbox') {
                    keepLikes(box);
                }
            });
            likes.addEventListener('input', function(e) {
                var box = e.target.closest ? e.target.closest('[data-adulto]') : null;
                if (box && e.target.type === 'text') {
                    sayLikes(box);
                    window.clearTimeout(later);
                    later = window.setTimeout(function() {
                        keepLikes(box);
                    }, 1400);
                }
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

        // Another family of the centre told them: which one, if they want to say, to thank them.
        var told = document.querySelector('[data-quien-cuenta]');
        var who = document.querySelector('.quien-cuenta');
        if (told && who) {
            var tell = function() {
                who.hidden = told.value !== told.getAttribute('data-quien-cuenta') && !who.querySelector('input').value;
            };
            told.addEventListener('change', tell);
            tell();
        }
        // On a network: which one, picked from those of the centre or written.
        var net = document.querySelector('[data-cual-red]');
        var which = document.querySelector('.cual-red');
        if (net && which) {
            var name = function() {
                which.hidden = net.value !== net.getAttribute('data-cual-red') && !which.querySelector('input').value;
            };
            net.addEventListener('change', name);
            name();
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
                var drawing = peg.querySelector('svg.colgante');
                var texts = all('text.mano', peg);
                var before = texts.some(function(text) {
                    return text.textContent !== '';
                });
                // Every object of the drawing carries the name; the one shown is fitted again.
                texts.forEach(function(text) {
                    text.textContent = now;
                });
                if (now && !before && drawing) {
                    drawing.classList.remove('llega');
                    drawing.getBoundingClientRect();
                    drawing.classList.add('llega');
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
            // The course that fits the year of birth (the year it starts less its age), while they have not chosen one
            // themselves; they can always choose another.
            var year = document.getElementById('c' + i + 'birthy');
            var select = document.getElementById('c' + i + 'offer');
            var born = year ? year.value.trim() : '';
            if (select && /^\d{4}$/.test(born) && (select.value === '0' || select.getAttribute('data-auto') === '1')) {
                var fits = Array.prototype.filter.call(select.options, function(option) {
                    return option.getAttribute('data-born') === born;
                });
                if (fits.length === 1) {
                    select.value = fits[0].value;
                    select.setAttribute('data-auto', '1');
                }
            }
            courses();
        };
        document.addEventListener('input', function(e) {
            var i = e.target.getAttribute('data-child');
            if (i !== null) {
                update(i);
            }
        });
        // A course chosen by hand is theirs: the date no longer moves it.
        document.addEventListener('change', function(e) {
            if (/^c\d+offer$/.test(e.target.id || '')) {
                e.target.removeAttribute('data-auto');
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

        // After the visit. Without motion when the person asked for less of it.
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Once they came, the label of each backpack is stuck down; the first time the page is seen in a session, its
        // corner goes down in front of them, as it did at the door.
        var stuck = document.querySelector('.perchas.pegada');
        if (stuck && !calm) {
            try {
                if (!window.sessionStorage.getItem('eguahe-pegada')) {
                    window.sessionStorage.setItem('eguahe-pegada', '1');
                    stuck.classList.add('pegando');
                }
            } catch (e) {
                // A private window: the label is already stuck.
            }
        }

        // What they were left to ask goes with the button saying so, once. With the script, it goes without leaving
        // the page: what was written leaves as a note and the slip that says who has it comes in its place.
        var leave = function(sheet, text) {
            if (calm || !text.value.trim()) {
                return;
            }
            var where = sheet.getBoundingClientRect();
            var box = text.getBoundingClientRect();
            var note = document.createElement('div');
            note.className = 'nota-que-sale';
            note.setAttribute('aria-hidden', 'true');
            note.textContent = text.value;
            note.style.left = (box.left - where.left) + 'px';
            note.style.top = (box.top - where.top) + 'px';
            note.style.width = box.width + 'px';
            note.style.height = box.height + 'px';
            sheet.appendChild(note);
            window.setTimeout(function() {
                if (note.parentNode) {
                    note.parentNode.removeChild(note);
                }
            }, 700);
        };
        all('form[data-preguntar], form[data-encuesta]').forEach(function(sheet) {
            sheet.addEventListener('submit', function(e) {
                var send = sheet.querySelector('button[name="send"]');
                var text = sheet.querySelector('textarea[name="question"]');
                if (!send || !text || !text.value.trim() || (e.submitter && e.submitter !== send)) {
                    return;
                }
                if (send.getAttribute('aria-disabled') === 'true') {
                    e.preventDefault();
                    return;
                }
                send.setAttribute('aria-disabled', 'true');
                var label = send.lastElementChild || send.lastChild;
                var was = label.textContent;
                label.textContent = send.getAttribute('data-sending');
                if (!window.fetch || !window.FormData || !window.DOMParser) {
                    return;
                }
                e.preventDefault();
                var action = sheet.getAttribute('action');
                var data = new window.FormData();
                data.append('sesskey', sheet.querySelector('[name="sesskey"]').value);
                data.append('action', sheet.querySelector('[name="action"]').value);
                data.append('fetch', '1');
                data.append('q', '0');
                data.append('question', text.value);
                var posted = false;
                window.fetch(action, {method: 'POST', body: data, credentials: 'same-origin'}).then(function(r) {
                    return r.json();
                }).then(function(answer) {
                    if (!answer || !answer.saved) {
                        throw new Error('not kept');
                    }
                    posted = true;
                    return window.fetch(action + (action.indexOf('?') === -1 ? '?' : '&') + 'asked=1',
                        {credentials: 'same-origin'}).then(function(r) {
                        return r.text();
                    });
                }).then(function(html) {
                    var fresh = new window.DOMParser().parseFromString(html, 'text/html').getElementById('pregunta');
                    var block = sheet.querySelector('#pregunta');
                    if (!fresh || !block) {
                        throw new Error('no page');
                    }
                    leave(sheet, text);
                    fresh = document.importNode(fresh, true);
                    block.parentNode.replaceChild(fresh, block);
                    var slip = fresh.querySelector('.ya-pasado');
                    if (slip) {
                        if (!calm) {
                            slip.classList.add('llega');
                        }
                        slip.focus();
                    }
                }).catch(function() {
                    // Once it is kept, the page says so as it always did; before that, the form goes as it always did.
                    if (posted) {
                        window.location.href = action + (action.indexOf('?') === -1 ? '?' : '&') + 'asked=1#pregunta';
                        return;
                    }
                    send.removeAttribute('aria-disabled');
                    label.textContent = was;
                    sheet.submit();
                });
            });
        });
        var just = document.querySelector('[data-recien]');
        if (just) {
            just.focus();
        }

        // The survey of the visit: each answer is kept when it is touched, and says so; nothing stays in the
        // browser, which inside WhatsApp may not keep it. A text is kept a moment after they stop writing. When every
        // question with words to choose is answered, the thanks comes with the name used at home and the label is
        // pressed on. Without this, the button keeps them all.
        var survey = document.querySelector('form[data-encuesta]');
        if (survey && window.fetch && window.FormData) {
            survey.classList.add('al-tocar');
            var farewell = survey.querySelector('[data-despedida]');
            var stamps = all('.sellos-etiqueta', survey.parentNode);
            var answered = function(question) {
                return all('input, textarea', question).some(function(input) {
                    return input.type === 'radio' || input.type === 'checkbox' ? input.checked : input.value.trim() !== '';
                });
            };
            var finished = function() {
                var shown = all('[data-q]', survey).filter(function(question) {
                    return !question.hidden;
                });
                var chosen = shown.filter(function(question) {
                    return question.hasAttribute('data-opciones');
                });
                var list = chosen.length ? chosen : shown;
                return list.length > 0 && list.every(answered);
            };
            var goodbye = function() {
                if (!farewell || !farewell.hidden || !finished()) {
                    return;
                }
                farewell.hidden = false;
                if (!calm) {
                    farewell.classList.add('llega');
                    stamps.forEach(function(stamp) {
                        stamp.classList.remove('estampa');
                        void stamp.offsetWidth;
                        stamp.classList.add('estampa');
                    });
                }
            };
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
                var where = survey.getAttribute('action');
                window.fetch(where, {method: 'POST', body: data, credentials: 'same-origin'}).then(function(r) {
                    return r.json();
                }).then(function(answer) {
                    if (!answer.saved) {
                        return;
                    }
                    if (said) {
                        said.textContent = survey.getAttribute('data-guardado-texto');
                        said.classList.remove('dicho');
                        void said.offsetWidth;
                        said.classList.add('dicho');
                    }
                    goodbye();
                }).catch(function() {
                    // Without connection the button is back: it keeps them all when they press it.
                    survey.classList.remove('al-tocar');
                });
            };
            var waiting = null;
            survey.addEventListener('change', function(e) {
                var question = e.target.closest ? e.target.closest('[data-q]') : null;
                if (question && e.target.name !== 'question') {
                    window.clearTimeout(waiting);
                    keep(question);
                }
            });
            survey.addEventListener('input', function(e) {
                var question = e.target.closest ? e.target.closest('[data-q]') : null;
                if (question && (e.target.tagName === 'TEXTAREA' || e.target.type === 'text')) {
                    window.clearTimeout(waiting);
                    waiting = window.setTimeout(function() {
                        keep(question);
                    }, 1400);
                }
            });
        }

        // The drawing of a child, big over the page: from its small one, or from the table of the hall. Touching it,
        // the button or Escape closes it, and the focus goes back where it was.
        var viewer = null, from = null;
        var shut = function() {
            if (viewer) {
                viewer.parentNode.removeChild(viewer);
                viewer = null;
                if (from && from.focus) {
                    from.focus();
                }
            }
        };
        var show = function(src, alt, close, origin) {
            shut();
            from = origin;
            viewer = document.createElement('div');
            viewer.className = 'visor-dibujo';
            viewer.setAttribute('role', 'dialog');
            viewer.setAttribute('aria-modal', 'true');
            viewer.setAttribute('aria-label', alt);
            var img = document.createElement('img');
            img.src = src;
            img.alt = alt;
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = close;
            viewer.appendChild(img);
            viewer.appendChild(button);
            viewer.addEventListener('click', shut);
            document.body.appendChild(viewer);
            button.focus();
        };
        // The drawing under each child draws itself once a visit, when it comes into view, in about 2.5 s (Gaudí);
        // without its strokes, the script or with less motion, the photo.
        var DV = window.DibujoVivo;
        if (DV && !DV.lessMotion() && window.fetch && window.Promise && window.IntersectionObserver) {
            all('a.dibujo-nino[data-trazos]').forEach(function(link) {
                var pic = link.querySelector('img');
                var photo = new Image();
                Promise.all([
                    new Promise(function(ok, ko) {
                        photo.onload = ok;
                        photo.onerror = ko;
                        photo.src = link.getAttribute('href');
                    }),
                    window.fetch(link.getAttribute('data-trazos'), {credentials: 'same-origin'}).then(function(r) {
                        if (!r.ok) {
                            throw new Error(String(r.status));
                        }
                        return r.json();
                    })
                ]).then(function(got) {
                    var a = DV.fromJSON(got[1]);
                    // Shorter than on the table: the same strokes, at 2.5 s.
                    var k = a.seconds > 2.5 ? 2.5 / a.seconds : 1;
                    a.strokes.forEach(function(st) {
                        st.t0 *= k;
                        st.t1 *= k;
                    });
                    a.seconds *= k;
                    var ratio = Math.min(2, window.devicePixelRatio || 1);
                    var canvas = document.createElement('canvas');
                    canvas.width = Math.round(96 * ratio);
                    canvas.height = Math.round(96 * ratio * photo.naturalHeight / photo.naturalWidth);
                    canvas.setAttribute('role', 'img');
                    canvas.setAttribute('aria-label', pic ? pic.alt : '');
                    var player = new DV.Player(canvas, photo, a, {after: 'still'});
                    if (pic && pic.parentNode) {
                        pic.parentNode.replaceChild(canvas, pic);
                    }
                    var key = 'eguahe-mini-' + link.getAttribute('href');
                    var before = false;
                    try {
                        before = window.sessionStorage.getItem(key) === '1';
                    } catch (err) {
                        before = false;
                    }
                    if (before) {
                        player.renderAt(player.total() + 1);
                        return;
                    }
                    player.renderAt(0);
                    var seen = new window.IntersectionObserver(function(entries) {
                        if (!entries.some(function(en) {
                            return en.isIntersecting;
                        })) {
                            return;
                        }
                        seen.disconnect();
                        try {
                            window.sessionStorage.setItem(key, '1');
                        } catch (err) {
                            // Only a convenience.
                        }
                        player.play();
                        count('touch', 'drawing_seen');
                    }, {threshold: 0.8});
                    seen.observe(canvas);
                }).catch(function() {
                    // The photo stays.
                });
            });
        }
        // A drawing sent to someone: big, it draws itself once when it comes into view, at its own pace; without its
        // strokes, the script or with less motion, the photo.
        if (DV && !DV.lessMotion() && window.fetch && window.Promise && window.IntersectionObserver) {
            all('a.dibujo-grande[data-trazos]').forEach(function(link) {
                var pic = link.querySelector('img');
                var photo = new Image();
                Promise.all([
                    new Promise(function(ok, ko) {
                        photo.onload = ok;
                        photo.onerror = ko;
                        photo.src = link.getAttribute('href');
                    }),
                    window.fetch(link.getAttribute('data-trazos'), {credentials: 'same-origin'}).then(function(r) {
                        if (!r.ok) {
                            throw new Error(String(r.status));
                        }
                        return r.json();
                    })
                ]).then(function(got) {
                    var a = DV.fromJSON(got[1]);
                    var ratio = Math.min(2, window.devicePixelRatio || 1);
                    var wide = Math.max(240, Math.round(link.getBoundingClientRect().width || 480));
                    var canvas = document.createElement('canvas');
                    canvas.width = Math.round(wide * ratio);
                    canvas.height = Math.round(wide * ratio * photo.naturalHeight / photo.naturalWidth);
                    canvas.setAttribute('role', 'img');
                    canvas.setAttribute('aria-label', pic ? pic.alt : '');
                    var player = new DV.Player(canvas, photo, a, {after: 'still'});
                    if (pic && pic.parentNode) {
                        pic.parentNode.replaceChild(canvas, pic);
                    }
                    player.renderAt(0);
                    var seen = new window.IntersectionObserver(function(entries) {
                        if (entries.some(function(en) {
                            return en.isIntersecting;
                        })) {
                            seen.disconnect();
                            player.play();
                        }
                    }, {threshold: 0.6});
                    seen.observe(canvas);
                }).catch(function() {
                    // The photo stays.
                });
            });
        }
        // The birthday: the video of the little ones and the card, made here by the art director's pieces (pub/cumple/),
        // seen and then kept or sent in the sheet of the drawing; the seal of the older ones, drawn beside the words.
        var scripts = function(list) {
            return list.reduce(function(chain, src) {
                return chain.then(function() {
                    if (document.querySelector('script[src="' + src + '"]')) {
                        return null;
                    }
                    return new Promise(function(done, failed) {
                        var tag = document.createElement('script');
                        tag.src = src;
                        tag.onload = done;
                        tag.onerror = failed;
                        document.head.appendChild(tag);
                    });
                });
            }, Promise.resolve());
        };
        var wish = function(data) {
            var o = Object.assign({}, data.o);
            if (data.voices) {
                // In Arabic, the reading the family chose in the story, to a boy or to a girl; none chosen, no voice.
                var sex = null;
                try {
                    sex = window.localStorage.getItem(data.key);
                } catch (err) {
                    sex = null;
                }
                o.voice = (sex === 'm' || sex === 'f') && data.voices[sex] ? data.voices[sex] : null;
            }
            return o;
        };
        all('button[data-cumple]').forEach(function(button) {
            var data = JSON.parse(button.getAttribute('data-cumple'));
            if (!window.Promise || !window.DibujoVideo || !document.createElement('canvas').getContext) {
                return;
            }
            button.hidden = false;
            button.addEventListener('click', function() {
                var o = wish(data);
                window.DibujoVideo.sheet(data.sheet, function(progress) {
                    return scripts(data.scripts).then(function() {
                        var made = window.EguaheCumple;
                        if (!made) {
                            return null;
                        }
                        return data.piece === 'video' ? made.make(o, progress) : made.card(o);
                    });
                }, button);
            });
        });
        all('img[data-cumple-sello]').forEach(function(img) {
            var data = JSON.parse(img.getAttribute('data-cumple-sello'));
            if (!window.Promise || !window.URL) {
                return;
            }
            scripts(data.scripts).then(function() {
                return window.EguaheCumple ? window.EguaheCumple.seal(wish(data)) : null;
            }).then(function(blob) {
                if (blob) {
                    img.src = URL.createObjectURL(blob);
                    img.hidden = false;
                }
            }).catch(function() {
                // The words alone.
            });
        });
        // Sending a piece on: the phone's own way to share, else the link copied; counted for the family that shared it.
        // A drawing with its strokes goes as a video to keep (pub/dibujo-video.js), seen before it is sent.
        all('button[data-video]').forEach(function(button) {
            button.hidden = false;
            if (!button.hasAttribute('data-compartir')) {
                button.addEventListener('click', function() {
                    if (window.DibujoVideo) {
                        window.DibujoVideo.open(button);
                    }
                });
            }
        });
        all('[data-compartir]').forEach(function(button) {
            button.addEventListener('click', function() {
                if (button.hasAttribute('data-video') && window.DibujoVideo) {
                    window.DibujoVideo.open(button);
                    return;
                }
                var url = button.getAttribute('data-compartir');
                var told = function() {
                    var note = button.parentNode.querySelector('.compartir-aviso');
                    if (note) {
                        note.textContent = button.getAttribute('data-copiado') || '';
                    }
                };
                var counted = function() {
                    var where = button.getAttribute('data-medir');
                    if (where && navigator.sendBeacon) {
                        try {
                            navigator.sendBeacon(where);
                        } catch (err) {
                            // Only a count.
                        }
                    }
                };
                var data = {title: button.getAttribute('data-titulo') || '', text: button.getAttribute('data-texto') || '',
                    url: url};
                if (navigator.share) {
                    navigator.share(data).then(counted).catch(function() {
                        // Not sent: nothing to count.
                    });
                    return;
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(function() {
                        told();
                        counted();
                    }).catch(function() {
                        window.prompt(data.title, url);
                    });
                    return;
                }
                window.prompt(data.title, url);
            });
        });
        document.addEventListener('click', function(e) {
            var link = e.target.closest ? e.target.closest('[data-ampliar]') : null;
            var onTable = e.target.matches && e.target.matches('.sala .dibujo, .tarjeta-dibujo') ? e.target : null;
            if (!link && !onTable) {
                return;
            }
            e.preventDefault();
            var any = document.querySelector('[data-ampliar]');
            var close = (link || any) ? (link || any).getAttribute('data-ampliar') : '×';
            var img = link ? (link.querySelector('img') || link.querySelector('canvas')) : onTable;
            // A drawing that drew itself is a canvas: big, it is the photo as it is.
            var src = link ? link.getAttribute('href') : (onTable.getAttribute('data-foto') || onTable.src);
            var alt = img ? (img.alt || img.getAttribute('aria-label') || '') : '';
            show(src, alt, close || '×', link || null);
            count('touch', 'drawing_enlarged');
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                shut();
            }
        });

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
