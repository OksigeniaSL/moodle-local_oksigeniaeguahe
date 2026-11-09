// This file is part of Moodle - https://moodle.org/ (GNU GPL v3 or later).

/**
 * What a family does with «Tu primer día», counted by Eguahe and never scored: that it opened the book, once in sight
 * for a moment; how far it got (each page once a visit); the reactions of the book (a heart, hearing it again, the
 * whole story, the drawing enlarged, saving it, sending it); the song switched off; and the story read to the end
 * without the voice. Nothing of anyone, and nothing at all without the address in the book's data.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var box = document.getElementById('libro-datos');
    var book = box ? JSON.parse(box.textContent) : null;
    var where = book && book.measure ? book.measure.url : '';
    if (!where || !window.FormData) {
        return;
    }
    var key = book.measure.sesskey;
    // The reactions of the book, by the names Eguahe keeps.
    var KINDS = {'me-gusta': 'like', 'me-gusta-quitado': 'unlike', 'volver-a-pagina': 'back', 'dibujo-ampliado': 'enlarged',
        'volver-a-escuchar': 'again', 'guardar': 'saved', 'mandar': 'sent', 'escuchado-entero': 'whole', 'repetido': 'repeated'};
    var opened = false;
    var waiting = [];
    var send = function(what, page) {
        if (!opened && what !== 'opened') {
            waiting.push([what, page]);
            return;
        }
        var data = new window.FormData();
        data.append('sesskey', key);
        data.append('kind', 'story');
        data.append('what', what);
        data.append('via', page === null || page === undefined ? '' : 'p' + page);
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(where, data)) {
                return;
            }
        } catch (err) {
            // The fetch below.
        }
        if (window.fetch) {
            window.fetch(where, {method: 'POST', body: data, credentials: 'same-origin', keepalive: true}).catch(function() {
                return;
            });
        }
    };
    var open = function() {
        if (opened || document.visibilityState === 'hidden') {
            return;
        }
        window.setTimeout(function() {
            if (opened || document.visibilityState === 'hidden') {
                return;
            }
            opened = true;
            send('opened', null);
            waiting.splice(0).forEach(function(one) {
                send(one[0], one[1]);
            });
        }, 1000);
    };
    open();
    document.addEventListener('visibilitychange', open);
    var reached = {};
    var listened = false;
    window.addEventListener('eguahe:pagina', function(e) {
        var page = e.detail ? e.detail.pagina : null;
        if (page === null || reached[page]) {
            return;
        }
        reached[page] = true;
        send('page', page);
        // To the end without the voice: read, not heard.
        if (page === 9 && !listened) {
            send('read', null);
        }
    });
    window.addEventListener('eguahe:reaccion', function(e) {
        var d = e.detail || {};
        if (KINDS[d.tipo]) {
            send(KINDS[d.tipo], d.pagina === undefined ? null : d.pagina);
        }
    });
    document.addEventListener('click', function(e) {
        var listen = e.target.closest ? e.target.closest('#oir') : null;
        if (listen) {
            listened = true;
        }
        var music = e.target.closest ? e.target.closest('#musica') : null;
        if (music) {
            window.setTimeout(function() {
                if (music.getAttribute('aria-pressed') === 'false') {
                    send('musicoff', null);
                }
            }, 0);
        }
    });
})();
