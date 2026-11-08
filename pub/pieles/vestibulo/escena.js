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
 * The scene of the skin «vestíbulo» (the living hall), over the paper desk: the hall of the centre in isometric, with
 * the families that wait hanging from the rail, the chalk board that says hello, the clock at the hour of the centre and,
 * on the page of a family, each child in their place with their drawing on the table. The placement is the reference of
 * the art director (Gaudí, revision/colocar.js of the delivery of 8 November 2026), with the letters of the panel; the
 * start, below, reads what the page gives in data-escena and draws it in the frame of the screen. Without JavaScript,
 * the page is the paper desk as it is.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
'use strict';
// Reference placement for skin A, isometric (the hall, its door, what hangs from each knob by age, the cards with the
// names, the bottles, the chalk board that says hello, the clock, the drawings on the table and the cloakroom of places).
// It shows how datos/vestibulo.json and datos/ropero.json are meant to be used. No dependencies; Chrome 93 and Firefox 98;
// only CSSOM (element.style), no inline scripts, so it runs under a 'self' CSP. The skin's escena.js can copy from here.
//
// Input data, as Eguahe gives it. A child: { tag, course, age, sibling_same_course, colour?, drawing? }
//   tag: the house name written on the labels; age: the minimum age of the course (it picks the object); course: the
//   course or stage, to put same-course siblings together; colour: one of the eight names (otherwise one from the tag).
// A family in the inbox: { children: [child…], day? }   (families in queue order: the one waiting longest first)
(function (root) {
  var SVGNS = 'http://www.w3.org/2000/svg';
  var HEX = { malva: '#7A6679', coral: '#C9614E', pizarra: '#566F86', mostaza: '#C99A3C', berenjena: '#5A3B57',
              salvia: '#7E8F6C', petroleo: '#2F6268', cielo: '#86A7C2' };
  var TEXTS = { hello: '¡Hola, {names}!', and: ' y ', today: 'Hoy es {day}',
                days: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'] };
  // eight small drawings for the cards of the hooks (a child of three finds their hook by it): sun, moon, star, leaf,
  // fish, boat, cloud, flower; 24 x 24, one stroke
  var SYMBOLS = [
    'M12 7.5a4.5 4.5 0 1 0 .01 0M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4',
    'M15.5 3.5a8.5 8.5 0 1 0 5 13.5a7 7 0 0 1-5-13.5z',
    'M12 3l2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.4l-5.3 3 1.2-6-4.5-4.1 6-.7z',
    'M5 19c0-8 5-13 14-14c0 9-5 14-14 14zM5 19l7-7',
    'M3 12c3-5 9-6 13-1l4-3v8l-4-3c-4 5-10 4-13-1zM8 11.2v.2',
    'M4 15h16l-3 4H7zM12 3v12M12 4l6 8h-6M12 6l-5 6h5',
    'M7 18a4 4 0 0 1-.5-8a5.5 5.5 0 0 1 10.5-1.5a4.5 4.5 0 0 1 .5 9.5z',
    'M12 9.5a2.5 2.5 0 1 0 .01 0M12 9.5c-1-4 2-6 3-4s-1 4-3 4M14.5 12c4-1 6 2 4 3s-4-1-4-3M12 14.5c1 4-2 6-3 4s1-4 3-4M9.5 12c-4 1-6-2-4-3s4 1 4 3'];

  function el(tag, cls, css) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (css) { e.style.cssText = css; }
    return e;
  }
  function img(src, x, y, w, h, extra) {
    var i = el('img', '', 'position:absolute;left:' + x.toFixed(2) + 'px;top:' + y.toFixed(2) + 'px;width:' + w + 'px;height:' + h + 'px;' + (extra || ''));
    i.src = src;
    i.alt = '';
    i.draggable = false;
    return i;
  }
  function fill(pattern, map) { return pattern.replace(/\{(\w+)\}/g, function (m, k) { return map[k] != null ? map[k] : m; }); }
  function projector(P) {
    return function (p) { return [P[0][0] * p[0] + P[0][1] * p[1] + P[0][2] * p[2] + P[0][3], P[1][0] * p[0] + P[1][1] * p[1] + P[1][2] * p[2] + P[1][3]]; };
  }
  function linear(P) {
    return function (d) { return [P[0][0] * d[0] + P[0][1] * d[1] + P[0][2] * d[2], P[1][0] * d[0] + P[1][1] * d[1] + P[1][2] * d[2]]; };
  }
  function add(a, b) { return [a[0] + b[0], a[1] + b[1], a[2] + b[2]]; }
  // A box of w x h CSS px onto three projected corners (top-left, top-right, bottom-left).
  function planeMatrix(tl, tr, bl, w, h) {
    return 'matrix(' + [(tr[0] - tl[0]) / w, (tr[1] - tl[1]) / w, (bl[0] - tl[0]) / h, (bl[1] - tl[1]) / h, tl[0], tl[1]]
      .map(function (v) { return v.toFixed(4); }).join(',') + ')';
  }
  // The three corners (3D) of a w x h rectangle centred at c, on the wall plane or facing the viewer, turned rot degrees.
  function corners3(c, w, h, plane, rot, u, v) {
    var a = (rot || 0) * Math.PI / 180, ex, ez;
    if (u && v) { ex = u; ez = v; } else if (plane === 'frente') {
      var r = [Math.SQRT1_2, Math.SQRT1_2, 0];
      ex = [Math.cos(a) * r[0], Math.cos(a) * r[1], Math.sin(a)];
      ez = [-Math.sin(a) * r[0], -Math.sin(a) * r[1], Math.cos(a)];
    } else {
      ex = [Math.cos(a), 0, Math.sin(a)];
      ez = [-Math.sin(a), 0, Math.cos(a)];
    }
    function pt(su, sv) { return [c[0] + su * w / 2 * ex[0] + sv * h / 2 * ez[0], c[1] + su * w / 2 * ex[1] + sv * h / 2 * ez[1], c[2] + su * w / 2 * ex[2] + sv * h / 2 * ez[2]]; }
    return [pt(-1, 1), pt(1, 1), pt(-1, -1)];
  }
  // «Leo y Noa», «Bruno e Irene»: in Spanish «y» turns into «e» before an i sound (not before «hie»)
  function joinNames(list, T) {
    if (list.length < 2) { return list[0] || ''; }
    var last = list[list.length - 1], and = T.and;
    if (and === ' y ' && /^(i|hi)(?!e)/i.test(last)) { and = ' e '; }
    // Each name isolated (U+2068…U+2069): one in Arabic keeps the commas and the «y» in their place.
    var iso = function (n) { return '\u2068' + n + '\u2069'; };
    return list.slice(0, -1).map(iso).join(', ') + and + iso(last);
  }
  function hash(s) { var h = 0; for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; } return Math.abs(h); }
  function colourOf(V, child) {
    var cols = V.objetos.colores;
    return child.colour && cols.indexOf(child.colour) >= 0 ? child.colour : cols[hash(child.tag || '') % cols.length];
  }
  function objectFor(age) { return age == null || age < 10 ? 'mochila' : (age < 12 ? 'mayor' : 'equipaje'); }

  // A label drawn in a 3D plane: a w_m x h_m rectangle around c. fontFor(W, H) gives the font size the label uses in its
  // own box of W x H CSS px; the label grows (up to maxGrow times) until that font is at least minPx on screen. make(box,
  // W, H, font, screenFont) fills the box.
  function label(host, proj, c, w, h, plane, rot, minPx, maxGrow, fontFor, make, u, v) {
    var W = 240, H = Math.round(240 * h / w), font = fontFor(W, H), k = 1, cs, screenFont;
    for (var pass = 0; pass < 2; pass++) {
      cs = corners3(c, w * k, h * k, plane, rot, u, v).map(proj);
      screenFont = font * Math.hypot(cs[2][0] - cs[0][0], cs[2][1] - cs[0][1]) / H;
      if (pass === 0 && minPx && screenFont < minPx) { k = Math.min(maxGrow || 1.6, minPx / screenFont); } else { break; }
    }
    var box = el('div', '', 'position:absolute;left:0;top:0;width:' + W + 'px;height:' + H + 'px;transform-origin:0 0;transform:' +
      planeMatrix(cs[0], cs[1], cs[2], W, H) + ';box-sizing:border-box');
    make(box, W, H, font, screenFont);
    host.appendChild(box);
    return { box: box, font: screenFont };
  }
  function fits(n, ratio, pad) { return function (W, H) { return Math.min(H * ratio, (W - pad * H) / (Math.max(3, n) * 0.47 + 0.4)); }; }
  function nameSpan(text, font, colour, extra) {
    var t = el('span', '', 'display:block;font:700 ' + font.toFixed(1) + 'px/1 "Eg Mano",Caveat,cursive;color:' + colour + ';white-space:nowrap;' + (extra || ''));
    t.textContent = text;
    t.dir = 'auto';
    return t;
  }
  function symbol(i, colour, size) {
    var s = document.createElementNS(SVGNS, 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('width', size);
    s.setAttribute('height', size);
    s.style.cssText = 'flex:none;overflow:visible';
    var p = document.createElementNS(SVGNS, 'path');
    p.setAttribute('d', SYMBOLS[i % SYMBOLS.length]);
    p.setAttribute('fill', 'none');
    p.setAttribute('stroke', colour);
    p.setAttribute('stroke-width', '2.2');
    p.setAttribute('stroke-linecap', 'round');
    p.setAttribute('stroke-linejoin', 'round');
    s.appendChild(p);
    return s;
  }

  // ---- the labels of each object ----
  function cardOnWall(host, ctx, c, w, h, child, text) {
    var L = ctx.L, sym = child && child.symbol !== false;
    var col = HEX[colourOf(ctx.V, child || { tag: text })] || L.tinta;
    return label(host, ctx.proj, c, w, h, 'pared', (hash(text) % 5) - 2, ctx.minPx, 1.45, fits(text.length, 0.6, sym ? 0.95 : 0.3), function (box, W, H, font) {
      box.style.background = L.papel;
      box.style.borderRadius = (H * 0.08) + 'px';
      box.style.boxShadow = '0 ' + (H * 0.04) + 'px ' + (H * 0.08) + 'px rgba(43,34,38,.28)';
      box.style.display = 'flex';
      box.style.alignItems = 'center';
      box.style.justifyContent = 'center';
      box.style.gap = (H * 0.06) + 'px';
      box.style.padding = '0 ' + (H * 0.1) + 'px';
      box.appendChild(el('i', '', 'position:absolute;left:50%;top:' + (H * 0.06) + 'px;width:' + (H * 0.1) + 'px;height:' + (H * 0.1) + 'px;margin-left:-' + (H * 0.05) + 'px;border-radius:50%;background:' + L.laton));
      if (sym) { box.appendChild(symbol(hash(child.tag || text), col, H * 0.55)); }
      box.appendChild(nameSpan(text, font, L.tinta));
    });
  }
  function bagLabel(host, ctx, c, name) {
    var L = ctx.L, sp = ctx.V.objetos.mochila.etiqueta, w = sp.ancho_m * 0.86;
    return label(host, ctx.proj, c, w, w * 0.62, 'pared', sp.giro_grados, ctx.minPx * 0.8, 1.35, fits(name.length, 0.42, 0.5), function (box, W, H, font) {
      box.style.background = L.coral;
      box.style.borderRadius = (H * 0.1) + 'px ' + (H * 0.1) + 'px 0 ' + (H * 0.1) + 'px';
      box.style.boxShadow = '0 ' + (H * 0.03) + 'px ' + (H * 0.06) + 'px rgba(43,34,38,.3)';
      var f = el('div', '', 'position:absolute;left:7%;right:7%;top:30%;bottom:9%;background:' + L.papel + ';display:flex;align-items:center;justify-content:center;' +
        'clip-path:polygon(0 0,100% 0,100% 62%,82% 100%,0 100%)');
      f.appendChild(nameSpan(name, font, L.tinta));
      box.appendChild(f);
      box.appendChild(el('i', '', 'position:absolute;right:7%;bottom:9%;width:18%;height:38%;background:linear-gradient(135deg,' + L.coral_oscuro + ' 50%,transparent 50%)'));
    });
  }
  function badge(host, ctx, c, name) {
    var L = ctx.L, sp = ctx.V.objetos.mayor.chapa;
    var cc = [c[0], c[1], c[2] - sp.alto_m / 2 - 0.006];
    return label(host, ctx.proj, cc, sp.ancho_m, sp.alto_m, 'frente', -6, ctx.minPx * 0.8, 1.5, fits(name.length, 0.62, 0.4), function (box, W, H, font) {
      box.style.background = '#FBF8F2';
      box.style.border = (H * 0.07) + 'px solid ' + L.laton;
      box.style.borderRadius = (H / 2) + 'px';
      box.style.display = 'flex';
      box.style.alignItems = 'center';
      box.style.justifyContent = 'center';
      box.style.boxShadow = '0 ' + (H * 0.05) + 'px ' + (H * 0.1) + 'px rgba(43,34,38,.3)';
      box.style.filter = 'brightness(' + ctx.paperK + ')';
      box.appendChild(nameSpan(name, font, '#2B2226'));
    });
  }
  function tagField(host, ctx, c, name) {
    var sp = ctx.V.objetos.equipaje.campo;
    return label(host, ctx.proj, c, sp.ancho_m, sp.alto_m, null, 0, ctx.minPx * 0.8, 1.12, fits(name.length, 0.7, 0.1), function (box, W, H, font) {
      box.style.display = 'flex';
      box.style.alignItems = 'center';
      box.style.justifyContent = 'center';
      box.appendChild(nameSpan(name, font, ctx.L.tinta, 'mix-blend-mode:multiply'));
    }, sp.u, sp.v);
  }
  function bottleName(host, ctx, c, name) {
    var sp = ctx.V.objetos.botella.cinta;
    return label(host, ctx.proj, c, sp.ancho_m * 0.92, sp.alto_m * 0.8, 'frente', 0, 0, 1, fits(name.length, 0.66, 0.1), function (box, W, H, font, screenFont) {
      box.style.display = 'flex';
      box.style.alignItems = 'center';
      box.style.justifyContent = 'center';
      if (screenFont < 8) {   // too small to read: a short stroke of ink, as a name seen from afar
        box.appendChild(el('i', '', 'display:block;width:62%;height:' + (H * 0.16) + 'px;border-radius:' + H + 'px;background:' + ctx.L.tinta + ';opacity:.7'));
      } else {
        box.appendChild(nameSpan(name, font, ctx.L.tinta, 'mix-blend-mode:multiply'));
      }
    });
  }
  function boxLabel(host, ctx, c, w, h, name) {
    return label(host, ctx.proj, c, w, h, 'pared', 0, 0, 1, fits(name.length, 0.62, 0.1), function (box, W, H, font) {
      box.style.display = 'flex';
      box.style.alignItems = 'center';
      box.style.justifyContent = 'center';
      box.appendChild(nameSpan(name, font, ctx.L.tinta, 'mix-blend-mode:multiply'));
    });
  }

  // ---- what hangs from a knob: one or more children's objects, back to front, each with its label ----
  function hang(host, ctx, at, kids, offsets, bright, withLabels) {
    var O = ctx.V.objetos;
    kids.forEach(function (child, j) {
      var o = offsets[j] || [0, 0, 0];
      var p3 = add(at, o);
      var kind = objectFor(child.age);
      var sp = O[kind];
      var px = ctx.proj(p3), an = sp.ancla[ctx.S], sz = sp.tamano[ctx.S];
      host.appendChild(img(fill(sp.archivo, { color: colourOf(ctx.V, child), luz: ctx.light, tamano: ctx.S }),
        px[0] - an[0], px[1] - an[1], sz[0], sz[1], bright));
      if (!withLabels || !child.tag) { return; }
      if (kind === 'mochila') { bagLabel(host, ctx, add(p3, sp.etiqueta.centro_m), child.tag); }
      if (kind === 'mayor') { badge(host, ctx, add(p3, sp.chapa.cuelga_m), child.tag); }
      if (kind === 'equipaje') { tagField(host, ctx, add(p3, sp.campo.centro_m), child.tag); }
    });
  }
  // the card over the knob: the house name of each child (brothers and sisters on the same card: «Leo y Noa»)
  function cards(host, ctx, centre, w, h, kids, texts) {
    var T = ctx.texts || TEXTS, n = texts.length;
    var text = joinNames(texts, T);
    cardOnWall(host, ctx, centre, n === 1 ? w : Math.min(0.5, w + 0.05 * (n - 1)), h, kids[0], text);
  }
  function bottles(host, ctx, base, sep, kids, bright, withNames) {
    var B = ctx.V.objetos.botella, n = kids.length;
    kids.forEach(function (child, i) {
      var p3 = [base[0] + (i - (n - 1) / 2) * sep, base[1], base[2]];
      var px = ctx.proj(p3), an = B.ancla[ctx.S], sz = B.tamano[ctx.S];
      host.appendChild(img(fill(B.archivo, { color: colourOf(ctx.V, child), luz: ctx.light, tamano: ctx.S }), px[0] - an[0], px[1] - an[1], sz[0], sz[1], bright));
      if (withNames && child.tag) { bottleName(host, ctx, add(p3, B.cinta.centro_m), child.tag); }
    });
  }
  // youngest first: drawn at the back; the older ones' smaller things (badge, luggage tag) stay in sight in front
  function front(host, ctx) {
    var F = ctx.V.bandeja.delante;
    if (!F) { return; }
    var r = F.recorte[ctx.S];
    host.appendChild(img(F.archivos[ctx.light][ctx.S], r[0], r[1], r[2], r[3]));
  }
  function byAge(kids) { return kids.slice().sort(function (a, b) { return (a.age || 0) - (b.age || 0); }); }

  // ---- the room: background, door, clock, chalk board ----
  function room(host, V, opts) {
    var S = opts.size || '2400', L = opts.light || 'dia', B = V.bandeja, PR = V.proyeccion[S];
    host.style.position = 'relative';
    host.style.width = PR.imagen[0] + 'px';
    host.style.height = PR.imagen[1] + 'px';
    host.style.overflow = 'hidden';
    var ctx = { V: V, S: S, light: L, L: V.luces[L], proj: projector(PR.P), texts: opts.texts, lin: linear(PR.P), minPx: (opts.minPx || 15) / (opts.screenScale || 1),
                paperK: opts.paperK || ({ dia: 1, tarde: 0.94, noche: 0.8 })[L] };
    host.appendChild(img(B.fondo[L][S], 0, 0, PR.imagen[0], PR.imagen[1]));
    if (opts.door != null) {
      var a = B.puerta.angulos, fi = 0;
      for (var i = 0; i < a.length; i++) { if (Math.abs(a[i] - opts.door) < Math.abs(a[fi] - opts.door)) { fi = i; } }
      var h = B.hueco.recorte[S];
      host.appendChild(img(B.hueco.archivos[L][S], h[0], h[1], h[2], h[3], 'opacity:' + (Math.sin(a[fi] * Math.PI / 180) / Math.sin(80 * Math.PI / 180)).toFixed(3)));
      var r = B.puerta.recorte[S], fw = B.puerta.tamano_fotograma[S];
      host.appendChild(el('div', '', 'position:absolute;left:' + r[0] + 'px;top:' + r[1] + 'px;width:' + fw[0] + 'px;height:' + fw[1] + 'px;' +
        'background:url(' + B.puerta.archivos[L][S] + ') ' + (-fi * fw[0]) + 'px 0/' + (fw[0] * a.length) + 'px ' + fw[1] + 'px no-repeat'));
    }
    if (opts.time != null) { clock(host, ctx, opts.time); }
    return ctx;
  }
  function clock(host, ctx, time) {
    var R = ctx.V.bandeja.reloj, c = R.centro_m, s = document.createElementNS(SVGNS, 'svg');
    var PR = ctx.V.proyeccion[ctx.S];
    s.setAttribute('width', PR.imagen[0]);
    s.setAttribute('height', PR.imagen[1]);
    s.style.cssText = 'position:absolute;left:0;top:0;pointer-events:none';
    var hh = time % 12, mm = (time % 1) * 60, cp = ctx.proj([c[0], c[1] - 0.004, c[2]]), ppm = PR.px_por_metro;
    [[(hh + mm / 60) / 12, R.radio_m * 0.5, 0.018], [mm / 60, R.radio_m * 0.76, 0.011]].forEach(function (hd) {
      var a = hd[0] * 2 * Math.PI, tip = ctx.proj([c[0] + Math.sin(a) * hd[1], c[1] - 0.004, c[2] + Math.cos(a) * hd[1]]);
      var ln = document.createElementNS(SVGNS, 'line');
      ln.setAttribute('x1', cp[0]); ln.setAttribute('y1', cp[1]); ln.setAttribute('x2', tip[0]); ln.setAttribute('y2', tip[1]);
      ln.setAttribute('stroke', ctx.L.tinta); ln.setAttribute('stroke-linecap', 'round');
      ln.setAttribute('stroke-width', (hd[2] * ppm).toFixed(2));
      s.appendChild(ln);
    });
    host.appendChild(s);
  }
  function chalk(host, ctx, text, centre) {
    var B = ctx.V.bandeja.pizarra, S = ctx.S;
    function onBoard(cs, make) {
      var W = 300, H = Math.round(300 * Math.hypot(cs[2][0] - cs[0][0], cs[2][1] - cs[0][1]) / Math.hypot(cs[1][0] - cs[0][0], cs[1][1] - cs[0][1]));
      var b = el('div', '', 'position:absolute;left:0;top:0;width:' + W + 'px;height:' + H + 'px;transform-origin:0 0;transform:' + planeMatrix(cs[0], cs[1], cs[2], W, H) + ';display:flex;align-items:center;justify-content:center;text-align:center');
      make(b, W, H);
      host.appendChild(b);
    }
    var plain = function (l) { return l.replace(/[\u2068\u2069]/g, ''); };
    var size = function (lines, W, H) {
      var longest = lines.reduce(function (m, l) { return Math.max(m, plain(l).length); }, 1);
      return Math.min(H / (lines.length * 1.15), W / (longest * 0.44 + 0.4));
    };
    var line = function (text, f) {
      var t = el('div', '', 'font:700 ' + f.toFixed(1) + 'px/1.08 "Eg Mano",Caveat,cursive;color:' + ctx.L.tiza + ';opacity:.92;' +
        'text-shadow:0 0 ' + (f * 0.05).toFixed(1) + 'px rgba(255,255,255,.35);white-space:nowrap');
      t.textContent = text;
      t.dir = 'auto';
      return t;
    };
    if (text && typeof text === 'object') {
      onBoard(B.saludo[S], function (b, W, H) {
        // «¡Hola,» at the size of «¡Hola, Alba!»; the names never under seven tenths of it, else one per line.
        var f1 = size([text.one], W, H), floor = 0.7 * f1, lines = text.lines;
        var f = size([text.first].concat(lines), W, H);
        if (f < floor) {
          lines = text.each;
          f = Math.max(floor, Math.min(size(lines, W, H - f1 * 1.15), W / (lines.reduce(function (m, l) {
            return Math.max(m, plain(l).length);
          }, 1) * 0.44 + 0.4)));
        }
        var first = Math.min(f1, Math.max(f, (H - lines.length * f * 1.15) / 1.15));
        var col = el('div', '', 'display:flex;flex-direction:column;align-items:center');
        col.appendChild(line(text.first, first));
        lines.forEach(function (l) { col.appendChild(line(l, f)); });
        b.appendChild(col);
      });
    } else if (text) {
      onBoard(B.saludo[S], function (b, W, H) {
        var lines = text.split('\n'), longest = lines.reduce(function (m, l) { return Math.max(m, l.length); }, 1);
        var f = Math.min(H / (lines.length * 1.15), W / (longest * 0.44 + 0.4));
        var t = el('div', '', 'font:700 ' + f.toFixed(1) + 'px/1.08 "Eg Mano",Caveat,cursive;color:' + ctx.L.tiza + ';opacity:.92;text-shadow:0 0 ' + (f * 0.05).toFixed(1) + 'px rgba(255,255,255,.35)');
        t.textContent = text;
        t.style.whiteSpace = 'pre-line';
        b.appendChild(t);
      });
    }
    if (centre) {
      onBoard(B.cabecera[S], function (b, W, H) {
        var t = el('div', '', 'font:600 ' + Math.min(H * 0.62, W / (centre.length * 0.55 + 0.5)).toFixed(1) + "px/1 'Eg Sans','Schibsted Grotesk',sans-serif;color:" + ctx.L.tinta + ';mix-blend-mode:multiply;letter-spacing:.02em');
        t.textContent = centre;
        b.appendChild(t);
      });
    }
  }
  // The hello of the board. One child: «¡Hola, Alba!» on one line. Several (the art director): «¡Hola,» alone at its
  // size, the names under it two at most per line, «y» on the last one and «!» at its end; each name isolated
  // (U+2068…U+2069), so one in Arabic keeps the commas and the «y» in their place. A text without names: the day.
  function helloText(names, opts) {
    var T = opts.texts || TEXTS;
    if (!names || !names.length) {
      var d = new Date(opts.date || Date.now());
      return fill(T.today, { day: T.days[d.getDay()] });
    }
    var iso = function (n) { return '⁨' + n + '⁩'; };
    var parts = T.hello.split('{names}'), before = parts[0], after = parts[1] || '';
    if (names.length === 1) {
      return before + iso(names[0]) + after;
    }
    var and = T.and, last = names[names.length - 1];
    if (and === ' y ' && /^(i|hi)(?!e)/i.test(last)) { and = ' e '; }
    var lines = [], rest = names.slice(0, -2), i;
    for (i = 0; i < rest.length; i += 2) { lines.push(rest.slice(i, i + 2).map(iso).join(', ') + ','); }
    lines.push(iso(names[names.length - 2]) + and + iso(last) + after);
    // One name per line, when two do not fit at seven tenths of the size of one.
    var each = names.slice(0, -1).map(function (n, k) { return iso(n) + (k < names.length - 2 ? ',' : ''); });
    each.push(and.trim() + ' ' + iso(last) + after);
    return { first: before.trim(), lines: lines, each: each, one: before + iso(names[0]) + after };
  }

  // ---- the inbox: the queue on the rail (one family per knob, or by days from nine families on) ----
  function hall(host, V, opts) {
    var ctx = room(host, V, opts), B = V.bandeja, slots = B.riel.huecos, fams = opts.families || [];
    var byDays = fams.length >= B.riel.modo_dias.desde;
    var groups = [], later = [];
    if (!byDays) {
      fams.slice(0, slots.length).forEach(function (f) { groups.push({ fams: [f] }); });
    } else {
      var idx = {}, order = [];
      fams.forEach(function (f) { var d = f.day || '?'; if (!idx[d]) { idx[d] = []; order.push(d); } idx[d].push(f); });
      order.forEach(function (d) { groups.push({ day: d, fams: idx[d] }); });
      while (groups.length > slots.length) {
        var g0 = groups.shift(), g1 = groups.shift();
        groups.unshift({ day: '≤ ' + g1.day, fams: g0.fams.concat(g1.fams) });
      }
    }
    groups.forEach(function (g, i) {
      var s = slots[i], k = s.luz[ctx.light];
      var bright = Math.abs(k - 1) > 0.03 ? 'filter:brightness(' + Math.min(1.35, Math.max(0.7, k)).toFixed(3) + ');' : '';
      if (!byDays) {
        var kids = (g.fams[0].children || []).slice(0, 4);
        hang(host, ctx, s.punto, byAge(kids), B.riel.abanico_m[Math.max(1, kids.length)], bright, true);
        bottles(host, ctx, s.botellas_m.base, s.botellas_m.separacion, kids, bright, true);
        var tc = s.tarjeta;
        later.push(function () { cards(host, ctx, tc.centro_m, tc.ancho_m, tc.alto_m, kids, kids.map(function (c) { return c.tag; })); });
      } else {
        var one = g.fams.slice(0, 6).map(function (f) { return byAge(f.children || [])[0] || {}; });
        hang(host, ctx, s.punto, one, B.riel.modo_dias.racimo_m.slice(0, one.length), bright, false);
        var t = s.tarjeta;
        later.push(function () { cardOnWall(host, ctx, t.centro_m, t.ancho_m, t.alto_m, { tag: g.day, symbol: false }, g.day + ' · ' + g.fams.length); });
      }
    });
    front(host, ctx);
    later.forEach(function (f) { f(); });
    chalk(host, ctx, helloText(opts.greet, opts), opts.centre);
    return host;
  }

  // ---- the family's page: each child in their own place (the small one on the low rail, the older ones on their own
  //      hooks by the door, same-course siblings together), their bottles, boxes and drawings, and the hello ----
  function family(host, V, opts) {
    var ctx = room(host, V, opts), B = V.bandeja, kids = (opts.children || []).slice(0, 4);
    var young = kids.filter(function (c) { return (c.age || 0) < 10; }), old = kids.filter(function (c) { return (c.age || 0) >= 10; });
    function grouped(list) {
      var out = [];
      list.forEach(function (c) {
        var g = c.sibling_same_course ? out.filter(function (o) { return o[0].sibling_same_course && o[0].course === c.course; })[0] : null;
        if (g) { g.push(c); } else { out.push([c]); }
      });
      return out;
    }
    var places = [], later = [];
    var low = [3, 4, 2, 5, 1, 6], lowi = 0, highs = B.mayores.pomos.slice().reverse(), hi = 0;
    grouped(young).forEach(function (g) { places.push({ s: B.riel.huecos[low[lowi++]], kids: g, muda: true }); });
    grouped(old).forEach(function (g) {
      if (hi < highs.length) { places.push({ s: highs[hi++], kids: g, muda: false }); } else { places.push({ s: B.riel.huecos[low[lowi++]], kids: g, muda: true }); }
    });
    places.forEach(function (pl) {
      var s = pl.s, k = s.luz[ctx.light];
      var bright = Math.abs(k - 1) > 0.03 ? 'filter:brightness(' + Math.min(1.35, Math.max(0.7, k)).toFixed(3) + ');' : '';
      if (opts.hung !== false) { hang(host, ctx, s.punto, byAge(pl.kids), B.riel.abanico_m[pl.kids.length], bright, true); }
      bottles(host, ctx, s.botellas_m.base, s.botellas_m.separacion, pl.kids, bright, true);
      later.push(function () {
        cards(host, ctx, s.tarjeta.centro_m, s.tarjeta.ancho_m, s.tarjeta.alto_m, pl.kids, pl.kids.map(function (c) { return c.tag; }));
        if (pl.muda && s.caja_muda) { boxLabel(host, ctx, s.caja_muda.centro_m, s.caja_muda.ancho_m, s.caja_muda.alto_m, pl.kids.map(function (c) { return c.tag; }).join(' · ')); }
      });
    });
    front(host, ctx);
    later.forEach(function (f) { f(); });
    drawings(host, ctx, kids, opts);
    chalk(host, ctx, helloText(kids.map(function (c) { return c.tag; }), opts), opts.centre);
    return host;
  }
  // the child's drawing on the table, as if left there: child.drawing = { url, orientation: 'portrait' | 'landscape' }
  // (or false). Child i takes sheet i; without a drawing, nothing (or a blank sheet if opts.blankSheets).
  function drawings(host, ctx, kids, opts) {
    var M = ctx.V.bandeja.mesa;
    kids.forEach(function (c, i) {
      var sh = M.hojas[i], d = c.drawing;
      if (typeof d === 'string') { d = { url: d, orientation: 'landscape' }; }
      if (!sh || (!d && !opts.blankSheets)) { return; }
      var orient = d && d.orientation === 'portrait' && sh.hoja.portrait ? 'portrait' : 'landscape';
      var piece = sh.hoja[orient], r = piece.recorte[ctx.S];
      host.appendChild(img(fill(piece.archivo, { luz: ctx.light, tamano: ctx.S }), r[0], r[1], r[2], r[3]));
      if (!d) { return; }
      var cs = sh.drawing_slot[orient][ctx.S], W = orient === 'portrait' ? 707 : 1000, H = orient === 'portrait' ? 1000 : 707;
      var pic = img(d.url, 0, 0, W, H, 'transform-origin:0 0;transform:' + planeMatrix(cs[0], cs[1], cs[3], W, H) + ';mix-blend-mode:multiply;cursor:zoom-in');
      pic.className = 'dibujo';
      pic.setAttribute('data-nino', String(i));
      pic.alt = c.tag ? 'Dibujo de ' + c.tag : 'Dibujo';
      host.appendChild(pic);
    });
  }

  // ---- the door, animated: open (1 s) and close (0.85 s) through the frames of the strip; the doorway fades in with
  //      the angle. With «less motion» (prefers-reduced-motion or data-mov="menos" on <html>) it jumps to the end. ----
  function doorAnimator(host, V, size, light) {
    var B = V.bandeja, P = B.puerta, a = P.angulos, h = B.hueco.recorte[size], r = P.recorte[size], fw = P.tamano_fotograma[size];
    var hole = img(B.hueco.archivos[light][size], h[0], h[1], h[2], h[3], 'opacity:0');
    var leaf = el('div', '', 'position:absolute;left:' + r[0] + 'px;top:' + r[1] + 'px;width:' + fw[0] + 'px;height:' + fw[1] + 'px;' +
      'background:url(' + P.archivos[light][size] + ') 0 0/' + (fw[0] * a.length) + 'px ' + fw[1] + 'px no-repeat');
    // right over the background, under everything the page puts on the scene
    var after = host.children[1] || null;
    host.insertBefore(hole, after);
    host.insertBefore(leaf, after);
    var angle = 0, raf = 0;
    function set(deg) {
      var fi = 0;
      for (var i = 0; i < a.length; i++) { if (Math.abs(a[i] - deg) < Math.abs(a[fi] - deg)) { fi = i; } }
      angle = deg;
      leaf.style.backgroundPosition = (-fi * fw[0]) + 'px 0';
      hole.style.opacity = (Math.sin(a[fi] * Math.PI / 180) / Math.sin(a[a.length - 1] * Math.PI / 180)).toFixed(3);
    }
    function less() {
      return document.documentElement.getAttribute('data-mov') === 'menos' ||
        (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }
    function to(target, ms) {
      cancelAnimationFrame(raf);
      if (less()) { set(target); return Promise.resolve(); }
      var from = angle, t0 = null;
      return new Promise(function (done) {
        function step(t) {
          if (t0 == null) { t0 = t; }
          var k = Math.min(1, (t - t0) / ms), e = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
          set(from + (target - from) * e);
          if (k < 1) { raf = requestAnimationFrame(step); } else { done(); }
        }
        raf = requestAnimationFrame(step);
      });
    }
    set(0);
    return { set: set, open: function () { return to(a[a.length - 1], 1000); }, close: function () { return to(0, 850); } };
  }

  // ---- the frame of a view: the image scaled and placed as V.bandeja.encuadres[key] says ----
  function frame(inner, V, key) {
    var e = V.bandeja.encuadres[key];
    inner.style.position = 'absolute';
    inner.style.left = '0';
    inner.style.top = '0';
    inner.style.transformOrigin = '0 0';
    inner.style.transform = 'translate(' + e.x + 'px,' + e.y + 'px) scale(' + e.escala + ')';
    return e;
  }

  // ---- the cloakroom of places: one rail per course, built at 2400 (whole pixels) and then scaled ----
  // courses: [{ places, enrolled, waiting, age }]
  function cloakroom(host, V, R, light, courses, opts) {
    opts = opts || {};
    var A = R.archivos, pz = R.piezas, T = pz.percha;
    var perRail = opts.perRail || 26;
    var lin = linear(V.proyeccion['2400'].P);
    function dx(xm) { return lin([xm, 0, 0]); }
    var rows = [];
    courses.forEach(function (c) {
      for (var start = 0; start < c.places; start += perRail) {
        rows.push({ c: c, n: Math.min(perRail, c.places - start), from: start, last: start + perRail >= c.places });
      }
    });
    var ox = opts.left != null ? opts.left : 60, oy = 60, y = oy, maxX = 0, items = [];
    rows.forEach(function (row) {
      var c = row.c, n = row.n, x0 = ox;
      function put(file, piece, at) { items.push([file, at[0] - piece.ancla[0], at[1] - piece.ancla[1], piece.w, piece.h]); }
      function xk(k) { return R.margen_m + R.paso_m * k + R.hueco_diez_m * Math.floor(k / 10); }
      function at(xm, extra) { var d = dx(xm), e = extra ? lin(extra) : [0, 0]; return [x0 + d[0] + e[0], y + d[1] + e[1]]; }
      put(fill(A.percha, { pieza: 'inicio', luz: light }), T.inicio, at(-0.06));
      for (var k = 0; k < n; k++) {
        if (k > 0 && k % 10 === 0) { put(fill(A.percha, { pieza: 'diez', luz: light }), T.diez, at(xk(k - 1) + R.paso_m / 2)); }
        put(fill(A.percha, { pieza: 'tramo', luz: light }), T.tramo, at(xk(k) - R.paso_m / 2));
      }
      put(fill(A.percha, { pieza: 'fin', luz: light }), T.fin, at(xk(n - 1) + R.paso_m / 2));
      var kind = objectFor(c.age), enrolled = Math.max(0, Math.min(n, (c.enrolled || 0) - row.from));
      var cols = V.objetos.colores;
      for (k = 0; k < n; k++) { put(fill(A.pomo_fondo, { luz: light }), pz['pomo-fondo'], at(xk(k))); }
      for (k = 0; k < enrolled; k++) {
        var o = V.objetos[kind], an = R.cosas.ancla[kind]['2400'] || R.cosas.ancla[kind], sz = R.cosas.tamano[kind]['2400'] || R.cosas.tamano[kind];
        var p = at(xk(k), [0, -0.045, 0]);
        items.push([fill(A.cosas, { objeto: kind, color: cols[(k * 5 + rows.indexOf(row) * 3) % cols.length], luz: light }), p[0] - an[0], p[1] - an[1], sz[0], sz[1]]);
      }
      for (k = 0; k < n; k++) { put(fill(A.pomo_cabeza, { luz: light }), pz['pomo-cabeza'], at(xk(k))); }
      var len = xk(n - 1) + R.paso_m / 2 + 0.06;
      if (row.last && c.waiting) {
        var bx = Math.max(0, len - 2.0 - R.banco_desde_fin_m + 0.6);
        put(fill(A.banco, { luz: light }), pz.banco, at(bx, [0, 0.03, 0.3 - 1.2]));
        for (k = 0; k < Math.min(6, c.waiting); k++) { put(fill(A.espera, { luz: light }), pz.espera, at(bx + 0.3 + 0.3 * k, [0, -0.04, 0.3 - 1.2])); }
      }
      row.origin = [x0, y];
      row.end = at(len);
      maxX = Math.max(maxX, row.end[0] + 40);
      y += (row.last && c.waiting ? R.fila_espera_px[1] : R.fila_px[1]);
    });
    var maxY = 0;
    items.forEach(function (it) { maxX = Math.max(maxX, it[1] + it[3]); maxY = Math.max(maxY, it[2] + it[4]); });
    host.style.position = 'relative';
    host.style.width = Math.ceil(maxX + 20) + 'px';
    host.style.height = Math.ceil(maxY + 20) + 'px';
    items.forEach(function (it) { host.appendChild(img(it[0], Math.round(it[1] * 100) / 100, Math.round(it[2] * 100) / 100, it[3], it[4])); });
    host.rows = rows;
    return host;
  }

  root.Vestibulo = { hall: hall, family: family, frame: frame, cloakroom: cloakroom, room: room, chalk: chalk, door: doorAnimator,
                     planeMatrix: planeMatrix, projector: projector, objectFor: objectFor, colourOf: colourOf, texts: TEXTS };
})(window);

(function () {
  // The start: each [data-vestibulo] of the page, drawn with its data in the frame of its width, again when it changes.
  var FRAMES = { '1920x1080': [1920, 1080], '1600x900': [1600, 900], '1366x768': [1366, 768], '390x844': [390, 844],
                 familia: [1366, 768] };
  // The bar of the mock-ups the frames were made with: what shows starts under it.
  var BAR = 60;
  function withBase(value, base) {
    if (typeof value === 'string') { return value.indexOf('pub/') === 0 ? base + value : value; }
    if (Array.isArray(value)) { return value.map(function (v) { return withBase(v, base); }); }
    if (value && typeof value === 'object') {
      var out = {};
      Object.keys(value).forEach(function (k) { out[k] = withBase(value[k], base); });
      return out;
    }
    return value;
  }
  function frameFor(kind, width) {
    if (kind === 'familia') { return width < 720 ? '390x844' : 'familia'; }
    return width >= 1900 ? '1920x1080' : (width >= 1580 ? '1600x900' : (width >= 720 ? '1366x768' : '390x844'));
  }
  function dark() {
    var t = document.documentElement.getAttribute('data-bs-theme') || document.documentElement.getAttribute('data-theme');
    if (t) { return t === 'dark'; }
    return Boolean(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }
  // The light by the hour of the centre, as the scene says; in dark, the night.
  function lightFor(V, time) {
    if (dark()) { return 'noche'; }
    var H = V.luces_horas || {};
    var inside = function (r) { return r && (r[0] <= r[1] ? time >= r[0] && time < r[1] : time >= r[0] || time < r[1]); };
    return inside(H.dia) ? 'dia' : (inside(H.tarde) ? 'tarde' : 'noche');
  }
  function draw(stage, V, data) {
    var sala = stage.querySelector('.sala');
    if (!sala) { return; }
    // Shown first, so that it can be measured; without the hall drawn, the page goes back to what it shows without it.
    document.documentElement.classList.add('sala-lista');
    try {
      var width = stage.clientWidth || window.innerWidth, key = frameFor(data.kind, width), e = V.bandeja.encuadres[key];
      var nominal = FRAMES[key] || [width, stage.clientHeight];
      var top = key === 'familia' || key === '390x844' ? 0 : BAR;
      // The frame was made for its own size: the whole of it in the height there is, and centred in the width.
      var k = key === '390x844' ? 1 : Math.min(1, (stage.clientHeight || nominal[1] - top) / (nominal[1] - top));
      var light = lightFor(V, data.time);
      while (sala.firstChild) { sala.removeChild(sala.firstChild); }
      var opts = { size: e.imagen, light: light, time: data.time, centre: data.centre, minPx: 15, screenScale: e.escala * k,
                   texts: data.texts, date: data.date };
      if (data.kind === 'familia') {
        opts.children = data.children || [];
        opts.hung = data.hung !== false;
        Vestibulo.family(sala, V, opts);
      } else {
        opts.families = data.families || [];
        opts.greet = data.greet && data.greet.length ? data.greet : null;
        Vestibulo.hall(sala, V, opts);
      }
      Vestibulo.frame(sala, V, key);
      var dx = (width - nominal[0] * k) / 2;
      sala.style.transform = 'translate(' + (e.x * k + dx).toFixed(1) + 'px,' + ((e.y - top) * k).toFixed(1) + 'px) scale(' +
        (e.escala * k).toFixed(4) + ')';
      stage.setAttribute('data-luz', light);
      stage.setAttribute('data-encuadre', key);
      stage.classList.add('dibujada');
    } catch (err) {
      document.documentElement.classList.remove('sala-lista');
    }
  }
  // The cloakroom of the places (Gaudí, section 8 of the plate): in each block of places, one cloakroom with a rail per
  // course, stacked by their start so they compare as bars, what hangs from the pegs taken and the bench of the waiting
  // list; the name and the free places at the left of each rail. Built at 2400 and scaled to the width of the block. The
  // rail of the desk stays in each course for when there is no JavaScript.
  function cloakrooms(V, R, boxes) {
    var groups = [];
    boxes.forEach(function (box) {
      var block = box.closest ? box.closest('.grafico-plazas, .bloque-plazas') : null;
      if (!block) { return; }
      var g = groups.filter(function (x) { return x.block === block; })[0];
      if (!g) { g = { block: block, boxes: [] }; groups.push(g); }
      g.boxes.push(box);
    });
    groups.forEach(function (g) {
      var courses = [];
      g.boxes.forEach(function (box) {
        var c;
        try { c = JSON.parse(box.getAttribute('data-ropero')); } catch (err) { return; }
        var course = box.parentNode, name = course.querySelector('.cab b'), free = course.querySelector('.cab .libres');
        c.label = name ? name.textContent : '';
        c.free = free ? free.textContent : '';
        courses.push(c);
        box.classList.add('con-ropero');
      });
      if (!courses.length) { return; }
      var list = g.block.querySelector('.plazas-cursos, .plazas');
      var frame = g.block.querySelector('.ropero-marco');
      if (frame) { frame.parentNode.removeChild(frame); }
      frame = document.createElement('div');
      frame.className = 'ropero-marco';
      frame.setAttribute('aria-hidden', 'true');
      (list || g.block).parentNode.insertBefore(frame, list || null);
      var host = document.createElement('div');
      host.className = 'ropero';
      frame.appendChild(host);
      var light = lightFor(V, new Date().getHours() + new Date().getMinutes() / 60);
      // Narrow, the rails alone: the names and the free places are in the list of the courses under them.
      var narrow = (frame.clientWidth || g.block.clientWidth || 0) < 560;
      Vestibulo.cloakroom(host, V, R, light, courses, { perRail: 26, left: narrow ? 40 : 520 });
      var w0 = parseFloat(host.style.width), h0 = parseFloat(host.style.height);
      var k = Math.min(0.42, (frame.clientWidth || g.block.clientWidth || w0) / w0);
      host.style.transformOrigin = '0 0';
      host.style.transform = 'scale(' + k.toFixed(4) + ')';
      host.style.position = 'absolute';
      host.style.left = '0';
      host.style.top = '0';
      // As tall as where the last rail ends (and its bench), not as the shadows of the pieces.
      var ends = (host.rows || []).map(function (r) { return r.end[1] + (r.last && r.c.waiting ? 260 : 120); });
      frame.style.height = Math.ceil(Math.min(h0, ends.length ? Math.max.apply(null, ends) : h0) * k + 8) + 'px';
      var seen = {};
      (narrow ? [] : host.rows || []).forEach(function (r) {
        if (seen[r.c.label]) { return; }
        seen[r.c.label] = 1;
        var lab = document.createElement('div');
        lab.className = 'ropero-fila';
        lab.style.width = Math.max(60, r.origin[0] * k - 18) + 'px';
        lab.style.top = Math.max(0, r.origin[1] * k - 14) + 'px';
        var b = document.createElement('b');
        b.textContent = r.c.label;
        lab.appendChild(b);
        var sp = document.createElement('span');
        sp.textContent = r.c.free;
        lab.appendChild(sp);
        frame.appendChild(lab);
      });
      frame.setAttribute('data-luz', light);
    });
  }
  function less() {
    return document.documentElement.getAttribute('data-mov') === 'menos' ||
      Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }
  function arrive(stage, V, data, detail) {
    var kids = detail.children && detail.children.length ? detail.children : [{ tag: '', age: null }];
    var slots = V.bandeja.riel.huecos, families = data.families || [];
    var index = Math.min(families.length, slots.length - 1);
    data.families = families.concat([{ children: kids.slice(0, 4), day: '' }]);
    var sala = stage.querySelector('.sala'), key = stage.getAttribute('data-encuadre');
    var e = key ? V.bandeja.encuadres[key] : null;
    if (!sala || !e || less() || !sala.animate || families.length >= V.bandeja.riel.modo_dias.desde) {
      draw(stage, V, data);
      return;
    }
    var size = e.imagen, light = stage.getAttribute('data-luz') || 'dia', child = kids.slice().sort(function (a, b) {
      return (a.age || 0) - (b.age || 0);
    })[0];
    var kind = Vestibulo.objectFor(child.age), O = V.objetos[kind];
    var src = O.archivo.replace('{color}', Vestibulo.colourOf(V, child)).replace('{luz}', light).replace('{tamano}', size);
    var from = V.bandeja.entrada[size], proj = Vestibulo.projector(V.proyeccion[size].P), to = proj(slots[index].punto);
    var an = O.ancla[size], sz = O.tamano[size];
    var bag = document.createElement('img');
    bag.src = src;
    bag.alt = '';
    bag.style.cssText = 'position:absolute;left:0;top:0;width:' + sz[0] + 'px;height:' + sz[1] + 'px;transform-origin:' +
      an[0] + 'px ' + an[1] + 'px;opacity:0';
    var door = Vestibulo.door(sala, V, size, light);
    sala.appendChild(bag);
    var at = function (p, k) {
      return 'translate(' + (p[0] - an[0]).toFixed(1) + 'px,' + (p[1] - an[1]).toFixed(1) + 'px) scale(' + k + ')';
    };
    var top = [(from[0] + to[0]) / 2, Math.min(from[1], to[1]) - 0.16 * V.proyeccion[size].px_por_metro];
    door.open().then(function () {
      var fly = bag.animate([
        { transform: at(from, 0.72), opacity: 0 },
        { transform: at(from, 0.78), opacity: 1, offset: 0.12 },
        { transform: at(top, 0.92), opacity: 1, offset: 0.55 },
        { transform: at(to, 1), opacity: 1 }
      ], { duration: 1100, easing: 'cubic-bezier(.3,.1,.25,1)', fill: 'forwards' });
      return new Promise(function (done) { fly.onfinish = done; });
    }).then(function () {
      return door.close();
    }).then(function () {
      draw(stage, V, data);
    });
  }
  function start() {
    var stages = Array.prototype.slice.call(document.querySelectorAll('[data-vestibulo]'));
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.piel-vestibulo [data-ropero]'));
    if ((!stages.length && !boxes.length) || !window.fetch) { return; }
    // Where the pieces are: the base the page gives, else that of the styles of the skin.
    var link = document.querySelector('link[href*="pieles/vestibulo/"]');
    var base = stages.length ? stages[0].getAttribute('data-base') || '' :
      (link ? link.getAttribute('href').replace(/pub\/pieles\/vestibulo\/.*$/, '') : '');
    var load = function (name) {
      return fetch(base + 'pub/pieles/vestibulo/datos/' + name + '.json', { credentials: 'same-origin' }).then(function (r) {
        return r.json();
      });
    };
    Promise.all([load('vestibulo'), boxes.length ? load('ropero') : Promise.resolve(null)]).then(function (both) {
      var V = withBase(both[0], base), R = both[1] ? withBase(both[1], base) : null;
      var fonts = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
      return fonts.then(function () {
        if (R) {
          cloakrooms(V, R, boxes);
          var waitR = 0;
          window.addEventListener('resize', function () {
            clearTimeout(waitR);
            waitR = setTimeout(function () { cloakrooms(V, R, boxes); }, 150);
          });
          // A folded part opened: its cloakrooms take the width they now have.
          document.addEventListener('toggle', function (e) {
            if (e.target.open && e.target.querySelector('[data-ropero]')) { cloakrooms(V, R, boxes); }
          }, true);
        }
        stages.forEach(function (stage) {
          var data;
          try { data = JSON.parse(stage.getAttribute('data-escena') || '{}'); } catch (err) { return; }
          draw(stage, V, data);
          var last = stage.clientWidth, wait = 0;
          window.addEventListener('resize', function () {
            clearTimeout(wait);
            wait = setTimeout(function () {
              if (stage.clientWidth !== last) { last = stage.clientWidth; draw(stage, V, data); }
            }, 150);
          });
          // A family that wrote while looking (the paper desk says it): the door opens, what is theirs comes in and
          // hangs on the first knob free, and the door closes.
          if (data.kind !== 'familia') {
            document.addEventListener('eguahe:ha-llegado', function (e) { arrive(stage, V, data, e.detail || {}); });
          }
          // Moodle going dark or light: the night of the hall, or its day.
          if (window.MutationObserver) {
            new MutationObserver(function () { draw(stage, V, data); }).observe(document.documentElement,
              { attributes: true, attributeFilter: ['data-bs-theme'] });
          }
        });
      });
    }).catch(function () {
      // Without its data the hall is not drawn: the page is the paper desk as it is.
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
