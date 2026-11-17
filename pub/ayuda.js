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
 * Eguahe: the engine of the guided helps (the one of Ayvurenda, made Eguahe's). The real screen runs in a frame, on the
 * practice centre; a pointer moves over it, types and taps; each step has its caption, and its voice when there is one,
 * with a music under it; «Try it» rings a button and waits for the person's tap. With ?auto=1 it taps by itself, for the
 * tests. Plain script, for Chrome 93 and Firefox 98; the configuration comes in data-ayuda.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var root = document.getElementById('eg-ayuda');
    if (!root) {
        return;
    }
    var config = {};
    try {
        config = JSON.parse(root.getAttribute('data-ayuda') || '{}');
    } catch (e) {
        config = {};
    }
    var frame = document.getElementById('eg-ayuda-marco');
    var stage = document.getElementById('eg-ayuda-escena');
    var pointer = document.getElementById('eg-ayuda-dedo');
    var ring = document.getElementById('eg-ayuda-anillo');
    var caption = document.getElementById('eg-ayuda-texto');
    var start = document.getElementById('eg-ayuda-empezar');
    var done = document.getElementById('eg-ayuda-hecho');
    var skipButton = document.getElementById('eg-ayuda-saltar');
    var unit = document.getElementById('eg-ayuda-unidad');
    var stepOut = document.getElementById('eg-ayuda-paso');
    var piece = (window.EgAyudaPiezas || {})[config.piece];
    if (!frame || !piece) {
        return;
    }
    var skipping = false;
    // The button «Try it» is waiting on, while it waits: «Skip to the end» taps it.
    var waiting = null;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var stored = function(key, fallback) {
        try {
            var value = window.localStorage.getItem(key);
            return value === null ? fallback : value;
        } catch (e) {
            return fallback;
        }
    };
    var store = function(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            // Without storage, it holds for this page.
        }
    };
    var sleep = function(ms) {
        return new Promise(function(resolve) {
            setTimeout(resolve, skipping ? 0 : ms);
        });
    };

    // The voice of each step, when the art package brings it; the caption stays, and «Voice» turns it off.
    var voices = config.voices || [];
    var muted = stored('eg-ayuda-voz', 'on') === 'off';
    var playing = null;
    var hush = function() {
        if (playing) {
            playing.pause();
            playing.dispatchEvent(new Event('ended'));
        }
    };
    // A voice still speaking when the person taps: it fades in 150 ms before the next step, never cut or overlapped.
    var fadeVoice = function() {
        var audio = playing;
        if (!audio) {
            return Promise.resolve();
        }
        var from = audio.volume;
        var step = 0;
        return new Promise(function(resolve) {
            var timer = setInterval(function() {
                step++;
                audio.volume = Math.max(0, from * (1 - step / 6));
                if (step >= 6) {
                    clearInterval(timer);
                    audio.pause();
                    audio.dispatchEvent(new Event('ended'));
                    resolve();
                }
            }, 25);
        });
    };
    var speak = function(index) {
        var url = voices[index];
        if (!url || muted || skipping) {
            return null;
        }
        return new Promise(function(resolve) {
            var audio = new Audio(url);
            audio.volume = 0.9;
            playing = audio;
            audio.addEventListener('ended', function() {
                if (playing === audio) {
                    playing = null;
                }
                resolve(true);
            }, {once: true});
            audio.addEventListener('error', function() {
                resolve(false);
            }, {once: true});
            audio.play().catch(function() {
                resolve(false);
            });
        });
    };
    // A music under it, low, looping while the help plays and fading out at its end.
    var music = config.music ? new Audio(config.music) : null;
    var musicOn = stored('eg-ayuda-musica', 'on') !== 'off';
    if (music) {
        music.loop = true;
        music.volume = 0.22;
    }
    var playMusic = function() {
        if (music && musicOn) {
            music.volume = 0.22;
            music.play().catch(function() {
                return null;
            });
        }
    };
    var fadeMusic = function() {
        if (!music || music.paused) {
            return;
        }
        var from = music.volume;
        var steps = 20;
        var step = 0;
        var timer = setInterval(function() {
            step++;
            music.volume = Math.max(0, from * (1 - step / steps));
            if (step >= steps) {
                clearInterval(timer);
                music.pause();
            }
        }, 100);
    };

    var doc = function() {
        return frame.contentDocument;
    };
    var find = function(selector) {
        var d = doc();
        if (!d) {
            return null;
        }
        if (typeof selector === 'function') {
            return selector(d);
        }
        // Inside a closed part, past its summary: the panel may still lay it out, but it is not seen.
        var folded = function(element) {
            var fold = element.closest('details:not([open])');
            return fold && !element.closest('summary') ? fold : null;
        };
        // The first one that is seen: a list may have hidden rows.
        var all = d.querySelectorAll(selector);
        for (var i = 0; i < all.length; i++) {
            if (all[i].getClientRects().length && !folded(all[i])) {
                return all[i];
            }
        }
        // One in a folded part is unfolded, as a person would, so that what is pointed at is seen.
        for (var j = 0; j < all.length; j++) {
            var fold = folded(all[j]);
            if (!fold) {
                continue;
            }
            while (fold) {
                fold.open = true;
                fold = fold.parentElement ? fold.parentElement.closest('details:not([open])') : null;
            }
            if (all[j].getClientRects().length) {
                return all[j];
            }
        }
        return null;
    };
    // The next page in the frame, or an error if none comes (a button that did not go anywhere).
    var loadOf = function() {
        return new Promise(function(resolve, reject) {
            var timer = setTimeout(function() {
                reject(new Error('no page after the tap'));
            }, 20000);
            frame.addEventListener('load', function() {
                clearTimeout(timer);
                resolve();
            }, {once: true});
        });
    };
    var wait = function(selector, timeout) {
        var until = Date.now() + (timeout || 15000);
        return new Promise(function(resolve, reject) {
            var look = function() {
                var element = find(selector);
                if (element) {
                    resolve(element);
                } else if (Date.now() > until) {
                    reject(new Error('not found: ' + selector));
                } else {
                    setTimeout(look, 100);
                }
            };
            look();
        });
    };
    // Brings what the pointer goes to into the frame's view, without moving the page around the frame: scrollIntoView
    // also scrolls the window, and the stage and its caption would go up and down at every step.
    var bring = function(element) {
        var view = frame.contentWindow;
        if (!view) {
            return;
        }
        var height = view.innerHeight;
        var r = element.getBoundingClientRect();
        // Seen whole already: nothing moves, and nothing at the top is cut.
        if (r.top >= 12 && r.bottom <= height - 12) {
            return;
        }
        // Else the block it belongs to, whole and from its top, when it fits; a long one, with the element a third down.
        var parts = 'section, article, header, form, li, .bloque, .lamina, .fila, .llegada';
        var block = element.closest(parts) || element;
        var b = block.getBoundingClientRect();
        var by = b.height <= height - 24 && b.top <= r.top ? b.top - 12 : r.top - height / 3;
        view.scrollBy(0, by);
        // Nothing left cut at the top: what the top edge crosses goes out whole, or comes in whole if the element stays.
        var top = frame.contentDocument.elementFromPoint(view.innerWidth / 2, 2);
        var cut = top && top.closest(parts);
        if (cut && !cut.contains(element)) {
            var c = cut.getBoundingClientRect();
            if (c.top < -2 && c.bottom > 2) {
                var e = element.getBoundingClientRect();
                view.scrollBy(0, e.bottom - c.top + 12 <= height ? c.top - 12 : c.bottom);
            }
        }
    };
    // The step on the strip: its number of how many, and the thread of how far it has gone.
    var mark = function(index) {
        var all = (config.steps || []).length;
        if (stepOut && all) {
            stepOut.textContent = (index + 1) + ' / ' + all;
            root.style.setProperty('--ayuda-avance', String((index + 1) / all));
        }
    };
    // Where an element of the frame is, in the stage.
    var place = function(element) {
        var f = frame.getBoundingClientRect();
        var s = stage.getBoundingClientRect();
        var r = element.getBoundingClientRect();
        // Never on its words: at its left, at its middle; with no room there, under it (or over it at the bottom).
        var left = r.left >= 26;
        var below = r.bottom + 14 <= frame.clientHeight - 8;
        return {x: f.left - s.left + (left ? r.left - 14 : r.left + Math.min(r.width / 2, 48)),
            y: f.top - s.top + (left ? r.top + Math.min(r.height / 2, 18) : (below ? r.bottom + 14 : r.top - 14)),
            left: f.left - s.left + r.left, top: f.top - s.top + r.top, width: r.width, height: r.height};
    };
    var tapAt = function() {
        pointer.classList.add('ayuda-dedo-toca');
        return sleep(250).then(function() {
            pointer.classList.remove('ayuda-dedo-toca');
        });
    };

    var h = {
        say: function(index) {
            var text = (config.steps || [])[index] || '';
            caption.textContent = text;
            mark(index);
            // Time to read it: about a word every quarter of a second, never less than two seconds. With a voice, until
            // it has finished, and a breath.
            var reading = sleep(Math.max(2200, text.split(/\s+/).length * 270));
            var voice = speak(index);
            return voice ? voice.then(function(spoke) {
                return spoke ? sleep(350) : reading;
            }) : reading;
        },
        point: function(selector) {
            return wait(selector).then(function(element) {
                // In the middle, not at an edge: a sticky header would cover it there.
                bring(element);
                return sleep(reduced ? 50 : 200).then(function() {
                    var at = place(element);
                    pointer.style.transform = 'translate(' + Math.round(at.x) + 'px, ' + Math.round(at.y) + 'px)';
                    pointer.classList.add('ayuda-dedo-on');
                    return sleep(reduced ? 100 : 700);
                }).then(function() {
                    return element;
                });
            });
        },
        // Points and says at once: the caption of a step over what it is about.
        show: function(selector, index) {
            return h.point(selector).then(function() {
                return h.say(index);
            });
        },
        tap: function(selector) {
            return h.point(selector).then(function(element) {
                return tapAt().then(function() {
                    element.click();
                    return sleep(500);
                });
            });
        },
        // Writes into a field, a character at a time, after what is already there.
        type: function(selector, text, keep) {
            return h.point(selector).then(function(field) {
                field.focus();
                if (!keep) {
                    field.value = '';
                }
                var i = 0;
                return new Promise(function(resolve) {
                    var next = function() {
                        if (i < text.length) {
                            field.value += text[i++];
                            field.dispatchEvent(new Event('input', {bubbles: true}));
                            setTimeout(next, skipping ? 0 : 38);
                        } else {
                            resolve(sleep(400));
                        }
                    };
                    next();
                });
            });
        },
        // Chooses an option of a list.
        choose: function(selector, value) {
            return h.point(selector).then(function(list) {
                list.value = value;
                list.dispatchEvent(new Event('change', {bubbles: true}));
                return sleep(400);
            });
        },
        // Taps something that loads another page in the frame, and waits for it.
        go: function(selector) {
            return h.point(selector).then(function(element) {
                var loaded = loadOf();
                return tapAt().then(function() {
                    element.click();
                    return loaded;
                }).then(function() {
                    // The new page has nothing under the old spot: the pointer waits out of sight.
                    pointer.classList.remove('ayuda-dedo-on');
                    return sleep(400);
                });
            });
        },
        wait: wait,
        // Whether something is on the screen.
        has: function(selector) {
            return !!find(selector);
        },
        // «Try it»: a ring round the button, and nothing moves until it is tapped. A tap that loads a page is waited for.
        you: function(index, selector, loads) {
            caption.textContent = (config.now || '') + ' ' + ((config.steps || [])[index] || '');
            mark(index);
            root.classList.add('ayuda-ahora');
            var said = speak(index) || Promise.resolve(false);
            return wait(selector).then(function(element) {
                bring(element);
                return sleep(200).then(function() {
                    var at = place(element);
                    ring.style.left = (at.left - 8) + 'px';
                    ring.style.top = (at.top - 8) + 'px';
                    ring.style.width = (at.width + 16) + 'px';
                    ring.style.height = (at.height + 16) + 'px';
                    ring.hidden = false;
                    pointer.classList.remove('ayuda-dedo-on');
                    return new Promise(function(resolve) {
                        var loaded = loads ? loadOf() : null;
                        element.addEventListener('click', function() {
                            waiting = null;
                            ring.hidden = true;
                            root.classList.remove('ayuda-ahora');
                            // What the tap did is seen for a moment before the next step speaks.
                            var faded = fadeVoice();
                            resolve(Promise.all([faded, loaded || null]).then(function() {
                                return sleep(800);
                            }));
                        }, {once: true});
                        waiting = element;
                        try {
                            element.focus({preventScroll: true});
                        } catch (e) {
                            // A button that cannot take the focus is still tapped by the mouse.
                        }
                        if (skipping) {
                            element.click();
                        } else if (config.auto) {
                            // As someone who listened: when the voice has said what to tap, and a breath.
                            said.then(function() {
                                return sleep(400);
                            }).then(function() {
                                if (waiting === element) {
                                    element.click();
                                }
                            });
                        }
                    });
                });
            });
        },
        text: function(key) {
            return (config.texts || {})[key] || '';
        }
    };

    // The first screen loads with the page and waits still behind «Start», so that what the help is about is seen from
    // the first moment; «Start» runs on it. After a stop or at the end, the page comes back whole.
    var loaded = new Promise(function(resolve) {
        frame.addEventListener('load', function() {
            resolve();
        }, {once: true});
    });
    frame.src = config.frame;
    var load = function() {
        return loaded;
    };

    var run = function() {
        // After a stop, the practice centre is not as the help expects: the page starts again from the beginning.
        if (document.body.getAttribute('data-ayuda') === 'failed') {
            try {
                window.sessionStorage.setItem('eg-ayuda-ir', '1');
            } catch (e) {
                // Without storage, the page comes back and waits for «Start».
            }
            window.location.reload();
            return null;
        }
        start.hidden = true;
        done.hidden = true;
        stage.classList.remove('eg-esperando');
        // The stage and its strip, whole on the screen, while the help plays.
        if (unit) {
            window.scrollTo(0, Math.max(0, unit.getBoundingClientRect().top + window.pageYOffset - 12));
        }
        skipping = false;
        skipButton.hidden = false;
        playMusic();
        return load().then(function() {
            return piece(h);
        }).then(function() {
            caption.textContent = '';
            pointer.classList.remove('ayuda-dedo-on');
            done.hidden = false;
            skipButton.hidden = true;
            fadeMusic();
            done.querySelector('button').focus();
            document.body.setAttribute('data-ayuda', 'done');
            return null;
        }).catch(function(error) {
            // In words, with the way to start again; what failed, for whoever looks into it.
            window.console.warn('eguahe help:', error.message);
            caption.textContent = config.stuck || error.message;
            ring.hidden = true;
            root.classList.remove('ayuda-ahora');
            pointer.classList.remove('ayuda-dedo-on');
            start.hidden = false;
            fadeMusic();
            document.body.setAttribute('data-ayuda', 'failed');
        });
    };

    document.getElementById('eg-ayuda-ir').addEventListener('click', run);
    try {
        if (window.sessionStorage.getItem('eg-ayuda-ir')) {
            window.sessionStorage.removeItem('eg-ayuda-ir');
            run();
        }
    } catch (e) {
        // Nothing stored: the help waits for «Start».
    }
    document.getElementById('eg-ayuda-otra').addEventListener('click', function() {
        window.location.reload();
    });
    skipButton.hidden = true;
    skipButton.addEventListener('click', function() {
        skipping = true;
        hush();
        if (waiting) {
            waiting.click();
        }
    });
    var voiceButton = document.getElementById('eg-ayuda-voz');
    if (voiceButton) {
        voiceButton.setAttribute('aria-pressed', muted ? 'false' : 'true');
        voiceButton.addEventListener('click', function() {
            muted = !muted;
            voiceButton.setAttribute('aria-pressed', muted ? 'false' : 'true');
            if (muted) {
                hush();
            }
            store('eg-ayuda-voz', muted ? 'off' : 'on');
        });
    }
    var musicButton = document.getElementById('eg-ayuda-musica');
    if (musicButton) {
        musicButton.setAttribute('aria-pressed', musicOn ? 'true' : 'false');
        musicButton.addEventListener('click', function() {
            musicOn = !musicOn;
            musicButton.setAttribute('aria-pressed', musicOn ? 'true' : 'false');
            if (musicOn && document.body.getAttribute('data-ayuda') !== 'done') {
                playMusic();
            } else if (music) {
                music.pause();
            }
            store('eg-ayuda-musica', musicOn ? 'on' : 'off');
        });
    }
    if (config.auto) {
        run();
    }
})();
