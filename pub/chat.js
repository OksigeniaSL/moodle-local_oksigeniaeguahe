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
 * The family beside a conversation: the chat (a dashboard app of Chatwoot) tells the page who writes, only from the
 * chats the site lets in, and the page asks Eguahe who that is. The email and the phone go in the body of a POST, never
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
    var ask = function(contact) {
        var email = (contact && contact.email) || '';
        var phone = (contact && contact.phone_number) || '';
        var key = email + '|' + phone;
        if (key === last) {
            return;
        }
        last = key;
        if (!email && !phone) {
            say(root.getAttribute('data-none'));
            return;
        }
        out.setAttribute('aria-busy', 'true');
        say(root.getAttribute('data-loading'));
        var body = new FormData();
        body.append('action', 'find');
        body.append('sesskey', root.getAttribute('data-sesskey'));
        body.append('email', email);
        body.append('phone', phone);
        fetch(root.getAttribute('data-url'), {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function(r) {
                return r.json();
            })
            .then(function(j) {
                out.removeAttribute('aria-busy');
                if (j.error === 'login') {
                    window.location.reload();
                    return;
                }
                out.innerHTML = j.html || '';
            })
            .catch(function() {
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
        ask(d.contact || (d.conversation && d.conversation.meta && d.conversation.meta.sender) || null);
    });
    if (window.parent !== window) {
        // The request carries nothing: only the chat answers it, and the answer is checked by its origin above.
        window.parent.postMessage('chatwoot-dashboard-app:fetch-info', '*');
    }
})();
