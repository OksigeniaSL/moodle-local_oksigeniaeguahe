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
 * The drawing of a child as a video to keep: the drawing drawing itself on the wall of the centre, the name of the
 * child, the gift of the centre, a still pause and, at the end, the seal of Eguahe. It travels as a file, so whoever
 * receives it keeps it in their phone even when the link no longer works (Enrique, 9-11: «Si se comparte con la abuela
 * ¿Cómo lo conserva?»).
 *
 * Made in the browser, frame by frame with the player of the living drawing (DibujoVivo, renderAt): with WebCodecs and
 * mp4-muxer (loaded only when asked) as an MP4 that WhatsApp and every phone play; without WebCodecs, recorded with
 * MediaRecorder when it can make an MP4; else nothing, and the sheet offers the picture instead. Before sending, the
 * family sees it; then the phone's own way to share sends the file with its text and the link; and it can always be
 * saved.
 *
 * A button opens it: [data-video] with a JSON of photo, strokes, name, kicker, gift, credit, mark, logo, initials,
 * file, picture (the still image to send when a video cannot be made) and the words of the sheet.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function(root) {
    'use strict';

    // The frames are drawn in 720 x 1280 units on a canvas of 1080 x 1920 (Full HD, upright): sharp on any phone.
    var W = 720;
    var H = 1280;
    var SCALE = 1.5;
    var FPS = 30;
    var HOLD = 1.5;
    var CREDITS = 2;
    var here = document.currentScript ? document.currentScript.src.replace(/[^/]*$/, '') : '';

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
        return new Promise(function(ok, ko) {
            if (!src) {
                ok(null);
                return;
            }
            var img = new Image();
            img.onload = function() {
                ok(img);
            };
            img.onerror = ko;
            img.src = src;
        });
    };
    var css = function(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    };

    /**
     * The words of a line at the largest size that fits, never narrowed.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {string} text
     * @param {string} weight
     * @param {string} family
     * @param {number} size
     * @param {number} room
     * @return {number}
     */
    var fit = function(c, text, weight, family, size, room) {
        for (; size > 16; size -= 2) {
            c.font = weight + ' ' + size + 'px ' + family;
            if (c.measureText(text).width <= room) {
                break;
            }
        }
        return size;
    };

    /**
     * Everything a frame needs, ready: the photo, the strokes, the player, the logo and the mark.
     *
     * @param {Object} v The data of the button.
     * @return {Promise<Object>}
     */
    var prepare = function(v) {
        var DV = root.DibujoVivo;
        return Promise.all([
            image(v.photo),
            root.fetch(v.strokes, {credentials: 'same-origin'}).then(function(r) {
                if (!r.ok) {
                    throw new Error(String(r.status));
                }
                return r.json();
            }),
            image(v.logo).catch(function() {
                return null;
            }),
            image(v.mark).catch(function() {
                return null;
            }),
            document.fonts && document.fonts.load ? Promise.all([
                document.fonts.load('600 96px "Eg Mano"', v.name),
                document.fonts.load('600 32px "Eg Sans"', v.gift + v.kicker + v.credit)
            ]).catch(function() {
                return null;
            }) : null
        ]).then(function(got) {
            var a = DV.fromJSON(got[1]);
            var sheet = document.createElement('canvas');
            var long = 900;
            var ratio = got[0].naturalHeight / got[0].naturalWidth;
            sheet.width = ratio >= 1 ? Math.round(long / ratio) : long;
            sheet.height = ratio >= 1 ? long : Math.round(long * ratio);
            var player = new DV.Player(sheet, got[0], a, {after: 'still'});
            return {v: v, player: player, sheet: sheet, logo: got[2], mark: got[3], drawn: player.total(),
                wall: css('--c-pared', '#f4eadc'), accent: css('--c-acento', '#2f5d62'),
                accentText: css('--c-acento-texto', '#2f5d62'), rtl: document.documentElement.dir === 'rtl'};
        });
    };

    /**
     * Colours for the video, always those of the day: the video is kept and shown anywhere, so it does not follow the
     * dark of the phone that made it. A wall read in the dark gives way to the paper of the day, and the words of the
     * centre are taken towards black until they read on it.
     *
     * @param {Object} p prepare()
     * @return {Object} wall, ink, soft, accent, accentText
     */
    var day = function(p) {
        var rgb = function(hex) {
            var h = String(hex || '').replace('#', '');
            if (h.length === 3) {
                h = h.replace(/./g, '$&$&');
            }
            var n = parseInt(h.slice(0, 6), 16);
            return isNaN(n) ? [47, 93, 98] : [n >> 16 & 255, n >> 8 & 255, n & 255];
        };
        var lum = function(c) {
            var f = function(x) {
                x /= 255;
                return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
            };
            return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
        };
        var ratio = function(a, b) {
            var x = lum(a);
            var y = lum(b);
            return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
        };
        var css = function(c) {
            return 'rgb(' + c.map(Math.round).join(',') + ')';
        };
        var accent = rgb(p.accent);
        var wall = rgb(p.wall);
        if (lum(wall) < 0.45) {
            // The wall of the day, as the server makes it: warm paper with 6 % of the colour of the centre.
            wall = [244, 234, 220].map(function(x, i) {
                return x + (accent[i] - x) * 0.06;
            });
        }
        var text = accent.slice();
        for (var k = 0; k < 20 && ratio(text, wall) < 4.8; k++) {
            text = text.map(function(x) {
                return x * 0.86;
            });
        }
        return {wall: css(wall), light: 'rgba(255, 255, 255, 0.5)', ink: '#2b2226', soft: '#5f5459', accent: css(accent),
            accentText: css(text)};
    };

    /**
     * The label of the first day, as on the pages of the families: the band in the colour of the centre, the paper, its
     * line and its peeled corner, and the name written by hand, at the largest size that fits its line.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} col day()
     * @param {string} name
     * @param {boolean} rtl
     */
    var label = function(c, col, name, rtl) {
        var shape = function(d, fill) {
            c.fillStyle = fill;
            c.fill(new Path2D(d));
        };
        c.shadowColor = 'rgba(58, 42, 30, 0.35)';
        c.shadowBlur = 6;
        c.shadowOffsetY = 2;
        shape('M-23 -12 Q-23 -16 -19 -16 L19 -16 Q23 -16 23 -12 L23 8 L15 16 L-19 16 Q-23 16 -23 12 Z', col.accent);
        c.shadowColor = 'transparent';
        shape('M-20.6 -6.2 L20.6 -6.2 L20.6 7 L13.8 13.8 L-18.6 13.8 Q-20.6 13.8 -20.6 11.8 Z', '#ffffff');
        c.globalAlpha = 0.75;
        shape('M-15 6.2 L9 6.2 L9 7.9 L-15 7.9 Z', '#8c8178');
        c.globalAlpha = 1;
        shape('M23 8 L15 16 L15 8 Z', '#dccdbb');
        c.fillStyle = col.ink;
        c.textAlign = 'center';
        c.direction = rtl ? 'rtl' : 'ltr';
        // The line has room for 27 units; a longer name gets smaller, never narrower.
        c.font = '600 13.5px "Eg Mano", cursive';
        var size = Math.min(13.5, 13.5 * 27 / Math.max(1, c.measureText(name).width));
        c.font = '600 ' + size.toFixed(2) + 'px "Eg Mano", cursive';
        c.fillText(name, -3, 5);
    };

    /**
     * A strip of paper tape, cream and half seen through, its ends torn.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {number} x Its centre.
     * @param {number} y
     * @param {number} angle Radians.
     */
    var tape = function(c, x, y, angle) {
        var len = 132;
        var wide = 38;
        c.save();
        c.translate(x, y);
        c.rotate(angle);
        c.beginPath();
        c.moveTo(-len / 2, -wide / 2);
        c.lineTo(len / 2, -wide / 2);
        for (var i = 0; i <= 6; i++) {
            c.lineTo(len / 2 + (i % 2 ? -3 : 3), -wide / 2 + i * wide / 6);
        }
        c.lineTo(-len / 2, wide / 2);
        for (var j = 6; j >= 0; j--) {
            c.lineTo(-len / 2 + (j % 2 ? 3 : -3), -wide / 2 + j * wide / 6);
        }
        c.closePath();
        c.fillStyle = 'rgba(232, 217, 180, 0.88)';
        c.fill();
        c.strokeStyle = 'rgba(201, 180, 138, 0.6)';
        c.lineWidth = 1;
        c.stroke();
        c.restore();
    };

    /**
     * One frame of the video at t seconds: the drawing stuck on the wall of the centre with two strips of tape, a little
     * crooked, drawing itself; when it is done, the label with the name is stuck on its corner; then a still pause; at
     * the end, the seal of Eguahe comes in. Above, «Un dibujo de» and the name by hand; below, the centre and its gift.
     * The same in every language: the label goes on the corner where the line ends (left in Arabic).
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} p prepare()
     * @param {number} t
     */
    var frame = function(c, p, t) {
        var v = p.v;
        var col = day(p);
        var ease = function(x) {
            x = Math.max(0, Math.min(1, x));
            return 1 - Math.pow(1 - x, 3);
        };
        c.save();
        c.direction = p.rtl ? 'rtl' : 'ltr';
        c.textAlign = 'center';
        c.textBaseline = 'alphabetic';
        // The wall, with a soft light from above.
        c.fillStyle = col.wall;
        c.fillRect(0, 0, W, H);
        var glow = c.createRadialGradient(W / 2, -120, 40, W / 2, -120, H * 0.85);
        glow.addColorStop(0, col.light);
        glow.addColorStop(1, 'rgba(255, 255, 255, 0)');
        c.fillStyle = glow;
        c.fillRect(0, 0, W, H);
        // Who drew it.
        c.fillStyle = col.soft;
        fit(c, v.kicker, '600', '"Eg Sans", sans-serif', 30, W - 120);
        c.fillText(v.kicker, W / 2, 132);
        c.fillStyle = col.ink;
        fit(c, v.name, '600', '"Eg Mano", cursive', 112, W - 110);
        c.fillText(v.name, W / 2, 246);
        // The drawing on the wall, a little crooked, with its shadow and two strips of tape on its corners.
        p.player.renderAt(Math.min(t, p.drawn));
        var room = {w: W - 130, h: 650};
        var s = Math.min(room.w / p.sheet.width, room.h / p.sheet.height);
        var dw = Math.round(p.sheet.width * s);
        var dh = Math.round(p.sheet.height * s);
        var cx = W / 2;
        var cy = 312 + room.h / 2;
        var tilt = -0.035;
        c.save();
        c.translate(cx, cy);
        c.rotate(tilt);
        c.shadowColor = 'rgba(58, 42, 30, 0.32)';
        c.shadowBlur = 34;
        c.shadowOffsetY = 16;
        c.fillStyle = '#f4efe6';
        c.fillRect(-dw / 2, -dh / 2, dw, dh);
        c.shadowColor = 'transparent';
        c.drawImage(p.sheet, -dw / 2, -dh / 2, dw, dh);
        c.restore();
        var corner = function(sx, sy) {
            var x = sx * dw / 2;
            var y = sy * dh / 2;
            return [cx + x * Math.cos(tilt) - y * Math.sin(tilt), cy + x * Math.sin(tilt) + y * Math.cos(tilt)];
        };
        var a = corner(-1, -1);
        tape(c, a[0] + 10, a[1] + 8, tilt - 0.7);
        a = corner(1, -1);
        tape(c, a[0] - 10, a[1] + 8, tilt + 0.7);
        // When it is done, the label with the name, stuck on the corner where the line ends.
        var stuck = ease((t - p.drawn) / 0.45);
        if (stuck > 0 && v.name) {
            a = corner(p.rtl ? -1 : 1, 1);
            c.save();
            c.globalAlpha = stuck;
            c.translate(a[0] + (p.rtl ? 46 : -46), a[1] - 40);
            c.rotate(-0.09 + (1 - stuck) * 0.25);
            var k = 4.2 * (1 + (1 - stuck) * 0.35);
            c.scale(k, k);
            label(c, col, v.name, p.rtl);
            c.restore();
        }
        // The centre: its logo or its initials, and its gift.
        var base = 1046;
        if (p.logo) {
            var lh = 64;
            var lw = Math.min(300, lh * p.logo.naturalWidth / p.logo.naturalHeight);
            c.drawImage(p.logo, (W - lw) / 2, base - 40, lw, lw * p.logo.naturalHeight / p.logo.naturalWidth);
        } else if (v.initials) {
            c.fillStyle = col.accent;
            c.beginPath();
            c.arc(W / 2, base - 8, 32, 0, Math.PI * 2);
            c.fill();
            c.fillStyle = '#ffffff';
            c.font = '700 25px "Eg Sans", sans-serif';
            c.fillText(v.initials, W / 2, base + 1);
        }
        c.fillStyle = col.accentText;
        fit(c, v.gift, '650', '"Eg Sans", sans-serif', 34, W - 120);
        c.fillText(v.gift, W / 2, base + 74);
        // At the end, the seal of Eguahe: its mark and its name, always this size.
        var since = ease((t - p.drawn - HOLD) / 0.5);
        if (since > 0) {
            c.globalAlpha = since;
            var mark = 38;
            c.font = '600 25px "Eg Sans", sans-serif';
            c.direction = 'ltr';
            var tw = c.measureText(v.credit).width;
            var total = (p.mark ? mark + 12 : 0) + tw;
            var left = (W - total) / 2;
            if (p.mark) {
                c.drawImage(p.mark, left, H - 100, mark, mark);
            }
            c.fillStyle = col.soft;
            c.textAlign = 'left';
            c.fillText(v.credit, left + (p.mark ? mark + 12 : 0), H - 72);
            c.globalAlpha = 1;
        }
        c.restore();
    };

    /**
     * The video, with WebCodecs and mp4-muxer: faster than real time, the same in every browser that has them.
     *
     * @param {Object} p prepare()
     * @param {function(number)} progress
     * @return {Promise<Blob|null>}
     */
    var withCodecs = function(p, progress) {
        var configs = ['avc1.640028', 'avc1.4d4028', 'avc1.42e028'].map(function(codec) {
            return {codec: codec, width: W * SCALE, height: H * SCALE, bitrate: 6000000, framerate: FPS,
                avc: {format: 'avc'}};
        });
        configs = configs.map(function(cfg) {
            return Object.assign({}, cfg, {bitrateMode: 'quantizer'});
        }).concat(configs);
        var pick = function(i) {
            if (i >= configs.length) {
                return Promise.resolve(null);
            }
            return root.VideoEncoder.isConfigSupported(configs[i]).then(function(r) {
                return r.supported ? configs[i] : pick(i + 1);
            }, function() {
                return pick(i + 1);
            });
        };
        return Promise.all([pick(0), root.Mp4Muxer ? null : load(here + 'mp4-muxer/mp4-muxer.min.js')]).then(function(got) {
            var config = got[0];
            if (!config || !root.Mp4Muxer) {
                return null;
            }
            var muxer = new root.Mp4Muxer.Muxer({target: new root.Mp4Muxer.ArrayBufferTarget(),
                video: {codec: 'avc', width: W * SCALE, height: H * SCALE, frameRate: FPS}, fastStart: 'in-memory',
                firstTimestampBehavior: 'offset'});
            var failed = null;
            var encoder = new root.VideoEncoder({
                output: function(chunk, meta) {
                    muxer.addVideoChunk(chunk, meta);
                },
                error: function(e) {
                    failed = e;
                }
            });
            encoder.configure(config);
            var canvas = document.createElement('canvas');
            canvas.width = W * SCALE;
            canvas.height = H * SCALE;
            var c = canvas.getContext('2d');
            var n = Math.ceil((p.drawn + HOLD + CREDITS) * FPS);
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
                frame(c, p, i / FPS);
                var vf = new root.VideoFrame(canvas, {timestamp: Math.round(i * 1e6 / FPS), duration: Math.round(1e6 / FPS)});
                var opts = {keyFrame: i % (FPS * 2) === 0};
                if (config.bitrateMode === 'quantizer') {
                    opts.avc = {quantizer: 20};
                }
                encoder.encode(vf, opts);
                vf.close();
                i++;
                progress(i / n);
                // Let the page breathe, and the encoder keep up.
                var wait = encoder.encodeQueueSize > 8 || i % 6 === 0;
                return wait ? new Promise(function(ok) {
                    setTimeout(ok, 0);
                }).then(step) : step();
            };
            return step();
        });
    };

    /**
     * The video recorded as it plays, where WebCodecs is missing and the browser records MP4 (Safari before 16.4).
     *
     * @param {Object} p prepare()
     * @param {function(number)} progress
     * @return {Promise<Blob|null>}
     */
    var recorded = function(p, progress) {
        var MR = root.MediaRecorder;
        var type = MR && ['video/mp4;codecs=avc1', 'video/mp4'].filter(function(t) {
            return MR.isTypeSupported(t);
        })[0];
        var canvas = document.createElement('canvas');
        if (!type || !canvas.captureStream) {
            return Promise.resolve(null);
        }
        canvas.width = W * SCALE;
        canvas.height = H * SCALE;
        var c = canvas.getContext('2d');
        var total = p.drawn + HOLD + CREDITS;
        return new Promise(function(ok) {
            var parts = [];
            var rec = new MR(canvas.captureStream(FPS), {mimeType: type, videoBitsPerSecond: 6000000});
            rec.ondataavailable = function(e) {
                if (e.data && e.data.size) {
                    parts.push(e.data);
                }
            };
            rec.onstop = function() {
                ok(parts.length ? new Blob(parts, {type: 'video/mp4'}) : null);
            };
            var start = null;
            var tick = function(now) {
                start = start === null ? now : start;
                var t = (now - start) / 1000;
                c.setTransform(SCALE, 0, 0, SCALE, 0, 0);
                frame(c, p, Math.min(t, total));
                progress(Math.min(1, t / total));
                if (t < total) {
                    root.requestAnimationFrame(tick);
                } else {
                    rec.stop();
                }
            };
            c.setTransform(SCALE, 0, 0, SCALE, 0, 0);
            frame(c, p, 0);
            rec.start();
            root.requestAnimationFrame(tick);
        });
    };

    /**
     * The video of a drawing, or null when this browser cannot make an MP4.
     *
     * @param {Object} v The data of the button.
     * @param {function(number)} progress From 0 to 1.
     * @return {Promise<Blob|null>}
     */
    var make = function(v, progress) {
        if (!root.DibujoVivo || !v.strokes || !root.fetch || !root.Promise) {
            return Promise.resolve(null);
        }
        return prepare(v).then(function(p) {
            var codecs = root.VideoEncoder && root.VideoFrame ? withCodecs(p, progress) : Promise.resolve(null);
            return codecs.catch(function() {
                return null;
            }).then(function(blob) {
                return blob || recorded(p, progress);
            });
        }).catch(function() {
            return null;
        });
    };

    /**
     * The sheet where the video is seen before it is sent, then sent as a file with its text and link, or saved; and,
     * when no video can be made here, what keeps instead (the still picture of a drawing, the voice of a story).
     *
     * @param {Object} v file, title, text, url and words (preparing, send, save, close, failed, and instead/saveinstead);
     *     instead: {href, file, kind} what goes when there is no video.
     * @param {function(function(number)): Promise<Blob|null>} build Makes the video, saying how far it got.
     * @param {HTMLElement} button What opened it: focus goes back there; data-medir and data-medir-guardar count.
     */
    var sheet = function(v, build, button) {
        var dialog = document.createElement('dialog');
        dialog.className = 'hoja-video';
        dialog.setAttribute('aria-label', v.title || '');
        var title = document.createElement('p');
        title.className = 'hoja-video-titulo';
        title.textContent = v.title || '';
        var shown = document.createElement('div');
        shown.className = 'hoja-video-vista';
        var state = document.createElement('p');
        state.className = 'hoja-video-estado';
        state.setAttribute('aria-live', 'polite');
        state.textContent = v.words.preparing;
        var bar = document.createElement('progress');
        bar.max = 1;
        bar.value = 0;
        var actions = document.createElement('p');
        actions.className = 'hoja-video-acciones';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'enlace';
        close.textContent = v.words.close;
        var url = null;
        var shut = function() {
            if (url) {
                URL.revokeObjectURL(url);
            }
            dialog.close();
            dialog.remove();
            if (button) {
                button.focus();
            }
        };
        close.addEventListener('click', shut);
        dialog.addEventListener('cancel', shut);
        shown.appendChild(state);
        shown.appendChild(bar);
        dialog.appendChild(title);
        dialog.appendChild(shown);
        dialog.appendChild(actions);
        dialog.appendChild(close);
        document.body.appendChild(dialog);
        if (dialog.showModal) {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        var counted = function(what) {
            var where = button && button.getAttribute(what === 'saved' ? 'data-medir-guardar' : 'data-medir');
            if (where && navigator.sendBeacon) {
                try {
                    navigator.sendBeacon(where);
                } catch (e) {
                    // Only a count.
                }
            }
        };
        var offer = function(blob, name, label) {
            url = URL.createObjectURL(blob);
            var file = new File([blob], name, {type: blob.type});
            var text = (v.text || '') + (v.url ? '\n' + v.url : '');
            if (navigator.canShare && navigator.canShare({files: [file]})) {
                var send = document.createElement('button');
                send.type = 'button';
                send.className = 'boton principal';
                send.textContent = v.words.send;
                send.addEventListener('click', function() {
                    navigator.share({files: [file], title: v.title, text: text}).then(function() {
                        counted('sent');
                    }).catch(function() {
                        // Not sent: nothing to count.
                    });
                });
                actions.appendChild(send);
            }
            var save = document.createElement('a');
            save.className = 'boton secundario';
            save.href = url;
            save.download = name;
            save.textContent = label;
            save.addEventListener('click', function() {
                counted('saved');
            });
            actions.appendChild(save);
            return url;
        };
        build(function(done) {
            bar.value = done;
        }).then(function(blob) {
            shown.removeChild(bar);
            if (blob) {
                var video = document.createElement('video');
                video.src = offer(blob, v.file, v.words.save);
                video.muted = true;
                video.autoplay = true;
                video.loop = true;
                video.controls = !!v.sound;
                video.playsInline = true;
                video.setAttribute('playsinline', '');
                video.setAttribute('aria-label', v.title || '');
                shown.replaceChild(video, state);
                return null;
            }
            // No video here: what keeps instead (the still picture, the voice of the story).
            var instead = v.instead;
            state.textContent = instead ? v.words.instead : v.words.failed;
            if (!instead) {
                return null;
            }
            return root.fetch(instead.href, {credentials: 'same-origin'}).then(function(r) {
                return r.ok ? r.blob() : null;
            }).then(function(got) {
                if (!got) {
                    state.textContent = v.words.failed;
                    return;
                }
                var href = offer(got, instead.file, v.words.saveinstead);
                if (instead.kind === 'picture') {
                    var img = document.createElement('img');
                    img.alt = v.title || '';
                    img.src = href;
                    shown.appendChild(img);
                }
            });
        }).catch(function() {
            state.textContent = v.words.failed;
        });
    };

    /**
     * The sheet of the video of a drawing, from its button.
     *
     * @param {HTMLElement} button
     */
    var open = function(button) {
        var v = JSON.parse(button.getAttribute('data-video'));
        v.title = v.title || v.name;
        v.instead = {href: v.picture, file: v.file.replace(/\.mp4$/, '.png'), kind: 'picture'};
        v.words.instead = v.words.picture;
        v.words.saveinstead = v.words.savepicture;
        sheet(v, function(progress) {
            return make(v, progress);
        }, button);
    };

    root.DibujoVideo = {make: make, open: open, sheet: sheet, frame: frame, size: [W, H]};
})(window);
