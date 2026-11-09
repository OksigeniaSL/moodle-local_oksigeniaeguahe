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
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* global BOOK, VOICE, T, tt, tr, LANG, RTL, MAESTRO, CENTRE_PHRASE, M, BED, DUCK, DOOR, ENDING */
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
    var GAP = 1.1;
    var DOOR_AT = 0.25;
    var here = BOOK.base;
    var V = BOOK.video;

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
    var fill = function(text) {
        return String(text || '').replace(/\{nombre\}/g, BOOK.child).replace(/\{centro\}/g, CENTRE_PHRASE)
            .replace(/\{tutor\}/g, (BOOK.tutors || {})[MAESTRO ? 'maestro' : 'maestra'] || '');
    };

    /**
     * When each page sounds and shows: its voice, then the door, then the next page; the dedication with the song alone.
     *
     * @param {Object} voices Page audio name => AudioBuffer.
     * @return {Object} pages (page, start, end, voice) and total seconds.
     */
    var plan = function(voices) {
        var t = 0.4;
        var out = [];
        BOOK.pages.forEach(function(p) {
            var voice = p.audio && voices[p.audio] ? voices[p.audio] : null;
            var long = p.kind === 'dedication' ? ENDING / 1000 : (voice ? voice.duration : 4) + GAP;
            out.push({page: p, start: t, end: t + long, voice: voice});
            t += long;
        });
        return {pages: out, total: t + 0.6};
    };

    /**
     * The sound of the whole video, mixed as the book plays it: each voice at its time, the door between pages, and the
     * song looped under them, down while the voice speaks and fading at the end.
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
        pl.pages.forEach(function(e, i) {
            if (e.voice) {
                var s = ctx.createBufferSource();
                s.buffer = e.voice;
                s.connect(ctx.destination);
                s.start(e.start);
                (VOICE.speech[e.page.audio] || []).forEach(function(seg) {
                    g.setTargetAtTime(BED * DUCK, e.start + Math.max(0, seg[0] - 0.05), 0.03);
                    g.setTargetAtTime(BED, e.start + seg[1] + 0.12, 0.12);
                });
            }
            if (doorBuf && i > 0 && i < pl.pages.length) {
                var d = ctx.createBufferSource();
                var dg = ctx.createGain();
                dg.gain.value = DOOR;
                d.buffer = doorBuf;
                d.connect(dg);
                dg.connect(ctx.destination);
                d.start(Math.max(0, e.start - GAP + DOOR_AT));
            }
        });
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

    /**
     * The lines of a text that fit a width, never narrowed.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {string} text
     * @param {number} width
     * @return {string[]}
     */
    var wrap = function(c, text, width) {
        var out = [];
        String(text).split('\n').forEach(function(para) {
            var line = '';
            para.split(' ').forEach(function(word) {
                var next = line ? line + ' ' + word : word;
                if (line && c.measureText(next).width > width) {
                    out.push(line);
                    line = word;
                } else {
                    line = next;
                }
            });
            if (line) {
                out.push(line);
            }
        });
        return out;
    };

    /**
     * One frame: the page of the book at that time, its illustration slowly coming closer, the name on it, the drawing
     * on the wall drawing itself, and the words of the page under it.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} k Everything ready: plan, images, the player of the drawing, colours.
     * @param {number} t
     */
    var frame = function(c, k, t) {
        var e = k.plan.pages.filter(function(x) {
            return t >= x.start;
        }).pop() || k.plan.pages[0];
        var p = MAESTRO && e.page.maestro ? Object.assign({}, e.page, e.page.maestro) : e.page;
        var local = t - e.start;
        var span = e.end - e.start;
        c.save();
        c.fillStyle = k.paper;
        c.fillRect(0, 0, W, H);
        c.direction = RTL ? 'rtl' : 'ltr';
        c.textAlign = 'center';
        c.fillStyle = k.accent;
        c.fillRect(0, 0, W, 12);
        // A little fade between pages.
        c.globalAlpha = Math.min(1, local / 0.35);
        if (p.kind === 'cover') {
            c.fillStyle = '#5c5650';
            c.font = '500 34px "Schibsted Grotesk", sans-serif';
            c.fillText(T.title, W / 2, 470);
            c.fillStyle = '#2b2226';
            c.font = '650 120px Caveat, cursive';
            c.fillText(BOOK.child, W / 2, 640);
            c.fillStyle = k.accentText;
            c.font = '600 30px "Schibsted Grotesk", sans-serif';
            c.fillText(BOOK.centre, W / 2, 760);
        } else if (p.kind === 'dedication') {
            var hands = k.images.dedication;
            if (hands) {
                var hw = 460;
                var hh = hw * hands.naturalHeight / hands.naturalWidth;
                c.drawImage(hands, (W - hw) / 2, 200, hw, hh);
            }
            c.fillStyle = '#2b2226';
            c.font = '600 46px Caveat, cursive';
            wrap(c, fill(tt('dedication') || T.dedication || ''), W - 120).forEach(function(line, i) {
                c.fillText(line, W / 2, 800 + i * 58);
            });
            c.fillStyle = k.accentText;
            var gs = 30;
            c.font = '600 ' + gs + 'px "Schibsted Grotesk", sans-serif';
            while (gs > 16 && c.measureText(V.gift).width > W - 100) {
                gs -= 1;
                c.font = '600 ' + gs + 'px "Schibsted Grotesk", sans-serif';
            }
            c.fillText(V.gift, W / 2, 1110);
            // The seal of Eguahe comes in at the end.
            var since = local - 2;
            if (since > 0) {
                c.globalAlpha = Math.min(1, since / 0.6);
                var mark = 40;
                c.font = '500 26px "Schibsted Grotesk", sans-serif';
                var tw = c.measureText(V.credit).width;
                var left = (W - (k.images.mark ? mark + 12 : 0) - tw) / 2;
                if (k.images.mark) {
                    c.drawImage(k.images.mark, left, H - 104, mark, mark);
                }
                c.fillStyle = '#5c5650';
                c.textAlign = 'left';
                c.direction = 'ltr';
                c.fillText(V.credit, left + (k.images.mark ? mark + 12 : 0), H - 74);
            }
        } else {
            // The illustration, coming slowly closer, with the name written on it.
            var img = k.images[p.img];
            var iw = W;
            var ih = W * 1024 / 1536;
            var zoom = 1 + 0.06 * Math.min(1, local / Math.max(span, 1));
            var top = 150;
            c.save();
            c.beginPath();
            c.rect(0, top, iw, ih);
            c.clip();
            c.translate(W / 2, top + ih / 2);
            c.scale(zoom, zoom);
            c.translate(-W / 2, -(top + ih / 2));
            if (img) {
                c.drawImage(img, 0, top, iw, ih);
            }
            var s = iw / 1536;
            (p.names || []).forEach(function(n) {
                c.save();
                c.translate(n.x * s, top + n.y * s);
                c.rotate((n.rot || 0) * Math.PI / 180);
                c.fillStyle = n.kind === 'chalk' ? '#f4f1e8' : '#2b2226';
                var text = n.text || (n.kind === 'chalk' ? fill(T.chalk) : BOOK.child);
                var size = n.size * s;
                c.font = (n.kind === 'chalk' ? 600 : 650) + ' ' + size + 'px Caveat, cursive';
                while (size > 6 && c.measureText(text).width > n.w * s) {
                    size -= 1;
                    c.font = (n.kind === 'chalk' ? 600 : 650) + ' ' + size + 'px Caveat, cursive';
                }
                c.fillText(text, 0, 0);
                c.restore();
            });
            if (p.frame && k.sheet) {
                var q = p.frame.quad;
                var fx = q[0][0] * s;
                var fy = top + q[0][1] * s;
                var fw = (q[1][0] - q[0][0]) * s;
                var fh = (q[3][1] - q[0][1]) * s;
                if (k.player) {
                    k.player.renderAt(Math.max(0, local - 0.6));
                }
                c.fillStyle = '#ffffff';
                c.fillRect(fx, fy, fw, fh);
                var r = Math.min(fw / k.sheet.width, fh / k.sheet.height);
                c.drawImage(k.sheet, fx + (fw - k.sheet.width * r) / 2, fy + (fh - k.sheet.height * r) / 2,
                    k.sheet.width * r, k.sheet.height * r);
            }
            c.restore();
            // The words of the page.
            c.fillStyle = '#2b2226';
            c.font = '450 34px "Schibsted Grotesk", sans-serif';
            wrap(c, fill(tt('page' + p.n)), W - 110).forEach(function(line, i) {
                c.fillText(line, W / 2, top + ih + 110 + i * 50);
            });
        }
        c.restore();
    };

    /**
     * Everything ready: the voices of the pages, the song and the door, the illustrations, the drawing.
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
        return Promise.all([
            Promise.all(loads),
            fetchAudio(ctx, M.loop),
            fetchAudio(ctx, M.door).catch(function() {
                return null;
            }),
            Promise.all(imgs),
            drawing,
            document.fonts && document.fonts.load ? Promise.all([
                document.fonts.load('650 60px Caveat', BOOK.child),
                document.fonts.load('450 34px "Schibsted Grotesk"', T.title)
            ]).catch(function() {
                return null;
            }) : null
        ]).then(function(got) {
            if (ctx.close) {
                ctx.close();
            }
            var k = {plan: plan(voices), bed: got[1], door: got[2], images: images, sheet: null, player: null,
                paper: '#fbf7ef', accent: V.accent, accentText: V.accentText};
            var photo = got[4];
            if (photo) {
                var sheet = document.createElement('canvas');
                var ratio = photo.naturalHeight / photo.naturalWidth;
                sheet.width = ratio >= 1 ? Math.round(400 / ratio) : 400;
                sheet.height = ratio >= 1 ? 400 : Math.round(400 * ratio);
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
        var encoder = new root.AudioEncoder({
            output: function(chunk, meta) {
                muxer.addAudioChunk(chunk, meta);
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
            return {codec: codec, width: W * SCALE, height: H * SCALE, bitrate: 3000000, framerate: FPS,
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
                                opts.avc = {quantizer: 23};
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

    root.CuentoVideo = {make: make, open: open, frame: frame};
    document.addEventListener('click', function(ev) {
        var button = ev.target.closest ? ev.target.closest('[data-cuento-video]') : null;
        if (button) {
            ev.preventDefault();
            open(button);
        }
    });
})(window);
