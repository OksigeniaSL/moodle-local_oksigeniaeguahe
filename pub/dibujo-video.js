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

    var W = 720;
    var H = 1280;
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
     * One frame of the video at t seconds.
     *
     * @param {CanvasRenderingContext2D} c
     * @param {Object} p prepare()
     * @param {number} t
     */
    var frame = function(c, p, t) {
        var v = p.v;
        c.save();
        c.direction = p.rtl ? 'rtl' : 'ltr';
        c.textAlign = 'center';
        c.textBaseline = 'alphabetic';
        c.fillStyle = p.wall;
        c.fillRect(0, 0, W, H);
        c.fillStyle = p.accent;
        c.fillRect(0, 0, W, 14);
        // Who drew it.
        c.fillStyle = '#5c5650';
        fit(c, v.kicker, '500', '"Eg Sans", sans-serif', 32, W - 120);
        c.fillText(v.kicker, W / 2, 150);
        c.fillStyle = '#1a1614';
        fit(c, v.name, '600', '"Eg Mano", cursive', 104, W - 120);
        c.fillText(v.name, W / 2, 262);
        // The sheet on the wall, taped, with the drawing drawing itself.
        p.player.renderAt(Math.min(t, p.drawn));
        var room = {w: W - 120, h: 640};
        var s = Math.min(room.w / p.sheet.width, room.h / p.sheet.height);
        var dw = Math.round(p.sheet.width * s);
        var dh = Math.round(p.sheet.height * s);
        var x = Math.round((W - dw) / 2);
        var y = 320 + Math.round((room.h - dh) / 2);
        c.shadowColor = 'rgba(0, 0, 0, 0.18)';
        c.shadowBlur = 24;
        c.shadowOffsetY = 8;
        c.fillStyle = '#ffffff';
        c.fillRect(x - 14, y - 14, dw + 28, dh + 28);
        c.shadowColor = 'transparent';
        c.drawImage(p.sheet, x, y, dw, dh);
        c.fillStyle = 'rgba(232, 217, 180, 0.86)';
        [[x - 6, y - 26, -0.12], [x + dw - 110, y - 26, 0.1]].forEach(function(tape) {
            c.save();
            c.translate(tape[0] + 58, tape[1] + 16);
            c.rotate(tape[2]);
            c.fillRect(-58, -16, 116, 32);
            c.restore();
        });
        // The gift of the centre, with its logo or its initials.
        var base = 1060;
        if (p.logo) {
            var lh = 64;
            var lw = Math.min(260, lh * p.logo.naturalWidth / p.logo.naturalHeight);
            c.drawImage(p.logo, (W - lw) / 2, base - 30, lw, lw * p.logo.naturalHeight / p.logo.naturalWidth);
        } else if (v.initials) {
            c.fillStyle = p.accent;
            c.beginPath();
            c.arc(W / 2, base + 2, 32, 0, Math.PI * 2);
            c.fill();
            c.fillStyle = '#ffffff';
            c.font = '700 26px "Eg Sans", sans-serif';
            c.fillText(v.initials, W / 2, base + 12);
        }
        c.fillStyle = p.accentText;
        fit(c, v.gift, '600', '"Eg Sans", sans-serif', 32, W - 120);
        c.fillText(v.gift, W / 2, base + 92);
        // At the end, the seal of Eguahe comes in.
        var since = t - p.drawn - HOLD;
        if (since > 0) {
            c.globalAlpha = Math.min(1, since / 0.5);
            var mark = 40;
            c.font = '500 26px "Eg Sans", sans-serif';
            var tw = c.measureText(v.credit).width;
            var total = (p.mark ? mark + 12 : 0) + tw;
            var left = (W - total) / 2;
            if (p.mark) {
                c.drawImage(p.mark, left, H - 104, mark, mark);
            }
            c.fillStyle = '#5c5650';
            c.textAlign = 'left';
            c.direction = 'ltr';
            c.fillText(v.credit, left + (p.mark ? mark + 12 : 0), H - 74);
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
        var configs = ['avc1.4d401f', 'avc1.42e01f', 'avc1.640028'].map(function(codec) {
            return {codec: codec, width: W, height: H, bitrate: 2500000, framerate: FPS, avc: {format: 'avc'}};
        });
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
                video: {codec: 'avc', width: W, height: H, frameRate: FPS}, fastStart: 'in-memory',
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
            canvas.width = W;
            canvas.height = H;
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
                frame(c, p, i / FPS);
                var vf = new root.VideoFrame(canvas, {timestamp: Math.round(i * 1e6 / FPS), duration: Math.round(1e6 / FPS)});
                encoder.encode(vf, {keyFrame: i % (FPS * 2) === 0});
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
        canvas.width = W;
        canvas.height = H;
        var c = canvas.getContext('2d');
        var total = p.drawn + HOLD + CREDITS;
        return new Promise(function(ok) {
            var parts = [];
            var rec = new MR(canvas.captureStream(FPS), {mimeType: type, videoBitsPerSecond: 2500000});
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
                frame(c, p, Math.min(t, total));
                progress(Math.min(1, t / total));
                if (t < total) {
                    root.requestAnimationFrame(tick);
                } else {
                    rec.stop();
                }
            };
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
     * The sheet where the family sees the video before sending it, sends it with its text and link, or saves it; with
     * the still picture when no video can be made here.
     *
     * @param {HTMLElement} button
     */
    var open = function(button) {
        var v = JSON.parse(button.getAttribute('data-video'));
        var dialog = document.createElement('dialog');
        dialog.className = 'hoja-video';
        dialog.setAttribute('aria-label', v.title || v.name);
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
            button.focus();
        };
        close.addEventListener('click', shut);
        dialog.addEventListener('cancel', shut);
        shown.appendChild(state);
        shown.appendChild(bar);
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
            var where = button.getAttribute(what === 'saved' ? 'data-medir-guardar' : 'data-medir');
            if (where && navigator.sendBeacon) {
                try {
                    navigator.sendBeacon(where);
                } catch (e) {
                    // Only a count.
                }
            }
        };
        var offer = function(blob, name, kind) {
            url = URL.createObjectURL(blob);
            var file = new File([blob], name, {type: blob.type});
            var text = v.text + (v.url ? '\n' + v.url : '');
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
            save.textContent = v.words[kind === 'video' ? 'save' : 'savepicture'];
            save.addEventListener('click', function() {
                counted('saved');
            });
            actions.appendChild(save);
            return url;
        };
        make(v, function(done) {
            bar.value = done;
        }).then(function(blob) {
            shown.removeChild(bar);
            if (blob) {
                var video = document.createElement('video');
                video.src = offer(blob, v.file, 'video');
                video.muted = true;
                video.autoplay = true;
                video.loop = true;
                video.playsInline = true;
                video.setAttribute('playsinline', '');
                video.setAttribute('aria-label', v.title || v.name);
                shown.replaceChild(video, state);
                return;
            }
            // No video here: the still picture, which keeps as well.
            state.textContent = v.words.picture;
            return root.fetch(v.picture, {credentials: 'same-origin'}).then(function(r) {
                return r.ok ? r.blob() : null;
            }).then(function(png) {
                if (!png) {
                    state.textContent = v.words.failed;
                    return;
                }
                var img = document.createElement('img');
                img.alt = v.title || v.name;
                img.src = offer(png, v.file.replace(/\.mp4$/, '.png'), 'picture');
                shown.appendChild(img);
            });
        }).catch(function() {
            state.textContent = v.words.failed;
        });
    };

    root.DibujoVideo = {make: make, open: open, frame: frame, size: [W, H]};
})(window);
