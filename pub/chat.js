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
 * The family beside a conversation: the chat (a dashboard app of Chatwoot, or the conversations of Marandu in the site
 * itself) tells the page who writes, only from the chats the site lets in and the site, and the page asks Eguahe who
 * that is. The email and the phone go in the body of a POST, never
 * in an address.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var root = document.querySelector('[data-chat]');
    var out = root && root.querySelector('[data-chat-out]');
    if (!out) {
        return;
    }
    var origins = [];
    try {
        origins = JSON.parse(root.getAttribute('data-origins') || '[]');
    } catch (e) {
        origins = [];
    }
    var last = null;
    var say = function(text) {
        out.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'chat-aviso';
        p.textContent = text;
        out.appendChild(p);
    };
    // The way a conversation came, as the chat says it (Chatwoot: «Channel::WebWidget», «Channel::Whatsapp»…).
    var way = function(d) {
        var c = String((d.conversation && d.conversation.meta && d.conversation.meta.channel) || d.channel || '').toLowerCase();
        if (c.indexOf('webwidget') !== -1 || c === 'web') {
            return 'web';
        }
        var known = ['whatsapp', 'telegram', 'email'].filter(function(w) {
            return c.indexOf(w) !== -1;
        });
        return known.length ? known[0] : '';
    };
    var ask = function(contact, channel) {
        var email = (contact && contact.email) || '';
        var phone = (contact && contact.phone_number) || '';
        var name = (contact && contact.name) || '';
        var key = email + '|' + phone + '|' + name + '|' + channel;
        if (key === last) {
            return;
        }
        last = key;
        if (!email && !phone) {
            say(root.getAttribute('data-none'));
            return;
        }
        out.setAttribute('aria-busy', 'true');
        // While it looks: the shape of a card in grey, not a sentence.
        var shape = root.querySelector('[data-chat-cargando]');
        if (shape && shape.content) {
            out.innerHTML = '';
            out.appendChild(shape.content.cloneNode(true));
        } else {
            say(root.getAttribute('data-loading'));
        }
        var body = new FormData();
        body.append('action', 'find');
        body.append('sesskey', root.getAttribute('data-sesskey'));
        body.append('email', email);
        body.append('phone', phone);
        body.append('name', name);
        body.append('channel', channel);
        fetch(root.getAttribute('data-url'), {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function(r) {
                return r.json();
            })
            .then(function(j) {
                if (key !== last) {
                    // Another conversation was opened meanwhile: this answer is of the one before.
                    return;
                }
                out.removeAttribute('aria-busy');
                if (j.error === 'login') {
                    window.location.reload();
                    return;
                }
                out.innerHTML = j.html || '';
            })
            .catch(function() {
                if (key !== last) {
                    return;
                }
                out.removeAttribute('aria-busy');
                last = null;
                say(root.getAttribute('data-failed'));
            });
    };
    window.addEventListener('message', function(e) {
        if (origins.indexOf(e.origin) === -1 || typeof e.data !== 'string') {
            return;
        }
        var msg;
        try {
            msg = JSON.parse(e.data);
        } catch (x) {
            return;
        }
        if (!msg || msg.event !== 'appContext' || !msg.data) {
            return;
        }
        var d = msg.data;
        if (d.theme === 'dark' || d.theme === 'light') {
            document.documentElement.setAttribute('data-bs-theme', d.theme);
        }
        ask(d.contact || (d.conversation && d.conversation.meta && d.conversation.meta.sender) || null, way(d));
    });
    if (window.parent !== window) {
        // The request carries nothing: only the chat answers it, and the answer is checked by its origin above.
        window.parent.postMessage('chatwoot-dashboard-app:fetch-info', '*');
    }
})();
