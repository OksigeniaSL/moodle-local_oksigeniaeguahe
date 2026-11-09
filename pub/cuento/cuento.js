// This file is part of Moodle - https://moodle.org/ (GNU GPL v3 or later).

/**
 * «Tu primer día», the welcome story of Eguahe (Gaudí, 8 and 9 November 2026): the pages turned by finger or arrow keys
 * (the other way in Arabic), the child's name written over each illustration, their drawing from the visit hung on the
 * wall of the class, the voice of each page with a little song under it (or the song alone where a language has no
 * voice), a handwritten dedication, and quiet reactions announced as `eguahe:reaccion` for Eguahe to count. Its data
 * and its words come from the page (#libro-datos).
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
'use strict';
const BOOK = JSON.parse(document.getElementById('libro-datos').textContent);
const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const STORY = BOOK.pages.filter(p => !p.kind).length;
const params = new URLSearchParams(location.search);

// --- Language, teacher and child -------------------------------------------------------------------------------------
// The language of the family's page (?lang=en|pt-PT|pt-BR|ar; Spanish by default) picks every text of the book from
// BOOK.strings (cadenas.json). Arabic reads right to left. The teacher is «maestra» by default and «maestro» when the
// centre says so (BOOK.teacher, or ?maestro=1 / ?maestro=0). In Arabic the voice says «your» as a boy or as a girl
// (-ka, -ki): ?nino=m|f (BOOK.childSex), the boy's when nobody says, the grammatical default.
const LANG = Object.prototype.hasOwnProperty.call(BOOK.strings, params.get('lang')) ? params.get('lang') : BOOK.lang;
const T = BOOK.strings[LANG];
const RTL = LANG === 'ar';
const MAESTRO = params.has('maestro') ? params.get('maestro') === '1' : BOOK.teacher === 'maestro';
const SEX = ['m', 'f'].includes(params.get('nino')) ? params.get('nino') : BOOK.childSex;
const fill = (s, v = {}) => String(s).replace(/\{(\w+)\}/g, (m, k) => (k in v ? v[k] : m));
const tr = (key, v) => fill(T[key], Object.assign({ nombre: BOOK.child }, v));
// The teacher's name (the class tutor), when Eguahe knows it, goes into pages 3, 6 and 7 of the written text (the voice
// does not say it): BOOK.tutors has the sample's, ?tutor=Name sets another and ?tutor= leaves it out.
const TUTOR = params.has('tutor') ? params.get('tutor').trim() : ((BOOK.tutors || {})[MAESTRO ? 'maestro' : 'maestra'] || '');
// a text that changes with the teacher: with «maestro» and with the name when they apply and the key exists
const tt = key => {
  const keys = [MAESTRO && TUTOR && key + '_maestro_tutor', MAESTRO && key + '_maestro', !MAESTRO && TUTOR && key + '_tutor', key];
  for (const k of keys) if (k && T[k] != null) return T[k];
};
const CENTRE_PHRASE = BOOK.centrePhrases[LANG] || BOOK.centre;

// The reading: with the child's name in the voice when there is one (only in Spanish, and only with «maestra»), or
// without it (?voz=sin-nombre; the reading of Eguahe's first release). The written text always carries the name. With
// «maestro» the reading is the one that says «maestro»; if a language has none, the one with «maestra» only when its
// words are the same (English: «teacher»), and otherwise no voice: never a voice that contradicts the page. A language
// without a voice is read aloud at home, with the song.
const VOICES = BOOK.voices[LANG] || {};
function pickVoice() {
  const sx = LANG === 'ar' ? '-' + SEX : '';
  if (params.get('voz') === 'ninguna') return null;      // read aloud at home, with the song (and to test that way)
  if (!MAESTRO && params.get('voz') !== 'sin-nombre' && VOICES.nombre) return 'nombre';
  if (!MAESTRO) return VOICES['sin-nombre' + sx] ? 'sin-nombre' + sx : null;
  if (VOICES['sin-nombre-maestro' + sx]) return 'sin-nombre-maestro' + sx;
  const same = ['page3', 'page6', 'page7'].every(k => T[k + '_maestro'] == null || T[k + '_maestro'] === T[k]);
  return same && VOICES['sin-nombre' + sx] ? 'sin-nombre' + sx : null;
}
const VOICE_NAME = pickVoice();
const VOICE = VOICE_NAME ? VOICES[VOICE_NAME] : null;
const pageName = p => p.kind === 'cover' ? T.cover : p.kind === 'dedication' ? T.dedication_label : tr('page_n', { n: p.n });

// The fixed texts of the page, in the family's language.
(function localise() {
  const root = document.documentElement;
  root.lang = LANG; root.dir = RTL ? 'rtl' : 'ltr';
  document.title = tr('doc_title');
  const set = (sel, attr, text) => { const el = $(sel); if (el) attr ? el.setAttribute(attr, text) : (el.textContent = text); };
  set('.muestra', null, T.sample);
  set('.tema', 'aria-label', T.theme);
  set('#libro', 'aria-roledescription', T.book_role);
  set('#libro', 'aria-label', tr('book_label'));
  set('.mandos', 'aria-label', T.nav);
  set('#ant', 'aria-label', T.prev);
  set('#sig', 'aria-label', T.next);
  set('#oir-texto', null, VOICE ? T.listen : T.read_with_music);
  set('#musica', 'aria-label', T.music); set('#musica', 'title', T.music);
  set('.vol .vh', null, T.volume);
  set('#vol-texto', null, fill(T.volume_value, { n: 25 }));
  set('.autor a span', null, T.by);
  set('#ver', 'aria-label', tr('drawing_label'));
  set('#ver-lienzo', 'aria-label', tr('drawing_label'));
  set('.ver-pie', null, tr('drawing_caption'));
  set('#ver-cerrar', 'aria-label', T.close);
  if (!VOICE) $('.app').classList.add('sin-voz');
})();

// --- Reactions ------------------------------------------------------------------------------------------------------
// Nothing here asks anything. A heart on each page for whoever wants it, three natural gestures at the end, and a few
// signals nobody presses (the whole story heard, heard again, the drawing enlarged, going back to a page). Each one is
// announced as an `eguahe:reaccion` event for Eguahe to count, with no data about anyone, and remembered in this
// browser so the same thing is not counted twice. Keys: «tipo:pagina»; things that may happen more than once (sending
// it to someone, hearing it again) carry their count.
const REACT_KEY = `cuento-${BOOK.book}-reacciones`;
let reactMem = null;
function reactState() {
  if (reactMem) return reactMem;
  try { reactMem = JSON.parse(localStorage.getItem(REACT_KEY) || '{}') || {}; } catch (e) { reactMem = {}; }
  return reactMem;
}
function reactSave() { try { localStorage.setItem(REACT_KEY, JSON.stringify(reactMem)); } catch (e) {} }
function announce(tipo, pagina, extra) {
  const detail = Object.assign({ libro: BOOK.book, pagina, tipo, voz: VOICE_NAME, idioma: LANG }, MAESTRO ? { maestro: true } : {}, extra || {});
  try { window.dispatchEvent(new CustomEvent('eguahe:reaccion', { detail })); } catch (e) {}
}
function react(tipo, pagina, extra, key) {
  const s = reactState();
  key = key || `${tipo}:${pagina}`;
  if (s[key]) return false;
  s[key] = 1; reactSave(); announce(tipo, pagina, extra);
  return true;
}
function unreact(key) { delete reactState()[key]; reactSave(); }
function count(name) { const s = reactState(); s['#' + name] = (s['#' + name] || 0) + 1; reactSave(); return s['#' + name]; }

const HEART = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.2c-.4-.3-7.2-4.6-8.7-9C2.2 8 4.2 4.9 7.3 4.9c2 0 3.6 1.2 4.7 2.9 1.1-1.7 2.7-2.9 4.7-2.9 3.1 0 5.1 3.1 4 6.3-1.5 4.4-8.3 8.7-8.7 9Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>';
const heartHtml = n => `<button class="corazon" type="button" data-pagina="${n}" aria-pressed="${!!reactState()['me-gusta:' + n]}" aria-label="${esc(T.like)}" title="${esc(T.like)}">${HEART}</button>`;

// --- Pages ---------------------------------------------------------------------------------------------------------
// The last two words of a line stay together, so no line of the book ends with a word on its own.
const lineHtml = l => esc(l.replace(/ (\S+)$/, '\u00a0$1')).replace('{nombre}', `<span class="nombre" dir="auto">${esc(BOOK.child)}</span>`)
  .replace('{centro}', `<bdi>${esc(CENTRE_PHRASE)}</bdi>`).replace('{tutor}', `<bdi>${esc(TUTOR)}</bdi>`);
const linesOf = s => String(s).split('\n');

function coverHtml() {
  // The first-day sticker of Eguahe, with the child's name on its line (geometry of kit/marca-eguahe/etiqueta.py).
  return `<section class="pagina portada" data-i="0" aria-label="${esc(T.cover)}">
    <h1 aria-label="${esc(tr('book_label'))}"><span class="antes" aria-hidden="true">${esc(T.title)}</span>
      <svg class="etiqueta" viewBox="-25 -18 50 36" aria-hidden="true">
        <path class="banda" d="M-23 -12 Q-23 -16 -19 -16 L19 -16 Q23 -16 23 -12 L23 8 L15 16 L-19 16 Q-23 16 -23 12 Z"/>
        <path class="papel" d="M-20.6 -6.2 L20.6 -6.2 L20.6 7 L13.8 13.8 L-18.6 13.8 Q-20.6 13.8 -20.6 11.8 Z"/>
        <path class="renglon" d="M-15 6.2 L9 6.2 L9 7.9 L-15 7.9 Z" opacity=".75"/>
        <path class="pico" d="M23 8 L15 16 L15 8 Z"/>
        <text x="-3" y="5" text-anchor="middle" font-size="13.5" data-w="27">${esc(BOOK.child)}</text>
      </svg>
    </h1>
    <p class="firma-centro">${(document.querySelector('.centro .marca-centro') || { outerHTML: '' }).outerHTML}<span>${esc(BOOK.centre)}</span></p>
    <p class="pista">${esc(T.hint)}</p>
  </section>`;
}

// The frame on the classroom wall: the page measures its four inner corners on the illustration, and the child's
// drawing goes there in perspective (a projective matrix from a rectangle to those corners).
function quadMatrix(W, H, q) {
  const [[x0, y0], [x1, y1], [x2, y2], [x3, y3]] = q;
  const dx1 = x1 - x2, dx2 = x3 - x2, dx3 = x0 - x1 + x2 - x3, dy1 = y1 - y2, dy2 = y3 - y2, dy3 = y0 - y1 + y2 - y3;
  let g = 0, h = 0;
  if (Math.abs(dx3) > 1e-9 || Math.abs(dy3) > 1e-9) {
    const den = dx1 * dy2 - dx2 * dy1;
    g = (dx3 * dy2 - dx2 * dy3) / den; h = (dx1 * dy3 - dx3 * dy1) / den;
  }
  const a = x1 - x0 + g * x1, b = x3 - x0 + h * x3, d = y1 - y0 + g * y1, e = y3 - y0 + h * y3;
  return [a / W, d / W, 0, g / W, b / H, e / H, 0, h / H, 0, 0, 1, 0, x0, y0, 0, 1].map(v => +v.toFixed(9)).join(',');
}
function frameHtml(p) {
  const f = p.frame, q = f.quad, dist = (a, b) => Math.hypot(b[0] - a[0], b[1] - a[1]);
  const W = Math.round((dist(q[0], q[1]) + dist(q[3], q[2])) / 2), H = Math.round((dist(q[0], q[3]) + dist(q[1], q[2])) / 2);
  const style = `width:${W}px;height:${H}px;transform:matrix3d(${quadMatrix(W, H, q)})`;
  if (BOOK.drawing) {
    const label = tr('drawing_open');
    return `<div class="capa"><button class="marco" type="button" aria-label="${esc(label)}" title="${esc(label)}" style="${style}">
      <span class="hoja"><canvas class="dibujo" width="300" height="400"></canvas></span></button></div>`;
  }
  // Without a drawing from the visit, never an empty frame: one of the other drawings of the class hangs there, still.
  return `<div class="capa" aria-hidden="true"><span class="marco sin-dibujo" style="${style}"><span class="hoja">` +
    `<img src="${BOOK.classDrawing}" alt=""></span></span></div>`;
}

function pageHtml(p, i) {
  // with «maestro», pages 3, 6 and 7 have their own illustration (and its own measures, if they moved)
  if (MAESTRO && p.maestro) p = Object.assign({}, p, p.maestro);
  const chalk = T.chalk.split('{nombre}');
  const names = (p.names || []).map(n => `<text class="${n.kind}" x="${n.x}" y="${n.y}" font-size="${n.size}"
      text-anchor="middle" data-w="${n.w}" transform="rotate(${n.rot || 0} ${n.x} ${n.y})${n.skew ? ` skewX(${n.skew})` : ''}"
      ${n.kind === 'chalk' ? 'fill="#F4F1E8" filter="url(#tiza)"' : 'fill="#2B2226" filter="url(#boli)"'}
      ${n.kind === 'chalk' && RTL ? 'direction="rtl"' : ''}
      font-family="Caveat" font-weight="${n.kind === 'chalk' ? 600 : 650}">${esc(n.text || (n.kind === 'chalk' ? chalk[0] + BOOK.child + (chalk[1] || '') : BOOK.child))}</text>`).join('');
  return `<section class="pagina" data-i="${i}" aria-label="${esc(tr('page_n_of', { n: p.n, total: STORY }))}">
    <figure class="lamina">
      <svg viewBox="0 0 1536 1024" role="img" aria-label="${esc(tt('alt' + p.n) || '')}">
        <image href="${BOOK.base}ilustraciones/${p.img}.webp" width="1536" height="1024" preserveAspectRatio="xMidYMid slice"/>
        ${names}
      </svg>
      ${p.frame ? frameHtml(p) : ''}
      ${heartHtml(p.n)}
    </figure>
    <p class="texto">${linesOf(tt('page' + p.n)).map(l => `<span class="l">${lineHtml(l)}</span>`).join('')}</p>
  </section>`;
}

const ICON = {
  otra: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.6 12.4a7.5 7.5 0 1 0 2.3-5.6"/><path d="M5.6 3.8v3.6h3.6"/></svg>',
  guardar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v10.5"/><path d="M7.6 10.4 12 14.8l4.4-4.4"/><path d="M5 16.8v1.9c0 .7.6 1.3 1.3 1.3h11.4c.7 0 1.3-.6 1.3-1.3v-1.9"/></svg>',
  mandar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.4 3.8 3.7 10.4l6.7 2.9 2.9 6.7 7.1-16.2Z"/><path d="m10.4 13.3 4.4-4.4"/></svg>',
  video: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m10.2 9.4 4.6 2.6-4.6 2.6Z"/></svg>',
};
function dedicationHtml(p, i) {
  // «Save it» keeps the whole story heard, with its voice and the song: only where there is one.
  const save = VOICE && VOICE.download
    ? `<a class="gesto" data-gesto="guardar" href="${esc(VOICE.download.href)}" download="${esc(tr('download_name'))}" target="_blank" rel="noopener">${ICON.guardar}<span>${esc(T.save)}</span></a>`
    : '';
  return `<section class="pagina dedicatoria" data-i="${i}" aria-label="${esc(T.dedication_label)}">
    <div class="hoja-dedicatoria">
      <img class="manos" src="${BOOK.base}ilustraciones/${p.img}.webp" alt="${esc(T.alt_dedication)}" width="1024" height="1024">
      <p class="dedica">${linesOf(T.dedication).map(l => `<span class="l">${lineHtml(l).replace('class="nombre"', 'class="nombre-mano"')}</span>`).join('')}</p>
      ${heartHtml(p.n)}
    </div>
    <div class="gestos">
      <button class="gesto" type="button" data-gesto="volver-a-escuchar">${ICON.otra}<span>${esc(VOICE ? T.listen_again : T.read_again)}</span></button>
      ${save}
      <button class="gesto" type="button" data-gesto="mandar">${ICON.mandar}<span>${esc(T.send)}</span></button>
      ${BOOK.video && VOICE ? `<button class="gesto" type="button" data-cuento-video>${ICON.video}<span>${esc(T.video)}</span></button>` : ''}
    </div>
    <p class="gestos-aviso" id="gestos-aviso" aria-live="polite"></p>
  </section>`;
}

const tira = $('#tira');
tira.innerHTML = BOOK.pages.map((p, i) => p.kind === 'cover' ? coverHtml() : p.kind === 'dedication' ? dedicationHtml(p, i) : pageHtml(p, i)).join('');
const pages = [...tira.children];
// Shared: the gift of the centre signs the dedication, as in a book given; the invitation closes the last page, under
// its gestures. Without the script they stay under the book.
(function () {
  const gift = $('.regalo'), dedication = tira.querySelector('.dedicatoria');
  if (!gift || !dedication) return;
  const sign = gift.querySelector('.regalo-firma'), invite = gift.querySelector('.regalo-invita');
  const sheet = dedication.querySelector('.hoja-dedicatoria');
  if (sign) { sheet.appendChild(sign); sheet.classList.add('con-firma'); }
  if (invite) dedication.appendChild(invite);
  gift.remove();
})();
const last = pages.length - 1;

// Names longer than their blank spot get smaller, never wider than the sticker.
function fitNames() {
  document.querySelectorAll('text[data-w]').forEach(t => {
    if (!t.dataset.size) t.dataset.size = t.getAttribute('font-size');
    t.setAttribute('font-size', t.dataset.size);
    const w = +t.dataset.w, len = t.getComputedTextLength();
    if (len > w) t.setAttribute('font-size', (t.dataset.size * w / len).toFixed(2));
  });
}
(document.fonts ? document.fonts.ready : Promise.resolve()).then(fitNames);

// The layer over each illustration follows its size.
const scaleLayer = el => el.style.setProperty('--s', (el.clientWidth / 1536).toFixed(5));
const layered = [...document.querySelectorAll('.lamina')].filter(l => l.querySelector('.capa'));
layered.forEach(scaleLayer);
if (window.ResizeObserver) { const ro = new ResizeObserver(es => es.forEach(e => scaleLayer(e.target))); layered.forEach(l => ro.observe(l)); }
else addEventListener('resize', () => layered.forEach(scaleLayer));

// --- The child's drawing in the frame --------------------------------------------------------------------------------
// The first time page 6 is seen in this visit, the drawing draws itself in the frame (dibujo-vivo.js, way 1: the photo
// uncovered stroke by stroke); then it stays still. Touching it opens it large, where it draws itself again and then
// trembles a little, as hand-drawn animation does. With «reduce motion», or if anything fails, the drawing is simply
// there, still.
const ALF = BOOK.pages.findIndex(p => p.frame);
const dlg = $('#ver');
let drawImg = null, drawReady = null, frameP = null, bigP = null, drawnOnce = false;
function stillOn(cv) { const c = cv.getContext('2d'); c.clearRect(0, 0, cv.width, cv.height); c.drawImage(drawImg, 0, 0, cv.width, cv.height); }
function playerFor(cv, after) {
  if (!window.DibujoVivo) return null;
  try { return new DibujoVivo.Player(cv, drawImg, DibujoVivo.fromJSON(BOOK.drawing.strokes), { after }); }
  catch (e) { return null; }
}
function prepareDrawing() {
  if (!BOOK.drawing || ALF < 0) return Promise.resolve(false);
  if (drawReady) return drawReady;
  drawImg = new Image();
  drawImg.src = BOOK.drawing.src;
  const cv = pages[ALF].querySelector('canvas.dibujo');
  drawReady = (drawImg.decode ? drawImg.decode() : new Promise((ok, ko) => { drawImg.onload = ok; drawImg.onerror = ko; }))
    .then(() => {
      frameP = playerFor(cv, 'still');
      if (frameP && !drawnOnce) frameP.renderAt(0);      // a blank sheet in the frame until the page is seen
      else stillOn(cv);
      return true;
    }).catch(() => false);
  return drawReady;
}
function drawInFrame() {
  if (drawnOnce || !BOOK.drawing) return;
  drawnOnce = true;
  prepareDrawing().then(ok => { if (ok && frameP) setTimeout(() => { if (at === ALF) frameP.play(); else frameP.renderAt(1e9); }, 650); });
}
function openDrawing() {
  if (!BOOK.drawing) return;
  react('dibujo-ampliado', BOOK.pages[ALF].n);
  if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
  prepareDrawing().then(ok => {
    if (!ok) return;
    const cv = $('#ver-lienzo');
    if (!bigP) bigP = playerFor(cv, 'boil');
    if (bigP) bigP.play(); else stillOn(cv);
    // and the small one is finished, whatever it was doing
    if (frameP) { frameP.stop(); frameP.renderAt(1e9); }
  });
}
function closeDrawing() { if (dlg.open) { dlg.close ? dlg.close() : dlg.removeAttribute('open'); } }
dlg.addEventListener('close', () => { if (bigP) bigP.stop(); });
dlg.addEventListener('click', e => { if (e.target === dlg) closeDrawing(); });
$('#ver-cerrar').onclick = closeDrawing;
tira.addEventListener('click', e => { if (e.target.closest('button.marco')) openDrawing(); });

// --- Turning pages -------------------------------------------------------------------------------------------------
const puntos = $('#puntos');
// Left to right, the next page is on the right; right to left, on the left: SIDE turns «forward» into a direction.
const SIDE = RTL ? -1 : 1;
puntos.innerHTML = BOOK.pages.map(p => `<button type="button" aria-label="${pageName(p)}"></button>`).join('');
let at = 0;
const seen = new Set();
function show(i, opts = {}) {
  const from = at;
  at = Math.max(0, Math.min(last, i));
  // going back to a page already seen in this visit says something too
  if (!opts.restart && at < from && at > 0 && seen.has(at)) react('volver-a-pagina', BOOK.pages[at].n);
  seen.add(at);
  if (opts.instant) { tira.style.transition = 'none'; requestAnimationFrame(() => requestAnimationFrame(() => tira.style.transition = '')); }
  tira.style.transform = `translateX(${-100 * at * SIDE}%)`;
  pages.forEach((p, k) => { p.inert = k !== at; p.setAttribute('aria-hidden', k !== at); });
  [...puntos.children].forEach((b, k) => b.setAttribute('aria-current', k === at));
  $('#ant').disabled = at === 0; $('#sig').disabled = at === last;
  const p = BOOK.pages[at];
  $('#aviso').textContent = p.kind === 'cover' ? T.cover : p.kind === 'dedication' ? T.dedication_label : tr('page_n_of', { n: p.n, total: STORY });
  // Eguahe counts how far each family gets, once a page.
  try { window.dispatchEvent(new CustomEvent('eguahe:pagina', { detail: { pagina: p.n, i: at } })); } catch (e) {}
  if (at === ALF) drawInFrame();
  if (playing) { if (opts.door !== false && !audio.ended && from !== at) door(); playPage(); }
  else { setProgress(0); setPlaying(false); }
}
$('#ant').onclick = () => show(at - 1);
$('#sig').onclick = () => show(at + 1);
puntos.onclick = e => { const k = [...puntos.children].indexOf(e.target); if (k >= 0) show(k); };
document.addEventListener('keydown', e => {
  if (dlg.open || e.target.matches('input')) return;
  const fwd = RTL ? 'ArrowLeft' : 'ArrowRight', back = RTL ? 'ArrowRight' : 'ArrowLeft';
  if (e.key === fwd || e.key === 'PageDown') { show(at + 1); e.preventDefault(); }
  if (e.key === back || e.key === 'PageUp') { show(at - 1); e.preventDefault(); }
  if (e.key === 'Home') show(0);
  if (e.key === 'End') show(last);
});

// Swipe with a finger (or drag with the mouse): the page follows, and past a third of a thumb it turns.
const libro = $('#libro');
let drag = null;
libro.addEventListener('pointerdown', e => {
  if (e.button !== 0 || e.target.closest('button, a, input')) return;
  drag = { x: e.clientX, y: e.clientY, dx: 0, on: false, id: e.pointerId, t: performance.now() };
});
libro.addEventListener('pointermove', e => {
  if (!drag || e.pointerId !== drag.id) return;
  const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
  if (!drag.on) {
    if (Math.abs(dx) < 8 || Math.abs(dx) < Math.abs(dy)) { if (Math.abs(dy) > 12) drag = null; return; }
    drag.on = true; libro.setPointerCapture(e.pointerId); tira.style.transition = 'none';
  }
  const ahead = -dx * SIDE;     // > 0 when the finger pulls the next page in
  const edge = (at === 0 && ahead < 0) || (at === last && ahead > 0) ? .3 : 1;
  drag.dx = dx * edge;
  tira.style.transform = `translateX(calc(${-100 * at * SIDE}% + ${drag.dx}px))`;
});
function endDrag(e) {
  if (!drag || e.pointerId !== drag.id) return;
  const d = drag; drag = null;
  if (!d.on) return;
  tira.style.transition = '';
  const fast = Math.abs(d.dx) / (performance.now() - d.t) > .45;
  const ahead = -d.dx * SIDE;
  if (ahead > 60 || (fast && ahead > 20)) show(at + 1);
  else if (ahead < -60 || (fast && ahead < -20)) show(at - 1);
  else show(at);
}
libro.addEventListener('pointerup', endDrag);
libro.addEventListener('pointercancel', endDrag);

// --- Listening -----------------------------------------------------------------------------------------------------
// One voice per page. While listening, the book turns by itself at the end of each page and reads the next one, and a
// little song plays under it (Web Audio): it ducks while the voice speaks and comes back in the pauses, and the door of
// Eguahe sounds its two notes when the page turns. The dedication has no voice: the song alone, for a few seconds, and
// then the door closes the book and the song fades. The song is inside the page (base64), so it works from a file.
const audio = new Audio();
audio.preload = 'auto';
let playing = false, finished = false, nextTimer = 0, doorTimer = 0, endTimer = 0, endTick = 0;
const ENDING = 9000;          // the song alone over the dedication, in ms
const heard = new Set();      // pages whose voice was heard to the end in this reading
let repeatArmed = !!reactState()['escuchado-entero:null'];
const vol = $('#vol');
const level = () => vol.value / 100;
audio.volume = level();
const M = BOOK.music;
// The song sits 11 dB under the voice (M.bedDb is set for the Spanish voice; another voice moves it by its own level)
const SHIFT = VOICE && VOICE.lufs != null ? VOICE.lufs - M.voiceLufs : 0;
const BED = Math.pow(10, (M.bedDb + SHIFT) / 20), DUCK = Math.pow(10, M.duckDb / 20), DOOR = Math.pow(10, (M.doorDb + SHIFT) / 20);
let ctx = null, bedGain = null, doorGain = null, bedBuf = null, doorBuf = null, bedSrc = null, ready = null;
const musica = $('#musica');
// Without a voice the song is what the button plays, so it is always on.
let musicOn = !VOICE || stored('cuento-musica') !== 'no';
musica.setAttribute('aria-pressed', musicOn);
function decode(url) {
  // The song and the door, from the plugin: fetched once the reader taps.
  return fetch(url, { credentials: 'same-origin' }).then(r => r.arrayBuffer())
    .then(b => new Promise((ok, ko) => ctx.decodeAudioData(b, ok, ko)));
}
function sound() {
  // Only after a tap (browsers keep audio quiet until then); a browser without Web Audio simply reads without the song.
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) return Promise.resolve(false);
  if (!ctx) {
    ctx = new AC();
    bedGain = ctx.createGain(); bedGain.gain.value = 0; bedGain.connect(ctx.destination);
    doorGain = ctx.createGain(); doorGain.connect(ctx.destination);
    ready = Promise.all([decode(M.loop), decode(M.door)]).then(([a, b]) => { bedBuf = a; doorBuf = b; return true; })
      .catch(() => false);
  }
  if (ctx.state === 'suspended') ctx.resume();
  return ready;
}
function startBed() {
  if (bedSrc || !bedBuf) return;
  bedSrc = ctx.createBufferSource();
  bedSrc.buffer = bedBuf; bedSrc.loop = true;
  // The loop lies between pre and pre + loop seconds; the file has some of its end before it and of its start after it
  bedSrc.loopStart = M.pre; bedSrc.loopEnd = M.pre + M.loopSeconds;
  bedSrc.connect(bedGain);
  bedSrc.start(0, M.pre);
}
function speaking() {
  // read aloud at home: the song stays down as if someone were reading, except over the dedication, where it sings alone
  if (!VOICE) return playing && !BOOK.pages[at].kind;
  if (audio.paused || !audio.duration) return false;
  const t = audio.currentTime, sp = VOICE.speech[BOOK.pages[at].audio] || [];
  return sp.some(([a, b]) => t >= a - .05 && t <= b + .12);
}
function bedTarget() { return musicOn && playing ? level() * BED * (speaking() ? DUCK : 1) : 0; }
let lastTarget = -1;
function follow() {
  if (ctx && bedGain) {
    const tgt = bedTarget();
    if (tgt !== lastTarget) {
      // down quickly when she speaks, back up slowly in the pause (the song's own sidechain: 80 ms and 350 ms); and when
      // the reading stops or the book ends, a slow fade of about two seconds, never a stop
      // (switched off with its button: a short fade, a quarter of a second)
      bedGain.gain.setTargetAtTime(tgt, ctx.currentTime, !musicOn ? .08 : tgt === 0 ? .6 : tgt < lastTarget ? .03 : .12);
      lastTarget = tgt;
    }
  }
  requestAnimationFrame(follow);
}
requestAnimationFrame(follow);
let doors = 0;
function door() {
  if (!ctx || !doorBuf || !musicOn) return;
  doors++;
  const s = ctx.createBufferSource();
  s.buffer = doorBuf; s.connect(doorGain);
  doorGain.gain.value = level() * DOOR;
  s.start();
}
musica.onclick = () => {
  musicOn = !musicOn;
  musica.setAttribute('aria-pressed', musicOn);
  store('cuento-musica', musicOn ? undefined : 'no');
  if (musicOn && playing) sound().then(ok => ok && startBed());
};
vol.oninput = () => { audio.volume = level(); $('#vol-texto').textContent = fill(T.volume_value, { n: vol.value }); lastTarget = -1; };
const oir = $('#oir');
function setProgress(f) { oir.style.setProperty('--prog', f); }
function setPlaying(on) {
  playing = on;
  oir.setAttribute('aria-pressed', on);
  $('#oir-texto').textContent = on ? T.pause : (at === last && finished ? T.again : VOICE ? T.listen : T.read_with_music);
}
function clearTimers() { clearTimeout(nextTimer); clearTimeout(doorTimer); clearTimeout(endTimer); clearInterval(endTick); }
function playPage() {
  clearTimers();
  const p = BOOK.pages[at];
  if (!p.audio) { audio.pause(); return ending(); }
  if (!VOICE) return;          // no voice: the song plays and the pages are turned by hand
  const src = `${VOICE.audio}/${p.audio}.mp3`;
  if (!audio.src.endsWith(src)) { audio.src = src; setProgress(0); }
  audio.play().catch(() => setPlaying(false));
}
function ending() {
  // The dedication: no voice, the song alone, and the door closes it.
  const t0 = performance.now();
  setProgress(0);
  endTick = setInterval(() => setProgress(Math.min(1, (performance.now() - t0) / ENDING)), 100);
  endTimer = setTimeout(() => {
    clearInterval(endTick);
    door();
    finished = true;
    setPlaying(false); setProgress(1);
    if (BOOK.pages.every((q, k) => !q.audio || k === 0 || heard.has(k))) { react('escuchado-entero', null); repeatArmed = true; }
  }, ENDING);
}
function startReading(from) {
  if (from != null) show(from, { restart: true });
  if (at <= 1) {
    heard.clear();
    if (repeatArmed) { repeatArmed = false; const n = count('repetido'); react('repetido', null, { vez: n }, 'repetido:' + n); }
  }
  finished = false;
  setPlaying(true); playPage();
  sound().then(ok => ok && startBed());
}
function stopReading() { audio.pause(); clearTimers(); setPlaying(false); }
oir.onclick = () => {
  if (playing) return stopReading();
  startReading(at === last && finished ? 0 : null);
};
audio.ontimeupdate = () => { if (audio.duration) setProgress(audio.currentTime / audio.duration); };
audio.onended = () => {
  setProgress(1);
  if (!playing) return;
  heard.add(at);
  if (at < last) {
    doorTimer = setTimeout(door, 250);
    nextTimer = setTimeout(() => show(at + 1), 1100);
  } else setPlaying(false);
};

// --- Hearts and the gestures of the end ------------------------------------------------------------------------------
function beat(el) { el.classList.remove('late'); void el.offsetWidth; el.classList.add('late'); }
function say(text) {
  const el = $('#gestos-aviso');
  el.textContent = text;
  clearTimeout(say.t); say.t = setTimeout(() => { el.textContent = ''; }, 7000);
}
async function copyLink() {
  let ok = false;
  try { await navigator.clipboard.writeText(BOOK.share); ok = true; } catch (e) {
    const t = document.createElement('textarea');
    t.value = BOOK.share; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { ok = document.execCommand('copy'); } catch (e2) {}
    t.remove();
  }
  say(ok ? T.link_copied : tr('link_here', { url: BOOK.share }));
  return ok;
}
async function sendIt() {
  const data = { title: tr('share_title'), text: tr('share_text'), url: BOOK.share };
  let via = null;
  if (navigator.share) {
    try { await navigator.share(data); via = 'compartir'; } catch (e) { if (e && e.name === 'AbortError') return; }
  }
  if (!via && await copyLink()) via = 'copiar';
  if (via) { const n = count('mandar'); react('mandar', BOOK.pages[last].n, { via, vez: n }, 'mandar:' + n); }
}
tira.addEventListener('click', e => {
  const h = e.target.closest('.corazon');
  if (h) {
    const n = +h.dataset.pagina, on = h.getAttribute('aria-pressed') !== 'true';
    h.setAttribute('aria-pressed', on);
    if (on) { react('me-gusta', n); beat(h); }
    else { unreact('me-gusta:' + n); announce('me-gusta-quitado', n); }
    return;
  }
  const g = e.target.closest('[data-gesto]');
  if (!g) return;
  const n = BOOK.pages[last].n;
  if (g.dataset.gesto === 'volver-a-escuchar') { react('volver-a-escuchar', n); startReading(0); }
  else if (g.dataset.gesto === 'guardar') { react('guardar', n); }
  else if (g.dataset.gesto === 'mandar') { sendIt(); }
});

// --- Light and dark ------------------------------------------------------------------------------------------------
const root = document.documentElement;
const prefersDark = matchMedia('(prefers-color-scheme: dark)');
function store(k, v) { try { v === undefined ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} }
function stored(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
const themeWanted = { claro: 'light', oscuro: 'dark' }[params.get('tema')] || stored('cuento-tema');
if (themeWanted) root.dataset.theme = themeWanted;
$('.tema').onclick = () => {
  const dark = root.dataset.theme ? root.dataset.theme === 'dark' : prefersDark.matches;
  root.dataset.theme = dark ? 'light' : 'dark';
  store('cuento-tema', root.dataset.theme);
};
if (!root.dataset.theme && prefersDark.matches) root.dataset.theme = 'dark';
prefersDark.addEventListener && prefersDark.addEventListener('change', e => {
  if (!stored('cuento-tema')) root.dataset.theme = e.matches ? 'dark' : 'light';
});

show(+params.get('p') || 0, { instant: true });
setTimeout(prepareDrawing, 400);
// For the screenshots, the video and the tests: show a page as if it were being read, without sound.
window.cuento = { show, setProgress, audio, openDrawing, closeDrawing, state: () => ({ ctx: ctx && ctx.state, bed: bedGain && bedGain.gain.value, target: bedTarget(),
  musicOn, playing, finished, at, speaking: speaking(), bedLoaded: !!bedBuf, doorLoaded: !!doorBuf, src: audio.src,
  drawing: !!frameP, drawnOnce, heard: [...heard], doors, voice: VOICE_NAME, lang: LANG, maestro: MAESTRO, sex: SEX }),
  pose(on) { oir.setAttribute('aria-pressed', on); $('#oir-texto').textContent = on ? T.pause : VOICE ? T.listen : T.read_with_music; } };
