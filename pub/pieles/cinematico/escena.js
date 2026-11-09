/* Eguahe, the skin «cinemático» (C): what moves, never what works. Without it the page is the same and still: the
 * river is drawn by the server (each lantern with its --x and its --fila), the list below has every family.
 * 1. The headline: the number in amber, and the words coming up one after another, once a session.
 * 2. A family that arrives while the page is open (panel-latido.js announces it with the event «eguahe:llega»): its
 *    lantern comes in from «Llegan» and drifts to its place.
 * 3. The river, measured: the server's lanes take a lantern as a share of the river, and many families of the same
 *    day do not fit. Here each card goes to the lane where it fits, moved at most half a card from its time; if it
 *    does not, it goes short (without «Llegó…»); if not even so, it stays only in the list below.
 * 4. The names of the constellation, measured: each one on the first side of its light where it fits, without
 *    touching another name or light, nor leaving the sky; the centre first, then the largest. One that fits nowhere is
 *    not written (the table under the sky has it).
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

    // 3. The river, measured (only when the lanterns float: on the phone they are a list).
    function rio(lista) {
        var lis = Array.prototype.slice.call(lista.querySelectorAll('.farolillo'));
        if (!lis.length || window.getComputedStyle(lis[0]).position !== 'absolute') {
            return;
        }
        lis.forEach(function (li) {
            li.classList.remove('compacto', 'fuera-del-rio');
            li.style.left = '';
        });
        var ancho = lista.clientWidth;
        var hueco = 12;
        var filas = [[], [], []];
        var orden = lis.map(function (li) {
            return {li: li, x: parseFloat(li.style.getPropertyValue('--x')) || 0};
        }).sort(function (a, b) { return b.x - a.x; });   // the longest waiting first: they are the news
        orden.forEach(function (f) {
            var sitio = null;
            [false, true].some(function (corto) {
                if (corto) {
                    f.li.classList.add('compacto');
                }
                var w = f.li.offsetWidth;
                var natural = f.x * (ancho - w);
                var mejor = null;
                filas.forEach(function (fila, i) {
                    // The nearest left to its time where it does not touch the others of the lane.
                    var candidatos = [natural];
                    fila.forEach(function (o) {
                        candidatos.push(o.izq - w - hueco, o.izq + o.w + hueco);
                    });
                    candidatos.forEach(function (izq) {
                        izq = Math.max(0, Math.min(ancho - w, izq));
                        if (Math.abs(izq - natural) > w / 2) {
                            return;
                        }
                        var choca = fila.some(function (o) {
                            return izq < o.izq + o.w + hueco && o.izq < izq + w + hueco;
                        });
                        if (!choca && (!mejor || Math.abs(izq - natural) < Math.abs(mejor.izq - natural))) {
                            mejor = {fila: i, izq: izq, w: w};
                        }
                    });
                });
                sitio = mejor;
                return !!mejor;
            });
            if (!sitio) {
                f.li.classList.remove('compacto');
                f.li.classList.add('fuera-del-rio');
                return;
            }
            filas[sitio.fila].push(sitio);
            f.li.style.setProperty('--fila', String(sitio.fila));
            f.li.style.left = Math.round(sitio.izq) + 'px';
        });
    }

    // 4. The names of the constellation, measured.
    function constelacion(ol) {
        var caja = ol.getBoundingClientRect();
        if (!caja.width) {
            return;
        }
        var items = Array.prototype.slice.call(ol.querySelectorAll('.estrella-centro, .estrella:not(.sin-sitio)'));
        items.forEach(function (li) {
            var n = li.querySelector('.estrella-nombre');
            if (n) {
                n.classList.remove('sin-nombre');
                n.removeAttribute('style');
            }
        });
        var luces = items.map(function (li) {
            var r = li.querySelector('.estrella-luz').getBoundingClientRect();
            return {l: r.left - 3, t: r.top - 3, r: r.right + 3, b: r.bottom + 3};
        });
        var puestas = [];
        var orden = items.map(function (li, i) {
            var t = parseFloat(li.style.getPropertyValue('--t')) || 0;
            return {li: li, luz: luces[i], peso: li.classList.contains('estrella-centro') ? 2 : t};
        }).sort(function (a, b) { return b.peso - a.peso; });
        var margen = 4;
        orden.forEach(function (o) {
            var nombre = o.li.querySelector('.estrella-nombre');
            if (!nombre) {
                return;
            }
            var w = nombre.offsetWidth;
            var h = nombre.offsetHeight;
            var cx = (o.luz.l + o.luz.r) / 2;
            var cy = (o.luz.t + o.luz.b) / 2;
            var r = (o.luz.r - o.luz.l) / 2;
            var d = r * 0.72;
            var lados = [
                [cx + r + 4, cy - h / 2], [cx - r - 4 - w, cy - h / 2],
                [cx - w / 2, cy + r + 2], [cx - w / 2, cy - r - 2 - h],
                [cx + d + 2, cy - d - h], [cx + d + 2, cy + d],
                [cx - d - 2 - w, cy - d - h], [cx - d - 2 - w, cy + d]
            ];
            if (o.li.classList.contains('estrella-centro')) {
                lados.unshift(lados.splice(2, 1)[0]);   // the centre: its name under it
            }
            var sitio = null;
            lados.some(function (p) {
                var rc = {l: p[0], t: p[1], r: p[0] + w, b: p[1] + h};
                if (rc.l < caja.left + margen || rc.r > caja.right - margen || rc.t < caja.top + margen ||
                        rc.b > caja.bottom - margen) {
                    return false;
                }
                var toca = function (q) {
                    return rc.l < q.r && q.l < rc.r && rc.t < q.b && q.t < rc.b;
                };
                if (puestas.some(toca) || luces.some(toca)) {
                    return false;
                }
                sitio = rc;
                return true;
            });
            if (!sitio) {
                nombre.classList.add('sin-nombre');
                return;
            }
            puestas.push({l: sitio.l - 2, t: sitio.t - 1, r: sitio.r + 2, b: sitio.b + 1});
            // The name hangs from the point of its light (the li is that point, with no size).
            var punto = o.li.getBoundingClientRect();
            nombre.style.left = Math.round(sitio.l - punto.left) + 'px';
            nombre.style.top = Math.round(sitio.t - punto.top) + 'px';
            nombre.style.right = 'auto';
            nombre.style.transform = 'none';
        });
    }

    function colocar() {
        Array.prototype.forEach.call(doc.querySelectorAll('.cine-farolillos'), rio);
        Array.prototype.forEach.call(doc.querySelectorAll('.cine-constelacion ol'), constelacion);
    }
    var espera = null;
    window.addEventListener('resize', function () {
        window.clearTimeout(espera);
        espera = window.setTimeout(colocar, 150);
    });
    if (doc.fonts && doc.fonts.ready) {
        doc.fonts.ready.then(colocar);
    } else {
        colocar();
    }
    doc.addEventListener('eguahe:llega', function () {
        window.setTimeout(colocar, 0);
    });

    // 5. A lantern that arrives: from the left edge of the river to its place.
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
