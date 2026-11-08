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
 * The drawing of a child, on the panel (taken by the team at the door) and on the page of its family (sent by them):
 * the four corners of the sheet onto those of the photo, then cut and straightened in the browser, so only the sheet
 * goes, flat. Without JavaScript the form sends the photo as it is.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    // Where the worker that finds the strokes lives: beside this file, with its version.
    var me = document.currentScript ? document.currentScript.src : '';
    var thread = me ? me.replace(/dibujo\.js(\?|$)/, 'dibujo-vivo-hilo.js$1') : '';
    // The drawing of a child: the four corners of the sheet onto those of the photo, then cut and straightened here, so
    // only the sheet goes, flat, and the page goes back by itself. Without JavaScript the form sends the photo as it is.
    var form = document.querySelector('form[data-dibujo]');
    if (!form || !window.fetch || !window.FormData || !document.createElement('canvas').toBlob) {
        return;
    }
    var input = form.querySelector('[data-dibujo-foto]');
    // From here the photo is chosen with the button and shown to be cut: the plain field is no longer needed.
    form.classList.add('con-recorte');
    // Sending waits for the photo: before it, only the button that takes it.
    var save = form.querySelector('[data-dibujo-guardar]');
    save.hidden = true;
    var area = form.querySelector('[data-dibujo-recorte]');
    var box = form.querySelector('[data-dibujo-caja]');
    var canvas = form.querySelector('[data-dibujo-lienzo]');
    var svg = form.querySelector('[data-dibujo-esquinas]');
    var turn = form.querySelector('[data-dibujo-girar]');
    var shape = form.querySelector('[data-dibujo-forma]');
    // The sheet comes out as a sheet of paper, upright or lying: in a photo at an angle its sides cannot say which.
    var upright = true;
    var setShape = function(value) {
        upright = value;
        shape.querySelectorAll('[data-forma]').forEach(function(b) {
            b.setAttribute('aria-pressed', (b.getAttribute('data-forma') === 'vertical') === upright ? 'true' : 'false');
        });
    };
    shape.addEventListener('click', function(e) {
        var b = e.target.closest('[data-forma]');
        if (b) {
            setShape(b.getAttribute('data-forma') === 'vertical');
        }
    });
    var state = form.querySelector('[data-dibujo-estado]');
    var ns = 'http://www.w3.org/2000/svg';
    var image = null;
    var shown = 1;
    var corners = [];
    var order = [0, 1, 2, 3];
    var LONGEST = 1600;
    var SOURCE = 2400;
    var draw = function() {
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }
        var poly = document.createElementNS(ns, 'polygon');
        poly.setAttribute('class', 'hoja');
        poly.setAttribute('points', corners.map(function(p) {
            return (p[0] * shown) + ',' + (p[1] * shown);
        }).join(' '));
        svg.appendChild(poly);
        corners.forEach(function(p, i) {
            var dot = document.createElementNS(ns, 'circle');
            dot.setAttribute('class', 'esquina' + (i === order[0] ? ' primera' : ''));
            dot.setAttribute('cx', p[0] * shown);
            dot.setAttribute('cy', p[1] * shown);
            dot.setAttribute('r', 13);
            dot.setAttribute('data-i', i);
            svg.appendChild(dot);
        });
    };
    var place = function() {
        var width = Math.min(box.parentNode.clientWidth, 720);
        shown = width / image.width;
        canvas.width = Math.round(image.width * shown);
        canvas.height = Math.round(image.height * shown);
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
        svg.setAttribute('width', canvas.width);
        svg.setAttribute('height', canvas.height);
        svg.setAttribute('viewBox', '0 0 ' + canvas.width + ' ' + canvas.height);
        draw();
    };
    input.addEventListener('change', function() {
        var file = input.files && input.files[0];
        if (!file) {
            return;
        }
        var img = new Image();
        img.onload = function() {
            // The photo, no bigger than it needs to be: the cut is made from it.
            var k = Math.min(1, SOURCE / Math.max(img.width, img.height));
            var src = document.createElement('canvas');
            src.width = Math.round(img.width * k);
            src.height = Math.round(img.height * k);
            src.getContext('2d').drawImage(img, 0, 0, src.width, src.height);
            image = src;
            var mx = src.width * 0.07;
            var my = src.height * 0.07;
            corners = [[mx, my], [src.width - mx, my], [src.width - mx, src.height - my], [mx, src.height - my]];
            order = [0, 1, 2, 3];
            area.hidden = false;
            save.hidden = false;
            turn.hidden = false;
            shape.hidden = false;
            setShape(src.height >= src.width);
            place();
        };
        // As data: the panel lets images come only from itself or as data.
        var reader = new FileReader();
        reader.onload = function() {
            img.src = reader.result;
        };
        reader.readAsDataURL(file);
    });
    var dragging = -1;
    var point = function(e) {
        var r = svg.getBoundingClientRect();
        return [Math.max(0, Math.min(image.width, (e.clientX - r.left) / shown)),
            Math.max(0, Math.min(image.height, (e.clientY - r.top) / shown))];
    };
    svg.addEventListener('pointerdown', function(e) {
        if (!image) {
            return;
        }
        var p = point(e);
        var best = -1;
        var far = 44 / shown;
        corners.forEach(function(c, i) {
            var d = Math.hypot(c[0] - p[0], c[1] - p[1]);
            if (d < far) {
                far = d;
                best = i;
            }
        });
        if (best >= 0) {
            dragging = best;
            svg.setPointerCapture(e.pointerId);
            e.preventDefault();
        }
    });
    svg.addEventListener('pointermove', function(e) {
        if (dragging >= 0) {
            corners[dragging] = point(e);
            draw();
        }
    });
    svg.addEventListener('pointerup', function() {
        dragging = -1;
    });
    turn.addEventListener('click', function() {
        // A quarter turn: the corner that goes on top changes, and the sheet lies the other way.
        order = [order[3], order[0], order[1], order[2]];
        setShape(!upright);
        draw();
    });
    // From the unit square onto the four corners (Heckbert): where each point of the flat sheet comes from.
    var mapping = function(q) {
        var x0 = q[0][0], y0 = q[0][1], x1 = q[1][0], y1 = q[1][1], x2 = q[2][0], y2 = q[2][1], x3 = q[3][0], y3 = q[3][1];
        var sx = x0 - x1 + x2 - x3;
        var sy = y0 - y1 + y2 - y3;
        var a, b, c, d, e, f, g, h;
        if (Math.abs(sx) < 1e-9 && Math.abs(sy) < 1e-9) {
            a = x1 - x0; b = x2 - x1; c = x0; d = y1 - y0; e = y2 - y1; f = y0; g = 0; h = 0;
        } else {
            var dx1 = x1 - x2, dx2 = x3 - x2, dy1 = y1 - y2, dy2 = y3 - y2;
            var den = dx1 * dy2 - dx2 * dy1;
            g = (sx * dy2 - dx2 * sy) / den;
            h = (dx1 * sy - sx * dy1) / den;
            a = x1 - x0 + g * x1; b = x3 - x0 + h * x3; c = x0;
            d = y1 - y0 + g * y1; e = y3 - y0 + h * y3; f = y0;
        }
        return function(u, v) {
            var w = g * u + h * v + 1;
            return [(a * u + b * v + c) / w, (d * u + e * v + f) / w];
        };
    };
    var cut = function() {
        var q = order.map(function(i) {
            return corners[i];
        });
        var ow = upright ? Math.round(LONGEST / Math.SQRT2) : LONGEST;
        var oh = upright ? LONGEST : Math.round(LONGEST / Math.SQRT2);
        var from = image.getContext('2d').getImageData(0, 0, image.width, image.height);
        var out = document.createElement('canvas');
        out.width = ow;
        out.height = oh;
        var ctx = out.getContext('2d');
        var to = ctx.createImageData(ow, oh);
        var map = mapping(q);
        var sw = image.width, sh = image.height, s = from.data, t = to.data;
        for (var y = 0; y < oh; y++) {
            for (var x = 0; x < ow; x++) {
                var p = map((x + 0.5) / ow, (y + 0.5) / oh);
                var px = Math.min(sw - 1.001, Math.max(0, p[0] - 0.5));
                var py = Math.min(sh - 1.001, Math.max(0, p[1] - 0.5));
                var ix = Math.floor(px), iy = Math.floor(py), fx = px - ix, fy = py - iy;
                var i00 = (iy * sw + ix) * 4, i10 = i00 + 4, i01 = i00 + sw * 4, i11 = i01 + 4;
                var o = (y * ow + x) * 4;
                for (var ch = 0; ch < 3; ch++) {
                    t[o + ch] = (s[i00 + ch] * (1 - fx) + s[i10 + ch] * fx) * (1 - fy) +
                        (s[i01 + ch] * (1 - fx) + s[i11 + ch] * fx) * fy;
                }
                t[o + 3] = 255;
            }
        }
        ctx.putImageData(to, 0, 0);
        return out;
    };
    // The drawing that draws itself on the page of its family (Gaudí): its strokes, found out of the page so it never
    // hangs. Ten seconds at most, or without a worker, and the photo goes alone: the table shows it still.
    var strokes = function(sheet, done) {
        if (!thread || !window.Worker || !window.createImageBitmap || typeof window.OffscreenCanvas === 'undefined') {
            done('');
            return;
        }
        var finished = false;
        var worker = null;
        var timer = 0;
        var end = function(json) {
            if (finished) {
                return;
            }
            finished = true;
            window.clearTimeout(timer);
            if (worker) {
                worker.terminate();
            }
            done(json || '');
        };
        timer = window.setTimeout(function() {
            end('');
        }, 10000);
        try {
            worker = new window.Worker(thread);
            worker.onmessage = function(e) {
                end(e.data && e.data.json);
            };
            worker.onerror = function() {
                end('');
            };
            window.createImageBitmap(sheet).then(function(bitmap) {
                worker.postMessage({bitmap: bitmap}, [bitmap]);
            }).catch(function() {
                end('');
            });
        } catch (err) {
            end('');
        }
    };
    // The cut sheet in place of the photo, while it is prepared: so it does not look stuck.
    var show = function(sheet) {
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }
        var width = Math.min(box.parentNode.clientWidth, 720, sheet.width);
        canvas.width = Math.round(width);
        canvas.height = Math.round(sheet.height * width / sheet.width);
        canvas.getContext('2d').drawImage(sheet, 0, 0, canvas.width, canvas.height);
        svg.setAttribute('width', canvas.width);
        svg.setAttribute('height', canvas.height);
        turn.hidden = true;
        shape.hidden = true;
    };
    // What the button says while it works.
    var say = function(text) {
        var nodes = Array.prototype.filter.call(save.childNodes, function(n) {
            return n.nodeType === 3 && n.nodeValue.trim() !== '';
        });
        if (nodes.length && text) {
            nodes[nodes.length - 1].nodeValue = text;
        }
    };
    var sending = false;
    form.addEventListener('submit', function(e) {
        if (!image) {
            return;
        }
        e.preventDefault();
        if (sending) {
            return;
        }
        sending = true;
        save.setAttribute('aria-disabled', 'true');
        var sheet = cut();
        show(sheet);
        var preparing = form.getAttribute('data-preparando') || '';
        say(preparing);
        state.textContent = preparing;
        image = null;
        strokes(sheet, function(json) {
            state.textContent = form.getAttribute('data-guardando') || '';
            post(sheet, json);
        });
    });
    var post = function(sheet, json) {
        sheet.toBlob(function(blob) {
            var data = new FormData(form);
            data.set('photo', blob, 'dibujo.jpg');
            if (json) {
                data.set('strokes', json);
            }
            data.set('fetch', '1');
            fetch(form.getAttribute('action'), {method: 'POST', body: data, credentials: 'same-origin'}).then(function(r) {
                return r.json();
            }).then(function(answer) {
                if (answer && answer.saved) {
                    window.location.href = answer.back || form.getAttribute('data-dibujo-vuelta');
                } else {
                    // It did not go in: another photo can be taken.
                    state.textContent = form.getAttribute('data-no-entra') || '';
                    sending = false;
                    save.removeAttribute('aria-disabled');
                }
            }).catch(function() {
                form.submit();
            });
        }, 'image/jpeg', 0.86);
    };
    window.addEventListener('resize', function() {
        if (image) {
            place();
        }
    });
})();
