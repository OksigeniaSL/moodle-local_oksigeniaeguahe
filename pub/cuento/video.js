/**
 * «Tu primer día» as a video to keep: the book read aloud, page by page, with its voice, the door between the pages
 * and the song under it that goes down while the voice speaks, as the book plays it; the child's name on each
 * illustration, their drawing drawing itself on the wall of the class, and at the end the dedication, the gift of the
 * centre and the seal of Eguahe. It travels as a file, so whoever receives it keeps it (Enrique, 9-11).
 *
 * Made in this page, where the book already is (cuento.js: BOOK, VOICE, T and the rest are its globals): the sound
 * mixed once with an OfflineAudioContext and encoded with AudioEncoder (AAC where there is, else Opus); the frames drawn
 * on a canvas and encoded with VideoEncoder; both packed by mp4-muxer into one MP4. Without those, no video: the sheet
 * offers the story heard, as «Save it» always did. The sheet is the one of the drawing (../dibujo-video.js).
 *
 * How it looks (Gaudí, 9 November 2026, with the grid Kimi K3 proposed): every frame is a page of the picture book, on
 * one grid. The illustration fills the top of the screen, and the paper of the book, always the same, lies under it;
 * the picture ends on the paper as a wash of paint ends, and the paper keeps a vein of its colour. The words come in
 * under it, line by line with the voice, centred, in sepia, the child's name in its own hand. The camera only comes
 * closer, slowly, to what the page is about, and moves only twice, because it tells something: from the chalkboard to
 * the teacher, and from the child's drawing on the wall to the class. Between pages, the picture and the words fade
 * through the cream of the paper; the sheet turns once, when the book opens. At the end, the dedication writes itself
 * by hand, and the gift of the centre and the seal close it.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* global BOOK, VOICE, VOICE_NAME, T, tt, tr, LANG, RTL, MAESTRO, TUTOR, CENTRE_PHRASE, M, BED, DUCK, DOOR */
(function(root) {
    'use strict';
    if (typeof BOOK === 'undefined' || !BOOK.video) {
        return;
    }
    // The frames are drawn in 720 x 1280 units on a canvas of 1080 x 1920 (Full HD, upright): sharp on any phone.
    var W = 720;
    var H = 1280;
    var SCALE = 1.5;
    var FPS = 24;
    var RATE = 48000;
    var here = BOOK.base;
    var V = BOOK.video;

    // The time of the book. The label sticks on the cover before its voice; the book opens with a sheet that turns
    // (TURN); then each page comes in through the cream of the paper (FADE), the door sounding as it goes (DOOR_AT), and
    // its voice starts a breath after (LEAD); the last line stays a second after the last word (HOLD). The child's
    // drawing draws itself as its page comes in (DRAW_AT), and the camera goes from it to the class. The dedication
    // writes itself by hand, then come the gift and the seal.
    var COVER_VOICE = 0.9;
    var COVER_HOLD = 1.6;
    var TURN = 1.15;
    var FADE = 0.6;
    var DOOR_AT = 0.1;
    var LEAD = 0.35;
    var HOLD = 1.0;
    var CHALK_AFTER = 0.45;
    var DRAW_AT = 0.6;
    var WRITE_FROM = 0.8;
    var WRITE_CPS = 15;

    var load = function(src) {
        return new Promise(function(ok, ko) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = ok;
            s.onerror = ko;
            document.head.appendChild(s);
        });
    };
    var image = function(src) {
        return new Promise(function(ok) {
            if (!src) {
                ok(null);
                return;
            }
            var img = new Image();
            img.onload = function() {
                ok(img);
            };
            img.onerror = function() {
                ok(null);
            };
            img.src = src;
        });
    };
    var fetchAudio = function(ctx, url) {
        return root.fetch(url, {credentials: 'same-origin'}).then(function(r) {
            if (!r.ok) {
                throw new Error(String(r.status));
            }
            return r.arrayBuffer();
        }).then(function(b) {
            return new Promise(function(ok, ko) {
                ctx.decodeAudioData(b, ok, ko);
            });
        });
    };
    var teacher = function() {
        return typeof TUTOR !== 'undefined' ? TUTOR : (BOOK.tutors || {})[MAESTRO ? 'maestro' : 'maestra'] || '';
    };
    var fill = function(text) {
        return String(text || '').replace(/\{nombre\}/g, BOOK.child).replace(/\{centro\}/g, CENTRE_PHRASE)
            .replace(/\{tutor\}/g, teacher());
    };
    var pageOf = function(page) {
        return MAESTRO && page.maestro ? Object.assign({}, page, page.maestro) : page;
    };
    var pageText = function(page) {
        return String(tt('page' + page.n) || '');
    };
    var dedicationLines = function() {
        return String(tt('dedication') || T.dedication || '');
    };

    // --- Time ----------------------------------------------------------------------------------------------------------

    /**
     * How long a line takes to say, by its letters.
     *
     * @param {string} line
     * @return {number}
     */
    var letters = function(line) {
        return String(line).replace(/[\s.,;:!?¡¿«»“”"'…\-–—()\u060c\u061f\u061b]/g, '').length;
    };

    /**
     * When the voice starts each line of a page. The voice has its stretches of speech (VOICE.speech); the words share
     * the time spoken by their letters, and a line that starts near a pause starts when the pause ends. What the voice
     * does not say counts as it says it: the name only in the reading that has it, never the centre (the voice says «your
     * new school») nor the teacher's name.
     *
     * @param {string[]} lines The lines of the page, with their gaps.
     * @param {Array[]} segs The stretches of speech of its voice, in seconds from its start.
     * @param {number} at When its voice starts.
     * @return {number[]}
     */
    var lineTimes = function(lines, segs, at) {
        var weight = lines.map(function(line) {
            return Math.max(1, letters(line.replace(/\{nombre\}/g, VOICE_NAME === 'nombre' ? BOOK.child : 'xxxx')
                .replace(/\{centro\}/g, 'xxxxxxxxx').replace(/\{tutor\}/g, '')));
        });
        var all = weight.reduce(function(a, b) {
            return a + b;
        }, 0);
        var spoken = segs.reduce(function(a, s) {
            return a + s[1] - s[0];
        }, 0);
        var onFile = function(x) {
            for (var j = 0; j < segs.length; j++) {
                var d = segs[j][1] - segs[j][0];
                if (x < d - 1e-6) {
                    return segs[j][0] + x;
                }
                x -= d;
            }
            return segs[segs.length - 1][1];
        };
        var out = [];
        var done = 0;
        var used = {};
        lines.forEach(function(line, i) {
            var guess = i === 0 ? segs[0][0] : onFile(spoken * done / all);
            if (i > 0) {
                var best = -1;
                segs.forEach(function(s, j) {
                    var d = Math.abs(s[0] - guess);
                    if (j > 0 && !used[j] && d < 0.9 && s[0] > out[i - 1] - at + 0.3 &&
                            (best < 0 || d < Math.abs(segs[best][0] - guess))) {
                        best = j;
                    }
                });
                if (best >= 0) {
                    used[best] = true;
                    guess = segs[best][0];
                }
                guess = Math.max(guess, out[i - 1] - at + 0.35);
            }
            out.push(at + guess);
            done += weight[i];
        });
        return out;
    };

    /**
     * When each page comes in, shows, speaks and goes; what happens on it (the chalk, the drawing, the one move of the
     * camera on the two pages that have one); and the door.
     *
     * @param {Object} voices Page audio name => AudioBuffer.
     * @return {Object} pages and total seconds; close, the door that closes the book.
     */
    var plan = function(voices) {
        var t = 0;
        var out = [];
        BOOK.pages.forEach(function(page, i) {
            var p = pageOf(page);
            var voice = p.audio && voices[p.audio] ? voices[p.audio] : null;
            var prev = out[i - 1];
            var e = {i: i, page: page, voice: voice, start: t, shown: t, lines: []};
            if (i > 0) {
                e.shown = t + (i === 1 ? TURN : FADE);
                e.door = t + DOOR_AT;
            }
            if (p.kind === 'dedication') {
                var said = dedicationLines().split('\n');
                var chars = said.reduce(function(a, l) {
                    return a + fill(l).length;
                }, 0);
                e.write = [e.shown + WRITE_FROM, e.shown + WRITE_FROM + chars / WRITE_CPS + 0.4 * (said.length - 1)];
                e.gift = e.write[1] + 0.9;
                e.seal = e.gift + 1.6;
                e.end = e.seal + 3.6;
            } else {
                var segs = voice ? (VOICE.speech[p.audio] || []).filter(function(s) {
                    return s[1] > s[0];
                }) : [];
                if (!segs.length) {
                    segs = [[0, voice ? Math.max(0.5, voice.duration - 1.2) : 4]];
                }
                e.at = i === 0 ? COVER_VOICE : e.shown + LEAD;
                if (prev && prev.voice) {
                    e.at = Math.max(e.at, prev.at + prev.voice.duration + 0.05);
                }
                e.said = e.at + segs[segs.length - 1][1];
                e.end = e.said + (p.kind === 'cover' ? COVER_HOLD : HOLD);
                if (p.kind !== 'cover') {
                    var raw = pageText(p).split('\n');
                    e.lines = lineTimes(raw, segs, e.at);
                    // The chalk writes the name while the voice reads the line that says it.
                    var chalk = (p.names || []).some(function(n) {
                        return n.kind === 'chalk';
                    });
                    for (var j = 0; chalk && j < raw.length; j++) {
                        if (raw[j].indexOf('{nombre}') >= 0) {
                            e.chalk = e.lines[j] + CHALK_AFTER;
                            break;
                        }
                    }
                    // The child's drawing draws itself on the wall of the class as its page comes in.
                    if (p.frame && BOOK.drawing) {
                        var strokes = BOOK.drawing.strokes;
                        var live = strokes && root.DibujoVivo ? (+strokes.seconds || 3) + 1.6 : 1.2;
                        e.draw = [e.shown + DRAW_AT, e.shown + DRAW_AT + live];
                    }
                    // The one move of the camera, slow, on a page that has one; the page waits for it to settle.
                    var shots = framesOf(e);
                    if (shots.length > 1) {
                        var from = when(e, shots[1].from);
                        var pa = place(shots[0], shots[0].z);
                        var pb = place(shots[1], zoom(shots[1], 0));
                        var way = Math.sqrt((pb.x - pa.x) * (pb.x - pa.x) + (pb.y - pa.y) * (pb.y - pa.y)) +
                            Math.abs(Math.log(shots[0].z / zoom(shots[1], 0))) * 200;
                        e.move = [from, from + (shots[1].dur || Math.max(MOVE, way / CALM))];
                        e.end = Math.max(e.end, e.move[1] + HOLD);
                    }
                }
            }
            out.push(e);
            t = e.end;
        });
        var last = out[out.length - 1];
        return {pages: out, total: t + 0.3, close: last && last.seal != null ? last.seal : null};
    };

    /**
     * The sound of the whole video, mixed as the book plays it: each voice at its time, the door as each page turns and
     * when the seal closes the book, and the song looped under them, down while the voice speaks and fading at the end.
     *
     * @param {Object} pl plan()
     * @param {AudioBuffer} bed
     * @param {AudioBuffer} doorBuf
     * @return {Promise<AudioBuffer>}
     */
    var mix = function(pl, bed, doorBuf) {
        var OAC = root.OfflineAudioContext || root.webkitOfflineAudioContext;
        var ctx = new OAC(1, Math.ceil(pl.total * RATE), RATE);
        var bedGain = ctx.createGain();
        bedGain.connect(ctx.destination);
        var src = ctx.createBufferSource();
        src.buffer = bed;
        src.loop = true;
        src.loopStart = M.pre;
        src.loopEnd = M.pre + M.loopSeconds;
        src.connect(bedGain);
        src.start(0, M.pre);
        var g = bedGain.gain;
        g.setValueAtTime(0, 0);
        g.setTargetAtTime(BED, 0, 0.6);
        var ring = function(at) {
            if (!doorBuf || at == null) {
                return;
            }
            var d = ctx.createBufferSource();
            var dg = ctx.createGain();
            dg.gain.value = DOOR;
            d.buffer = doorBuf;
            d.connect(dg);
            dg.connect(ctx.destination);
            d.start(Math.max(0, at));
        };
        pl.pages.forEach(function(e) {
            if (e.voice) {
                var s = ctx.createBufferSource();
                s.buffer = e.voice;
                s.connect(ctx.destination);
                s.start(e.at);
                (VOICE.speech[pageOf(e.page).audio] || []).forEach(function(seg) {
                    g.setTargetAtTime(BED * DUCK, e.at + Math.max(0, seg[0] - 0.05), 0.03);
                    g.setTargetAtTime(BED, e.at + seg[1] + 0.12, 0.12);
                });
            }
            ring(e.door);
        });
        ring(pl.close);
        g.setTargetAtTime(0, pl.total - 2.2, 0.6);
        return ctx.startRendering ? new Promise(function(ok, ko) {
            var r = ctx.startRendering();
            if (r && r.then) {
                r.then(ok, ko);
            } else {
                ctx.oncomplete = function(ev) {
                    ok(ev.renderedBuffer);
                };
            }
        }) : Promise.reject(new Error('no render'));
    };

    // --- The grid ------------------------------------------------------------------------------------------------------

    // One grid for every frame, the cover and the dedication too (Gaudí, with Kimi K3's proposal, 9 November 2026), in
    // units of 720 x 1280 (the pixels of the video divided by 1.5), on a module of 8 pixels:
    // - the picture: the whole width, from the top down to PLATE (1056 px), the illustration at 1584 x 1056 px and
    //   cropped at the sides; what matters lives in what shows, the central 68 %;
    // - the seam, from SEAM_TOP to SEAM_BOTTOM (936 to 1152 px): the picture ends on the paper as a wash of paint ends,
    //   along a line that wanders EDGE ± 16 (± 24 px), and the paper keeps a little of its colour under it;
    // - the paper, the same on every page (only the picture and the words change), PAPER with a fixed grain of 4 to 6 %
    //   and a vein of the colour of each picture at 5 %;
    // - the words, between BOX_TOP and BOX_BOTTOM (1248 to 1704 px: never lower, where a player puts its controls),
    //   MEASURE wide (780 px), centred, in SEPIA, 46 px on 64, or the next size down in a language that needs it
    //   (SIZES); the name in its own hand at 1.15 times;
    // - the folio, centred on FOLIO (1848 px), 26 px, in sepia at half.
    var PLATE = 704;
    var SEAM_TOP = 624;
    var SEAM_BOTTOM = 768;
    var EDGE = 676;
    var BOX_TOP = 832;
    var BOX_BOTTOM = 1136;
    var MEASURE = 520;
    var FOLIO = 1232;
    var SIZES = [[46, 64], [42, 58], [40, 56], [38, 53]];
    var SEPIA = '#4a3b2e';
    var PAPER = '#f6f0e3';
    // the scale of the illustration (1536 x 1024) when it just fills the height of the picture
    var BASE = PLATE / 1024;
    // where the paper laid over the picture begins, a little above the seam
    var ZONE = 600;
    var INK = '#2b2226';
    var CHALK = '#f4f1e8';
    var PAPER_BACK = '#eee5d6';
    var INK_SOFT = '#6b5d50';
    var SANS = '"Schibsted Grotesk", sans-serif';
    var HAND = 'Caveat, cursive';

    var clamp = function(x, a, b) {
        return x < a ? a : x > b ? b : x;
    };
    var smooth = function(a, b, x) {
        var u = clamp((x - a) / (b - a), 0, 1);
        return u * u * (3 - 2 * u);
    };
    var easeOut = function(u) {
        u = clamp(u, 0, 1);
        return 1 - Math.pow(1 - u, 3);
    };
    var easeInOut = function(u) {
        u = clamp(u, 0, 1);
        return u < 0.5 ? 4 * u * u * u : 1 - Math.pow(-2 * u + 2, 3) / 2;
    };
    var sine = function(u) {
        u = clamp(u, 0, 1);
        return 0.5 - 0.5 * Math.cos(Math.PI * u);
    };
    var rad = function(deg) {
        return (deg || 0) * Math.PI / 180;
    };
    var rng = function(seed) {
        var s = seed >>> 0 || 1;
        return function() {
            s ^= s << 13;
            s >>>= 0;
            s ^= s >>> 17;
            s ^= s << 5;
            s >>>= 0;
            return s / 4294967296;
        };
    };
    var rgbOf = function(hex) {
        var h = String(hex || '').replace('#', '');
        if (h.length === 3) {
            h = h.replace(/./g, '$&$&');
        }
        var n = parseInt(h.slice(0, 6), 16);
        return isNaN(n) ? [251, 247, 239] : [n >> 16 & 255, n >> 8 & 255, n & 255];
    };
    var css = function(rgb, a) {
        return 'rgba(' + rgb.map(Math.round).join(',') + ',' + (a == null ? 1 : a) + ')';
    };
    // The direction of a piece kept whole (a name, the centre): that of its first strong letter.
    var dirOf = function(s) {
        var m = /[A-Za-zÀ-ɏͰ-ԯ]|[֐-ࣿיִ-﷿ﹰ-﻿]/.exec(String(s));
        return m && /[֐-ࣿיִ-﷿ﹰ-﻿]/.test(m[0]) ? 'rtl' : m ? 'ltr' : RTL ? 'rtl' : 'ltr';
    };
    var canvasOf = function(w, h) {
        var cv = document.createElement('canvas');
        cv.width = Math.max(1, Math.round(w));
        cv.height = Math.max(1, Math.round(h));
        return cv;
    };

    /**
     * Smooth value noise in [0, 1], with `cell` pixels between its knots.
     *
     * @param {number} w
     * @param {number} h
     * @param {number} cell
     * @param {number} seed
     * @return {Float32Array}
     */
    var field = function(w, h, cell, seed) {
        var r = rng(seed);
        var gw = Math.ceil(w / cell) + 2;
        var gh = Math.ceil(h / cell) + 2;
        var g = new Float32Array(gw * gh);
        for (var i = 0; i < g.length; i++) {
            g[i] = r();
        }
        var out = new Float32Array(w * h);
        for (var y = 0; y < h; y++) {
            var fy = y / cell;
            var iy = fy | 0;
            var ty = fy - iy;
            ty = ty * ty * (3 - 2 * ty);
            for (var x = 0; x < w; x++) {
                var fx = x / cell;
                var ix = fx | 0;
                var tx = fx - ix;
                tx = tx * tx * (3 - 2 * tx);
                var a = iy * gw + ix;
                var top = g[a] + (g[a + 1] - g[a]) * tx;
                var bottom = g[a + gw] + (g[a + gw + 1] - g[a + gw]) * tx;
                out[y * w + x] = top + (bottom - top) * ty;
            }
        }
        return out;
    };

    /**
     * The grain of the paper, once, at the pixels of the video: a soft mottle of fibres and a fine tooth, in [-1, 1]. It
     * never moves: the paper stays where it is and only the picture moves under the camera (a grain that moved would turn
     * into blocks at the bitrate of the video).
     *
     * @param {Object} L look()
     * @return {Float32Array}
     */
    var grainOf = function(L) {
        if (L.grain) {
            return L.grain;
        }
        var w = L.pw;
        var h = L.ph;
        var big = field(w, h, 70 * L.dpr, 7);
        var mid = field(w, h, 11 * L.dpr, 11);
        var fine = field(w, h, 2.2 * L.dpr, 13);
        var r = rng(17);
        var g = new Float32Array(w * h);
        for (var i = 0; i < g.length; i++) {
            g[i] = (big[i] - 0.5) * 0.9 + (mid[i] - 0.5) * 0.7 + (fine[i] - 0.5) * 0.9 + (r() - 0.5) * 0.35;
        }
        L.grain = g;
        return g;
    };

    /**
     * The colour of the paper before its grain: a touch lighter than PAPER, so that with its grain on it, it is PAPER.
     *
     * @return {number[]}
     */
    var paperBase = function() {
        return rgbOf(PAPER).map(function(v) {
            return Math.min(255, v / 0.97);
        });
    };

    /**
     * The paper of the book, under everything, the same on every page.
     *
     * @param {CanvasRenderingContext2D} c
     */
    var paper = function(c) {
        c.fillStyle = css(paperBase());
        c.fillRect(0, 0, W, H);
    };

    /**
     * The grain of the paper, as a layer the frame is multiplied by (94 to 100 %), made once at the pixels of the video:
     * on the picture and on the paper alike, which are one sheet, and never on the words. It never moves.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} L
     */
    var grain = function(c, L) {
        if (!L.grainLayer) {
            var g = grainOf(L);
            var cv = canvasOf(L.pw, L.ph);
            var x = cv.getContext('2d');
            var img = x.createImageData(L.pw, L.ph);
            var d = img.data;
            for (var i = 0, j = 0; i < g.length; i++, j += 4) {
                var v = 255 * (0.94 + 0.06 * clamp(0.5 + g[i] * 0.35, 0, 1));
                d[j] = v;
                d[j + 1] = v;
                d[j + 2] = v;
                d[j + 3] = 255;
            }
            x.putImageData(img, 0, 0);
            L.grainLayer = cv;
        }
        c.save();
        c.globalCompositeOperation = 'multiply';
        c.drawImage(L.grainLayer, 0, 0, W, H);
        c.restore();
    };

    /**
     * The colour of an illustration where it meets the words: the average of its lower part, for the paper to take it.
     *
     * @param {HTMLImageElement} img
     * @return {number[]|null}
     */
    var toneOf = function(img) {
        if (!img) {
            return null;
        }
        try {
            var cv = canvasOf(48, 32);
            var x = cv.getContext('2d');
            x.drawImage(img, 0, 0, 48, 32);
            var d = x.getImageData(0, 18, 48, 14).data;
            var s = [0, 0, 0];
            for (var i = 0; i < d.length; i += 4) {
                s[0] += d[i];
                s[1] += d[i + 1];
                s[2] += d[i + 2];
            }
            var n = d.length / 4;
            return [s[0] / n, s[1] / n, s[2] / n];
        } catch (err) {
            return null;
        }
    };

    /**
     * The colour of the picture of a page (the paper of the book where there is none), once.
     *
     * @param {Object} L
     * @param {Object} k
     * @param {Object} e
     * @return {number[]}
     */
    var toneFor = function(L, k, e) {
        if (!L.tones[e.i]) {
            L.tones[e.i] = toneOf(k.images[pageOf(e.page).img]) || rgbOf(k.paper);
        }
        return L.tones[e.i];
    };

    /**
     * The paper of a page laid over its picture, as a canvas from ZONE down at the pixels of the video: the picture ends
     * there as a wash of paint ends on paper, along a brushed line that wanders EDGE ± 16 units, frayed by the grain of
     * the paper and with its pigment a little deeper where it stops; under it, the paper of the book, with a vein of the
     * colour of the picture at 5 %, a little more right under the paint, gone by the end of the seam. (Gaudí chose the
     * edge of a wash between three ways, with the frames in front: a melt into grainy paper read as fog, and a sheet with
     * a deckled edge laid over the picture read as a collage.) Kept for the three pages around the one shown.
     *
     * @param {Object} L
     * @param {Object} k
     * @param {Object} e The page in the plan.
     * @return {HTMLCanvasElement}
     */
    var zone = function(L, k, e) {
        var key = e.i;
        var hit = L.zones.filter(function(z) {
            return z.key === key;
        })[0];
        if (hit) {
            return hit.canvas;
        }
        var g = grainOf(L);
        var base = paperBase();
        var tone = toneFor(L, k, e);
        var deep = tone.map(function(v) {
            return v * 0.42;
        });
        var w = L.pw;
        var top = Math.round(ZONE * L.dpr);
        var h = L.ph - top;
        var cv = canvasOf(w, h);
        var x = cv.getContext('2d');
        var img = x.createImageData(w, h);
        var d = img.data;
        var edge = field(w, 1, 90 * L.dpr, 23 + e.i);
        var wave = field(w, 1, 26 * L.dpr, 31 + e.i);
        var vein = field(w, h, 150 * L.dpr, 37 + e.i);
        var feather = 3.2 * L.dpr;
        var rim = 13 * L.dpr;
        var end = SEAM_BOTTOM * L.dpr - top;
        for (var px = 0; px < w; px++) {
            var line = (EDGE + (edge[px] - 0.5) * 22 + (wave[px] - 0.5) * 10) * L.dpr - top;
            for (var py = 0; py < h; py++) {
                var i = py * w + px;
                var j = i * 4;
                var gi = g[i + top * w];
                var at = py - line + gi * 2.2 * L.dpr;
                // the paper, and specks of it showing through the thin paint near the edge
                var al = Math.max(smooth(-feather, feather, at),
                    smooth(-rim * 1.2, 0, at) * clamp((gi - 0.25) * 0.5, 0, 0.3));
                // the pigment that gathers where the wash stops
                var ra = 0.24 * smooth(-rim, -feather * 0.4, at) * (1 - al);
                var tint = 0.05 * (0.35 + 0.65 * vein[i]) + 0.07 * (1 - smooth(line, end, py));
                var oa = al + ra;
                for (var ch = 0; ch < 3; ch++) {
                    var pc = base[ch] * (1 - tint) + tone[ch] * tint;
                    d[j + ch] = oa ? (pc * al + deep[ch] * ra) / oa : 0;
                }
                d[j + 3] = 255 * Math.min(1, oa);
            }
        }
        x.putImageData(img, 0, 0);
        L.zones.unshift({key: key, canvas: cv});
        L.zones.length = Math.min(L.zones.length, 3);
        return cv;
    };

    // --- Words -------------------------------------------------------------------------------------------------------

    /**
     * The runs of a line of the book: its words and, kept whole as the book keeps them (<bdi>), the child's name (by
     * hand), the centre and the teacher.
     *
     * @param {string} line
     * @return {Object[]}
     */
    var runsOf = function(line) {
        var out = [];
        var re = /\{(nombre|centro|tutor)\}/g;
        var last = 0;
        var m;
        while ((m = re.exec(line))) {
            if (m.index > last) {
                out.push({kind: 'text', s: line.slice(last, m.index)});
            }
            out.push({kind: m[1] === 'nombre' ? 'name' : 'iso', id: m.index,
                s: m[1] === 'nombre' ? BOOK.child : m[1] === 'centro' ? CENTRE_PHRASE : teacher()});
            last = re.lastIndex;
        }
        if (last < line.length) {
            out.push({kind: 'text', s: line.slice(last)});
        }
        return out;
    };

    /**
     * The words of a line, where it can break: each a list of pieces (a name and its punctuation stay together).
     *
     * @param {string} line
     * @return {Array[]}
     */
    var wordsOf = function(line) {
        var words = [];
        var cur = [];
        var cut = function() {
            if (cur.length) {
                words.push(cur);
            }
            cur = [];
        };
        runsOf(line).forEach(function(run) {
            if (run.kind === 'name') {
                cur.push({kind: 'name', s: run.s});
                return;
            }
            run.s.split(' ').forEach(function(part, j) {
                if (j > 0) {
                    cut();
                }
                if (part) {
                    cur.push({kind: run.kind, s: part, id: run.id});
                }
            });
        });
        cut();
        return words;
    };

    /**
     * The lines of a run of words at a width, filled one by one.
     *
     * @param {Array[]} words With their widths.
     * @param {number} width
     * @param {number} space
     * @return {Object[]} words, w
     */
    var fillLines = function(words, width, space) {
        var lines = [];
        var line = [];
        var lw = 0;
        words.forEach(function(word) {
            if (line.length && lw + space + word.w > width) {
                lines.push({words: line, w: lw});
                line = [];
                lw = 0;
            }
            lw += (line.length ? space : 0) + word.w;
            line.push(word);
        });
        lines.push({words: line, w: lw});
        return lines;
    };

    /**
     * A phrase in two lines, broken where a reader would breathe: after a comma or a colon if there is one near the
     * middle, never after a little word («te», «la», «a», «the»), never one word alone, and the two lines as even as can
     * be.
     *
     * @param {Array[]} words With their widths.
     * @param {number} width
     * @param {number} space
     * @return {Object[]|null}
     */
    var twoLines = function(words, width, space) {
        var best = null;
        var text = function(word) {
            return word.map(function(piece) {
                return piece.s;
            }).join('');
        };
        for (var i = 1; i < words.length; i++) {
            var a = words.slice(0, i);
            var b = words.slice(i);
            var wa = a.reduce(function(x, w) {
                return x + w.w;
            }, 0) + space * (a.length - 1);
            var wb = b.reduce(function(x, w) {
                return x + w.w;
            }, 0) + space * (b.length - 1);
            if (wa > width || wb > width) {
                continue;
            }
            var end = text(a[a.length - 1]);
            var cost = Math.abs(wa - wb) / width;
            if (/[,:;\u2014\u060c\u061b]$/.test(end)) {
                cost -= 0.3;
            }
            if (a[a.length - 1].length === 1 && !/[,:;.!?\u2014\u060c\u061b\u061f]$/.test(end) &&
                    end.replace(/[^\w\u00c0-\u024f\u0600-\u06ff]/g, '').length <= 3) {
                cost += 0.6;
            }
            if (b.length === 1 || (a.length === 1 && !/[,:;\u060c]$/.test(end))) {
                cost += 0.6;
            }
            if (!best || cost < best.cost) {
                best = {cost: cost, lines: [{words: a, w: wa}, {words: b, w: wb}]};
            }
        }
        return best ? best.lines : null;
    };

    /**
     * A text laid out as the book sets it: the lines of the page are its phrases, composed by hand, and they stay so; a
     * phrase too long for the page breaks into lines of even length (the narrowest width that keeps their number), so
     * no word is left alone at the end. At the largest size up to o.size whose lines fit o.span (from the first
     * baseline to the last); the runs of each line in the order they are seen (right to left in Arabic).
     *
     * @param {CanvasRenderingContext2D} c
     * @param {string} raw With its lines and its gaps.
     * @param {Object} o size, min, lh, width, span, weight, family, hand (weight of the name), handScale, tilt, rtl
     * @return {Object} lines (runs, w, para, chars), size, lh, fonts
     */
    var layout = function(c, raw, o) {
        var paras = String(raw).split('\n').map(wordsOf);
        var rtl = o.rtl;
        var size = o.size;
        var fonts;
        var lines;
        var space;
        for (;;) {
            fonts = {text: o.weight + ' ' + size + 'px ' + o.family, iso: o.weight + ' ' + size + 'px ' + o.family,
                name: o.hand + ' ' + (size * o.handScale).toFixed(1) + 'px ' + HAND};
            c.font = fonts.text;
            space = c.measureText(' ').width;
            var wide = false;
            lines = [];
            paras.forEach(function(words, pi) {
                words.forEach(function(word) {
                    word.w = 0;
                    word.forEach(function(piece) {
                        c.font = fonts[piece.kind];
                        piece.w = c.measureText(piece.s).width;
                        word.w += piece.w;
                    });
                    wide = wide || word.w > o.width;
                });
                var set = fillLines(words, o.width, space);
                if (set.length === 2) {
                    set = twoLines(words, o.width, space) || set;
                } else if (set.length > 2) {
                    var lo = o.width * 0.4;
                    var hi = o.width;
                    for (var s = 0; s < 12; s++) {
                        var mid = (lo + hi) / 2;
                        if (fillLines(words, mid, space).length <= set.length) {
                            hi = mid;
                        } else {
                            lo = mid;
                        }
                    }
                    set = fillLines(words, hi + 0.5, space);
                }
                set.forEach(function(line) {
                    line.para = pi;
                    lines.push(line);
                });
            });
            if (size <= o.min || (!wide && (lines.length - 1) * size * o.lh <= o.span &&
                    (!o.whole || lines.length === paras.length || size <= o.whole))) {
                break;
            }
            size -= 1;
        }
        lines.forEach(function(line) {
            var runs = [];
            line.chars = 0;
            line.words.forEach(function(word, wi) {
                word.forEach(function(piece, pj) {
                    var last = runs[runs.length - 1];
                    var gap = wi > 0 && pj === 0;
                    line.chars += piece.s.length + (gap ? 1 : 0);
                    if (last && last.kind === piece.kind && (piece.kind === 'text' || last.id === piece.id) &&
                            piece.kind !== 'name') {
                        last.s += (gap ? ' ' : '') + piece.s;
                        last.w += (gap ? space : 0) + piece.w;
                        return;
                    }
                    if (gap) {
                        if (last && last.kind === 'text') {
                            last.s += ' ';
                            last.w += space;
                        } else if (piece.kind === 'text') {
                            runs.push({kind: 'text', s: ' ' + piece.s, w: space + piece.w});
                            return;
                        } else {
                            runs.push({kind: 'text', s: ' ', w: space});
                        }
                    }
                    runs.push({kind: piece.kind, s: piece.s, w: piece.w, id: piece.id});
                });
            });
            // Where each run starts, from the left of the line, in the order it is seen.
            var pos = 0;
            runs.forEach(function(run) {
                run.x = rtl ? line.w - pos - run.w : pos;
                pos += run.w;
            });
            line.runs = runs;
            delete line.words;
        });
        return {lines: lines, size: size, lh: size * o.lh, fonts: fonts, rtl: rtl, handScale: o.handScale,
            tilt: o.tilt || 0};
    };

    /**
     * Draws the pen's work so far, up to `prog`: solid behind the pen and fading in front of it, in the direction the
     * line is written.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {number} x0 Left of what is written.
     * @param {number} x1 Right of it.
     * @param {number} top
     * @param {number} bottom
     * @param {number} prog 0 to 1.
     * @param {boolean} rtl
     * @param {Function} draw
     */
    var writeOn = function(c, x0, x1, top, bottom, prog, rtl, draw) {
        if (prog <= 0) {
            return;
        }
        if (prog >= 1) {
            draw();
            return;
        }
        var feather = Math.max(6, (bottom - top) * 0.45);
        var head = x0 - feather + prog * (x1 - x0 + 2 * feather);
        if (rtl) {
            head = x1 + feather - prog * (x1 - x0 + 2 * feather);
        }
        var slices = 5;
        var alpha = c.globalAlpha;
        for (var s = -1; s < slices; s++) {
            var a = s < 0 ? 1 : 1 - (s + 0.5) / slices;
            var from = s < 0 ? (rtl ? head + feather : x0 - feather * 3) : head - feather + s * feather / slices;
            var to = s < 0 ? (rtl ? x1 + feather * 3 : head - feather) : head - feather + (s + 1) * feather / slices;
            if (rtl && s >= 0) {
                from = head + feather - (s + 1) * feather / slices;
                to = head + feather - s * feather / slices;
            }
            if (to <= from) {
                continue;
            }
            c.save();
            c.beginPath();
            c.rect(from, top, to - from, bottom - top);
            c.clip();
            c.globalAlpha = alpha * a;
            draw();
            c.restore();
        }
    };

    /**
     * Draws a line laid out by layout(), its left at x and its baseline at y; the name written by hand up to `pen`.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} lay
     * @param {Object} line
     * @param {number} x
     * @param {number} y
     * @param {string} colour
     * @param {number} pen How far the name is written, 0 to 1.
     */
    var drawLine = function(c, lay, line, x, y, colour, pen) {
        c.fillStyle = colour;
        c.textAlign = 'left';
        c.textBaseline = 'alphabetic';
        line.runs.forEach(function(run) {
            var rx = x + run.x;
            if (run.kind === 'name') {
                var size = lay.size * lay.handScale;
                c.save();
                c.translate(rx + run.w / 2, y + lay.size * 0.04);
                c.rotate(rad(lay.tilt));
                c.font = lay.fonts.name;
                c.direction = dirOf(run.s);
                writeOn(c, -run.w / 2, run.w / 2, -size, size * 0.4, pen, c.direction === 'rtl', function() {
                    c.fillText(run.s, -run.w / 2, 0);
                });
                c.restore();
                return;
            }
            c.font = lay.fonts[run.kind];
            c.direction = run.kind === 'iso' ? dirOf(run.s) : lay.rtl ? 'rtl' : 'ltr';
            c.fillText(run.s, rx, y);
        });
    };

    // --- The camera --------------------------------------------------------------------------------------------------

    // How the camera looks at each page, in the units of the illustration (1536 x 1024): x, the middle of what shows (the
    // crop of the sides: 768 shows its central 68 %; from 524 to 1012, a little to one side, never past the edge); at,
    // what it comes closer to; z, how close by the end of the look (1.04, 1.06 at the most); to, where on the screen what
    // it looks at ends. It comes closer from z0 (1 if not said). Two pages have a second look from a moment of the page (`from`, see
    // when()), and the camera goes there slowly (in `dur` seconds, or as its way asks at CALM), because it tells
    // something: from the chalkboard to the teacher, and from the child's drawing on the wall to the class.
    // The child's drawing is the moment that moves most: the camera goes to the frame up to 1.5, the frame at 70 % of the
    // width (as far left as it goes: the picture ends 112 px after it) and a third of it, at the height of the eyes, with
    // the cork of the class's drawings on its left and the heads of the children looking at it below: the class sees
    // your child's drawing (Gaudí chose it over 2, which shows the frame bigger and loses them). The wall softens, like a
    // depth of field; the drawing, drawn by its player on a sheet of 640 px, stays sharp. Checked page by page: the
    // child, the name and what happens show (the LEEME has the check).
    // The chalkboard starts close on the board alone, the frame of the door at its edge (z0 1.78), and steps back to the
    // child and the teacher when the voice gets to her: from further, the teacher, crouching towards the child, would
    // smile at the edge of the screen for seconds (Enrique, 10 November 2026). The child of the first page and of the
    // blocks shows whole.
    var FRAMES = {
        mochila: [{x: 716, at: [1000, 470], z: 1.05}],
        botella: [{x: 768, at: [800, 560], z: 1.04}],
        pizarra: [{x: 1282, at: [1282, 310], z0: 1.78, z: 1.82}, {from: 'chalk+2.4', x: 700, at: [800, 420], z: 1.04}],
        percha: [{x: 700, at: [676, 200], z: 1.05}],
        amigo: [{x: 716, at: [700, 430], z: 1.04}],
        alfombra: [{x: 1012, at: [1324, 300], z: 1.5, to: [506, 290]}, {from: 'drawn+0.8', dur: 4, x: 600, at: [460, 420],
            z: 1.03}],
        // without the child's drawing the frame keeps one of the class, still: the camera stays with the children
        'alfombra-sin': [{x: 640, at: [460, 420], z: 1.04}],
        casa: [{x: 700, at: [560, 360], z: 1.05}],
        vuelta: [{x: 768, at: [768, 330], z: 1.06}]
    };
    // A move of the camera takes MOVE seconds at least, and never goes faster than CALM screen units a second.
    var MOVE = 3.2;
    var CALM = 140;

    /**
     * The looks of the camera on a page.
     *
     * @param {Object} e
     * @return {Object[]}
     */
    var framesOf = function(e) {
        var id = e.page.id;
        return FRAMES[id + (e.page.frame && !e.draw ? '-sin' : '')] || FRAMES[id] || [{x: 768, at: [768, 400], z: 1.04}];
    };

    /**
     * The time of a moment of a page, by its name: «in» as the page comes in, «L2» as the voice starts line 2 (L2.5
     * halfway to line 3), «said» after the last word, «chalk» as the chalk starts, «draw» and «drawn» for the drawing on
     * the wall, «end» as the page goes; with +seconds. Null when the page has no such moment.
     *
     * @param {Object} e
     * @param {string} ref
     * @return {number|null}
     */
    var when = function(e, ref) {
        var m = /^([A-Za-z]+)(\d*(?:\.\d+)?)([+-]\d+(?:\.\d+)?)?$/.exec(String(ref));
        if (!m) {
            return null;
        }
        var x = null;
        if (m[1] === 'in') {
            x = e.shown;
        } else if (m[1] === 'end') {
            x = e.end;
        } else if (m[1] === 'said') {
            x = e.said;
        } else if (m[1] === 'chalk') {
            x = e.chalk;
        } else if (m[1] === 'draw') {
            x = e.draw ? e.draw[0] : null;
        } else if (m[1] === 'drawn') {
            x = e.draw ? e.draw[1] : null;
        } else if (m[1] === 'L') {
            var n = +m[2];
            var i = Math.floor(n);
            var a = e.lines[i];
            var b = i + 1 < e.lines.length ? e.lines[i + 1] : e.said;
            x = a == null ? null : a + (n - i) * (b - a);
        }
        return x == null ? null : x + (+m[3] || 0);
    };

    /**
     * Where the illustration is on the screen for a look, z times closer: its scale and its offset, always covering the
     * band of the picture.
     *
     * @param {Object} f A look (with `to`, the place on the screen where what it looks at ends, at its closest).
     * @param {number} z
     * @return {Object} s, x, y
     */
    var place = function(f, z) {
        var s = BASE * z;
        var ax = W / 2 + (f.at[0] - f.x) * BASE;
        var ay = f.at[1] * BASE;
        if (f.to) {
            // what it looks at slides, as it comes closer, to where the look wants it on the screen
            var u = f.z > (f.z0 || 1) ? clamp((z - (f.z0 || 1)) / (f.z - (f.z0 || 1)), 0, 1) : 1;
            ax += (f.to[0] - ax) * u;
            ay += (f.to[1] - ay) * u;
        }
        return {s: s, x: clamp(ax - f.at[0] * s, W - 1536 * s, 0), y: clamp(ay - f.at[1] * s, PLATE - 1024 * s, 0)};
    };

    /**
     * How close a look is when u of its way is done: from z0 (1 if not said) to z.
     *
     * @param {Object} f
     * @param {number} u
     * @return {number}
     */
    var zoom = function(f, u) {
        var z0 = f.z0 || 1;
        return z0 + (f.z - z0) * u;
    };

    /**
     * Where the camera is on a page at time t.
     *
     * @param {Object} e
     * @param {number} t
     * @return {Object} s, x, y
     */
    var camera = function(e, t) {
        var looks = framesOf(e);
        var a = looks[0];
        if (!e.move) {
            return place(a, zoom(a, smooth(e.shown, e.end, t)));
        }
        var b = looks[1];
        if (t <= e.move[0]) {
            return place(a, zoom(a, smooth(e.shown, e.move[0], t)));
        }
        if (t >= e.move[1]) {
            return place(b, zoom(b, smooth(e.move[1], e.end, t)));
        }
        // a step back that is one: the scale changes evenly in its ratio, and the middle of what shows goes straight
        var pa = place(a, a.z);
        var pb = place(b, zoom(b, 0));
        var u = sine((t - e.move[0]) / (e.move[1] - e.move[0]));
        var s = Math.exp(Math.log(pa.s) + (Math.log(pb.s) - Math.log(pa.s)) * u);
        var cx = (W / 2 - pa.x) / pa.s + ((W / 2 - pb.x) / pb.s - (W / 2 - pa.x) / pa.s) * u;
        var cy = (PLATE / 2 - pa.y) / pa.s + ((PLATE / 2 - pb.y) / pb.s - (PLATE / 2 - pa.y) / pa.s) * u;
        return {s: s, x: clamp(W / 2 - cx * s, W - 1536 * s, 0), y: clamp(PLATE / 2 - cy * s, PLATE - 1024 * s, 0)};
    };

    // --- The pieces on the pictures ------------------------------------------------------------------------------------

    /**
     * The size at which a name fits its blank spot, never wider than it (as the book does).
     *
     * @param {CanvasRenderingContext2D} c
     * @param {string} text
     * @param {Object} n The spot.
     * @param {string} weight
     * @return {number}
     */
    var fitSize = function(c, text, n, weight) {
        c.font = weight + ' ' + n.size + 'px ' + HAND;
        var len = c.measureText(text).width;
        return len > n.w ? n.size * n.w / len : n.size;
    };

    /**
     * The chalk of the board, once: the words in chalk, a little rough at the edges and broken by the grain of the board.
     *
     * @param {Object} L
     * @param {CanvasRenderingContext2D} c
     * @param {string} text
     * @param {Object} n Its spot.
     * @return {Object}
     */
    var chalkOf = function(L, c, text, n) {
        if (L.chalk) {
            return L.chalk;
        }
        var size = fitSize(c, text, n, '600');
        var font = '600 ' + size.toFixed(2) + 'px ' + HAND;
        c.font = font;
        var w = c.measureText(text).width;
        var pad = size * 0.35;
        var R = 2.4 * L.dpr / 1.5;
        var cw = w + 2 * pad;
        var ch = size * 1.45 + pad;
        var cv = canvasOf(cw * R, ch * R);
        var x = cv.getContext('2d');
        x.scale(R, R);
        x.font = font;
        x.textAlign = 'center';
        x.direction = RTL ? 'rtl' : 'ltr';
        x.fillStyle = CHALK;
        [[0, 0, 0.75], [0.7, -0.45, 0.4], [-0.6, 0.5, 0.4], [0.35, 0.65, 0.3]].forEach(function(o) {
            x.globalAlpha = o[2];
            x.fillText(text, cw / 2 + o[0], pad + size + o[1]);
        });
        // the grain of the board rubs out specks of chalk, and a few streaks where the stick dragged
        x.globalCompositeOperation = 'destination-out';
        var r = rng(41);
        var specks = Math.round(cw * ch / 3.2);
        for (var i = 0; i < specks; i++) {
            x.globalAlpha = 0.15 + 0.6 * r();
            x.fillRect(r() * cw, r() * ch, 0.5 + r() * 1.3, 0.35 + r() * 0.7);
        }
        for (var s = 0; s < 26; s++) {
            x.globalAlpha = 0.18 + 0.2 * r();
            x.save();
            x.translate(r() * cw, r() * ch);
            x.rotate(-0.25 + r() * 0.2);
            x.fillRect(-12, 0, 24 + r() * 30, 0.6);
            x.restore();
        }
        L.chalk = {canvas: cv, w: w, size: size, pad: pad, cw: cw, ch: ch, dur: 1.3 + 0.05 * text.length};
        return L.chalk;
    };

    /**
     * The names written on the picture (stickers, pegs) and the chalk of the board, written while the voice reads it.
     *
     * @param {CanvasRenderingContext2D} c In the units of the picture.
     * @param {Object} L
     * @param {Object} e
     * @param {Object} p The page.
     * @param {number} t
     */
    var names = function(c, L, e, p, t) {
        (p.names || []).forEach(function(n, j) {
            if (n.kind === 'chalk') {
                if (e.chalk == null) {
                    return;
                }
                var art = chalkOf(L, c, fill(T.chalk), n);
                var u = (t - e.chalk) / art.dur;
                if (u <= 0) {
                    return;
                }
                c.save();
                c.translate(n.x, n.y);
                c.rotate(rad(n.rot));
                writeOn(c, -art.w / 2, art.w / 2, -art.size * 1.1, art.size * 0.5, easeInOut(u) * 0.94 + u * 0.06,
                    RTL, function() {
                        c.drawImage(art.canvas, -art.cw / 2, -art.pad - art.size, art.cw, art.ch);
                    });
                c.restore();
                return;
            }
            var text = n.text || BOOK.child;
            var key = p.img + ':' + j;
            var size = L.sizes[key] || (L.sizes[key] = fitSize(c, text, n, '650'));
            c.save();
            c.translate(n.x, n.y);
            c.rotate(rad(n.rot));
            if (n.skew) {
                c.transform(1, 0, Math.tan(rad(n.skew)), 1, 0, 0);
            }
            c.font = '650 ' + size.toFixed(2) + 'px ' + HAND;
            c.fillStyle = INK;
            c.textAlign = 'center';
            c.direction = dirOf(text);
            c.fillText(text, 0, 0);
            c.restore();
        });
    };

    /**
     * The frame on the wall of the class (page 6): its mount, with the shadow of the frame from the window, and the
     * sheet a touch crooked on it; on the sheet the child's drawing, drawing itself, lifted from the grey of a phone photo
     * and laid on warm paper, as the book does; without one, a drawing of the class, still.
     *
     * @param {CanvasRenderingContext2D} c In the units of the picture.
     * @param {Object} k
     * @param {Object} L
     * @param {Object} e
     * @param {Object} p
     * @param {number} t
     */
    var wall = function(c, k, L, e, p, t) {
        var q = p.frame.quad;
        var x = q[0][0];
        var y = q[0][1];
        var w = q[1][0] - q[0][0];
        var h = q[3][1] - q[0][1];
        if (k.player) {
            k.player.renderAt(e.draw ? Math.max(0, t - e.draw[0]) : 1e4);
        }
        var src = k.sheet;
        if (BOOK.drawing) {
            // brightness(1.2), as the book: the sheet once more, added at a fifth
            var lift = L.lift || (L.lift = canvasOf(src.width, src.height));
            var lx = lift.getContext('2d');
            lx.globalCompositeOperation = 'copy';
            lx.globalAlpha = 1;
            lx.drawImage(src, 0, 0);
            lx.globalCompositeOperation = 'lighter';
            lx.globalAlpha = 0.2;
            lx.drawImage(src, 0, 0);
            lx.globalCompositeOperation = 'source-over';
            lx.globalAlpha = 1;
            src = lift;
        }
        c.save();
        c.fillStyle = '#f5e1cb';
        c.fillRect(x, y, w, h);
        c.beginPath();
        c.rect(x, y, w, h);
        c.clip();
        var g = c.createLinearGradient(x, y, x, y + 16);
        g.addColorStop(0, 'rgba(54, 35, 58, 0.30)');
        g.addColorStop(1, 'rgba(54, 35, 58, 0)');
        c.fillStyle = g;
        c.fillRect(x, y, w, 16);
        g = c.createLinearGradient(x, y, x + 14, y);
        g.addColorStop(0, 'rgba(54, 35, 58, 0.26)');
        g.addColorStop(1, 'rgba(54, 35, 58, 0)');
        c.fillStyle = g;
        c.fillRect(x, y, 14, h);
        var sw = w * 0.84;
        var sh = h * 0.85;
        c.translate(x + w * 0.08 + sw / 2, y + h * 0.07 + sh / 2);
        c.rotate(rad(-1.2));
        c.shadowColor = 'rgba(54, 35, 58, 0.26)';
        c.shadowBlur = 8 * L.cam;
        c.shadowOffsetX = 3 * L.cam;
        c.shadowOffsetY = 5 * L.cam;
        c.fillStyle = '#fcf4e8';
        c.fillRect(-sw / 2, -sh / 2, sw, sh);
        c.shadowColor = 'rgba(0, 0, 0, 0)';
        c.beginPath();
        c.rect(-sw / 2, -sh / 2, sw, sh);
        c.clip();
        var r = Math.max(sw / src.width, sh / src.height);
        c.globalCompositeOperation = BOOK.drawing ? 'multiply' : 'source-over';
        c.drawImage(src, -src.width * r / 2, -src.height * r / 2, src.width * r, src.height * r);
        c.restore();
    };

    // --- The pages -----------------------------------------------------------------------------------------------------

    /**
     * The fold of the book on the side of the spine: a breath of shadow, so every frame is a page.
     *
     * @param {CanvasRenderingContext2D} c
     */
    var gutter = function(c) {
        var x0 = RTL ? W : 0;
        var g = c.createLinearGradient(x0, 0, RTL ? W - 46 : 46, 0);
        g.addColorStop(0, 'rgba(54, 35, 58, 0.13)');
        g.addColorStop(0.35, 'rgba(54, 35, 58, 0.05)');
        g.addColorStop(1, 'rgba(54, 35, 58, 0)');
        c.fillStyle = g;
        c.fillRect(RTL ? W - 46 : 0, 0, 46, H);
    };

    /**
     * The mark of the centre: its logo, or its initials on its colour, as the page shows it.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} L
     * @param {Object} k
     * @param {number} x Left.
     * @param {number} y Top.
     * @param {number} size
     */
    var badge = function(c, L, k, x, y, size) {
        if (L.logo) {
            var r = Math.min(size / L.logo.naturalWidth, size / L.logo.naturalHeight);
            var lw = L.logo.naturalWidth * r;
            var lh = L.logo.naturalHeight * r;
            c.drawImage(L.logo, x + (size - lw) / 2, y + (size - lh) / 2, lw, lh);
            return;
        }
        c.fillStyle = k.accent;
        c.beginPath();
        var rr = size * 0.26;
        c.moveTo(x + rr, y);
        c.arcTo(x + size, y, x + size, y + size, rr);
        c.arcTo(x + size, y + size, x, y + size, rr);
        c.arcTo(x, y + size, x, y, rr);
        c.arcTo(x, y, x + size, y, rr);
        c.closePath();
        c.fill();
        c.fillStyle = '#ffffff';
        c.font = '700 ' + Math.round(size * 0.4) + 'px ' + SANS;
        c.textAlign = 'center';
        c.direction = dirOf(L.initials);
        c.fillText(L.initials, x + size / 2, y + size * 0.64);
    };

    /**
     * A mark and its words on one line, centred: the gift of the centre, the seal of Eguahe.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Function} mark draws the mark at (x, y, size)
     * @param {number} size
     * @param {string} text
     * @param {string} font
     * @param {string} colour
     * @param {number} y Middle of the line.
     */
    var signed = function(c, mark, size, text, font, colour, y) {
        c.font = font;
        var tw = c.measureText(text).width;
        var gap = Math.round(size * 0.32);
        var all = size + gap + tw;
        var left = (W - all) / 2;
        var mx = RTL ? left + tw + gap : left;
        var tx = RTL ? left : left + size + gap;
        mark(mx, y - size / 2, size);
        c.font = font;
        c.fillStyle = colour;
        c.textAlign = 'left';
        c.direction = RTL ? 'rtl' : 'ltr';
        c.fillText(text, tx, y + size * 0.2);
    };

    /**
     * The label of the first day, as on the cover of the book: the band in the colour of the centre, the paper, its line
     * and its peeled corner, and the name by hand at the largest size that fits its line.
     *
     * @param {CanvasRenderingContext2D} c Centred on it, in its units (50 x 36).
     * @param {Object} k
     */
    var label = function(c, k) {
        var shape = function(d, colour) {
            c.fillStyle = colour;
            c.fill(new Path2D(d));
        };
        shape('M-23 -12 Q-23 -16 -19 -16 L19 -16 Q23 -16 23 -12 L23 8 L15 16 L-19 16 Q-23 16 -23 12 Z', k.accent);
        c.shadowColor = 'rgba(0, 0, 0, 0)';
        shape('M-20.6 -6.2 L20.6 -6.2 L20.6 7 L13.8 13.8 L-18.6 13.8 Q-20.6 13.8 -20.6 11.8 Z', '#ffffff');
        c.globalAlpha *= 0.75;
        shape('M-15 6.2 L9 6.2 L9 7.9 L-15 7.9 Z', '#8c8178');
        c.globalAlpha /= 0.75;
        shape('M23 8 L15 16 L15 8 Z', '#dccdbb');
        c.fillStyle = INK;
        c.textAlign = 'center';
        c.direction = dirOf(BOOK.child);
        c.font = '650 13.5px ' + HAND;
        var size = Math.min(13.5, 13.5 * 27 / Math.max(1, c.measureText(BOOK.child).width));
        c.font = '650 ' + size.toFixed(2) + 'px ' + HAND;
        c.fillText(BOOK.child, -3, 5);
    };

    /**
     * The cover's picture: the label of the first day sticking on the paper, in the band of the pictures (lifted at the
     * first frame, so the picture a phone shows of the video already says whose it is).
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k
     * @param {Object} L
     * @param {Object} e
     * @param {number} t
     */
    var coverPicture = function(c, k, L, e, t) {
        var u = t - e.shown;
        var glow = c.createRadialGradient(W / 2, PLATE / 2, 30, W / 2, PLATE / 2, 640);
        glow.addColorStop(0, 'rgba(255, 246, 226, 0.75)');
        glow.addColorStop(1, 'rgba(255, 246, 226, 0)');
        c.fillStyle = glow;
        c.fillRect(0, 0, W, H);
        // Lifted, then pressed onto the paper, its shadow drawing in as it lands; then it barely breathes.
        var p = easeOut(u / 0.85);
        var press = Math.sin(Math.PI * clamp((u - 0.62) / 0.32, 0, 1));
        var unit = 9.6 * (1 + 0.1 * (1 - p) - 0.014 * press + 0.015 * clamp(u / Math.max(1, e.end - e.shown), 0, 1.2));
        c.save();
        c.translate(W / 2, PLATE / 2 + 8 - 34 * (1 - p));
        c.rotate(rad(-4 - 6 * (1 - p)));
        c.scale(unit, unit);
        c.shadowColor = 'rgba(54, 35, 58, ' + (0.2 + 0.06 * (1 - p)) + ')';
        c.shadowBlur = (16 + 34 * (1 - p)) * L.dpr;
        c.shadowOffsetY = (10 + 26 * (1 - p)) * L.dpr;
        label(c, k);
        c.restore();
    };

    /**
     * The cover's words: the title where the words go, and the centre on the line of the folio.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k
     * @param {Object} L
     */
    var coverText = function(c, k, L) {
        c.fillStyle = SEPIA;
        c.textAlign = 'center';
        c.direction = RTL ? 'rtl' : 'ltr';
        var ts = 54;
        c.font = '700 ' + ts + 'px ' + SANS;
        while (ts > 30 && c.measureText(T.title).width > W - 2 * 72) {
            ts -= 2;
            c.font = '700 ' + ts + 'px ' + SANS;
        }
        c.fillText(T.title, W / 2, BOX_TOP + 48);
        signed(c, function(x, y, size) {
            badge(c, L, k, x, y, size);
        }, 30, BOOK.centre, '650 21px ' + SANS, SEPIA, FOLIO);
    };

    /**
     * A story page's picture: the illustration under the camera, the names on it and the drawing on the wall, and the
     * paper laid over it from the seam down.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k
     * @param {Object} L
     * @param {Object} e
     * @param {number} t
     */
    var storyPicture = function(c, k, L, e, t) {
        var p = pageOf(e.page);
        var img = k.images[p.img];
        var cam = camera(e, t);
        L.cam = cam.s * L.dpr;
        c.save();
        c.beginPath();
        c.rect(0, 0, W, SEAM_BOTTOM);
        c.clip();
        c.translate(cam.x, cam.y);
        c.scale(cam.s, cam.s);
        c.imageSmoothingEnabled = true;
        c.imageSmoothingQuality = 'high';
        if (img) {
            c.drawImage(img, 0, 0, 1536, 1024);
        }
        names(c, L, e, p, t);
        if (p.frame && k.sheet) {
            wall(c, k, L, e, p, t);
        }
        c.restore();
        c.drawImage(zone(L, k, e), 0, ZONE, W, H - ZONE);
    };

    /**
     * The size of the words of the whole book in its language: the first of SIZES that sets every page within the box
     * of the words, as a book has one size for its text.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} L
     * @return {number} The step of SIZES.
     */
    var body = function(c, L) {
        if (L.body == null) {
            var texts = BOOK.pages.filter(function(p) {
                return !p.kind;
            }).map(function(p) {
                return pageText(pageOf(p));
            });
            for (var s = 0; s < SIZES.length; s++) {
                var st = textStyle(s);
                L.body = s;
                var fits = texts.every(function(raw) {
                    return layout(c, raw, st).lines.length * st.size * st.lh <= BOX_BOTTOM - BOX_TOP + 0.5;
                });
                if (fits) {
                    break;
                }
            }
        }
        return L.body;
    };
    var textStyle = function(step) {
        var px = SIZES[step];
        var size = Math.round(px[0] / SCALE * 100) / 100;
        return {size: size, min: size, lh: px[1] / px[0], width: MEASURE, span: 1e4, weight: 450, family: SANS, hand: 650,
            handScale: 1.15, tilt: -2, rtl: RTL};
    };

    /**
     * The words of a page in their box, centred, in sepia: each line comes in as the voice starts it (350 ms, rising 12
     * px), and the name in it is written by hand; a phrase that breaks comes in in two, with the voice.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} L
     * @param {Object} e
     * @param {Object} p
     * @param {number} t
     */
    var words = function(c, L, e, p, t) {
        var lay = L.text[e.i];
        if (!lay) {
            lay = layout(c, pageText(p), textStyle(body(c, L)));
            // when each line comes in: its line of the page, and the part of it where it breaks
            var paras = {};
            lay.lines.forEach(function(line) {
                (paras[line.para] = paras[line.para] || []).push(line);
            });
            Object.keys(paras).forEach(function(pi) {
                var list = paras[pi];
                var from = e.lines[pi] != null ? e.lines[pi] : e.said;
                var to = e.lines[+pi + 1] != null ? e.lines[+pi + 1] : e.said;
                var all = list.reduce(function(a, l) {
                    return a + l.chars;
                }, 0);
                var before = 0;
                list.forEach(function(line) {
                    line.at = from + (to - from) * 0.9 * before / Math.max(1, all) - 0.1;
                    var name = line.runs.filter(function(r) {
                        return r.kind === 'name';
                    })[0];
                    if (name) {
                        var ahead = 0;
                        for (var r = 0; r < line.runs.length && line.runs[r] !== name; r++) {
                            ahead += line.runs[r].s.length;
                        }
                        line.pen = line.at + 0.25 + Math.min(1.2, (to - from) * 0.7) * ahead / Math.max(1, line.chars);
                        line.penDur = 0.35 + 0.045 * name.s.length;
                    }
                    before += line.chars;
                });
            });
            L.text[e.i] = lay;
        }
        lay.lines.forEach(function(line, j) {
            var a = smooth(line.at, line.at + 0.35, t);
            if (a <= 0) {
                return;
            }
            var y = BOX_TOP + j * lay.lh + lay.lh / 2 + lay.size * 0.35 + (1 - easeOut((t - line.at) / 0.35)) * 8;
            c.save();
            c.globalAlpha *= a;
            drawLine(c, lay, line, (W - line.w) / 2, y, SEPIA, line.pen != null ? (t - line.pen) / line.penDur : 1);
            c.restore();
        });
    };

    /**
     * The folio of a page, on its line, in sepia at half.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {number} n
     */
    var folio = function(c, n) {
        var text = String(n);
        try {
            text = Number(n).toLocaleString(LANG);
        } catch (err) {
            text = String(n);
        }
        c.save();
        c.globalAlpha *= 0.5;
        c.fillStyle = SEPIA;
        c.font = '500 17.33px ' + SANS;
        c.textAlign = 'center';
        c.direction = RTL ? 'rtl' : 'ltr';
        c.fillText(text, W / 2, FOLIO + 6);
        c.restore();
    };

    /**
     * The dedication's picture: the two hands, small, in the band of the pictures, in a warm light; the paper is what
     * this page is about.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k
     * @param {Object} L
     * @param {Object} e
     * @param {number} t
     */
    var dedicationPicture = function(c, k, L, e, t) {
        var d = Math.max(0, t - e.shown);
        var glow = c.createRadialGradient(W / 2, 470, 20, W / 2, 470, 620);
        glow.addColorStop(0, 'rgba(255, 232, 196, 0.6)');
        glow.addColorStop(1, 'rgba(255, 232, 196, 0)');
        c.fillStyle = glow;
        c.fillRect(0, 0, W, H);
        var hands = k.images.dedication;
        if (hands) {
            var hw = 300 * (1 + 0.03 * clamp(d / Math.max(1, e.end - e.shown), 0, 1));
            var hh = hw * hands.naturalHeight / hands.naturalWidth;
            c.drawImage(hands, (W - hw) / 2, 470 - hh / 2, hw, hh);
        }
    };

    /**
     * The dedication's words: the dedication writing itself by hand where the words go, the gift of the centre at the
     * foot of the box, and the seal of Eguahe on the line of the folio, which closes the book with the door.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k
     * @param {Object} L
     * @param {Object} e
     * @param {number} t
     */
    var dedicationText = function(c, k, L, e, t) {
        var lay = L.dedication;
        if (!lay) {
            lay = layout(c, dedicationLines(), {size: 42, min: 30, whole: 34, lh: RTL ? 1.45 : 1.3, width: W - 2 * 72,
                span: 150, weight: 600, family: HAND, hand: 600, handScale: 1, rtl: RTL});
            var all = lay.lines.reduce(function(a, l) {
                return a + l.chars;
            }, 0);
            var span = e.write[1] - e.write[0] - 0.4 * (lay.lines.length - 1);
            var at = e.write[0];
            lay.lines.forEach(function(line) {
                line.at = at;
                line.dur = span * line.chars / Math.max(1, all);
                at += line.dur + 0.4;
            });
            L.dedication = lay;
        }
        lay.lines.forEach(function(line, j) {
            var y = BOX_TOP + lay.size + j * lay.lh;
            var x = (W - line.w) / 2;
            writeOn(c, x, x + line.w, y - lay.size, y + lay.size * 0.45, (t - line.at) / line.dur, RTL, function() {
                drawLine(c, lay, line, x, y, SEPIA, 1);
            });
        });
        var gift = smooth(e.gift, e.gift + 0.9, t);
        if (gift > 0) {
            c.save();
            c.globalAlpha *= gift;
            c.fillStyle = 'rgba(74, 59, 46, 0.22)';
            c.fillRect(W / 2 - 36, BOX_BOTTOM - 74, 72, 1.5);
            var gs = 22;
            c.font = '600 ' + gs + 'px ' + SANS;
            while (gs > 14 && c.measureText(V.gift).width > MEASURE - 40) {
                gs -= 1;
                c.font = '600 ' + gs + 'px ' + SANS;
            }
            signed(c, function(x, y, size) {
                badge(c, L, k, x, y, size);
            }, 30, V.gift, '600 ' + gs + 'px ' + SANS, k.accentText, BOX_BOTTOM - 26);
            c.restore();
        }
        var seal = smooth(e.seal, e.seal + 0.9, t);
        if (seal > 0) {
            c.save();
            c.globalAlpha *= seal;
            signed(c, function(x, y, size) {
                if (k.images.mark) {
                    c.drawImage(k.images.mark, x, y, size, size);
                }
            }, 26, V.credit, '500 17.33px ' + SANS, INK_SOFT, FOLIO);
            c.restore();
        }
    };

    /**
     * What a page shows in the band of the pictures (with its paper over it), at time t.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k
     * @param {Object} L
     * @param {Object} e
     * @param {number} t
     */
    var picture = function(c, k, L, e, t) {
        c.save();
        if (e.page.kind === 'cover') {
            coverPicture(c, k, L, e, t);
        } else if (e.page.kind === 'dedication') {
            dedicationPicture(c, k, L, e, t);
        } else {
            storyPicture(c, k, L, e, t);
        }
        c.restore();
    };

    /**
     * What a page says, in the box of the words and on the line of the folio, at time t.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k
     * @param {Object} L
     * @param {Object} e
     * @param {number} t
     */
    var lettering = function(c, k, L, e, t) {
        c.save();
        if (e.page.kind === 'cover') {
            coverText(c, k, L);
        } else if (e.page.kind === 'dedication') {
            dedicationText(c, k, L, e, t);
        } else {
            words(c, L, e, pageOf(e.page), t);
            folio(c, e.page.n);
        }
        c.restore();
    };

    /**
     * A whole page at time t: the paper, its picture, the grain over both, its words, and the fold of the spine.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k
     * @param {Object} L
     * @param {Object} e
     * @param {number} t
     */
    var page = function(c, k, L, e, t) {
        paper(c);
        picture(c, k, L, e, t);
        grain(c, L);
        lettering(c, k, L, e, t);
        gutter(c);
    };

    /**
     * Draws something at a part of the opacity it has, if any.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {number} a
     * @param {Function} draw
     */
    var faded = function(c, a, draw) {
        if (a <= 0) {
            return;
        }
        c.save();
        c.globalAlpha *= a;
        draw();
        c.restore();
    };

    /**
     * A page drawn once on a canvas of its own, at the pixels of the video: what turns and what is under it.
     *
     * @param {Object} L
     * @param {string} slot
     * @param {Function} draw
     * @return {HTMLCanvasElement}
     */
    var still = function(L, slot, draw) {
        var cv = L.stills[slot] || (L.stills[slot] = canvasOf(L.pw, L.ph));
        var x = cv.getContext('2d');
        x.save();
        x.clearRect(0, 0, cv.width, cv.height);
        x.scale(L.dpr, L.dpr);
        draw(x);
        x.restore();
        return cv;
    };

    /**
     * Cuts a polygon by the line through m across n, keeping the side `side` (1: where n points, -1: the other).
     *
     * @param {Array[]} poly
     * @param {number[]} m
     * @param {number[]} n
     * @param {number} side
     * @return {Array[]}
     */
    var cut = function(poly, m, n, side) {
        var out = [];
        var f = function(q) {
            return side * ((q[0] - m[0]) * n[0] + (q[1] - m[1]) * n[1]);
        };
        for (var i = 0; i < poly.length; i++) {
            var a = poly[i];
            var b = poly[(i + 1) % poly.length];
            var fa = f(a);
            var fb = f(b);
            if (fa >= 0) {
                out.push(a);
            }
            if ((fa >= 0) !== (fb >= 0)) {
                var u = fa / (fa - fb);
                out.push([a[0] + (b[0] - a[0]) * u, a[1] + (b[1] - a[1]) * u]);
            }
        }
        return out;
    };
    var trace = function(c, poly) {
        c.beginPath();
        poly.forEach(function(q, i) {
            if (i) {
                c.lineTo(q[0], q[1]);
            } else {
                c.moveTo(q[0], q[1]);
            }
        });
        c.closePath();
    };

    /**
     * A page turning like the sheet of a book, held by its outer bottom corner: the corner lifts and goes over the spine,
     * the fold between them; under it, the next page, in the shadow of the sheet; on the sheet's back, the paper and the
     * picture of its front, faintly, seen through. In Arabic the book opens the other way, and so it turns.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} L
     * @param {HTMLCanvasElement} from The page that turns.
     * @param {HTMLCanvasElement} to The page under it.
     * @param {number} u 0 to 1.
     */
    var turn = function(c, L, from, to, u) {
        var th = Math.PI * easeInOut(u);
        var lx = W * Math.cos(th);
        var corner = RTL ? [0, H] : [W, H];
        var held = [RTL ? W - lx : lx, H - 0.32 * W * Math.sin(th)];
        c.drawImage(to, 0, 0, W, H);
        var dx = corner[0] - held[0];
        var dy = corner[1] - held[1];
        var len = Math.sqrt(dx * dx + dy * dy);
        if (len < 0.5) {
            c.drawImage(from, 0, 0, W, H);
            return;
        }
        var n = [dx / len, dy / len];
        var m = [(corner[0] + held[0]) / 2, (corner[1] + held[1]) / 2];
        var sheet = [[0, 0], [W, 0], [W, H], [0, H]];
        var lifted = cut(sheet, m, n, 1);
        var flat = cut(sheet, m, n, -1);
        var mn = m[0] * n[0] + m[1] * n[1];
        var mirror = function(q) {
            var s = 2 * ((q[0] * n[0] + q[1] * n[1]) - mn);
            return [q[0] - s * n[0], q[1] - s * n[1]];
        };
        var flap = lifted.map(mirror);
        var fade = 1 - smooth(0.82, 1, u);
        // the next page, in the shadow of the sheet near the fold
        c.save();
        trace(c, lifted);
        c.clip();
        var g = c.createLinearGradient(m[0], m[1], m[0] + n[0] * 110, m[1] + n[1] * 110);
        g.addColorStop(0, 'rgba(40, 25, 30, ' + 0.34 * fade + ')');
        g.addColorStop(1, 'rgba(40, 25, 30, 0)');
        c.fillStyle = g;
        c.fillRect(0, 0, W, H);
        c.restore();
        // the part of the sheet still lying flat, darker where it starts to bend
        if (flat.length > 2) {
            c.save();
            trace(c, flat);
            c.clip();
            c.drawImage(from, 0, 0, W, H);
            g = c.createLinearGradient(m[0], m[1], m[0] - n[0] * 70, m[1] - n[1] * 70);
            g.addColorStop(0, 'rgba(54, 35, 58, 0.14)');
            g.addColorStop(1, 'rgba(54, 35, 58, 0)');
            c.fillStyle = g;
            c.fillRect(0, 0, W, H);
            c.restore();
        }
        // the back of the sheet, with its shadow on what is under it
        if (flap.length > 2) {
            c.save();
            c.shadowColor = 'rgba(40, 25, 30, ' + 0.3 * fade + ')';
            c.shadowBlur = 28 * L.dpr;
            trace(c, flap);
            c.fillStyle = PAPER_BACK;
            c.fill();
            c.restore();
            c.save();
            trace(c, flap);
            c.clip();
            c.transform(1 - 2 * n[0] * n[0], -2 * n[0] * n[1], -2 * n[0] * n[1], 1 - 2 * n[1] * n[1], 2 * mn * n[0],
                2 * mn * n[1]);
            c.globalAlpha *= 0.07;
            c.drawImage(from, 0, 0, W, H);
            c.restore();
            c.save();
            trace(c, flap);
            c.clip();
            var reach = len / 2;
            g = c.createLinearGradient(m[0], m[1], m[0] - n[0] * reach, m[1] - n[1] * reach);
            g.addColorStop(0, 'rgba(54, 35, 58, 0.18)');
            g.addColorStop(0.14, 'rgba(54, 35, 58, 0.04)');
            g.addColorStop(0.45, 'rgba(255, 255, 255, 0.14)');
            g.addColorStop(1, 'rgba(54, 35, 58, 0.06)');
            c.fillStyle = g;
            c.fillRect(0, 0, W, H);
            c.restore();
        }
    };

    /**
     * Everything the frames keep between them, made at the first: the grain, the paper of each page, the layouts.
     *
     * @param {CanvasRenderingContext2D} c
     * @return {Object}
     */
    var look = function(c) {
        // The pixels of a unit: the page draws in 720 x 1280 units on a canvas scaled for the video (read, never set).
        var m = c.getTransform ? c.getTransform() : null;
        var dpr = m ? Math.sqrt(m.a * m.a + m.b * m.b) || 1 : 1;
        var L = {dpr: dpr, pw: Math.round(W * dpr), ph: Math.round(H * dpr), zones: [], text: {}, sizes: {}, tones: {},
            body: null, stills: {}, chalk: null, lift: null, dedication: null, cam: dpr, turned: -1};
        var logo = document.querySelector('.top .centro img.marca-centro');
        var same = function(src) {
            try {
                return new URL(src, root.location.href).origin === root.location.origin || /^data:/.test(src);
            } catch (err) {
                return false;
            }
        };
        L.logo = logo && logo.complete && logo.naturalWidth && same(logo.src) ? logo : null;
        var mono = document.querySelector('.top .centro .monograma');
        L.initials = mono ? mono.textContent.trim() : String(BOOK.centre || '').split(/\s+/).map(function(w) {
            return w.charAt(0);
        }).join('').slice(0, 2).toUpperCase();
        return L;
    };

    /**
     * One frame of the video at t seconds, drawn in 720 x 1280 units on whatever scale the canvas has. The book opens
     * with a sheet that turns; after that, the paper and the folio stay, and only the picture and the words go and come,
     * through the cream of the paper.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k Everything ready: plan, images, the player of the drawing, colours.
     * @param {number} t
     */
    var frame = function(c, k, t) {
        var L = k.look || (k.look = look(c));
        var pages = k.plan.pages;
        var i = 0;
        while (i + 1 < pages.length && t >= pages[i + 1].start) {
            i++;
        }
        var e = pages[i];
        c.save();
        if (i > 0 && t < e.shown) {
            var from = pages[i - 1];
            var u = (t - e.start) / (e.shown - e.start);
            if (i === 1) {
                if (L.turned !== i) {
                    still(L, 'from', function(x) {
                        page(x, k, L, from, from.end);
                    });
                    still(L, 'to', function(x) {
                        page(x, k, L, e, e.shown);
                    });
                    L.turned = i;
                }
                turn(c, L, L.stills.from, L.stills.to, u);
            } else {
                var out = 1 - smooth(0, 0.5, u);
                var into = smooth(0.5, 1, u);
                paper(c);
                faded(c, out, function() {
                    picture(c, k, L, from, t);
                });
                faded(c, into, function() {
                    picture(c, k, L, e, t);
                });
                grain(c, L);
                faded(c, out, function() {
                    lettering(c, k, L, from, t);
                });
                faded(c, into, function() {
                    lettering(c, k, L, e, t);
                });
                gutter(c);
            }
        } else {
            page(c, k, L, e, t);
        }
        c.restore();
    };

    /**
     * Everything ready: the voices of the pages, the song and the door, the illustrations, the drawing, and the letters
     * of every word the frames write (in Arabic, their Arabic faces).
     *
     * @return {Promise<Object>}
     */
    var prepare = function() {
        var AC = root.AudioContext || root.webkitAudioContext;
        var ctx = new AC();
        var voices = {};
        var loads = BOOK.pages.filter(function(p) {
            return p.audio && VOICE;
        }).map(function(p) {
            return fetchAudio(ctx, VOICE.audio + '/' + p.audio + '.mp3').then(function(b) {
                voices[p.audio] = b;
            });
        });
        var images = {};
        var imgs = BOOK.pages.filter(function(p) {
            return p.img;
        }).map(function(p) {
            var name = MAESTRO && p.maestro && p.maestro.img ? p.maestro.img : p.img;
            return image(here + 'ilustraciones/' + name + '.webp').then(function(i) {
                images[name] = i;
            });
        });
        imgs.push(image(here + 'ilustraciones/dedicatoria-manos.webp').then(function(i) {
            images.dedication = i;
        }));
        imgs.push(image(V.mark).then(function(i) {
            images.mark = i;
        }));
        // Their drawing on the wall of the class; without one, never an empty frame: one of the class, as in the book.
        var drawing = image(BOOK.drawing ? BOOK.drawing.src : BOOK.classDrawing);
        var said = BOOK.pages.map(function(p) {
            return p.kind ? '' : fill(pageText(pageOf(p)));
        }).join(' ');
        var hand = BOOK.child + ' ' + fill(T.chalk) + ' ' + fill(dedicationLines());
        return Promise.all([
            Promise.all(loads),
            fetchAudio(ctx, M.loop),
            fetchAudio(ctx, M.door).catch(function() {
                return null;
            }),
            Promise.all(imgs),
            drawing,
            document.fonts && document.fonts.load ? Promise.all([
                document.fonts.load('650 60px Caveat', hand),
                document.fonts.load('600 60px Caveat', hand),
                document.fonts.load('450 34px "Schibsted Grotesk"', said + ' ' + T.title),
                document.fonts.load('700 34px "Schibsted Grotesk"', T.title + ' ' + BOOK.centre),
                document.fonts.load('600 34px "Schibsted Grotesk"', V.gift + ' ' + V.credit)
            ]).catch(function() {
                return null;
            }) : null
        ]).then(function(got) {
            if (ctx.close) {
                ctx.close();
            }
            var k = {plan: plan(voices), bed: got[1], door: got[2], images: images, sheet: null, player: null,
                paper: PAPER, accent: V.accent, accentText: V.accentText};
            var photo = got[4];
            if (photo) {
                var sheet = document.createElement('canvas');
                var ratio = photo.naturalHeight / photo.naturalWidth;
                // large enough to stay sharp with the camera close to the frame on the wall
                sheet.width = ratio >= 1 ? Math.round(640 / ratio) : 640;
                sheet.height = ratio >= 1 ? 640 : Math.round(640 * ratio);
                k.sheet = sheet;
                if (BOOK.drawing && BOOK.drawing.strokes && root.DibujoVivo) {
                    k.player = new root.DibujoVivo.Player(sheet, photo, root.DibujoVivo.fromJSON(BOOK.drawing.strokes),
                        {after: 'still'});
                } else {
                    sheet.getContext('2d').drawImage(photo, 0, 0, sheet.width, sheet.height);
                }
            }
            return k;
        });
    };

    /**
     * The sound, encoded: AAC where the browser has it, else Opus.
     *
     * @param {AudioBuffer} buffer
     * @param {Object} muxer
     * @return {Promise<void>}
     */
    var encodeSound = function(buffer, muxer, config) {
        var failed = null;
        // WebKit stamps the AAC packets made from a block with the time of the block, and a player or WhatsApp may drop
        // the packets that share it: an AAC packet is always 1024 samples, so each goes right after the one before.
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
        var step = 4800;
        for (var i = 0; i < data.length; i += step) {
            var part = data.subarray(i, Math.min(data.length, i + step));
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

    /**
     * The first configuration of a list the browser can encode.
     *
     * @param {Function} kind VideoEncoder or AudioEncoder.
     * @param {Object[]} configs
     * @return {Promise<Object|null>}
     */
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

    /**
     * The video of the story, or null when this browser cannot make one with its sound.
     *
     * @param {function(number)} progress
     * @return {Promise<Blob|null>}
     */
    var make = function(progress) {
        if (!VOICE || !root.VideoEncoder || !root.AudioEncoder || !root.OfflineAudioContext || !root.AudioData) {
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
            root.Mp4Muxer ? null : load(here + '../mp4-muxer/mp4-muxer.min.js')]).then(function(got) {
            var vc = got[0];
            var ac = got[1];
            if (!vc || !ac || !root.Mp4Muxer) {
                return null;
            }
            return prepare().then(function(k) {
                // what the video is made of, for whoever checks it (the tests)
                root.CuentoVideo.plan = k.plan;
                progress(0.04);
                return mix(k.plan, k.bed, k.door).then(function(buffer) {
                    progress(0.1);
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
                        var canvas = document.createElement('canvas');
                        canvas.width = W * SCALE;
                        canvas.height = H * SCALE;
                        var c = canvas.getContext('2d');
                        var n = Math.ceil(k.plan.total * FPS);
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
                            progress(0.1 + 0.9 * i / n);
                            return encoder.encodeQueueSize > 6 || i % 4 === 0 ? new Promise(function(ok) {
                                setTimeout(ok, 0);
                            }).then(step) : step();
                        };
                        return step();
                    });
                });
            });
        }).catch(function() {
            return null;
        });
    };

    /**
     * The sheet of the video of the story, from its button at the dedication.
     *
     * @param {HTMLElement} button
     */
    var open = function(button) {
        if (!root.DibujoVideo) {
            return;
        }
        var v = {title: V.title, text: V.text, url: V.url, file: V.file, sound: true, words: V.words,
            instead: VOICE && VOICE.download ? {href: VOICE.download.href, file: tr('download_name'), kind: 'audio'} : null};
        root.DibujoVideo.sheet(v, make, button);
    };

    // prepare and frame, for whoever draws a single frame (the tests and the stills of the sheet)
    root.CuentoVideo = {make: make, open: open, frame: frame, prepare: prepare};
    document.addEventListener('click', function(ev) {
        var button = ev.target.closest ? ev.target.closest('[data-cuento-video]') : null;
        if (button) {
            ev.preventDefault();
            open(button);
        }
    });
})(window);
