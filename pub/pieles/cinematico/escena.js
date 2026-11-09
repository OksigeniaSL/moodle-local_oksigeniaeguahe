/* Eguahe, the skin «cinemático» (C): what moves, never what works. Without it the page is the same and still: the
 * river is drawn by the server (each lantern with its --x and its --fila), the list below has every family.
 * 1. The headline: the number in amber, and the words coming up one after another, once a session.
 * 2. A family that arrives while the page is open (panel-latido.js announces it with the event «eguahe:llega»): its
 *    lantern comes in from «Llegan» and drifts to its place.
 * Chrome 93 and Firefox 98; only transform and opacity; still with «less motion». Jujol, 9 November 2026. */
(function () {
    'use strict';
    var doc = document;
    var body = doc.body;
    if (!body || !body.classList.contains('piel-cinematico')) {
        return;
    }
    var quieto = (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) ||
        doc.documentElement.getAttribute('data-mov') === 'menos';

    // 1. The headline of the scene.
    var titular = doc.querySelector('.cine-cabeza .titular');
    if (titular && !titular.querySelector('.cine-palabra')) {
        var texto = titular.textContent.trim();
        var visto = false;
        try {
            visto = sessionStorage.getItem('eguahe-cine-titular') === texto;
            sessionStorage.setItem('eguahe-cine-titular', texto);
        } catch (e) {
            visto = true;
        }
        titular.textContent = '';
        texto.split(/\s+/).forEach(function (palabra, i) {
            var caja = doc.createElement('span');
            caja.className = 'cine-palabra';
            var dentro = doc.createElement('span');
            dentro.textContent = palabra;
            if (i === 0 && /^\d+$/.test(palabra)) {
                dentro.className = 'cine-num';
            }
            dentro.style.setProperty('--i', String(i));
            caja.appendChild(dentro);
            titular.appendChild(caja);
            titular.appendChild(doc.createTextNode(' '));
        });
        if (visto || quieto) {
            titular.classList.add('cine-listo');
        } else {
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    titular.classList.add('cine-listo');
                });
            });
        }
    }

    // 2. The cut of cinema: when a family opens in the sheet beside the inbox, two black bands come in and go.
    var bandas = null;
    doc.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('[data-abre-hoja]');
        if (!a || quieto || window.innerWidth < 1000) {
            return;
        }
        if (!bandas) {
            bandas = doc.createElement('div');
            bandas.className = 'cine-bandas';
            bandas.setAttribute('aria-hidden', 'true');
            bandas.appendChild(doc.createElement('i'));
            bandas.appendChild(doc.createElement('i'));
            body.appendChild(bandas);
            bandas.getBoundingClientRect();
        }
        bandas.classList.add('cortando');
        window.setTimeout(function () {
            bandas.classList.remove('cortando');
        }, 650);
    }, true);   // on the way down: the panel's own handler opens the sheet and stops the click

    // 3. A lantern that arrives: from the left edge of the river to its place.
    doc.addEventListener('eguahe:llega', function (ev) {
        var li = ev.detail && ev.detail.farolillo;
        if (!li || quieto || !li.animate) {
            return;
        }
        var rio = li.closest('.cine-farolillos');
        var dx = rio ? -(li.getBoundingClientRect().left - rio.getBoundingClientRect().left) - 40 : -200;
        li.animate([
            {transform: 'translateX(' + dx + 'px)', opacity: 0},
            {transform: 'translateX(' + (dx * 0.6) + 'px)', opacity: 1, offset: 0.25},
            {transform: 'none', opacity: 1}
        ], {duration: 1600, easing: 'cubic-bezier(.2,.7,.1,1)'});
    });
}());
