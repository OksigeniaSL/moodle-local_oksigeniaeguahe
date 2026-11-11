/**
 * The birthday of a small child (up to about five) as a video to keep: the class of «Tu primer día», a cake on the
 * table with as many candles as the child turns, lit one by one; the voice of the teacher says «Happy birthday» with
 * the child's name; the camera opens to the class, with the child's own drawing framed on the wall (or, without one,
 * the age drawn in crayon by the class); the camera comes nearer, the candles are blown, paper confetti falls, and the words say how old the
 * child is today and who sends it. Twelve seconds, upright (1080 x 1920), with its own music.
 *
 * The same grid as the video of the story (Gaudí, 9 November 2026, with Kimi K3): the picture fills the top of the
 * screen and ends on the paper of the book like a wash of paint; the words come in on the paper, centred, in sepia,
 * the name in its own hand, never below where a player puts its controls. Made in the browser like the other two
 * videos: the sound mixed once with an OfflineAudioContext and encoded with AudioEncoder (AAC, else Opus), the
 * frames drawn on a canvas and encoded with VideoEncoder, both packed by mp4-muxer. Without those, no video.
 *
 *   EguaheCumple.make(o, progress) -> Promise<Blob|null>     the MP4
 *   EguaheCumple.prepare(o) -> Promise<k>                    the loaded pieces, for frame()
 *   EguaheCumple.frame(c, k, t)                              one frame, at t seconds (the sheet's stills, the tests)
 *
 * o: {base, name, age, lang, rtl, drawing (URL, image or canvas, or null), voice: {url, json} or null,
 *     words: {wish, name, years, from}, fonts: {sans, hand}, keepLit}
 *   base: where cumple-clase.webp, cumple-musica.mp3 and cumple-musica.json are (with the trailing slash).
 *   voice.json: the cumple.json of the voice bank (kit/voz-nombre, reading «cumple»): nombre_inicio_s and
 *   nombre_fin_s, the seconds where the name is said.
 *   words.wish: «¡Feliz cumpleaños,» — words.name: «Sebas!» — words.years: «Hoy cumples 4 años.» —
 *   words.from: «Con cariño, tu clase de Los Almendros». The plugin writes them in each language.
 *   keepLit: the candles stay lit, without the breath or the smoke, for a child who has not started yet: its class
 *   keeps the cake for it to blow («Te esperamos en tu clase»).
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function(root) {
    'use strict';

    var W = 720;
    var H = 1280;
    var SCALE = 1.5;
    var FPS = 24;
    var RATE = 48000;
    var TOTAL = 12;

    // The grid of the story video, in units of 720 x 1280: the picture down to BAND, the wash from SEAM0 to SEAM1, and
    // the words on the paper between TOP and BOTTOM (never lower: a player puts its controls there).
    var BAND = 704;
    var SEAM0 = 628;
    var SEAM1 = 760;
    var TOP = 806;
    var BOTTOM = 1136;
    var PAPER = '#f6f0e3';
    var SEPIA = '#4a3b2e';

    // The plate (cumple-clase.webp, 1536 x 1024): the top of the cake and the paper inside the frame on the wall.
    var CAKE = {cx: 789, cy: 517, rx: 94, ry: 15};
    var FRAME = {x: 659, y: 130, w: 290, h: 227};
    // The candles, the pennants of the garland and the crayons: the colours of the picture.
    var WAX = ['#e7a19c', '#9cc0d6', '#efcd78', '#a8c79a', '#c5a5d4', '#eeb487'];
    var CONFETTI = ['#e98e7f', '#efc35f', '#9fbf8d', '#86aecb', '#e3a5c0', '#f2a46a'];

    // The time, on the marks of the music (cumple-musica.json): the candles light while the celesta twinkles, the
    // voice comes with the tune, the camera opens to the class, the candles are blown with the glockenspiel.
    var LIGHT_FROM = 0.55;
    var LIGHT_STEP = 0.32;
    var VOICE_AT = 3.0;
    var OPEN_FROM = 3.1;
    var OPEN_TO = 5.6;
    var NEAR_FROM = 6.8;
    var NEAR_TO = 8.05;
    var DRAWING_FROM = 4.6;
    var BLOW = 8.18;
    var YEARS_AT = 8.7;
    var FROM_AT = 9.7;

    var clamp = function(x, a, b) {
        return Math.max(a, Math.min(b, x));
    };
    var smooth = function(a, b, x) {
        var u = clamp((x - a) / (b - a), 0, 1);
        return u * u * (3 - 2 * u);
    };
    var lerp = function(a, b, u) {
        return a + (b - a) * u;
    };
    var rng = function(seed) {
        var s = seed >>> 0;
        return function() {
            s = (s + 0x6D2B79F5) >>> 0;
            var r = Math.imul(s ^ (s >>> 15), 1 | s);
            r = (r + Math.imul(r ^ (r >>> 7), 61 | r)) ^ r;
            return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
        };
    };
    var image = function(src) {
        if (!src || typeof src !== 'string') {
            return Promise.resolve(src || null);
        }
        return new Promise(function(ok) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() {
                ok(img);
            };
            img.onerror = function() {
                ok(null);
            };
            img.src = src;
        });
    };
    var json = function(src) {
        if (!src || typeof src !== 'string') {
            return Promise.resolve(src || null);
        }
        return fetch(src).then(function(r) {
            return r.ok ? r.json() : null;
        }, function() {
            return null;
        });
    };
    var bytes = function(src) {
        return src ? fetch(src).then(function(r) {
            return r.ok ? r.arrayBuffer() : null;
        }, function() {
            return null;
        }) : Promise.resolve(null);
    };
    var canvasOf = function(w, h) {
        var cv = document.createElement('canvas');
        cv.width = w;
        cv.height = h;
        return cv;
    };

    // ------------------------------------------------------------------------------------------------- the camera
    // The picture seen at scale z (1 = its whole height in the band), centred on (x, y) of the plate. Close on the
    // cake while the candles light, then it opens, slowly, to the class and the drawing on the wall, and stays.
    var CLOSE = {x: 789, y: 498, z: 3.0};
    // wide enough for the child (from behind), the cake, the drawing and the teacher's face; never a face cut in half
    var WIDE = {x: 870, y: 476, z: 1.08};
    // nearer for the blow, so the candles going out are seen: the child, the cake, the drawing and the classmate
    var NEAR = {x: 755, y: 452, z: 1.42};
    var camera = function(t) {
        var u = smooth(OPEN_FROM, OPEN_TO, t);
        var v = smooth(NEAR_FROM, NEAR_TO, t);
        var push = 0.05 * smooth(0, OPEN_FROM, t);
        var x = lerp(lerp(CLOSE.x, WIDE.x, u), NEAR.x, v);
        var y = lerp(lerp(CLOSE.y, WIDE.y, u), NEAR.y, v);
        var z = lerp(lerp(CLOSE.z, WIDE.z, u), NEAR.z, v);
        return {x: x, y: y, z: z + push};
    };

    // -------------------------------------------------------------------------------------------- the candles
    var candlesOf = function(age) {
        var n = clamp(Math.round(age) || 1, 1, 6);
        var out = [];
        for (var i = 0; i < n; i++) {
            var a = n === 1 ? Math.PI / 2 : -Math.PI / 2 + (i + 0.5) * 2 * Math.PI / n + 0.35;
            var r = n === 1 ? 0 : (n <= 3 ? 0.42 : 0.6);
            out.push({x: CAKE.cx + Math.cos(a) * CAKE.rx * r, y: CAKE.cy + Math.sin(a) * CAKE.ry * r + 2,
                wax: WAX[i % WAX.length], lit: LIGHT_FROM + i * LIGHT_STEP, seed: i * 1.7 + 0.3});
        }
        // back to front, so the near candles stand in front of the far ones
        out.sort(function(a, b) {
            return a.y - b.y;
        });
        return out;
    };

    var candle = function(c, k, t) {
        var h = 46;
        var w = 9;
        c.save();
        c.translate(k.x, k.y);
        // its shadow on the icing
        c.fillStyle = 'rgba(120,80,40,0.18)';
        c.beginPath();
        c.ellipse(1.5, 1, w * 0.75, 2.4, 0, 0, Math.PI * 2);
        c.fill();
        // the wax, with its spiral of white
        c.beginPath();
        c.moveTo(-w / 2, 0);
        c.lineTo(-w / 2, -h + 2);
        c.quadraticCurveTo(-w / 2, -h, 0, -h);
        c.quadraticCurveTo(w / 2, -h, w / 2, -h + 2);
        c.lineTo(w / 2, 0);
        c.closePath();
        c.fillStyle = k.wax;
        c.fill();
        c.save();
        c.clip();
        c.strokeStyle = 'rgba(255,252,244,0.75)';
        c.lineWidth = 2.4;
        for (var y = 6; y > -h - 12; y -= 9) {
            c.beginPath();
            c.moveTo(-w, y);
            c.lineTo(w, y - 7);
            c.stroke();
        }
        // the light from the left, as in the picture
        var g = c.createLinearGradient(-w / 2, 0, w / 2, 0);
        g.addColorStop(0, 'rgba(255,255,255,0.22)');
        g.addColorStop(0.6, 'rgba(255,255,255,0)');
        g.addColorStop(1, 'rgba(90,50,30,0.18)');
        c.fillStyle = g;
        c.fillRect(-w, -h - 4, 2 * w, h + 8);
        c.restore();
        // the wick
        c.strokeStyle = '#3b2a22';
        c.lineWidth = 1.6;
        c.lineCap = 'round';
        c.beginPath();
        c.moveTo(0, -h);
        c.quadraticCurveTo(0.8, -h - 3, 0.3, -h - 6);
        c.stroke();
        c.restore();
    };

    var flame = function(c, k, t, glowOnly, kept) {
        var out = kept ? 0 : smooth(BLOW + k.seed * 0.02, BLOW + 0.26 + k.seed * 0.02, t);
        var on = smooth(k.lit, k.lit + 0.22, t) * (1 - out);
        if (on <= 0.001) {
            return;
        }
        var flick = 1 + 0.07 * Math.sin(t * 17 + k.seed * 5) + 0.05 * Math.sin(t * 31 + k.seed * 2);
        var lean = 0.06 * Math.sin(t * 7 + k.seed);
        var x = k.x;
        var y = k.y - 52;
        c.save();
        if (glowOnly) {
            var gr = c.createRadialGradient(x, y - 4, 0, x, y - 4, 46 * on);
            gr.addColorStop(0, 'rgba(255,214,140,0.55)');
            gr.addColorStop(0.4, 'rgba(255,190,110,0.18)');
            gr.addColorStop(1, 'rgba(255,190,110,0)');
            c.globalCompositeOperation = 'lighter';
            c.fillStyle = gr;
            c.fillRect(x - 50, y - 54, 100, 100);
            c.restore();
            return;
        }
        c.translate(x, y + 4);
        c.rotate(lean);
        c.scale(on, on * flick);
        var fh = 17;
        var fw = 5.2;
        var path = function(s) {
            c.beginPath();
            c.moveTo(0, -fh * s);
            c.bezierCurveTo(fw * s, -fh * 0.45 * s, fw * 1.1 * s, 0, 0, 2 * s);
            c.bezierCurveTo(-fw * 1.1 * s, 0, -fw * s, -fh * 0.45 * s, 0, -fh * s);
        };
        path(1);
        c.fillStyle = 'rgba(245,150,60,0.92)';
        c.fill();
        path(0.68);
        c.fillStyle = 'rgba(255,214,120,0.95)';
        c.fill();
        path(0.36);
        c.fillStyle = 'rgba(255,248,225,1)';
        c.fill();
        c.restore();
    };

    // The smoke of a candle just blown: two thin wisps that rise, sway and fade.
    var smoke = function(c, k, t) {
        var u = (t - BLOW - 0.18) / 1.5;
        if (u <= 0 || u >= 1) {
            return;
        }
        c.save();
        c.lineCap = 'round';
        // each wisp drawn three times, wider and fainter, so it reads as smoke and not as a thread
        for (var j = 0; j < 2; j++) {
            var x = k.x;
            var y = k.y - 52 - u * 10;
            var rise = 18 + u * 56;
            var sway = (j ? -1 : 1) * (5 + 12 * u) * Math.sin(u * 4 + k.seed + j * 1.3);
            for (var pass = 0; pass < 3; pass++) {
                c.strokeStyle = 'rgba(150,140,132,' + ((1 - u) * [0.07, 0.1, 0.16][pass]).toFixed(3) + ')';
                c.lineWidth = [7, 4.5, 2][pass] + u * 3;
                c.beginPath();
                c.moveTo(x, y);
                c.bezierCurveTo(x + sway, y - rise * 0.35, x - sway * 0.8, y - rise * 0.7, x + sway * 0.5, y - rise);
                c.stroke();
            }
        }
        c.restore();
    };

    // ------------------------------------------------------------------------- the drawing in the frame on the wall
    // The child's drawing on the paper in the frame, multiplied so it looks drawn on it; without one, the age drawn
    // by the class in crayon.
    var framed = function(c, k, t) {
        var a = smooth(DRAWING_FROM, DRAWING_FROM + 1.0, t);
        if (a <= 0) {
            return;
        }
        var m = 14;
        var box = {x: FRAME.x + m, y: FRAME.y + m, w: FRAME.w - 2 * m, h: FRAME.h - 2 * m};
        c.save();
        c.globalAlpha = a;
        c.globalCompositeOperation = 'multiply';
        if (k.drawing) {
            var d = k.drawing;
            var s = Math.min(box.w / d.width, box.h / d.height);
            var dw = d.width * s;
            var dh = d.height * s;
            c.drawImage(d, box.x + (box.w - dw) / 2, box.y + (box.h - dh) / 2, dw, dh);
        } else {
            var r = rng(k.age * 97 + 11);
            c.font = '700 ' + Math.round(box.h * 0.92) + 'px ' + k.hand;
            c.textAlign = 'center';
            c.textBaseline = 'middle';
            // crayon: the numeral laid several times, a little off each time, in two colours of the picture
            for (var i = 0; i < 9; i++) {
                c.fillStyle = i < 6 ? 'rgba(226,120,100,0.22)' : 'rgba(239,190,80,0.2)';
                c.fillText(String(k.age), box.x + box.w / 2 + (r() - 0.5) * 3, box.y + box.h / 2 + 6 + (r() - 0.5) * 3);
            }
            // three little stars around it
            c.strokeStyle = 'rgba(120,150,190,0.55)';
            c.lineWidth = 2.2;
            [[0.16, 0.24, 9], [0.84, 0.3, 7], [0.8, 0.8, 8]].forEach(function(q) {
                var x = box.x + box.w * q[0];
                var y = box.y + box.h * q[1];
                c.beginPath();
                for (var p = 0; p < 4; p++) {
                    var ang = p * Math.PI / 4;
                    c.moveTo(x - Math.cos(ang) * q[2], y - Math.sin(ang) * q[2]);
                    c.lineTo(x + Math.cos(ang) * q[2], y + Math.sin(ang) * q[2]);
                }
                c.stroke();
            });
        }
        c.restore();
    };

    // ------------------------------------------------------------------------------------------------ the picture
    var picture = function(c, k, t) {
        var cam = camera(t);
        var s = BAND / k.plate.height * cam.z;
        c.save();
        c.beginPath();
        c.rect(0, 0, W, SEAM1);
        c.clip();
        c.translate(W / 2, BAND / 2);
        c.scale(s, s);
        c.translate(-cam.x, -cam.y);
        c.drawImage(k.plate, 0, 0);
        framed(c, k, t);
        // the room warms a little while the candles burn
        var kept = !!(k.o && k.o.keepLit);
        var warm = 0.12 * smooth(LIGHT_FROM, LIGHT_FROM + 1.5, t) * (kept ? 1 : 1 - smooth(BLOW, BLOW + 0.6, t));
        if (warm > 0) {
            c.save();
            c.globalCompositeOperation = 'soft-light';
            c.fillStyle = 'rgba(255,190,110,' + warm.toFixed(3) + ')';
            c.fillRect(0, 0, k.plate.width, k.plate.height);
            c.restore();
        }
        k.candles.forEach(function(q) {
            candle(c, q, t);
        });
        k.candles.forEach(function(q) {
            flame(c, q, t, true, kept);
        });
        k.candles.forEach(function(q) {
            flame(c, q, t, false, kept);
            if (!kept) {
                smoke(c, q, t);
            }
        });
        c.restore();
    };

    // The picture ends on the paper as a wash ends: a soft edge that wanders a little, the paper taking the warm
    // colour of the picture in a vein along it.
    var wash = function(c, k) {
        if (!k.washLayer) {
            var cv = canvasOf(W, H - SEAM0);
            var x = cv.getContext('2d');
            var img = x.createImageData(W, H - SEAM0);
            var d = img.data;
            var paper = [246, 240, 227];
            var vein = [236, 214, 186];
            var r = rng(20261110);
            var ph = [r() * 6, r() * 6, r() * 6];
            for (var px = 0; px < W; px++) {
                var edge = (SEAM0 + SEAM1) / 2 + 18 * Math.sin(px / 61 + ph[0]) + 9 * Math.sin(px / 23 + ph[1]) +
                    4 * Math.sin(px / 9 + ph[2]);
                for (var py = 0; py < H - SEAM0; py++) {
                    var y = py + SEAM0;
                    var a = smooth(edge - 46, edge + 22, y);
                    var v = Math.exp(-Math.pow((y - edge - 26) / 22, 2)) * 0.55;
                    var j = (py * W + px) * 4;
                    d[j] = lerp(paper[0], vein[0], v);
                    d[j + 1] = lerp(paper[1], vein[1], v);
                    d[j + 2] = lerp(paper[2], vein[2], v);
                    d[j + 3] = Math.round(255 * a);
                }
            }
            x.putImageData(img, 0, 0);
            k.washLayer = cv;
        }
        c.drawImage(k.washLayer, 0, SEAM0);
    };

    // The grain of the paper, multiplied over the whole sheet and never moving (moving grain costs bitrate).
    var grain = function(c, k) {
        if (!k.grainLayer) {
            var w = W * SCALE;
            var h = H * SCALE;
            var cv = canvasOf(w, h);
            var x = cv.getContext('2d');
            var img = x.createImageData(w, h);
            var d = img.data;
            var r = rng(7);
            for (var i = 0; i < d.length; i += 4) {
                var v = 255 * (0.955 + 0.045 * r());
                d[i] = v;
                d[i + 1] = v;
                d[i + 2] = v;
                d[i + 3] = 255;
            }
            x.putImageData(img, 0, 0);
            k.grainLayer = cv;
        }
        c.save();
        c.globalCompositeOperation = 'multiply';
        c.drawImage(k.grainLayer, 0, 0, W, H);
        c.restore();
    };

    // ---------------------------------------------------------------------------------------------- the confetti
    var confettiOf = function() {
        var r = rng(4);
        var out = [];
        for (var i = 0; i < 120; i++) {
            out.push({x: r() * W, y: -20 - r() * 700, v: 120 + r() * 110, sway: 14 + r() * 26, ph: r() * 6.3,
                spin: (r() - 0.5) * 9, w: 10 + r() * 8, h: 13 + r() * 9, tri: r() < 0.4,
                colour: CONFETTI[Math.floor(r() * CONFETTI.length)]});
        }
        return out;
    };
    var confetti = function(c, k, t) {
        var u = t - BLOW - 0.12;
        if (u <= 0) {
            return;
        }
        c.save();
        k.confetti.forEach(function(q) {
            var y = q.y + q.v * u;
            if (y > H + 20) {
                return;
            }
            var x = q.x + q.sway * Math.sin(u * 2.4 + q.ph);
            // on the paper, the pieces settle and fade, so the words stay clear
            var a = 1 - smooth(BAND - 40, TOP + 40, y);
            if (a <= 0) {
                return;
            }
            c.save();
            c.globalAlpha = a * 0.92;
            c.translate(x, y);
            c.rotate(q.ph + q.spin * u);
            c.scale(1, Math.cos(u * 5 + q.ph));
            c.fillStyle = q.colour;
            if (q.tri) {
                c.beginPath();
                c.moveTo(-q.w / 2, -q.h / 2);
                c.lineTo(q.w / 2, -q.h / 2);
                c.lineTo(0, q.h / 2);
                c.closePath();
                c.fill();
            } else {
                c.fillRect(-q.w / 2, -q.h / 2, q.w, q.h * 0.6);
            }
            c.restore();
        });
        c.restore();
    };

    // ------------------------------------------------------------------------------------------------- the words
    // A line written on, left to right (right to left in Arabic), between t0 and t1.
    var write = function(c, k, text, font, colour, y, t0, t1, t) {
        var u = smooth(t0, t1, t);
        if (u <= 0) {
            return;
        }
        c.save();
        c.font = font;
        c.fillStyle = colour;
        c.textAlign = 'center';
        c.textBaseline = 'alphabetic';
        c.direction = k.rtl ? 'rtl' : 'ltr';
        var w = c.measureText(text).width;
        var x0 = W / 2 - w / 2 - 12;
        var x1 = W / 2 + w / 2 + 12;
        c.beginPath();
        if (k.rtl) {
            c.rect(lerp(x1, x0, u) - 16, y - 120, x1 - lerp(x1, x0, u) + 32, 170);
        } else {
            c.rect(x0 - 16, y - 120, lerp(x0, x1, u) - x0 + 16, 170);
        }
        c.clip();
        c.fillText(text, W / 2, y);
        c.restore();
    };
    // The largest size up to `max` at which the text fits the width of the page (600 of 720).
    var fit = function(c, text, weight, family, max) {
        var size = max;
        c.font = weight + ' ' + size + 'px ' + family;
        while (c.measureText(text).width > 600 && size > 18) {
            size -= 1;
            c.font = weight + ' ' + size + 'px ' + family;
        }
        return c.font;
    };
    var words = function(c, k, t) {
        var v = k.timing;
        if (!k.fonts) {
            k.fonts = {wish: fit(c, k.words.wish, 600, k.sans, 46), name: fit(c, k.words.name, 700, k.hand, 108),
                years: fit(c, k.words.years, 500, k.sans, 36), from: fit(c, k.words.from, 600, k.hand, 40)};
        }
        write(c, k, k.words.wish, k.fonts.wish, SEPIA, TOP + 64, v.wish[0], v.wish[1], t);
        write(c, k, k.words.name, k.fonts.name, SEPIA, TOP + 180, v.name[0], v.name[1], t);
        write(c, k, k.words.years, k.fonts.years, SEPIA, TOP + 250, YEARS_AT, YEARS_AT + 0.9, t);
        write(c, k, k.words.from, k.fonts.from, 'rgba(74,59,46,0.78)', BOTTOM - 14, FROM_AT, FROM_AT + 1.1, t);
    };

    // ------------------------------------------------------------------------------------------------- one frame
    var frame = function(c, k, t) {
        c.fillStyle = PAPER;
        c.fillRect(0, 0, W, H);
        picture(c, k, t);
        wash(c, k);
        confetti(c, k, t);
        words(c, k, t);
        grain(c, k);
        // in from the cream of the paper, as the pages of the book come in
        var fade = 1 - smooth(0, 0.6, t);
        if (fade > 0) {
            c.fillStyle = 'rgba(246,240,227,' + fade.toFixed(3) + ')';
            c.fillRect(0, 0, W, H);
        }
    };

    // ----------------------------------------------------------------------------------------- loading the pieces
    var prepare = function(o) {
        var base = o.base || '';
        var f = o.fonts || {};
        return Promise.all([image(base + 'cumple-clase.webp'), image(o.drawing), json(o.voice && o.voice.json),
            json(base + 'cumple-musica.json'), document.fonts ? document.fonts.ready : null]).then(function(got) {
            var voice = got[2];
            // the words come with the voice when there is one: the wish up to the name, then the name in its hand
            var timing = {wish: [VOICE_AT - 0.1, VOICE_AT + 0.9], name: [VOICE_AT + 0.9, VOICE_AT + 1.7]};
            // cumple.json of the voice bank: nombre_inicio_s and nombre_fin_s, where the name is said
            var said = voice && typeof voice.nombre_inicio_s === 'number' ? [voice.nombre_inicio_s, voice.nombre_fin_s] : null;
            if (said) {
                timing = {wish: [VOICE_AT - 0.1, VOICE_AT + Math.max(0.5, said[0])],
                    name: [VOICE_AT + said[0], VOICE_AT + said[1] + 0.25]};
            }
            return {plate: got[0], drawing: got[1], voice: voice, music: got[3], age: o.age, rtl: !!o.rtl,
                words: o.words, sans: f.sans || '"Schibsted Grotesk", sans-serif', hand: f.hand || 'Caveat, cursive',
                candles: candlesOf(o.age), confetti: confettiOf(), timing: timing, o: o};
        });
    };

    // --------------------------------------------------------------------------------------------------- the sound
    // The music goes down under the voice, which comes at VOICE_AT; a soft breath blows the candles.
    var mix = function(k) {
        var ctx = new root.OfflineAudioContext(1, Math.ceil(RATE * TOTAL), RATE);
        // the whole mix 3.5 dB up, so the voice sits where it does in the video of the story (-18 LUFS)
        var out = ctx.createGain();
        out.gain.value = 1.5;
        out.connect(ctx.destination);
        var base = k.o.base || '';
        var decode = function(buf) {
            return buf ? ctx.decodeAudioData(buf).catch(function() {
                return null;
            }) : Promise.resolve(null);
        };
        return Promise.all([bytes(base + 'cumple-musica.mp3').then(decode),
            bytes(k.o.voice && k.o.voice.url).then(decode)]).then(function(got) {
            var music = got[0];
            var voice = got[1];
            if (music) {
                var src = ctx.createBufferSource();
                src.buffer = music;
                var g = ctx.createGain();
                g.gain.setValueAtTime(1, 0);
                if (voice) {
                    var end = VOICE_AT + voice.duration;
                    g.gain.setValueAtTime(1, VOICE_AT - 0.25);
                    g.gain.linearRampToValueAtTime(0.42, VOICE_AT);
                    g.gain.setValueAtTime(0.42, end);
                    g.gain.linearRampToValueAtTime(1, end + 0.5);
                }
                src.connect(g).connect(out);
                src.start(0);
            }
            if (voice) {
                var vs = ctx.createBufferSource();
                vs.buffer = voice;
                // the voice sits well over the music, which goes down 7.5 dB under it
                var vg = ctx.createGain();
                vg.gain.value = 1;
                vs.connect(vg).connect(out);
                vs.start(VOICE_AT);
            }
            // the breath: a short burst of noise, filtered soft
            var n = Math.ceil(RATE * 0.42);
            var nb = ctx.createBuffer(1, n, RATE);
            var d = nb.getChannelData(0);
            var r = rng(3);
            for (var i = 0; i < n; i++) {
                var e = Math.sin(Math.PI * i / n);
                d[i] = (r() * 2 - 1) * e * e * 0.16;
            }
            var ns = ctx.createBufferSource();
            ns.buffer = nb;
            var bp = ctx.createBiquadFilter();
            bp.type = 'bandpass';
            bp.frequency.value = 900;
            bp.Q.value = 0.7;
            ns.connect(bp).connect(out);
            if (!k.o.keepLit) {
                ns.start(BLOW - 0.18);
            }
            return ctx.startRendering();
        });
    };

    var encodeSound = function(buffer, muxer, config) {
        var failed = null;
        var packets = 0;
        var encoder = new root.AudioEncoder({
            output: function(chunk, meta) {
                var aac = config.codec !== 'opus';
                muxer.addAudioChunk(chunk, meta, aac ? packets * 1024 * 1e6 / RATE : chunk.timestamp);
                packets++;
            },
            error: function(e) {
                failed = e;
            }
        });
        encoder.configure(config);
        var data = buffer.getChannelData(0);
        for (var i = 0; i < data.length; i += 4800) {
            var part = data.subarray(i, Math.min(data.length, i + 4800));
            var ad = new root.AudioData({format: 'f32-planar', sampleRate: RATE, numberOfFrames: part.length,
                numberOfChannels: 1, timestamp: Math.round(i * 1e6 / RATE), data: part});
            encoder.encode(ad);
            ad.close();
        }
        return encoder.flush().then(function() {
            if (failed) {
                throw failed;
            }
        });
    };

    var pick = function(kind, configs) {
        var at = function(i) {
            if (i >= configs.length) {
                return Promise.resolve(null);
            }
            return kind.isConfigSupported(configs[i]).then(function(r) {
                return r.supported ? configs[i] : at(i + 1);
            }, function() {
                return at(i + 1);
            });
        };
        return at(0);
    };

    var load = function(src) {
        return new Promise(function(ok, ko) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = ok;
            s.onerror = ko;
            document.head.appendChild(s);
        });
    };

    /**
     * The video, or null when this browser cannot make it with its sound.
     *
     * @param {Object} o see the top of the file; o.muxer: the URL of mp4-muxer.min.js if it is not loaded yet
     * @param {function(number)} progress
     * @return {Promise<Blob|null>}
     */
    var make = function(o, progress) {
        progress = progress || function() {};
        if (!root.VideoEncoder || !root.AudioEncoder || !root.OfflineAudioContext || !root.AudioData) {
            return Promise.resolve(null);
        }
        var video = ['avc1.640028', 'avc1.4d4028', 'avc1.42e028'].map(function(codec) {
            return {codec: codec, width: W * SCALE, height: H * SCALE, bitrate: 5000000, framerate: FPS,
                avc: {format: 'avc'}};
        });
        video = video.map(function(cfg) {
            return Object.assign({}, cfg, {bitrateMode: 'quantizer'});
        }).concat(video);
        var sound = [{codec: 'mp4a.40.2', sampleRate: RATE, numberOfChannels: 1, bitrate: 96000},
            {codec: 'opus', sampleRate: RATE, numberOfChannels: 1, bitrate: 64000}];
        return Promise.all([pick(root.VideoEncoder, video), pick(root.AudioEncoder, sound),
            root.Mp4Muxer || !o.muxer ? null : load(o.muxer)]).then(function(got) {
            var vc = got[0];
            var ac = got[1];
            if (!vc || !ac || !root.Mp4Muxer) {
                return null;
            }
            return prepare(o).then(function(k) {
                if (!k.plate) {
                    return null;
                }
                progress(0.03);
                return mix(k).then(function(buffer) {
                    progress(0.08);
                    var muxer = new root.Mp4Muxer.Muxer({target: new root.Mp4Muxer.ArrayBufferTarget(),
                        video: {codec: 'avc', width: W * SCALE, height: H * SCALE, frameRate: FPS},
                        audio: {codec: ac.codec === 'opus' ? 'opus' : 'aac', sampleRate: RATE, numberOfChannels: 1},
                        fastStart: 'in-memory', firstTimestampBehavior: 'offset'});
                    return encodeSound(buffer, muxer, ac).then(function() {
                        var failed = null;
                        var encoder = new root.VideoEncoder({
                            output: function(chunk, meta) {
                                muxer.addVideoChunk(chunk, meta);
                            },
                            error: function(e) {
                                failed = e;
                            }
                        });
                        encoder.configure(vc);
                        var canvas = canvasOf(W * SCALE, H * SCALE);
                        var c = canvas.getContext('2d');
                        var n = Math.ceil(TOTAL * FPS);
                        var i = 0;
                        var step = function() {
                            if (failed) {
                                return Promise.reject(failed);
                            }
                            if (i >= n) {
                                return encoder.flush().then(function() {
                                    muxer.finalize();
                                    return new Blob([muxer.target.buffer], {type: 'video/mp4'});
                                });
                            }
                            c.setTransform(SCALE, 0, 0, SCALE, 0, 0);
                            frame(c, k, i / FPS);
                            var vf = new root.VideoFrame(canvas, {timestamp: Math.round(i * 1e6 / FPS),
                                duration: Math.round(1e6 / FPS)});
                            var opts = {keyFrame: i % (FPS * 3) === 0};
                            if (vc.bitrateMode === 'quantizer') {
                                opts.avc = {quantizer: 22};
                            }
                            encoder.encode(vf, opts);
                            vf.close();
                            i++;
                            progress(0.08 + 0.92 * i / n);
                            // A phone encodes slower than it draws, and every frame waiting holds a whole picture:
                            // wait until the encoder has at most two before drawing the next (it filled the memory).
                            if (encoder.encodeQueueSize > 2 || i % 4 === 0) {
                                return new Promise(function(ok) {
                                    var check = function() {
                                        if (encoder.encodeQueueSize <= 2) {
                                            ok();
                                        } else {
                                            setTimeout(check, 4);
                                        }
                                    };
                                    setTimeout(check, 0);
                                }).then(step);
                            }
                            return step();
                        };
                        return step();
                    });
                });
            });
        }).catch(function() {
            return null;
        });
    };

    root.EguaheCumple = {make: make, prepare: prepare, frame: frame, TOTAL: TOTAL};
})(window);
