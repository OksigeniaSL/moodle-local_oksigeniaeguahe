// This file is part of Moodle - https://moodle.org/ (GNU GPL v3 or later).

/**
 * «Tu primer día», the welcome story of Eguahe (Gaudí, 8 November 2026): the pages turned by finger or arrow keys, the
 * child's name written over each illustration, their drawing from the visit hung on the wall of the class, the voice
 * of each page with a little song under it, a handwritten dedication, and quiet reactions announced as
 * `eguahe:reaccion` for Eguahe to count. Its data and its words come from the page (#libro-datos).
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
'use strict';
const BOOK = JSON.parse(document.getElementById('libro-datos').textContent);
const T = BOOK.str;
const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const STORY = BOOK.pages.filter(p => !p.kind).length;
// The reading: with the child's name in the voice when there is one, or without it (?voz=sin-nombre; the reading of
// Eguahe's first release). The written text always carries the name.
const VOICE_NAME = new URLSearchParams(location.search).get('voz') === 'sin-nombre' || !BOOK.voices.nombre ? 'sin-nombre' : 'nombre';
const VOICE = BOOK.voices[VOICE_NAME];
const pageName = p => p.kind === 'cover' ? T.cover : p.kind === 'dedication' ? T.dedication : T.page.replace('{n}', p.n);

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
  const detail = Object.assign({ libro: BOOK.book, pagina, tipo, voz: VOICE_NAME }, extra || {});
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
const heartHtml = n => `<button class="corazon" type="button" data-pagina="${n}" aria-pressed="${!!reactState()['me-gusta:' + n]}" aria-label="${esc(T.liked)}" title="${esc(T.liked)}">${HEART}</button>`;

// --- Pages ---------------------------------------------------------------------------------------------------------
// The last two words of a line stay together, so no line of the book ends with a word on its own.
const lineHtml = l => esc(l.replace(/ (\S+)$/, '\u00a0$1')).replace('{nombre}', `<span class="nombre">${esc(BOOK.child)}</span>`)
  .replace('{centro}', esc(BOOK.centrePhrase));

function coverHtml() {
  // The first-day sticker of Eguahe, with the child's name on its line (geometry of kit/marca-eguahe/etiqueta.py).
  return `<section class="pagina portada" data-i="0" aria-label="${esc(T.cover)}">
    <h1 aria-label="${esc(T.titlechild)}"><span class="antes" aria-hidden="true">${esc(T.title)}</span>
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
    const label = T.enlarge;
    return `<div class="capa"><button class="marco" type="button" aria-label="${esc(label)}" title="${esc(label)}" style="${style}">
      <span class="hoja"><canvas class="dibujo" width="300" height="400"></canvas></span></button></div>`;
  }
  // Without a drawing from the visit, never an empty frame: one of the other drawings of the class hangs there, still.
  return `<div class="capa" aria-hidden="true"><span class="marco sin-dibujo" style="${style}"><span class="hoja">` +
    `<img src="${BOOK.classDrawing}" alt=""></span></span></div>`;
}

function pageHtml(p, i) {
  const names = (p.names || []).map(n => `<text class="${n.kind}" x="${n.x}" y="${n.y}" font-size="${n.size}"
      text-anchor="middle" data-w="${n.w}" transform="rotate(${n.rot || 0} ${n.x} ${n.y})${n.skew ? ` skewX(${n.skew})` : ''}"
      ${n.kind === 'chalk' ? 'fill="#F4F1E8" filter="url(#tiza)"' : 'fill="#2B2226" filter="url(#boli)"'}
      font-family="Caveat" font-weight="${n.kind === 'chalk' ? 600 : 650}">${esc(n.text || (n.pre || '') + BOOK.child + (n.post || ''))}</text>`).join('');
  return `<section class="pagina" data-i="${i}" aria-label="${esc(T.pageof.replace('{n}', p.n).replace('{of}', STORY))}">
    <figure class="lamina">
      <svg viewBox="0 0 1536 1024" role="img" aria-label="${esc(p.alt || '')}">
        <image href="${BOOK.base}ilustraciones/${p.img}.webp" width="1536" height="1024" preserveAspectRatio="xMidYMid slice"/>
        ${names}
      </svg>
      ${p.frame ? frameHtml(p) : ''}
      ${heartHtml(p.n)}
    </figure>
    <p class="texto">${p.text.map(l => `<span class="l">${lineHtml(l)}</span>`).join('')}</p>
  </section>`;
}

const ICON = {
  otra: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.6 12.4a7.5 7.5 0 1 0 2.3-5.6"/><path d="M5.6 3.8v3.6h3.6"/></svg>',
  guardar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v10.5"/><path d="M7.6 10.4 12 14.8l4.4-4.4"/><path d="M5 16.8v1.9c0 .7.6 1.3 1.3 1.3h11.4c.7 0 1.3-.6 1.3-1.3v-1.9"/></svg>',
  mandar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.4 3.8 3.7 10.4l6.7 2.9 2.9 6.7 7.1-16.2Z"/><path d="m10.4 13.3 4.4-4.4"/></svg>',
};
function dedicationHtml(p, i) {
  const save = VOICE.download
    ? `<a class="gesto" data-gesto="guardar" href="${esc(VOICE.download.href)}" download="${esc(VOICE.download.name)}" target="_blank" rel="noopener">${ICON.guardar}<span>${esc(T.save)}</span></a>`
    : `<button class="gesto" type="button" data-gesto="guardar">${ICON.guardar}<span>${esc(T.save)}</span></button>`;
  return `<section class="pagina dedicatoria" data-i="${i}" aria-label="${esc(T.dedication)}">
    <div class="hoja-dedicatoria">
      <img class="manos" src="${BOOK.base}ilustraciones/${p.img}.webp" alt="${esc(p.alt || '')}" width="1024" height="1024">
      <p class="dedica">${p.lines.map(l => `<span class="l">${lineHtml(l).replace('class="nombre"', 'class="nombre-mano"')}</span>`).join('')}</p>
      ${heartHtml(p.n)}
    </div>
    <div class="gestos">
      <button class="gesto" type="button" data-gesto="volver-a-escuchar">${ICON.otra}<span>${esc(T.again)}</span></button>
      ${save}
      <button class="gesto" type="button" data-gesto="mandar">${ICON.mandar}<span>${esc(T.send)}</span></button>
    </div>
    <p class="gestos-aviso" id="gestos-aviso" aria-live="polite"></p>
  </section>`;
}

const tira = $('#tira');
tira.innerHTML = BOOK.pages.map((p, i) => p.kind === 'cover' ? coverHtml() : p.kind === 'dedication' ? dedicationHtml(p, i) : pageHtml(p, i)).join('');
const pages = [...tira.children];
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
  tira.style.transform = `translateX(${-100 * at}%)`;
  pages.forEach((p, k) => { p.inert = k !== at; p.setAttribute('aria-hidden', k !== at); });
  [...puntos.children].forEach((b, k) => b.setAttribute('aria-current', k === at));
  $('#ant').disabled = at === 0; $('#sig').disabled = at === last;
  const p = BOOK.pages[at];
  $('#aviso').textContent = p.kind === 'cover' ? T.cover : p.kind === 'dedication' ? T.dedication :
    T.pageof.replace('{n}', p.n).replace('{of}', STORY);
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
  if (e.key === 'ArrowRight' || e.key === 'PageDown') { show(at + 1); e.preventDefault(); }
  if (e.key === 'ArrowLeft' || e.key === 'PageUp') { show(at - 1); e.preventDefault(); }
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
  const edge = (at === 0 && dx > 0) || (at === last && dx < 0) ? .3 : 1;
  drag.dx = dx * edge;
  tira.style.transform = `translateX(calc(${-100 * at}% + ${drag.dx}px))`;
});
function endDrag(e) {
  if (!drag || e.pointerId !== drag.id) return;
  const d = drag; drag = null;
  if (!d.on) return;
  tira.style.transition = '';
  const fast = Math.abs(d.dx) / (performance.now() - d.t) > .45;
  if (d.dx < -60 || (fast && d.dx < -20)) show(at + 1);
  else if (d.dx > 60 || (fast && d.dx > 20)) show(at - 1);
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
const BED = Math.pow(10, M.bedDb / 20), DUCK = Math.pow(10, M.duckDb / 20), DOOR = Math.pow(10, M.doorDb / 20);
let ctx = null, bedGain = null, doorGain = null, bedBuf = null, doorBuf = null, bedSrc = null, ready = null;
const musica = $('#musica');
let musicOn = stored('cuento-musica') !== 'no';
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
vol.oninput = () => { audio.volume = level(); $('#vol-texto').textContent = T.percent.replace('{n}', vol.value); lastTarget = -1; };
const oir = $('#oir');
function setProgress(f) { oir.style.setProperty('--prog', f); }
function setPlaying(on) {
  playing = on;
  oir.setAttribute('aria-pressed', on);
  $('#oir-texto').textContent = on ? T.pause : (at === last && finished ? T.otravez : T.listen);
}
function clearTimers() { clearTimeout(nextTimer); clearTimeout(doorTimer); clearTimeout(endTimer); clearInterval(endTick); }
function playPage() {
  clearTimers();
  const p = BOOK.pages[at];
  if (!p.audio) { audio.pause(); return ending(); }
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
  say(ok ? T.copied : T.thelink.replace('{url}', BOOK.share));
  return ok;
}
async function sendIt() {
  const data = { title: T.titlechild, text: T.sharetext, url: BOOK.share };
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
const params = new URLSearchParams(location.search);
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
  drawing: !!frameP, drawnOnce, heard: [...heard], doors, voice: VOICE_NAME }), pose(on) { oir.setAttribute('aria-pressed', on); $('#oir-texto').textContent = on ? T.pause : T.listen; } };
