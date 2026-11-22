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
 * Eguahe's scrollbar, the reading rail. On a wide screen it takes the place of the browser's:
 * the wooden rail of the coat rack along the right edge, a knob for each section of the page (its h2 headings) and the
 * little backpack of the brand hanging from it, which comes down with the reading and swings a little as it goes. The
 * whole strip (34 px, more than the 24 px of WCAG 2.2, 2.5.8) is pressed or dragged like a scrollbar; a knob takes you to
 * its section, and says its name on the coral tag while the pointer is on it or while dragging. Screen readers skip it;
 * the keyboard, the wheel and the finger scroll the page as always. Not inside a frame (the practice centre of the
 * guided helps, an embedded page), not on short pages; with «reduce motion», it moves without swinging. Without
 * JavaScript nothing changes. After the ruler of oksigenia.com and the bookmark of Ayvurenda.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
'use strict';
(function() {
    if (window.top !== window.self) {
        return;
    }
    var PAD = 22;
    var NAME_MS = 1200;
    var wide = window.matchMedia('(min-width: 1000px)');
    var still = window.matchMedia('(prefers-reduced-motion: reduce)');
    // The backpack of the brand (hq kit, marca-eguahe/svg/mochila-claro.svg), hanging by its loop.
    var MOCHILA = '<svg class="riel-lectura-mochila" viewBox="0 0 64 64" aria-hidden="true" focusable="false">' +
        '<path d="M28 15.5 L28 11 C28 8.8 29.8 7 32 7 C34.2 7 36 8.8 36 11 L36 15.5 L34.4 15.5 L34.4 11 C34.4 9.7 33.3 8.6 32 ' +
        '8.6 C30.7 8.6 29.6 9.7 29.6 11 L29.6 15.5 Z" fill="#6F5E6E"/><path d="M48 19 C55 23 56.4 40 54.6 62 L52.4 61.8 C54 41 ' +
        '52.6 25.4 46.6 21.6 Z" fill="#6F5E6E"/><path d="M13 30 C13 21 19.5 14 28.5 14 L35.5 14 C44.5 14 51 21 51 30 L51 55.5 C51 ' +
        '58 49 60 46.5 60 L17.5 60 C15 60 13 58 13 55.5 Z" fill="#6F5E6E"/><path d="M16 22 L16 28.5 C16 31.5 18.4 33.6 21.4 33.6 ' +
        'L42.6 33.6 C45.6 33.6 48 31.5 48 28.5 L48 22 L46.2 22 L46.2 28.5 C46.2 30.5 44.6 31.8 42.6 31.8 L21.4 31.8 C19.4 31.8 ' +
        '17.8 30.5 17.8 28.5 L17.8 22 Z" fill="#FBF5EC"/><g transform="translate(32 47) rotate(-8) scale(0.68)"><path d="M-23 ' +
        '-12 Q-23 -16 -19 -16 L19 -16 Q23 -16 23 -12 L23 8 L15 16 L-19 16 Q-23 16 -23 12 Z" fill="#CF6450"/><path d="M-20.6 ' +
        '-6.2 L20.6 -6.2 L20.6 7 L13.8 13.8 L-18.6 13.8 Q-20.6 13.8 -20.6 11.8 Z" fill="#FFFFFF"/><path d="M-15 6.2 L9 6.2 L9 ' +
        '7.9 L-15 7.9 Z" fill="#6F5E6E"/><path d="M23 8 L15 16 L15 8 Z" fill="#E3D2BF"/></g></svg>';

    function start() {
        if (document.querySelector('.riel-lectura') || document.body.classList.contains('incrustado')) {
            return;
        }
        var html = document.documentElement;
        var root = document.querySelector('main') || document.body;
        var bar = document.createElement('div');
        bar.className = 'riel-lectura';
        bar.setAttribute('aria-hidden', 'true');
        bar.hidden = true;
        bar.innerHTML = '<div class="riel-lectura-vara"></div><div class="riel-lectura-pomos"></div>' + MOCHILA +
            '<div class="riel-lectura-nombre"><span></span></div>';
        document.body.appendChild(bar);
        var knobs = bar.querySelector('.riel-lectura-pomos');
        var bag = bar.querySelector('.riel-lectura-mochila');
        var name = bar.querySelector('.riel-lectura-nombre');
        var heads = [];
        var span = 1;
        var max = 1;
        var current = -1;
        var dragging = false;
        var hide = 0;
        var queued = false;
        // The swing: an angle that follows the speed of the backpack and settles back, as a pendulum would.
        var swing = {y: -1, angle: 0, speed: 0, running: false};

        var to = function(y, smooth) {
            window.scrollTo({top: y, behavior: smooth && !still.matches ? 'smooth' : 'auto'});
        };
        var at = function(clientY) {
            return Math.min(1, Math.max(0, (clientY - PAD) / span)) * max;
        };
        var say = function(text, top, forAMoment) {
            name.firstChild.textContent = text;
            name.style.top = top + 'px';
            name.classList.add('visible');
            clearTimeout(hide);
            if (forAMoment) {
                hide = setTimeout(function() {
                    name.classList.remove('visible');
                }, NAME_MS);
            }
        };

        bar.addEventListener('pointerdown', function(event) {
            if (event.button !== 0) {
                return;
            }
            var knob = event.target.closest('.riel-lectura-pomo');
            if (knob && heads[knob.dataset.i]) {
                to(heads[knob.dataset.i].y, true);
                event.preventDefault();
                return;
            }
            dragging = true;
            bar.setPointerCapture(event.pointerId);
            bar.classList.add('arrastrando');
            to(at(event.clientY), false);
            event.preventDefault();
        });
        bar.addEventListener('pointermove', function(event) {
            if (dragging) {
                to(at(event.clientY), false);
            }
        });
        var stop = function() {
            dragging = false;
            bar.classList.remove('arrastrando');
        };
        bar.addEventListener('pointerup', stop);
        bar.addEventListener('pointercancel', stop);

        var build = function() {
            var docH = html.scrollHeight;
            bar.hidden = !wide.matches || docH < window.innerHeight * 1.3;
            html.classList.toggle('tiene-riel', !bar.hidden);
            if (bar.hidden) {
                return;
            }
            span = Math.max(1, window.innerHeight - 2 * PAD);
            max = Math.max(1, docH - window.innerHeight);
            heads = [];
            knobs.textContent = '';
            var last = -100;
            Array.prototype.forEach.call(root.querySelectorAll('h2'), function(el) {
                var text = el.textContent.replace(/\s+/g, ' ').trim();
                if (!text || el.offsetParent === null) {
                    return;
                }
                // Where the page goes for this section, and so where its knob sits on the rail: the backpack lands on it.
                var y = Math.max(0, Math.min(max, el.getBoundingClientRect().top + window.scrollY - 16));
                var top = PAD + span * (y / max);
                // A knob per section, never two on top of each other.
                if (top - last < 20 || heads.length >= 14) {
                    return;
                }
                last = top;
                var knob = document.createElement('span');
                knob.className = 'riel-lectura-pomo';
                knob.style.top = top + 'px';
                knob.dataset.i = String(heads.length);
                knob.addEventListener('mouseenter', function() {
                    say(text.length > 42 ? text.slice(0, 40) + '…' : text, top, false);
                });
                knob.addEventListener('mouseleave', function() {
                    name.classList.remove('visible');
                });
                knobs.appendChild(knob);
                heads.push({y: y, top: top, text: text.length > 42 ? text.slice(0, 40) + '…' : text});
            });
            current = -1;
            draw();
        };

        var place = function() {
            bag.style.transform = 'translateY(' + swing.y.toFixed(1) + 'px) rotate(' + swing.angle.toFixed(2) + 'deg)';
        };
        var pendulum = function() {
            // Spring back to hanging straight, with some damping.
            swing.speed += -swing.angle * 0.12;
            swing.speed *= 0.82;
            swing.angle += swing.speed;
            place();
            if (Math.abs(swing.angle) > 0.05 || Math.abs(swing.speed) > 0.05) {
                window.requestAnimationFrame(pendulum);
            } else {
                swing.angle = 0;
                swing.running = false;
                place();
            }
        };

        var draw = function() {
            queued = false;
            if (bar.hidden) {
                return;
            }
            var f = Math.min(1, Math.max(0, window.scrollY / max));
            var y = PAD + span * f - 6;
            if (swing.y >= 0 && !still.matches) {
                // The faster it goes, the more it leans back.
                swing.speed += Math.max(-4, Math.min(4, (y - swing.y) * 0.08));
                if (!swing.running) {
                    swing.running = true;
                    window.requestAnimationFrame(pendulum);
                }
            }
            swing.y = y;
            place();
            var now = -1;
            heads.forEach(function(h, i) {
                if (h.y <= window.scrollY + 24) {
                    now = i;
                }
            });
            if (now !== current) {
                current = now;
                Array.prototype.forEach.call(knobs.children, function(k, i) {
                    k.classList.toggle('ahora', i === now);
                });
                // While dragging, the name of the section it goes through, for a moment.
                if (now >= 0 && dragging) {
                    say(heads[now].text, heads[now].top, true);
                }
            }
        };
        var later = function() {
            if (!queued) {
                queued = true;
                window.requestAnimationFrame(draw);
            }
        };
        var rebuild = 0;
        var soon = function() {
            clearTimeout(rebuild);
            rebuild = setTimeout(build, 150);
        };
        window.addEventListener('scroll', later, {passive: true});
        window.addEventListener('resize', soon);
        if (wide.addEventListener) {
            wide.addEventListener('change', build);
        }
        if (window.ResizeObserver) {
            new window.ResizeObserver(soon).observe(document.body);
        }
        build();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
