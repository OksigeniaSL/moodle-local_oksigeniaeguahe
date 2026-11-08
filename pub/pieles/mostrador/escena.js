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
 * The scene of the skin «mostrador» (the paper desk), on top of panel.js: the rubber stamp that marks «Contestada»
 * on the pad when an answer goes, and the machine of the way of the campaign, drawn once and lit at the stop the line
 * of the datum is telling. It only listens to the events of panel.js; without it the panel is the same, quieter.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';

    var doc = document;
    var here = (doc.currentScript && doc.currentScript.src || '').replace(/[^/]*$/, '');
    var E = window.Eguahe || {gesto: function() {
        return true;
    }, quieto: function() {
        return false;
    }};
    var on = function(name, fn) {
        doc.addEventListener('eguahe:' + name, function(e) {
            fn(e.detail || {});
        });
    };

    // The stamp: it comes down on the pad, presses and lifts; the ink stays. The pad already says «answered».
    on('contestada', function(d) {
        var pad = d.nota && d.nota.querySelector('.que');
        var ink = pad && pad.querySelector('.sello-grande');
        if (!ink || !ink.animate) {
            return;
        }
        if (E.quieto() || !E.gesto(900)) {
            ink.animate([{opacity: 0}, {opacity: 0.9}], {duration: 150});
            return;
        }
        var stamp = doc.createElement('img');
        stamp.src = here + 'sello.webp';
        stamp.alt = '';
        stamp.className = 'sello-golpe';
        pad.appendChild(stamp);
        var a = ink.getBoundingClientRect();
        var b = pad.getBoundingClientRect();
        stamp.style.left = (a.left - b.left + a.width / 2 - 48) + 'px';
        stamp.style.top = (a.top - b.top + a.height / 2 - 60) + 'px';
        ink.style.opacity = '0';
        stamp.animate([
            {transform: 'translate(-10px, -46px) scale(1.2)', opacity: 0},
            {transform: 'translate(-4px, -18px) scale(1.1)', opacity: 1, offset: 0.3},
            {transform: 'translate(0, 0) scale(1)', opacity: 1, offset: 0.5},
            {transform: 'translate(0, 0) scale(0.98)', opacity: 1, offset: 0.62},
            {transform: 'translate(18px, -52px) scale(1.16)', opacity: 0}
        ], {duration: 900, easing: 'cubic-bezier(.3,.7,.3,1)'}).onfinish = function() {
            stamp.parentNode.removeChild(stamp);
        };
        window.setTimeout(function() {
            ink.style.opacity = '';
            ink.animate([{opacity: 0, transform: 'rotate(-8deg) scale(1.04)'}, {opacity: 0.9, transform: 'rotate(-8deg)'}],
                {duration: 160, easing: 'ease-out'});
        }, 470);
    });

    // The machine of the way: drawn once (an SVG of this skin), lit at the stop that is being told.
    var slot = doc.querySelector('[data-figura="camino"]');
    if (slot && window.fetch) {
        window.fetch(here + 'camino.svg', {credentials: 'same-origin'}).then(function(r) {
            return r.ok ? r.text() : '';
        }).then(function(svg) {
            if (svg.indexOf('<svg') === -1) {
                return;
            }
            slot.innerHTML = svg;
            var figure = slot.querySelector('svg');
            on('parada', function(d) {
                figure.setAttribute('data-encendida', d.clave || '');
            });
            // Every stop can be touched on the drawing too.
            Array.prototype.forEach.call(figure.querySelectorAll('[data-estacion]'), function(part) {
                part.addEventListener('mouseenter', function() {
                    if (window.Eguahe && window.Eguahe.parada) {
                        window.Eguahe.parada(part.getAttribute('data-estacion'));
                    }
                });
            });
            // Once, when it comes into sight: the cards go along the belt from the letterbox to the hooks.
            if (!E.quieto() && 'IntersectionObserver' in window) {
                var seen = new IntersectionObserver(function(entries) {
                    if (entries[0].isIntersecting) {
                        seen.disconnect();
                        if (E.gesto(2400)) {
                            figure.classList.add('en-marcha');
                            window.setTimeout(function() {
                                figure.classList.remove('en-marcha');
                            }, 2400);
                        }
                    }
                }, {threshold: 0.4});
                seen.observe(figure);
            }
        }).catch(function() {
            // Without the drawing, the numbers say it.
        });
    }
})();
