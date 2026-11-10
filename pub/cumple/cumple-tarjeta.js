/**
 * The birthday of a child from six to eleven as a card to keep (1080 x 1350), and of everyone from twelve on as a
 * small seal beside the words of the message (480 x 480). Made in the browser, on a canvas, as a PNG.
 *
 * The card: the badge a child pins on the backpack that hangs from their name in the panel (the ladder of
 * data/escalera.json), with their age in the middle and their name round the rim, its two ribbons, on the paper of the
 * book; above it, the garland of the class. Each step less childish (Gaudí, 10 November 2026):
 *   6-7 (escolar): coral badge, the whole garland and confetti;
 *   8-9 (figura): sage badge, half the garland, a few dots;
 *   10-11 (llavero): ink-blue badge, no garland, one thin line.
 * With the child's drawing, it goes in a little photo taped beside the badge. The name goes round the rim, or, in a
 * script that joins its letters (Arabic), straight above the number.
 *
 * The seal, for twelve and over: a thin ring, the age in the house's letters and a line, sepia on the paper. Nothing
 * childish.
 *
 *   EguaheCumple.card(o) -> Promise<Blob>     o: {name, age, rtl, drawing, words: {wish, years, from}, fonts}
 *   EguaheCumple.seal(o) -> Promise<Blob>     o: {age, words: {years}, fonts}
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function(root) {
    'use strict';

    var PAPER = '#f6f0e3';
    var SEPIA = '#4a3b2e';
    var STEPS = [
        {upto: 7, face: '#e3806f', ribbon: '#efc35f', rim: '#f4d9cf', garland: 1, dots: 26},
        {upto: 9, face: '#6f9c86', ribbon: '#e3806f', rim: '#dbe9df', garland: 0.5, dots: 10},
        {upto: 99, face: '#34506f', ribbon: '#c9a46a', rim: '#d9e1ea', garland: 0, dots: 0}
    ];
    var PENNANTS = ['#e98e7f', '#efc35f', '#9fbf8d', '#86aecb', '#e3a5c0', '#f2a46a'];

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
    var blobOf = function(cv) {
        return new Promise(function(ok) {
            cv.toBlob(ok, 'image/png');
        });
    };
    var fonts = function(o) {
        var f = o.fonts || {};
        return {sans: f.sans || '"Schibsted Grotesk", sans-serif', hand: f.hand || 'Caveat, cursive'};
    };
    // The paper, with its fixed grain.
    var paper = function(c, w, h, seed) {
        c.fillStyle = PAPER;
        c.fillRect(0, 0, w, h);
        var img = c.getImageData(0, 0, w, h);
        var d = img.data;
        var r = rng(seed);
        for (var i = 0; i < d.length; i += 4) {
            var v = 0.955 + 0.045 * r();
            d[i] *= v;
            d[i + 1] *= v;
            d[i + 2] *= v;
        }
        c.putImageData(img, 0, 0);
    };
    var fit = function(c, text, weight, family, max, width) {
        var size = max;
        c.font = weight + ' ' + size + 'px ' + family;
        while (c.measureText(text).width > width && size > 16) {
            size -= 1;
            c.font = weight + ' ' + size + 'px ' + family;
        }
        return size;
    };

    // The garland of the class across the top, as in the picture of the birthday: a string and its pennants.
    var garland = function(c, w, share, r) {
        if (!share) {
            c.strokeStyle = 'rgba(74,59,46,0.35)';
            c.lineWidth = 2;
            c.beginPath();
            c.moveTo(w * 0.3, 96);
            c.lineTo(w * 0.7, 96);
            c.stroke();
            return;
        }
        var x0 = share < 1 ? w * 0.22 : -20;
        var x1 = share < 1 ? w * 0.78 : w + 20;
        var sag = 70;
        var y = function(x) {
            var u = (x - x0) / (x1 - x0);
            return 60 + sag * 4 * u * (1 - u);
        };
        c.strokeStyle = 'rgba(74,59,46,0.55)';
        c.lineWidth = 2.5;
        c.beginPath();
        for (var x = x0; x <= x1; x += 8) {
            c[x === x0 ? 'moveTo' : 'lineTo'](x, y(x));
        }
        c.stroke();
        var n = share < 1 ? 7 : 13;
        for (var i = 0; i < n; i++) {
            var px = x0 + (i + 0.5) * (x1 - x0) / n;
            var py = y(px);
            c.save();
            c.translate(px, py);
            c.rotate((r() - 0.5) * 0.18);
            c.fillStyle = PENNANTS[i % PENNANTS.length];
            c.beginPath();
            c.moveTo(-26, 0);
            c.lineTo(26, 0);
            c.lineTo(0, 58);
            c.closePath();
            c.fill();
            c.restore();
        }
    };

    // Letters round the top of a circle, centred on its top.
    var arc = function(c, text, cx, cy, radius, size, family) {
        c.save();
        c.font = '700 ' + size + 'px ' + family;
        var widths = Array.prototype.map.call(text, function(ch) {
            return c.measureText(ch).width + size * 0.06;
        });
        var total = widths.reduce(function(a, b) {
            return a + b;
        }, 0);
        var angle = -Math.PI / 2 - total / radius / 2;
        c.textAlign = 'center';
        c.textBaseline = 'middle';
        for (var i = 0; i < text.length; i++) {
            var a = angle + widths[i] / 2 / radius;
            c.save();
            c.translate(cx + Math.cos(a) * radius, cy + Math.sin(a) * radius);
            c.rotate(a + Math.PI / 2);
            c.fillText(text[i], 0, 0);
            c.restore();
            angle += widths[i] / radius;
        }
        c.restore();
    };

    // The badge: its ribbons behind, the metal rim, the face, a thin inner ring, the age and the name.
    var badge = function(c, o, f, step, cx, cy, R) {
        // the ribbons, cut in a V
        [-1, 1].forEach(function(side) {
            c.save();
            c.translate(cx + side * R * 0.32, cy + R * 0.55);
            c.rotate(side * 0.22);
            c.fillStyle = step.ribbon;
            c.beginPath();
            c.moveTo(-R * 0.17, 0);
            c.lineTo(R * 0.17, 0);
            c.lineTo(R * 0.17, R * 0.95);
            c.lineTo(0, R * 0.8);
            c.lineTo(-R * 0.17, R * 0.95);
            c.closePath();
            c.fill();
            c.fillStyle = 'rgba(0,0,0,0.12)';
            c.fillRect(-R * 0.17, 0, R * 0.34, R * 0.12);
            c.restore();
        });
        // its shadow on the paper
        c.fillStyle = 'rgba(74,59,46,0.16)';
        c.beginPath();
        c.ellipse(cx + 10, cy + 16, R, R, 0, 0, Math.PI * 2);
        c.fill();
        // the rim, catching the light from the top left
        var g = c.createLinearGradient(cx - R, cy - R, cx + R, cy + R);
        g.addColorStop(0, '#fbfaf6');
        g.addColorStop(0.5, step.rim);
        g.addColorStop(1, '#b9b3a8');
        c.fillStyle = g;
        c.beginPath();
        c.arc(cx, cy, R, 0, Math.PI * 2);
        c.fill();
        // the face
        c.fillStyle = step.face;
        c.beginPath();
        c.arc(cx, cy, R * 0.9, 0, Math.PI * 2);
        c.fill();
        var sheen = c.createRadialGradient(cx - R * 0.4, cy - R * 0.5, R * 0.05, cx - R * 0.2, cy - R * 0.3, R * 1.1);
        sheen.addColorStop(0, 'rgba(255,255,255,0.28)');
        sheen.addColorStop(0.5, 'rgba(255,255,255,0.04)');
        sheen.addColorStop(1, 'rgba(0,0,0,0.12)');
        c.fillStyle = sheen;
        c.fill();
        c.strokeStyle = 'rgba(255,250,240,0.7)';
        c.lineWidth = 3;
        c.beginPath();
        c.arc(cx, cy, R * 0.66, 0, Math.PI * 2);
        c.stroke();
        // the age
        c.fillStyle = '#fffaf0';
        c.textAlign = 'center';
        c.textBaseline = 'alphabetic';
        c.font = '800 ' + Math.round(R * 0.95) + 'px ' + f.sans;
        c.fillText(String(o.age), cx, cy + R * 0.33);
        // the name round the rim, or straight above the number in a joined script
        c.fillStyle = '#fffaf0';
        if (o.rtl) {
            var size = fit(c, o.name, 700, f.sans, Math.round(R * 0.16), R * 0.9);
            c.font = '700 ' + size + 'px ' + f.sans;
            c.direction = 'rtl';
            c.fillText(o.name, cx, cy - R * 0.43);
        } else {
            var big = Math.round(R * 0.15);
            arc(c, o.name, cx, cy, R * 0.78, o.name.length > 14 ? Math.round(big * 14 / o.name.length) : big, f.sans);
        }
    };

    // The child's drawing in a little photo with a strip of tape.
    var photo = function(c, img, x, y, w, angle) {
        var h = w * 1.18;
        c.save();
        c.translate(x, y);
        c.rotate(angle);
        c.fillStyle = 'rgba(74,59,46,0.18)';
        c.fillRect(-w / 2 + 8, -h / 2 + 10, w, h);
        c.fillStyle = '#fffdf8';
        c.fillRect(-w / 2, -h / 2, w, h);
        var m = w * 0.07;
        var iw = w - 2 * m;
        var ih = iw;
        var s = Math.min(iw / img.width, ih / img.height);
        c.save();
        c.beginPath();
        c.rect(-w / 2 + m, -h / 2 + m, iw, ih);
        c.clip();
        c.fillStyle = '#f3ede0';
        c.fillRect(-w / 2 + m, -h / 2 + m, iw, ih);
        c.drawImage(img, -img.width * s / 2, -h / 2 + m + (ih - img.height * s) / 2, img.width * s, img.height * s);
        c.restore();
        c.fillStyle = 'rgba(236,224,196,0.82)';
        c.save();
        c.translate(0, -h / 2);
        c.rotate(-0.05);
        c.fillRect(-w * 0.22, -14, w * 0.44, 30);
        c.restore();
        c.restore();
    };

    var card = function(o) {
        var W = 1080;
        var H = 1350;
        var f = fonts(o);
        var step = STEPS.filter(function(s) {
            return o.age <= s.upto;
        })[0];
        return Promise.all([image(o.drawing), document.fonts ? document.fonts.ready : null]).then(function(got) {
            var cv = document.createElement('canvas');
            cv.width = W;
            cv.height = H;
            var c = cv.getContext('2d');
            var r = rng((o.age || 1) * 131 + (o.name || '').length);
            paper(c, W, H, 11);
            garland(c, W, step.garland, r);
            // a few paper dots, for the youngest
            for (var i = 0; i < step.dots; i++) {
                c.fillStyle = PENNANTS[i % PENNANTS.length];
                c.globalAlpha = 0.7;
                var x = r() * W;
                var y = 180 + r() * 700;
                if (Math.abs(x - W / 2) < 330 && y > 230 && y < 900) {
                    continue;
                }
                c.save();
                c.translate(x, y);
                c.rotate(r() * 6.3);
                c.fillRect(-7, -4, 14, 8);
                c.restore();
            }
            c.globalAlpha = 1;
            var drawing = got[0];
            var cx = drawing ? W * 0.41 : W / 2;
            badge(c, o, f, step, cx, 560, 300);
            if (drawing) {
                photo(c, drawing, W * 0.83, 880, 220, 0.09);
            }
            // the words, centred under the badge, never on it
            c.textAlign = 'center';
            c.direction = o.rtl ? 'rtl' : 'ltr';
            c.fillStyle = SEPIA;
            var s1 = fit(c, o.words.wish, 600, f.sans, 64, 940);
            c.font = '600 ' + s1 + 'px ' + f.sans;
            c.fillText(o.words.wish, W / 2, 1150);
            var s2 = fit(c, o.words.years, 500, f.sans, 38, 940);
            c.font = '500 ' + s2 + 'px ' + f.sans;
            c.fillText(o.words.years, W / 2, 1212);
            var s3 = fit(c, o.words.from, 600, f.hand, 44, 940);
            c.font = '600 ' + s3 + 'px ' + f.hand;
            c.fillStyle = 'rgba(74,59,46,0.8)';
            c.fillText(o.words.from, W / 2, 1290);
            return blobOf(cv);
        });
    };

    var seal = function(o) {
        var S = 480;
        var f = fonts(o);
        return (document.fonts ? document.fonts.ready : Promise.resolve()).then(function() {
            var cv = document.createElement('canvas');
            cv.width = S;
            cv.height = S;
            var c = cv.getContext('2d');
            paper(c, S, S, 5);
            var cx = S / 2;
            var cy = S / 2;
            c.strokeStyle = SEPIA;
            c.lineWidth = 3;
            c.beginPath();
            c.arc(cx, cy, 196, 0, Math.PI * 2);
            c.stroke();
            c.lineWidth = 1.2;
            c.beginPath();
            c.arc(cx, cy, 184, 0, Math.PI * 2);
            c.stroke();
            c.fillStyle = SEPIA;
            c.textAlign = 'center';
            c.font = '700 168px ' + f.sans;
            c.fillText(String(o.age), cx, cy + 46);
            // the line under the number and the word of the years, small, in the house's coral
            c.strokeStyle = '#c4573f';
            c.lineWidth = 2.5;
            c.beginPath();
            c.moveTo(cx - 54, cy + 84);
            c.lineTo(cx + 54, cy + 84);
            c.stroke();
            var size = fit(c, o.words.years, 600, f.sans, 30, 260);
            c.font = '600 ' + size + 'px ' + f.sans;
            c.fillText(o.words.years, cx, cy + 128);
            return blobOf(cv);
        });
    };

    root.EguaheCumple = Object.assign(root.EguaheCumple || {}, {card: card, seal: seal});
})(window);
