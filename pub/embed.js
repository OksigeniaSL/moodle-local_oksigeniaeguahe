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
 * For the web of a centre, on every page of it:
 * - where the visit first came from (the utm of its link, or the site it came from: a search engine, an AI assistant),
 *   kept in this browser for 90 days and passed to the links and the forms of Oksigenia Eguahe on the page, so that a
 *   family that came from an AI assistant and then pressed «Pedir plaza» is counted as such (eg_src, eg_med, eg_cmp,
 *   eg_ref; classes/local/arrival.php);
 * - a form of Eguahe inside the page is as tall as it is, without a scroll bar of its own, and after sending, the page
 *   goes to its top.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var KEY = 'eguahe-llegada';
    var DAYS = 90;
    var PAGES = /\/local\/oksigeniaeguahe\/(j|w|invita)\.php/;

    // The site an address names, without www.
    var host = function(url) {
        try {
            return new URL(url).hostname.replace(/^www\./, '').toLowerCase();
        } catch (e) {
            return '';
        }
    };

    // Where this visit came from, the first time: kept, and not written over until it is old.
    var first = function() {
        var kept = null;
        try {
            kept = JSON.parse(window.localStorage.getItem(KEY) || 'null');
        } catch (e) {
            kept = null;
        }
        if (kept && kept.t && Date.now() - kept.t < DAYS * 86400000) {
            return kept;
        }
        var query = new URLSearchParams(window.location.search);
        var ref = document.referrer ? host(document.referrer) : '';
        if (ref === host(window.location.href)) {
            ref = '';
        }
        var now = {
            src: (query.get('utm_source') || '').slice(0, 100),
            med: (query.get('utm_medium') || '').slice(0, 30),
            cmp: (query.get('utm_campaign') || '').slice(0, 255),
            ref: ref.slice(0, 100),
            t: Date.now()
        };
        if (!now.src && !now.ref) {
            return null;
        }
        try {
            window.localStorage.setItem(KEY, JSON.stringify(now));
        } catch (e) {
            // Without storage, only this page passes it on.
        }
        return now;
    };

    // An address of Eguahe with where the visit came from, unless it already says it.
    var carry = function(url, from) {
        var u;
        try {
            u = new URL(url, window.location.href);
        } catch (e) {
            return url;
        }
        if (!PAGES.test(u.pathname) || u.searchParams.has('eg_src') || u.searchParams.has('eg_ref')) {
            return url;
        }
        [['eg_src', from.src], ['eg_med', from.med], ['eg_cmp', from.cmp], ['eg_ref', from.ref]].forEach(function(p) {
            if (p[1]) {
                u.searchParams.set(p[0], p[1]);
            }
        });
        return u.toString();
    };

    var from = first();
    if (from) {
        var mark = function() {
            var links = document.querySelectorAll('a[href*="/local/oksigeniaeguahe/"]');
            for (var i = 0; i < links.length; i++) {
                links[i].href = carry(links[i].href, from);
            }
            var frames = document.querySelectorAll('iframe[src*="/local/oksigeniaeguahe/w.php"]');
            for (var j = 0; j < frames.length; j++) {
                var src = carry(frames[j].src, from);
                if (src !== frames[j].src) {
                    frames[j].src = src;
                }
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', mark);
        } else {
            mark();
        }
    }

    window.addEventListener('message', function(event) {
        var data = event.data;
        if (!data || (data.eguahe !== 'height' && data.eguahe !== 'top')) {
            return;
        }
        var frames = document.querySelectorAll('iframe[src*="/local/oksigeniaeguahe/w.php"]');
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow !== event.source) {
                continue;
            }
            if (data.eguahe === 'height') {
                frames[i].style.height = Math.max(300, Math.min(20000, Number(data.height) || 0)) + 'px';
                frames[i].style.minHeight = '0';
            } else if (frames[i].getBoundingClientRect().top < 0) {
                frames[i].scrollIntoView({block: 'start'});
            }
        }
    });
})();
