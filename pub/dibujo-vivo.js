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

// The drawing that draws itself, by the art director of Oksigenia (8 November 2026), as delivered.
// @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
// @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/**
 * dibujo-vivo.js: a child's drawing, photographed on paper, draws itself (way 1 of the art note «Eguahe: el dibujo
 * del niño, animado»). Plain JS, no dependencies, canvas 2D only. Two parts:
 *
 *   var a = DibujoVivo.analyze(imgOrCanvas);          // ink from paper, strokes and their order (once per drawing)
 *   var json = DibujoVivo.toJSON(a);                    // what is worth keeping beside the photo (a few KB)
 *   var a2 = DibujoVivo.fromJSON(json);                 // ... and back, without analysing again
 *   var p = new DibujoVivo.Player(canvas, img, a, {after: 'boil'});   // or 'still'
 *   p.play();  // or p.renderAt(seconds) for a recorder, frame by frame
 *
 * How the analysis goes (all at a working size, longest side `work`, 640 px by default):
 *  1. The paper: in blocks of 16 px, the colour of the brightest pixels; blocks that are mostly ink (much darker or
 *     more coloured than the sheet as a whole) take their neighbours' colour. Smoothed, it is the light over the sheet.
 *  2. The ink: each pixel divided by its paper (white balance and shading out at once); ink strength is how far the
 *     weakest channel falls below the paper (so yellow crayon counts: blue drops). Two thresholds from the noise of the
 *     paper, with hysteresis; a small closing joins the gaps of wax crayon; specks go.
 *  3. Thick ink (coloured-in areas, more than ~6 mm across) is filled back and forth like colouring in; the rest is
 *     thinned to its centre line (Zhang-Suen), cut into a graph and joined across crossings by good continuation, so a
 *     round head stays one stroke and a leg leaving it is another.
 *  4. Colours by k-means in Lab; strokes go colour by colour, darkest first, outlines before colouring in, each one
 *     from the nearest end to where the last one ended, so the pen wanders as a child's does.
 * The player reveals the photo itself through a brush that follows the strokes (radius from the distance transform),
 * over a clean sheet (the photo with the ink near the strokes painted out with the paper), so what appears is exactly
 * what the child drew. At the end, the ink the strokes missed near them fades in slowly; the rest of the photo never
 * changed, so the last frame is the same sheet with all its ink, with no jump. Then it stays still or boils:
 * three slightly warped copies of the ink, cycled at ~7 fps, like hand-drawn animation. Reduced motion (the media
 * query or data-mov="menos" on <html>) shows the drawing at once, still.
 */
(function (root) {
  'use strict';

  var now = function () { return (root.performance && performance.now) ? performance.now() : Date.now(); };

  // ---------------------------------------------------------------------------------------------- small helpers --
  function rng(seed) {
    var s = seed >>> 0 || 1;
    return function () { s ^= s << 13; s >>>= 0; s ^= s >>> 17; s ^= s << 5; s >>>= 0; return s / 4294967296; };
  }
  function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
  function smooth(a, b, v) { var t = clamp((v - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); }

  function canvasOf(w, h) {
    var c = (root.document ? document.createElement('canvas') : new OffscreenCanvas(w, h));
    c.width = w; c.height = h;
    return c;
  }

  // sRGB 0..1 -> Lab (D65)
  function lin(c) { return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
  function lab(r, g, b) {
    r = lin(r); g = lin(g); b = lin(b);
    var x = (r * 0.4124 + g * 0.3576 + b * 0.1805) / 0.95047, y = r * 0.2126 + g * 0.7152 + b * 0.0722,
      z = (r * 0.0193 + g * 0.1192 + b * 0.9505) / 1.08883;
    var f = function (t) { return t > 0.008856 ? Math.cbrt(t) : 7.787 * t + 16 / 116; };
    var fx = f(x), fy = f(y), fz = f(z);
    return [116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)];
  }

  // Exact Euclidean distance transform (Felzenszwalb & Huttenlocher): distance from each pixel of `mask` to the
  // nearest pixel outside it.
  function edt(mask, W, H) {
    var INF = 1e20, f = new Float64Array(Math.max(W, H)), d = new Float64Array(Math.max(W, H)),
      v = new Int32Array(Math.max(W, H)), z = new Float64Array(Math.max(W, H) + 1), g = new Float64Array(W * H);
    var i, x, y;
    for (i = 0; i < W * H; i++) { g[i] = mask[i] ? INF : 0; }
    function pass(n) {
      var k = 0, q, s;
      v[0] = 0; z[0] = -INF; z[1] = INF;
      for (q = 1; q < n; q++) {
        s = ((f[q] + q * q) - (f[v[k]] + v[k] * v[k])) / (2 * q - 2 * v[k]);
        while (s <= z[k]) { k--; s = ((f[q] + q * q) - (f[v[k]] + v[k] * v[k])) / (2 * q - 2 * v[k]); }
        k++; v[k] = q; z[k] = s; z[k + 1] = INF;
      }
      k = 0;
      for (q = 0; q < n; q++) { while (z[k + 1] < q) { k++; } d[q] = (q - v[k]) * (q - v[k]) + f[v[k]]; }
    }
    for (x = 0; x < W; x++) {
      for (y = 0; y < H; y++) { f[y] = g[y * W + x]; }
      pass(H);
      for (y = 0; y < H; y++) { g[y * W + x] = d[y]; }
    }
    for (y = 0; y < H; y++) {
      for (x = 0; x < W; x++) { f[x] = g[y * W + x]; }
      pass(W);
      for (x = 0; x < W; x++) { g[y * W + x] = Math.sqrt(d[x]); }
    }
    return g;
  }

  // Binary dilation / erosion with a square of radius r (separable running max).
  function morph(mask, W, H, r, erode) {
    var tmp = new Uint8Array(W * H), out = new Uint8Array(W * H), x, y, k, on = erode ? 0 : 1;
    for (y = 0; y < H; y++) {
      for (x = 0; x < W; x++) {
        var v = erode ? 1 : 0;
        for (k = -r; k <= r; k++) {
          var xx = x + k;
          var m = (xx < 0 || xx >= W) ? (erode ? 0 : 0) : mask[y * W + xx];
          if (m === on) { v = on; break; }
        }
        tmp[y * W + x] = v;
      }
    }
    for (y = 0; y < H; y++) {
      for (x = 0; x < W; x++) {
        var w = erode ? 1 : 0;
        for (k = -r; k <= r; k++) {
          var yy = y + k;
          var n = (yy < 0 || yy >= H) ? 0 : tmp[yy * W + x];
          if (n === on) { w = on; break; }
        }
        out[y * W + x] = w;
      }
    }
    return out;
  }

  // 8-connected components; returns labels (0 = none) and sizes.
  function components(mask, W, H) {
    var lab_ = new Int32Array(W * H), sizes = [0], stack = new Int32Array(W * H), n = 0, i;
    for (i = 0; i < W * H; i++) {
      if (!mask[i] || lab_[i]) { continue; }
      n++; var top = 0, size = 0; stack[top++] = i; lab_[i] = n;
      while (top) {
        var p = stack[--top], px = p % W, py = (p - px) / W; size++;
        for (var dy = -1; dy <= 1; dy++) {
          var yy = py + dy; if (yy < 0 || yy >= H) { continue; }
          for (var dx = -1; dx <= 1; dx++) {
            var xx = px + dx; if (xx < 0 || xx >= W) { continue; }
            var q = yy * W + xx;
            if (mask[q] && !lab_[q]) { lab_[q] = n; stack[top++] = q; }
          }
        }
      }
      sizes.push(size);
    }
    return {labels: lab_, sizes: sizes, count: n};
  }

  // Zhang-Suen thinning, in place (the border must be 0).
  function thin(m, W, H) {
    var list = [], i, k, iter = 0;
    for (i = 0; i < W * H; i++) { if (m[i]) { list.push(i); } }
    var changed = true;
    while (changed && iter < 400) {
      changed = false; iter++;
      for (var pass = 0; pass < 2; pass++) {
        var del = [];
        for (k = 0; k < list.length; k++) {
          i = list[k];
          if (!m[i]) { continue; }
          var p2 = m[i - W], p3 = m[i - W + 1], p4 = m[i + 1], p5 = m[i + W + 1], p6 = m[i + W], p7 = m[i + W - 1],
            p8 = m[i - 1], p9 = m[i - W - 1];
          var B = p2 + p3 + p4 + p5 + p6 + p7 + p8 + p9;
          if (B < 2 || B > 6) { continue; }
          var A = (!p2 && p3) + (!p3 && p4) + (!p4 && p5) + (!p5 && p6) + (!p6 && p7) + (!p7 && p8) + (!p8 && p9) +
            (!p9 && p2);
          if (A !== 1) { continue; }
          if (pass === 0 ? (p2 && p4 && p6) || (p4 && p6 && p8) : (p2 && p4 && p8) || (p2 && p6 && p8)) { continue; }
          del.push(i);
        }
        for (k = 0; k < del.length; k++) { m[del[k]] = 0; }
        if (del.length) { changed = true; }
      }
      var keep = [];
      for (k = 0; k < list.length; k++) { if (m[list[k]]) { keep.push(list[k]); } }
      list = keep;
    }
    return list;
  }

  // Ramer-Douglas-Peucker over [x, y, r] points.
  function simplify(pts, eps) {
    if (pts.length < 3) { return pts; }
    var keep = new Uint8Array(pts.length); keep[0] = keep[pts.length - 1] = 1;
    var stack = [[0, pts.length - 1]];
    while (stack.length) {
      var s = stack.pop(), a = s[0], b = s[1], ax = pts[a][0], ay = pts[a][1], bx = pts[b][0], by = pts[b][1];
      var dx = bx - ax, dy = by - ay, L = Math.hypot(dx, dy) || 1, best = -1, bi = -1;
      for (var i = a + 1; i < b; i++) {
        var d = Math.abs((pts[i][0] - ax) * dy - (pts[i][1] - ay) * dx) / L;
        // keep where the thickness changes too, so the brush follows a stroke that swells
        d = Math.max(d, Math.abs(pts[i][2] - (pts[a][2] + (pts[b][2] - pts[a][2]) * (i - a) / (b - a))) * 0.6);
        if (d > best) { best = d; bi = i; }
      }
      if (best > eps) { keep[bi] = 1; stack.push([a, bi], [bi, b]); }
    }
    return pts.filter(function (_, i) { return keep[i]; });
  }

  function polyLength(p) {
    var L = 0;
    for (var i = 1; i < p.length; i++) { L += Math.hypot(p[i][0] - p[i - 1][0], p[i][1] - p[i - 1][1]); }
    return L;
  }

  // ------------------------------------------------------------------------------------------------- analysis --
  /**
   * The drawing from a photo of it.
   * @param {HTMLImageElement|HTMLCanvasElement|ImageBitmap} src
   * @param {Object} [o] work: longest side for the analysis (640); seconds: fixed drawing time (else 3 to 5 by
   *   how much there is to draw).
   */
  function analyze(src, o) {
    o = o || {};
    var t0 = now(), times = {};
    var sw = src.naturalWidth || src.videoWidth || src.width, sh = src.naturalHeight || src.videoHeight || src.height;
    var work = o.work || 640, sc = work / Math.max(sw, sh);
    var W = Math.round(sw * sc), H = Math.round(sh * sc), N = W * H;
    var cv = canvasOf(W, H), cx = cv.getContext('2d', {willReadFrequently: true});
    cx.imageSmoothingQuality = 'high';
    cx.drawImage(src, 0, 0, W, H);
    var px = cx.getImageData(0, 0, W, H).data;
    times.read = now() - t0;

    // 1. Paper, in blocks.
    var t1 = now();
    var paper = paperModel(px, W, H);
    times.paper = now() - t1;

    // 2. Ink strength and the mask.
    t1 = now();
    var s = new Float32Array(N), i, x, y;
    var P = paperAt(paper, W, H);
    // on a 3 x 3 average: the grain of the paper drops a lot, a line of pencil only a little
    var pb = boxBlur(px, W, H);
    for (i = 0; i < N; i++) {
      var r = pb[3 * i] / P[3 * i], g = pb[3 * i + 1] / P[3 * i + 1], b = pb[3 * i + 2] / P[3 * i + 2];
      s[i] = clamp(1 - Math.min(r, g, b), -0.5, 1);
    }
    // the noise of the paper, from the blocks that are paper and from the light side only: at least the brightest
    // 30 % of each of those blocks is paper, whatever ink there is in the rest
    var hist = new Uint32Array(1501), cnt = 0;
    for (i = 0; i < N; i++) {
      x = i % W; y = (i - x) / W;
      if (paper.valid[((y / paper.B) | 0) * paper.gw + ((x / paper.B) | 0)]) { hist[Math.round((s[i] + 0.5) * 1000)]++; cnt++; }
    }
    var q = function (frac) {
      var k = 0, acc = 0, goal = frac * cnt;
      while (k < 1500 && acc + hist[k] < goal) { acc += hist[k]; k++; }
      return k / 1000 - 0.5;
    };
    var q25 = q(0.25), sigma = Math.max(0.004, (q25 - q(0.05)) / 0.97), med = q25 + 0.674 * sigma;
    var lo = clamp(med + 3 * sigma, 0.04, 0.15), hi = clamp(med + 7 * sigma, 0.1, 0.26);
    for (i = 0; i < N; i++) { if (s[i] < 0) { s[i] = 0; } }
    var ink = new Uint8Array(N), strong = new Uint8Array(N);
    for (i = 0; i < N; i++) { if (s[i] > lo) { ink[i] = 1; } if (s[i] > hi) { strong[i] = 1; } }
    // hysteresis: weak ink only where it touches strong ink
    var comp = components(ink, W, H), keepC = new Uint8Array(comp.count + 1);
    for (i = 0; i < N; i++) { if (strong[i]) { keepC[comp.labels[i]] = 1; } }
    for (i = 0; i < N; i++) { ink[i] = keepC[comp.labels[i]]; }
    // the edge of the sheet: a sliver of table left by the cut is not ink
    var edge = Math.max(2, Math.round(Math.min(W, H) * 0.012));
    for (y = 0; y < H; y++) {
      for (x = 0; x < W; x++) { if (x < edge || y < edge || x >= W - edge || y >= H - edge) { ink[y * W + x] = 0; } }
    }
    // close the gaps of wax crayon, then drop specks
    var k1 = Math.max(1, Math.round(work / 640));
    ink = morph(morph(ink, W, H, k1, false), W, H, k1, true);
    comp = components(ink, W, H);
    var minArea = Math.round(14 * (work / 640) * (work / 640));
    for (i = 0; i < N; i++) { if (comp.sizes[comp.labels[i]] < minArea) { ink[i] = 0; } }
    for (i = 0; i < W; i++) { ink[i] = ink[N - W + i] = 0; }
    for (i = 0; i < H; i++) { ink[i * W] = ink[i * W + W - 1] = 0; }
    // the shadow in a corner of the photo: a patch nearly all in the outer band of the sheet, about as grey as paper
    comp = components(ink, W, H);
    var band = Math.round(Math.min(W, H) * 0.06), inBand = new Float64Array(comp.count + 1), chroma = new Float64Array(comp.count + 1);
    for (i = 0; i < N; i++) {
      var lc = comp.labels[i];
      if (!lc || !ink[i]) { continue; }
      x = i % W; y = (i - x) / W;
      if (x < band || y < band || x >= W - band || y >= H - band) { inBand[lc]++; }
      chroma[lc] += Math.max(pb[3 * i], pb[3 * i + 1], pb[3 * i + 2]) - Math.min(pb[3 * i], pb[3 * i + 1], pb[3 * i + 2]);
    }
    var gCh = paper.global[0] - paper.global[2];
    for (i = 0; i < N; i++) {
      var lk = comp.labels[i];
      if (lk && inBand[lk] > 0.7 * comp.sizes[lk] && chroma[lk] / comp.sizes[lk] < Math.abs(gCh) + 30) { ink[i] = 0; }
    }
    var inkCount = 0;
    for (i = 0; i < N; i++) { inkCount += ink[i]; }
    times.ink = now() - t1;

    // 3. Colours: a child draws crayon by crayon, so the ink is split by colour first (k-means in Lab over a sample,
    // each ink pixel to its nearest colour on the 3 x 3 average, then a 3 x 3 majority so edges do not make a layer).
    t1 = now();
    // The colours come from the strong ink only: the pale fringe of a black line would make a grey layer of its own
    // and every outline would be drawn twice. The fringe then takes the colour of the strong ink nearest to it.
    // The seeds are the inside of the ink (2 px or more from its edge); a drawing of thin pencil lines has no inside,
    // and then it is the ink above the high threshold.
    var dtInk = edt(ink, W, H), strongInk = new Uint8Array(N), seeds = 0;
    for (i = 0; i < N; i++) { if (ink[i] && dtInk[i] >= 2 * work / 640) { strongInk[i] = 1; seeds++; } }
    if (seeds < 300) { for (i = 0; i < N; i++) { strongInk[i] = ink[i] && s[i] > hi ? 1 : 0; } }
    var cols = colourLayers(pb, P, strongInk, W, H);
    var K = cols.centres.length, label = new Int8Array(N).fill(-1);
    for (i = 0; i < N; i++) {
      if (!strongInk[i]) { continue; }
      var L0 = lab(clamp(pb[3 * i] / P[3 * i], 0, 1), clamp(pb[3 * i + 1] / P[3 * i + 1], 0, 1), clamp(pb[3 * i + 2] / P[3 * i + 2], 0, 1));
      var bi = 0, bd = 1e9;
      for (var ci = 0; ci < K; ci++) { var dd = distw(L0, cols.centres[ci]); if (dd < bd) { bd = dd; bi = ci; } }
      label[i] = bi;
    }
    var label2 = label.slice(), votes = new Int32Array(K);
    for (y = 1; y < H - 1; y++) {
      for (x = 1; x < W - 1; x++) {
        i = y * W + x;
        if (label[i] < 0) { continue; }
        votes.fill(0);
        for (var dy = -1; dy <= 1; dy++) { for (var dx = -1; dx <= 1; dx++) { var lb = label[i + dy * W + dx]; if (lb >= 0) { votes[lb]++; } } }
        var top = label[i];
        for (ci = 0; ci < K; ci++) { if (votes[ci] > votes[top]) { top = ci; } }
        label2[i] = top;
      }
    }
    label = label2;
    // the fringe: breadth first from the strong ink, through the ink
    var queue = new Int32Array(N), qh = 0, qt = 0;
    for (i = 0; i < N; i++) { if (label[i] >= 0) { queue[qt++] = i; } }
    while (qh < qt) {
      var pq = queue[qh++], pxq = pq % W;
      var nb = [pq - W, pq + W, pxq > 0 ? pq - 1 : -1, pxq < W - 1 ? pq + 1 : -1];
      for (var ni = 0; ni < 4; ni++) {
        var qn = nb[ni];
        if (qn >= 0 && qn < N && ink[qn] && label[qn] < 0) { label[qn] = label[pq]; queue[qt++] = qn; }
      }
    }
    times.colour = now() - t1;

    // 4. In each colour: thick ink (coloured in) apart from lines; lines thinned to their centre and joined into
    // strokes, thick ink filled back and forth.
    var fillR = 6 * work / 640, strokes = [], dt = new Float32Array(N);
    times.thick = times.thin = times.trace = times.hatch = 0;
    for (ci = 0; ci < K; ci++) {
      t1 = now();
      var m = new Uint8Array(N);
      for (i = 0; i < N; i++) { m[i] = label[i] === ci ? 1 : 0; }
      m = morph(morph(m, W, H, 1, false), W, H, 1, true);
      var cc = components(m, W, H);
      for (i = 0; i < N; i++) { if (cc.sizes[cc.labels[i]] < minArea) { m[i] = 0; } }
      for (i = 0; i < W; i++) { m[i] = m[N - W + i] = 0; }
      for (i = 0; i < H; i++) { m[i * W] = m[i * W + W - 1] = 0; }
      // coloured in with crayon is full of little holes of paper: thickness is measured on the ink with them shut
      var shut = morph(morph(m, W, H, Math.round(3 * work / 640), false), W, H, Math.round(3 * work / 640), true);
      var dShut = edt(shut, W, H), d = edt(m, W, H), core = new Uint8Array(N);
      for (i = 0; i < N; i++) { core[i] = dShut[i] > fillR ? 1 : 0; if (d[i] > dt[i]) { dt[i] = d[i]; } }
      var fill = morph(core, W, H, Math.ceil(fillR) + 1, false);
      // a line of wax crayon is broken by the grain of the paper: its centre line is taken with the gaps shut, so it is
      // one line that grows and not a row of dashes; the brush takes its width from that line, a little wider
      var lineK = Math.max(1, Math.round(2 * work / 640));
      var lineShut = morph(morph(m, W, H, lineK, false), W, H, lineK, true);
      var thinMask = new Uint8Array(N), fillMask = new Uint8Array(N);
      for (i = 0; i < N; i++) {
        if (fill[i] && shut[i]) { fillMask[i] = 1; } else if (lineShut[i] && !fill[i]) { thinMask[i] = 1; }
      }
      for (i = 0; i < W; i++) { thinMask[i] = thinMask[N - W + i] = 0; }
      for (i = 0; i < H; i++) { thinMask[i * W] = thinMask[i * W + W - 1] = 0; }
      var dLine = edt(thinMask, W, H);
      times.thick += now() - t1;
      t1 = now();
      var skelList = thin(thinMask, W, H);
      times.thin += now() - t1;
      t1 = now();
      var hatches = hatchFills(fillMask, W, H, work);
      times.hatch += now() - t1;
      t1 = now();
      // the bits of line inside a coloured-in area are drawn by its colouring, not before it as loose specks
      var zone = hatches.length ? morph(fillMask, W, H, Math.ceil(9 * work / 640), false) : fillMask;
      // a closed outline (a head, a window, the walls of a house) goes round its hole of paper in one go, from the top
      var rings = closedRings(lineShut, dLine, W, H, work, o.debug ? (o.debug.rings = o.debug.rings || []) : null);   // with its coloured-in bits: hair over a head does not open it
      var lines = cutOut(traceStrokes(thinMask, skelList, dLine, W, H, work), rings.zone, W, work).filter(function (p) {
        var inside = 0;
        p.forEach(function (q) { var xq = Math.round(q[0]), yq = Math.round(q[1]); if (zone[yq * W + xq]) { inside++; } });
        return !hatches.length || inside < 0.6 * p.length;
      });
      if (o.debug) { (o.debug.raw = o.debug.raw || []).push({c: ci, lines: lines.map(function (p) { return p.map(function (q) { return q.slice(); }); })}); }
      rings.loops.forEach(function (p) { strokes.push({k: 'l', c: ci, p: p, ring: true}); });
      mergeStrokes(lines, work).forEach(function (p) { strokes.push({k: 'l', c: ci, p: p}); });
      times.trace += now() - t1;
      hatches.forEach(function (p) { strokes.push({k: 'f', c: ci, p: p}); });
    }

    // 5. The order.
    t1 = now();
    strokes.forEach(function (st) { st.len = polyLength(st.p); });
    // a speck of a line next to a longer one of its colour comes out when the crayon passes by, not as a dot of its own
    var shortL = 10 * work / 640, nearL = 30 * work / 640;
    var shorts = strokes.filter(function (st) { return st.k === 'l' && st.len < shortL; });
    var ordered = order(strokes.filter(function (st) { return !(st.k === 'l' && st.len < shortL); }), cols, work);
    var alone = [], riders = [];
    shorts.forEach(function (st) {
      var mid = st.p[Math.floor(st.p.length / 2)], best = null;
      ordered.forEach(function (h) {
        if (h.c !== st.c) { return; }
        var acc = 0;
        for (var k = 1; k < h.p.length; k++) {
          var a = h.p[k - 1], b = h.p[k], dx = b[0] - a[0], dy = b[1] - a[1], L2 = dx * dx + dy * dy || 1;
          var u = clamp(((mid[0] - a[0]) * dx + (mid[1] - a[1]) * dy) / L2, 0, 1);
          var d = Math.hypot(a[0] + dx * u - mid[0], a[1] + dy * u - mid[1]), L = Math.sqrt(L2);
          if (d < nearL && (!best || d + (h.k === 'f' ? nearL : 0) < best.d)) { best = {h: h, d: d + (h.k === 'f' ? nearL : 0), along: acc + u * L}; }
          acc += L;
        }
      });
      if (best) { riders.push([st, best]); } else { alone.push(st); }
    });
    if (alone.length) { ordered = order(ordered.concat(alone), cols, work); }
    var timing = schedule(ordered, o.seconds);
    riders.forEach(function (r) {
      var h = r[1].h, at = h.t0 + (h.t1 - h.t0) * clamp(r[1].along / (h.len || 1), 0, 1);
      r[0].t0 = at; r[0].t1 = at + 0.06;
    });
    strokes = ordered.concat(riders.map(function (r) { return r[0]; }));
    strokes.sort(function (a, b) { return a.t0 - b.t0; });
    times.order = now() - t1;
    times.total = now() - t0;

    return {
      v: 1, w: W, h: H, srcW: sw, srcH: sh, paper: paper, lo: lo, hi: hi, strokes: strokes, seconds: timing,
      layers: cols.centres.length, inkShare: inkCount / N, times: times,
      // kept for way 2 (the puppet), not serialised
      _ink: ink, _dt: dt, _s: s, _P: P, _label: label, _centres: cols.centres
    };
  }

  // 3 x 3 average of the RGB of an RGBA picture (edges clamped).
  function boxBlur(px, W, H) {
    var out = new Float32Array(3 * W * H);
    for (var y = 0; y < H; y++) {
      for (var x = 0; x < W; x++) {
        var r = 0, g = 0, b = 0;
        for (var dy = -1; dy <= 1; dy++) {
          var yy = y + dy < 0 ? 0 : y + dy >= H ? H - 1 : y + dy;
          for (var dx = -1; dx <= 1; dx++) {
            var xx = x + dx < 0 ? 0 : x + dx >= W ? W - 1 : x + dx, j = 4 * (yy * W + xx);
            r += px[j]; g += px[j + 1]; b += px[j + 2];
          }
        }
        var o = 3 * (y * W + x);
        out[o] = r / 9; out[o + 1] = g / 9; out[o + 2] = b / 9;
      }
    }
    return out;
  }

  // Least squares helpers for the paper surface: 1, x, y (and x², xy, y²) over the blocks, x and y in -1..1.
  function basis(gx, gy, gw, gh, terms) {
    var x = gw > 1 ? 2 * gx / (gw - 1) - 1 : 0, y = gh > 1 ? 2 * gy / (gh - 1) - 1 : 0;
    return terms === 6 ? [1, x, y, x * x, x * y, y * y] : [1, x, y];
  }
  function evalFit(fit, gx, gy, gw, gh) {
    var f = basis(gx, gy, gw, gh, fit[0].length);
    return fit.map(function (c) { var v = 0; for (var k = 0; k < c.length; k++) { v += c[k] * f[k]; } return clamp(v, 1, 255); });
  }
  function solve(A, b, n) {
    var M = A.map(function (row, i) { var r = Array.prototype.slice.call(row); r.push(b[i]); return r; });
    for (var c = 0; c < n; c++) {
      var piv = c;
      for (var r = c + 1; r < n; r++) { if (Math.abs(M[r][c]) > Math.abs(M[piv][c])) { piv = r; } }
      var tmp = M[c]; M[c] = M[piv]; M[piv] = tmp;
      var d = M[c][c] || 1e-9;
      for (r = 0; r < n; r++) {
        if (r === c) { continue; }
        var f = M[r][c] / d;
        for (var k = c; k <= n; k++) { M[r][k] -= f * M[c][k]; }
      }
    }
    return M.map(function (row, i) { return row[n] / (row[i] || 1e-9); });
  }

  function paperModel(px, W, H) {
    var B = 16, gw = Math.ceil(W / B), gh = Math.ceil(H / B), n = gw * gh;
    var col = new Float32Array(3 * n), valid = new Uint8Array(n), hist = new Uint32Array(256), i, gx, gy, x, y;
    var blockY = new Float32Array(n);
    // the sheet as a whole: bright pixels with little colour
    var ys = new Uint32Array(256);
    for (i = 0; i < W * H; i++) { ys[(px[4 * i] * 0.299 + px[4 * i + 1] * 0.587 + px[4 * i + 2] * 0.114) | 0]++; }
    var acc = 0, cut = 255;
    while (cut > 0 && acc < W * H * 0.2) { acc += ys[cut]; cut--; }
    var gr = 0, gg = 0, gb = 0, gc = 0;
    for (i = 0; i < W * H; i++) {
      var Y = px[4 * i] * 0.299 + px[4 * i + 1] * 0.587 + px[4 * i + 2] * 0.114;
      var ch = Math.max(px[4 * i], px[4 * i + 1], px[4 * i + 2]) - Math.min(px[4 * i], px[4 * i + 1], px[4 * i + 2]);
      if (Y >= cut && ch < 45) { gr += px[4 * i]; gg += px[4 * i + 1]; gb += px[4 * i + 2]; gc++; }
    }
    if (!gc) { gr = gg = gb = 235; gc = 1; }
    gr /= gc; gg /= gc; gb /= gc;
    var gY = gr * 0.299 + gg * 0.587 + gb * 0.114, gC = Math.max(gr, gg, gb) - Math.min(gr, gg, gb);
    for (gy = 0; gy < gh; gy++) {
      for (gx = 0; gx < gw; gx++) {
        hist.fill(0);
        var tot = 0;
        for (y = gy * B; y < Math.min(H, gy * B + B); y++) {
          for (x = gx * B; x < Math.min(W, gx * B + B); x++) {
            i = y * W + x; hist[(px[4 * i] * 0.299 + px[4 * i + 1] * 0.587 + px[4 * i + 2] * 0.114) | 0]++; tot++;
          }
        }
        var a2 = 0, c2 = 255;
        while (c2 > 0 && a2 < tot * 0.3) { a2 += hist[c2]; c2--; }
        var r = 0, g = 0, b = 0, c = 0;
        for (y = gy * B; y < Math.min(H, gy * B + B); y++) {
          for (x = gx * B; x < Math.min(W, gx * B + B); x++) {
            i = y * W + x;
            if (px[4 * i] * 0.299 + px[4 * i + 1] * 0.587 + px[4 * i + 2] * 0.114 >= c2) {
              r += px[4 * i]; g += px[4 * i + 1]; b += px[4 * i + 2]; c++;
            }
          }
        }
        var k = gy * gw + gx;
        r /= c; g /= c; b /= c;
        col[3 * k] = r; col[3 * k + 1] = g; col[3 * k + 2] = b;
        var bY = r * 0.299 + g * 0.587 + b * 0.114, bC = Math.max(r, g, b) - Math.min(r, g, b);
        blockY[k] = bY;
        valid[k] = bC < gC + 28 ? 1 : 0;
      }
    }
    // The light on a sheet is smooth; ink is not. A quadratic surface per channel is fitted to the blocks that look
    // like paper, again and again without the blocks far from it (pale crayon, a coloured-in area's paper holes), and
    // the blocks left out take the surface. With few paper blocks (a sheet coloured all over) the surface is a plane.
    for (i = 0; i < n; i++) { valid[i] = valid[i] && blockY[i] > gY * 0.35 ? 1 : 0; }
    var fit = null;
    for (var round = 0; round < 5; round++) {
      var count = 0;
      for (i = 0; i < n; i++) { count += valid[i]; }
      if (count < 6) { break; }
      var terms = count >= 30 ? 6 : 3;
      fit = [0, 1, 2].map(function (ch) {
        var A = [], B2 = [];
        for (var r = 0; r < terms; r++) { A.push(new Float64Array(terms)); B2.push(0); }
        for (var j = 0; j < n; j++) {
          if (!valid[j]) { continue; }
          var f = basis(j % gw, (j / gw) | 0, gw, gh, terms);
          for (var r1 = 0; r1 < terms; r1++) {
            B2[r1] += f[r1] * col[3 * j + ch];
            for (var c1 = 0; c1 < terms; c1++) { A[r1][c1] += f[r1] * f[c1]; }
          }
        }
        return solve(A, B2, terms);
      });
      var res = new Float32Array(n), list = [];
      for (i = 0; i < n; i++) {
        var fv = evalFit(fit, i % gw, (i / gw) | 0, gw, gh);
        res[i] = Math.hypot(col[3 * i] - fv[0], col[3 * i + 1] - fv[1], col[3 * i + 2] - fv[2]);
        if (valid[i]) { list.push(res[i]); }
      }
      list.sort(function (p1, p2) { return p1 - p2; });
      var sig = 1.4826 * list[list.length >> 1], lim = Math.max(7, 2.5 * sig), changed = 0;
      for (i = 0; i < n; i++) {
        var keep = res[i] < lim && blockY[i] > 0.35 * gY && (Math.max(col[3 * i], col[3 * i + 1], col[3 * i + 2]) - Math.min(col[3 * i], col[3 * i + 1], col[3 * i + 2])) < gC + 28 ? 1 : 0;
        if (keep !== valid[i]) { changed++; }
        valid[i] = keep;
      }
      if (!changed) { break; }
    }
    // a shadow (the corners of a phone photo, a hand) is the paper darker, with the same tint: it stays as it is
    for (i = 0; i < n && fit; i++) {
      if (valid[i]) { continue; }
      var fs = evalFit(fit, i % gw, (i / gw) | 0, gw, gh), sumF = fs[0] + fs[1] + fs[2],
        sumB = col[3 * i] + col[3 * i + 1] + col[3 * i + 2], kk = sumB / sumF;
      var tint = Math.abs(col[3 * i] / sumB - fs[0] / sumF) + Math.abs(col[3 * i + 1] / sumB - fs[1] / sumF);
      if (kk > 0.4 && kk < 1.05 && tint < 0.015) { valid[i] = 1; }
    }
    for (i = 0; i < n; i++) {
      if (valid[i]) { continue; }
      var fv2 = fit ? evalFit(fit, i % gw, (i / gw) | 0, gw, gh) : [gr, gg, gb];
      col[3 * i] = fv2[0]; col[3 * i + 1] = fv2[1]; col[3 * i + 2] = fv2[2];
    }
    var pass;
    // smooth: two box passes
    for (pass = 0; pass < 2; pass++) {
      var nc = new Float32Array(3 * n);
      for (gy = 0; gy < gh; gy++) {
        for (gx = 0; gx < gw; gx++) {
          var s0 = [0, 0, 0], w0 = 0;
          for (var yy = Math.max(0, gy - 1); yy <= Math.min(gh - 1, gy + 1); yy++) {
            for (var xx = Math.max(0, gx - 1); xx <= Math.min(gw - 1, gx + 1); xx++) {
              var qq = yy * gw + xx, ww = (xx === gx && yy === gy) ? 2 : 1;
              s0[0] += col[3 * qq] * ww; s0[1] += col[3 * qq + 1] * ww; s0[2] += col[3 * qq + 2] * ww; w0 += ww;
            }
          }
          var k3 = gy * gw + gx;
          nc[3 * k3] = s0[0] / w0; nc[3 * k3 + 1] = s0[1] / w0; nc[3 * k3 + 2] = s0[2] / w0;
        }
      }
      col = nc;
    }
    return {B: B, gw: gw, gh: gh, col: col, valid: valid, global: [gr, gg, gb], W: W, H: H};
  }

  // The paper at every pixel of a w x h picture (bilinear over the blocks).
  function paperAt(paper, w, h) {
    var out = new Float32Array(3 * w * h), fx = paper.W / w, fy = paper.H / h, B = paper.B, gw = paper.gw,
      gh = paper.gh, col = paper.col;
    for (var y = 0; y < h; y++) {
      var gyf = clamp((y + 0.5) * fy / B - 0.5, 0, gh - 1), gy0 = Math.floor(gyf), gy1 = Math.min(gh - 1, gy0 + 1),
        ty = gyf - gy0;
      for (var x = 0; x < w; x++) {
        var gxf = clamp((x + 0.5) * fx / B - 0.5, 0, gw - 1), gx0 = Math.floor(gxf), gx1 = Math.min(gw - 1, gx0 + 1),
          tx = gxf - gx0, o = 3 * (y * w + x);
        for (var c = 0; c < 3; c++) {
          var a = col[3 * (gy0 * gw + gx0) + c] * (1 - tx) + col[3 * (gy0 * gw + gx1) + c] * tx,
            b = col[3 * (gy1 * gw + gx0) + c] * (1 - tx) + col[3 * (gy1 * gw + gx1) + c] * tx;
          out[o + c] = Math.max(1, a * (1 - ty) + b * ty);
        }
      }
    }
    return out;
  }

  // The thinned ink as a graph of edges between nodes (ends and crossings), joined into strokes.
  function traceStrokes(sk, list, dt, W, H, work) {
    var N8 = [-W - 1, -W, -W + 1, -1, 1, W - 1, W, W + 1];
    var deg = new Uint8Array(W * H), i, k;
    list = list.filter(function (p) { return sk[p]; });
    list.forEach(function (p) { var c = 0; for (k = 0; k < 8; k++) { c += sk[p + N8[k]]; } deg[p] = c; });
    var isNode = new Uint8Array(W * H);
    list.forEach(function (p) { if (deg[p] !== 2) { isNode[p] = 1; } });
    // clusters of crossing pixels are one node
    var nodeId = new Int32Array(W * H).fill(-1), nodes = [];
    list.forEach(function (p) {
      if (!isNode[p] || nodeId[p] >= 0) { return; }
      var id = nodes.length, st = [p], pix = [];
      nodeId[p] = id;
      while (st.length) {
        var c = st.pop(); pix.push(c);
        for (var j = 0; j < 8; j++) {
          var q = c + N8[j];
          if (isNode[q] && nodeId[q] < 0 && deg[q] > 2 && deg[c] > 2) { nodeId[q] = id; st.push(q); }
        }
      }
      var sx = 0, sy = 0;
      pix.forEach(function (c) { sx += c % W; sy += (c / W) | 0; });
      nodes.push({pix: pix, x: sx / pix.length, y: sy / pix.length, edges: []});
    });
    var visited = new Uint8Array(W * H), edges = [];
    function pt(p) { var x = p % W; return [x, (p - x) / W, dt[p]]; }
    function walk(startNode, from, first) {
      var path = [pt(from), pt(first)], prev = from, cur = first, end = -1;
      visited[first] = 1;
      for (var guard = 0; guard < W * H; guard++) {
        var next = -1, nodeNext = -1;
        for (var j = 0; j < 8; j++) {
          var q = cur + N8[j];
          if (!sk[q] || q === prev) { continue; }
          if (isNode[q]) { if (nodeId[q] !== nodeId[from] || path.length > 3) { nodeNext = q; } } else if (!visited[q]) {
            // orthogonal steps first, so a diagonal does not skip a pixel of the line
            if (next < 0 || (j === 1 || j === 3 || j === 4 || j === 6)) { next = q; }
          }
        }
        if (nodeNext >= 0 && (next < 0 || path.length > 2)) { path.push(pt(nodeNext)); end = nodeId[nodeNext]; break; }
        if (next < 0) { break; }
        visited[next] = 1; path.push(pt(next)); prev = cur; cur = next;
      }
      return {a: startNode, b: end, p: path};
    }
    nodes.forEach(function (nd, id) {
      nd.pix.forEach(function (p) {
        for (var j = 0; j < 8; j++) {
          var q = p + N8[j];
          if (sk[q] && !isNode[q] && !visited[q]) {
            var e = walk(id, p, q);
            edges.push(e);
          }
        }
      });
    });
    // closed loops with no node at all
    list.forEach(function (p) {
      if (isNode[p] || visited[p] || !sk[p]) { return; }
      visited[p] = 1;
      var path = [pt(p)], prev = -1, cur = p;
      for (var guard = 0; guard < W * H; guard++) {
        var next = -1;
        for (var j = 0; j < 8; j++) { var q = cur + N8[j]; if (sk[q] && !visited[q] && q !== prev) { next = q; break; } }
        if (next < 0) { break; }
        visited[next] = 1; path.push(pt(next)); prev = cur; cur = next;
      }
      path.push(path[0]);
      edges.push({a: -1, b: -1, p: path, loop: true});
    });
    edges.forEach(function (e, ei) {
      if (e.a >= 0) { nodes[e.a].edges.push([ei, 0]); }
      if (e.b >= 0) { nodes[e.b].edges.push([ei, 1]); }
    });
    // prune short spurs (a bump of the ink edge thinned into a whisker)
    var dead = new Uint8Array(edges.length);
    var spur = Math.max(4, 5 * work / 640);
    edges.forEach(function (e, ei) {
      if (e.loop) { return; }
      var la = e.a >= 0 ? nodes[e.a].edges.length : 0, lb = e.b >= 0 ? nodes[e.b].edges.length : 0;
      var tipEnd = (la === 1 && lb >= 3) || (lb === 1 && la >= 3);
      var L = polyLength(e.p), junctionR = la >= 3 ? e.p[0][2] : e.p[e.p.length - 1][2];
      if (tipEnd && L < Math.max(spur, junctionR * 1.6)) { dead[ei] = 1; }
    });
    // direction of each live edge as it leaves a node
    function dirAt(e, end) {
      var p = e.p, n = p.length, a = end ? p[n - 1] : p[0], m = Math.min(n - 1, Math.round(14 * work / 640)),
        b = end ? p[n - 1 - m] : p[m], dx = b[0] - a[0], dy = b[1] - a[1], L = Math.hypot(dx, dy) || 1;
      return [dx / L, dy / L];
    }
    var link = edges.map(function () { return [null, null]; });
    nodes.forEach(function (nd) {
      var inc = nd.edges.filter(function (q) { return !dead[q[0]]; });
      if (inc.length === 2) {
        var A = inc[0], B = inc[1];
        if (A[0] !== B[0]) { link[A[0]][A[1]] = B; link[B[0]][B[1]] = A; }
        return;
      }
      if (inc.length < 3) { return; }
      var pairs = [];
      for (var a = 0; a < inc.length; a++) {
        for (var b = a + 1; b < inc.length; b++) {
          if (inc[a][0] === inc[b][0]) { continue; }
          var da = dirAt(edges[inc[a][0]], inc[a][1]), db = dirAt(edges[inc[b][0]], inc[b][1]);
          var ang = Math.acos(clamp(-(da[0] * db[0] + da[1] * db[1]), -1, 1));
          pairs.push([ang, a, b]);
        }
      }
      pairs.sort(function (u, v) { return u[0] - v[0]; });
      var used = {};
      pairs.forEach(function (pr) {
        if (pr[0] > 0.7 || used[pr[1]] || used[pr[2]]) { return; }   // 40 degrees: an arc goes on into the arc, not into the leg
        used[pr[1]] = used[pr[2]] = 1;
        var A = inc[pr[1]], B = inc[pr[2]];
        link[A[0]][A[1]] = B; link[B[0]][B[1]] = A;
      });
    });
    // chains of linked edges become strokes
    var taken = new Uint8Array(edges.length), out = [];
    function chainFrom(ei, startEnd) {
      var pts = [], cur = ei, enter = startEnd, guard = 0;
      while (cur !== null && !taken[cur] && guard++ < 10000) {
        taken[cur] = 1;
        var p = edges[cur].p.slice();
        if (enter === 1) { p.reverse(); }
        pts = pts.length ? pts.concat(p.slice(1)) : p;
        var exitEnd = 1 - enter, nx = link[cur][exitEnd];
        if (!nx) { break; }
        cur = nx[0]; enter = nx[1];
      }
      return pts;
    }
    edges.forEach(function (e, ei) {
      if (dead[ei] || taken[ei]) { return; }
      if (link[ei][0] && !link[ei][1]) { out.push(chainFrom(ei, 1)); } else if (!link[ei][0]) { out.push(chainFrom(ei, 0)); }
    });
    edges.forEach(function (e, ei) { if (!dead[ei] && !taken[ei]) { out.push(chainFrom(ei, 0)); } });
    // smooth a little, simplify, keep the brush radius
    var margin = 2 * work / 640, minR = 2.6 * work / 640;
    return out.filter(function (p) { return p.length >= 2; }).map(function (p) {
      var sm = p.map(function (q, j) {
        if (j === 0 || j === p.length - 1) { return q.slice(); }
        var a = p[j - 1], b = p[j + 1];
        return [(a[0] + 2 * q[0] + b[0]) / 4, (a[1] + 2 * q[1] + b[1]) / 4, Math.max(a[2], q[2], b[2])];
      });
      sm.forEach(function (q) { q[2] = Math.max(minR, q[2] + margin); });
      return simplify(sm, 0.6);
    }).filter(function (p) { return polyLength(p) >= 2 || p.length >= 2; });
  }

  // The closed outlines of one colour: each hole of paper in its lines that is roundish (a head, a sun, a window, a
  // house; not the odd shapes between the loops of a scribble) gives one stroke all round it, along the middle of its
  // ring of ink, starting at the top and going left. `zone` is the ring, so the pieces of line on it can be taken out.
  function closedRings(ink, dLine, W, H, work, dbg) {
    var N = W * H, i, x, y, loops = [], zone = new Uint8Array(N);
    var notInk = new Uint8Array(N);
    for (i = 0; i < N; i++) { notInk[i] = ink[i] ? 0 : 1; }
    var comp = components(notInk, W, H), minA = 380 * (work / 640) * (work / 640), maxA = N * 0.3;
    var box = [], touch = new Uint8Array(comp.count + 1), per = new Float64Array(comp.count + 1);
    for (i = 0; i < N; i++) {
      var l = comp.labels[i];
      if (!l) { continue; }
      x = i % W; y = (i - x) / W;
      var b = box[l] || (box[l] = [x, y, x, y, 0, 0]);
      if (x < b[0]) { b[0] = x; } if (y < b[1]) { b[1] = y; } if (x > b[2]) { b[2] = x; } if (y > b[3]) { b[3] = y; }
      b[4] += x; b[5] += y;
      if (x === 0 || y === 0 || x === W - 1 || y === H - 1) { touch[l] = 1; }
      else if (ink[i - 1] || ink[i + 1] || ink[i - W] || ink[i + W]) { per[l]++; }
    }
    for (l = 1; l <= comp.count; l++) {
      var A = comp.sizes[l];
      if (touch[l] || A < minA || A > maxA) { continue; }
      var bb = box[l], pad = Math.ceil(16 * work / 640), x0 = Math.max(0, bb[0] - pad), y0 = Math.max(0, bb[1] - pad),
        x1 = Math.min(W - 1, bb[2] + pad), y1 = Math.min(H - 1, bb[3] + pad), w = x1 - x0 + 1, h = y1 - y0 + 1;
      // the hole with its islands (eyes, a mouth) filled: everything inside its outer edge
      var outside = new Uint8Array(w * h), st = [];
      for (var xx = 0; xx < w; xx++) { st.push(xx, (h - 1) * w + xx); }
      for (var yy = 0; yy < h; yy++) { st.push(yy * w, yy * w + w - 1); }
      while (st.length) {
        var q = st.pop();
        if (outside[q]) { continue; }
        var qx = q % w, qy = (q - qx) / w;
        if (comp.labels[(qy + y0) * W + qx + x0] === l) { continue; }
        outside[q] = 1;
        if (qx > 0) { st.push(q - 1); } if (qx < w - 1) { st.push(q + 1); } if (qy > 0) { st.push(q - w); } if (qy < h - 1) { st.push(q + w); }
      }
      // roundish, by its outer edge (eyes and a mouth inside do not count): a scribble's odd shapes are not outlines
      var fa = 0, fp = 0;
      for (i = 0; i < w * h; i++) {
        if (outside[i]) { continue; }
        fa++;
        var fx0 = i % w;
        if (fx0 === 0 || fx0 === w - 1 || outside[i - 1] || outside[i + 1] || outside[i - w] || outside[i + w]) { fp++; }
      }
      var circ = 4 * Math.PI * fa / (fp * fp * 1.27);   // 1.27: a pixel edge is longer than the curve it follows
      if (dbg) { dbg.push({A: fa, circ: +circ.toFixed(2), box: box[l].slice(0, 4)}); }
      if (circ < 0.5) { continue; }
      var notFilled = new Uint8Array(w * h);
      for (i = 0; i < w * h; i++) { notFilled[i] = outside[i]; }
      var dist = edt(notFilled, w, h);   // distance from outside pixels to the filled hole
      // the half width of the ring: its ink next to the hole
      var sr = 0, sn = 0;
      for (i = 0; i < w * h; i++) {
        if (dist[i] > 0 && dist[i] <= 4) {
          var gi = ((i / w) | 0) + y0, gj = (i % w) + x0, g = gi * W + gj;
          if (ink[g]) { sr += dLine[g]; sn++; }
        }
      }
      if (!sn) { continue; }
      var rh = Math.max(1.5, sr / sn);
      // a ring of ink all round, or it is not an outline (a hole that opens into a gap)
      var cx = bb[4] / A - x0, cy = bb[5] / A - y0, pts = [], miss = 0, steps = 120, capT = 14 * work / 640;
      for (var k = 0; k <= steps; k++) {
        var th = -Math.PI / 2 - 2 * Math.PI * k / steps;          // from the top, to the left
        var dx = Math.cos(th), dy = Math.sin(th), rr = 0, entry = -1, exit = -1;
        for (; rr < w + h; rr += 0.5) {
          var px = cx + dx * rr, py = cy + dy * rr, ix = Math.round(px), iy = Math.round(py);
          if (ix < 0 || iy < 0 || ix >= w || iy >= h) { break; }
          var onInk = ink[(iy + y0) * W + ix + x0];
          if (entry < 0) {
            if (onInk && dist[iy * w + ix] > 0) { entry = rr; }
            else if (dist[iy * w + ix] > 3 * rh + 4) { break; }   // out of the hole and no ink: an opening
          } else if (!onInk || rr - entry > capT) { exit = rr; break; }
        }
        if (entry < 0 || exit < 0) { miss++; continue; }
        var mid = (entry + exit) / 2;
        pts.push([cx + dx * mid + x0, cy + dy * mid + y0, (exit - entry) / 2 + 2 * work / 640]);
      }
      // the brush as wide as the ring around it, not as one ray that ran along an arm
      pts = pts.map(function (q, j) {
        var rs = [];
        for (var d = -3; d <= 3; d++) { var o = pts[(j + d + pts.length) % pts.length]; if (o) { rs.push(o[2]); } }
        rs.sort(function (u, v) { return u - v; });
        return [q[0], q[1], Math.max(2.6 * work / 640, rs[rs.length >> 1])];
      });
      if (dbg) { dbg[dbg.length - 1].miss = miss; dbg[dbg.length - 1].rh = +rh.toFixed(2); }
      if (miss > steps * 0.12 || pts.length < steps * 0.8) { continue; }
      loops.push(simplify(pts, 0.6));
      for (i = 0; i < w * h; i++) {
        if (dist[i] <= 2 * rh + 2) { zone[(((i / w) | 0) + y0) * W + (i % w) + x0] = 1; }
      }
    }
    return {loops: loops, zone: zone};
  }

  // The lines with what falls on the rings taken out; what is left of each, in pieces.
  function cutOut(lines, zone, W, work) {
    var out = [], minL = 5 * work / 640;
    lines.forEach(function (p) {
      var run = [];
      var flush = function () { if (run.length >= 2 && polyLength(run) >= minL) { out.push(run); } run = []; };
      p.forEach(function (q) {
        if (zone[Math.round(q[1]) * W + Math.round(q[0])]) { flush(); } else { run.push(q); }
      });
      flush();
    });
    return out;
  }

  // Pieces of line of one colour that carry on from each other (a gap of a few pixels, the same direction: a crayon
  // line broken by the paper, the arcs of a round head cut by an arm) are one stroke. A stroke that closes on itself
  // (a head, a sun) starts at its top and goes round to the left, as a hand draws a circle.
  function mergeStrokes(list, work) {
    var G = 14 * work / 640, maxAng = 50 * Math.PI / 180, n = list.length, i, j;
    if (!n) { return []; }
    var reach = 9 * work / 640;
    function end(p, e) {
      var a = e ? p[p.length - 1] : p[0], k = 1, acc = 0, b = a;
      while (k < p.length && acc < reach) {
        b = e ? p[p.length - 1 - k] : p[k];
        acc = Math.hypot(b[0] - a[0], b[1] - a[1]); k++;
      }
      var dx = a[0] - b[0], dy = a[1] - b[1], L = Math.hypot(dx, dy) || 1;
      return {x: a[0], y: a[1], ox: dx / L, oy: dy / L, r: a[2]};     // the point, the way out of the stroke there
    }
    var ends = list.map(function (p) { return [end(p, 0), end(p, 1)]; });
    var cand = [];
    for (i = 0; i < n; i++) {
      for (j = i; j < n; j++) {
        for (var ea = 0; ea < 2; ea++) {
          for (var eb = 0; eb < 2; eb++) {
            if (i === j && ea >= eb) { continue; }
            if (i === j && polyLength(list[i]) < 4 * G) { continue; }
            var A = ends[i][ea], B = ends[j][eb], gap = Math.hypot(B.x - A.x, B.y - A.y);
            // where an arm leaves a head, the centre line of a thick line breaks over about three widths
            if (gap > G + 3 * Math.max(A.r, B.r)) { continue; }
            // going out of one and into the other: the two ways out face each other
            var ang = Math.acos(clamp(-(A.ox * B.ox + A.oy * B.oy), -1, 1));
            if (gap > 2) {
              var gx = (B.x - A.x) / gap, gy = (B.y - A.y) / gap;
              ang = Math.max(ang, Math.acos(clamp(A.ox * gx + A.oy * gy, -1, 1)));
            }
            if (ang > maxAng) { continue; }
            cand.push([gap + ang * 180 / Math.PI * 0.3, i, ea, j, eb]);
          }
        }
      }
    }
    cand.sort(function (a, b) { return a[0] - b[0]; });
    var link = list.map(function () { return [null, null]; });
    cand.forEach(function (c) {
      if (link[c[1]][c[2]] || link[c[3]][c[4]]) { return; }
      link[c[1]][c[2]] = [c[3], c[4]]; link[c[3]][c[4]] = [c[1], c[2]];
    });
    var taken = new Uint8Array(n), out = [];
    function walk(start, enterEnd) {
      var pts = [], cur = start, enter = enterEnd, guard = 0, closed = false;
      while (cur !== null && guard++ < 100000) {
        if (taken[cur]) { closed = cur === start; break; }
        taken[cur] = 1;
        var p = list[cur].slice();
        if (enter === 1) { p.reverse(); }
        if (pts.length) {
          var a = pts[pts.length - 1], b = p[0];
          if (Math.hypot(b[0] - a[0], b[1] - a[1]) > 1.5) { pts.push([a[0], a[1], 0.5], [b[0], b[1], 0.5]); }
          pts = pts.concat(p);
        } else { pts = p; }
        var nx = link[cur][1 - enter];
        if (!nx) { break; }
        cur = nx[0]; enter = nx[1];
      }
      return {p: pts, closed: closed};
    }
    for (i = 0; i < n; i++) {
      if (taken[i]) { continue; }
      if (link[i][0] && !link[i][1]) { out.push(walk(i, 1)); } else if (!link[i][0]) { out.push(walk(i, 0)); }
    }
    for (i = 0; i < n; i++) { if (!taken[i]) { out.push(walk(i, 0)); } }
    return out.map(function (r) {
      var p = r.p, a = p[0], b = p[p.length - 1];
      var closed = r.closed || (p.length > 8 && polyLength(p) > 6 * G && Math.hypot(a[0] - b[0], a[1] - b[1]) < G + 3 * Math.max(a[2], b[2]));
      if (!closed) { return p; }
      // from the top, round to the left
      var ring = Math.hypot(a[0] - b[0], a[1] - b[1]) < 1.5 ? p.slice(0, -1) : p.slice(), top = 0;
      ring.forEach(function (q, k) { if (q[1] < ring[top][1]) { top = k; } });
      ring = ring.slice(top).concat(ring.slice(0, top));
      var ahead = ring[Math.min(ring.length - 1, 6)];
      if (ahead[0] > ring[0][0]) { ring = [ring[0]].concat(ring.slice(1).reverse()); }
      ring.push(ring[0].slice());
      return ring;
    });
  }

  // Colouring in: lines back and forth along the long axis of each thick area, joined where they overlap.
  function hatchFills(mask, W, H, work) {
    var comp = components(mask, W, H), out = [], minA = Math.round(60 * (work / 640) * (work / 640));
    var spacing = 11 * work / 640, R = spacing * 0.74;
    var groups = [];
    for (var c = 1; c <= comp.count; c++) { if (comp.sizes[c] >= minA) { groups[c] = []; } }
    for (var i = 0; i < W * H; i++) { var l = comp.labels[i]; if (l && groups[l]) { groups[l].push(i); } }
    groups.forEach(function (pix, lbl) {
      if (!pix) { return; }
      var mx = 0, my = 0;
      pix.forEach(function (p) { mx += p % W; my += (p / W) | 0; });
      mx /= pix.length; my /= pix.length;
      var sxx = 0, syy = 0, sxy = 0;
      pix.forEach(function (p) { var x = p % W - mx, y = ((p / W) | 0) - my; sxx += x * x; syy += y * y; sxy += x * y; });
      var th = 0.5 * Math.atan2(2 * sxy, sxx - syy);
      var tr = sxx + syy, det = sxx * syy - sxy * sxy, l1 = tr / 2 + Math.sqrt(Math.max(0, tr * tr / 4 - det)),
        l2 = tr / 2 - Math.sqrt(Math.max(0, tr * tr / 4 - det));
      if (l1 < 2.2 * Math.max(l2, 1)) { th = -0.45; } // roundish: diagonal strokes, as a right hand colours
      var ux = Math.cos(th), uy = Math.sin(th), nx = -uy, ny = ux;
      var umin = 1e9, umax = -1e9, vmin = 1e9, vmax = -1e9;
      pix.forEach(function (p) {
        var x = p % W - mx, y = ((p / W) | 0) - my, u = x * ux + y * uy, v = x * nx + y * ny;
        if (u < umin) { umin = u; } if (u > umax) { umax = u; } if (v < vmin) { vmin = v; } if (v > vmax) { vmax = v; }
      });
      var inside = function (u, v) {
        var x = Math.round(mx + u * ux + v * nx), y = Math.round(my + u * uy + v * ny);
        return x >= 0 && y >= 0 && x < W && y < H && comp.labels[y * W + x] === lbl;
      };
      var chains = [], prev = [];
      var nl = Math.max(1, Math.ceil((vmax - vmin) / spacing));
      var off = (vmax - vmin - (nl - 1) * spacing) / 2;
      for (var li = 0; li < nl; li++) {
        var v = vmin + off + li * spacing, segs = [], start = null, gap = 0;
        for (var u = umin - 1; u <= umax + 1; u += 1) {
          if (inside(u, v)) { if (start === null) { start = u; } gap = 0; } else if (start !== null) {
            gap++;
            if (gap > 3) { if (u - gap - start > 2) { segs.push([start, u - gap]); } start = null; gap = 0; }
          }
        }
        if (start !== null) { segs.push([start, umax]); }
        var now_ = [];
        segs.forEach(function (sg) {
          // continue the chain whose last segment overlaps this one
          var best = null;
          prev.forEach(function (pc) {
            var ov = Math.min(pc.seg[1], sg[1]) - Math.max(pc.seg[0], sg[0]);
            if (ov > spacing && !pc.used && (!best || ov > best.ov)) { best = {pc: pc, ov: ov}; }
          });
          var ch;
          if (best) { ch = best.pc.chain; best.pc.used = true; } else { ch = {pts: [], dir: 1}; chains.push(ch); }
          var a = ch.dir > 0 ? sg[0] : sg[1], b = ch.dir > 0 ? sg[1] : sg[0];
          ch.pts.push([mx + a * ux + v * nx, my + a * uy + v * ny, R], [mx + b * ux + v * nx, my + b * uy + v * ny, R]);
          ch.dir = -ch.dir;
          now_.push({seg: sg, chain: ch, used: false});
        });
        prev = now_;
      }
      chains.forEach(function (ch) { if (ch.pts.length >= 2) { out.push(ch.pts); } });
    });
    return out;
  }

  // The colours of the ink: k-means in Lab over a sample of ink pixels (RGB, 3 per pixel), divided by their paper.
  function colourLayers(px, P, ink, W, H) {
    var idx = [], i;
    for (i = 0; i < W * H; i++) { if (ink[i]) { idx.push(i); } }
    var rand = rng(7), sample = [], step = Math.max(1, Math.floor(idx.length / 4000));
    for (i = 0; i < idx.length; i += step) {
      var p = idx[i];
      sample.push(lab(clamp(px[3 * p] / P[3 * p], 0, 1), clamp(px[3 * p + 1] / P[3 * p + 1], 0, 1),
        clamp(px[3 * p + 2] / P[3 * p + 2], 0, 1)));
    }
    if (!sample.length) { return {centres: [[50, 0, 0]]}; }
    var K = Math.min(6, sample.length), cen = [sample[Math.floor(rand() * sample.length)]];
    while (cen.length < K) {
      var d2 = sample.map(function (s) {
        var m = 1e9; cen.forEach(function (c) { m = Math.min(m, distw(s, c)); }); return m;
      });
      var sum = d2.reduce(function (a, b) { return a + b; }, 0), r = rand() * sum, j = 0;
      while (j < d2.length - 1 && r > d2[j]) { r -= d2[j]; j++; }
      cen.push(sample[j].slice());
    }
    for (var it = 0; it < 12; it++) {
      var acc = cen.map(function () { return [0, 0, 0, 0]; });
      sample.forEach(function (s) {
        var b = 0, bd = 1e9;
        cen.forEach(function (c, ci) { var d = distw(s, c); if (d < bd) { bd = d; b = ci; } });
        acc[b][0] += s[0]; acc[b][1] += s[1]; acc[b][2] += s[2]; acc[b][3]++;
      });
      cen = acc.filter(function (a) { return a[3] > sample.length * 0.01; })
        .map(function (a) { return [a[0] / a[3], a[1] / a[3], a[2] / a[3]]; });
    }
    // near colours are one crayon
    var merged = [];
    cen.forEach(function (c) {
      // near colours are one crayon; and every grey (black marker pressed hard or softly, pencil) is one
      var m = merged.find(function (q) {
        return Math.sqrt(distw(q, c)) < 16 || (Math.hypot(q[1], q[2]) < 12 && Math.hypot(c[1], c[2]) < 12);
      });
      if (!m) { merged.push(c); }
    });
    merged.sort(function (a, b) { return a[0] - b[0]; });   // darkest first
    return {centres: merged};
  }
  // Lab distance with lightness counting half: a crayon pressed hard or softly is still one crayon.
  function distw(a, b) { return 0.25 * (a[0] - b[0]) * (a[0] - b[0]) + (a[1] - b[1]) * (a[1] - b[1]) + (a[2] - b[2]) * (a[2] - b[2]); }
  function dist2(a, b) { return (a[0] - b[0]) * (a[0] - b[0]) + (a[1] - b[1]) * (a[1] - b[1]) + (a[2] - b[2]) * (a[2] - b[2]); }

  function colourOf(pts, px, P, W, H, centres) {
    var votes = centres.map(function () { return 0; });
    var n = 0;
    for (var j = 0; j < pts.length; j++) {
      var a = pts[j], b = pts[Math.min(pts.length - 1, j + 1)];
      for (var t = 0; t < 1; t += 0.25) {
        var x = Math.round(a[0] + (b[0] - a[0]) * t), y = Math.round(a[1] + (b[1] - a[1]) * t);
        if (x < 0 || y < 0 || x >= W || y >= H) { continue; }
        var p = y * W + x;
        var L = lab(clamp(px[4 * p] / P[3 * p], 0, 1), clamp(px[4 * p + 1] / P[3 * p + 1], 0, 1),
          clamp(px[4 * p + 2] / P[3 * p + 2], 0, 1));
        if (L[0] > 92 && Math.abs(L[1]) + Math.abs(L[2]) < 8) { continue; } // paper inside a wide brush
        var bi = 0, bd = 1e9;
        centres.forEach(function (c, ci) { var d = dist2(L, c); if (d < bd) { bd = d; bi = ci; } });
        votes[bi]++; n++;
      }
    }
    var best = 0;
    votes.forEach(function (v, i) { if (v > votes[best]) { best = i; } });
    return best;
  }

  // Colour by colour, darkest first, outlines before colouring in; inside a group, from the end nearest the pen.
  function order(strokes, cols, work) {
    var groups = [];
    ['l', 'f'].forEach(function (kind) {
      cols.centres.forEach(function (_, ci) {
        var g = strokes.filter(function (s) { return s.k === kind && s.c === ci; });
        if (g.length) { groups.push(g); }
      });
    });
    var out = [], pen = null;
    groups.forEach(function (g) {
      var left = g.slice();
      if (!pen) {
        left.sort(function (a, b) { return b.len - a.len; });
        var first = left.shift();
        out.push(first); pen = first.p[first.p.length - 1];
      }
      while (left.length) {
        var bi = 0, bd = 1e18, flip = false;
        left.forEach(function (s, i) {
          var a = s.p[0], b = s.p[s.p.length - 1], da = Math.hypot(a[0] - pen[0], a[1] - pen[1]),
            db = Math.hypot(b[0] - pen[0], b[1] - pen[1]), bonus = Math.min(s.len, 120) * 0.25;
          if (da - bonus < bd) { bd = da - bonus; bi = i; flip = false; }
          if (db - bonus < bd) { bd = db - bonus; bi = i; flip = true; }
        });
        var s = left.splice(bi, 1)[0];
        if (flip) { s.p.reverse(); }
        out.push(s); pen = s.p[s.p.length - 1];
      }
    });
    // a crayon line broken by the grain of the paper is one movement: a piece that starts right where the last one
    // ended, in the same colour, is drawn in the same go (the pen crosses the gap thin, over paper)
    var joined = [], join = 14 * (work || 640) / 640;
    out.forEach(function (s) {
      var last = joined[joined.length - 1];
      if (last && last.k === s.k && last.c === s.c) {
        var e = last.p[last.p.length - 1], b = s.p[0];
        if (Math.hypot(e[0] - b[0], e[1] - b[1]) < join) {
          last.p = last.p.concat([[e[0], e[1], 0.5], [b[0], b[1], 0.5]], s.p);
          last.len = polyLength(last.p);
          return;
        }
      }
      joined.push(s);
    });
    return joined;
  }

  // When each stroke starts and ends: 3 to 5 s in all, colouring in faster than outlining, short pauses between.
  function schedule(strokes, fixed) {
    var Lw = 0;
    strokes.forEach(function (s) { Lw += s.len * (s.k === 'f' ? 0.45 : 1); });
    var T = fixed || clamp(3 + 2 * (Lw - 900) / 6000, 3, 5);
    var n = strokes.length, gap = Math.min(0.09, 0.22 * T / Math.max(1, n));
    var draw = T - gap * Math.max(0, n - 1), speed = Lw / Math.max(0.5, draw), t = 0;
    strokes.forEach(function (s, i) {
      var d = s.len * (s.k === 'f' ? 0.45 : 1) / speed;
      s.t0 = t; s.t1 = t + Math.max(0.001, d);
      t = s.t1 + (i < n - 1 ? gap : 0);
    });
    return t;
  }

  // ---------------------------------------------------------------------------------------- keep it, bring it --
  function b64(bytes) {
    var str = '';
    for (var i = 0; i < bytes.length; i++) { str += String.fromCharCode(bytes[i]); }
    return root.btoa ? btoa(str) : Buffer.from(bytes).toString('base64');
  }
  function unb64(text) {
    var str = root.atob ? atob(text) : Buffer.from(text, 'base64').toString('binary'), out = new Uint8Array(str.length);
    for (var i = 0; i < str.length; i++) { out[i] = str.charCodeAt(i); }
    return out;
  }
  function toJSON(a) {
    var q = function (v) { return Math.round(v * 2) / 2; };
    return {
      v: 1, w: a.w, h: a.h, lo: +a.lo.toFixed(3), hi: +a.hi.toFixed(3), seconds: +a.seconds.toFixed(3),
      paper: {B: a.paper.B, gw: a.paper.gw, gh: a.paper.gh, W: a.paper.W, H: a.paper.H,
        col: b64(Uint8Array.from(a.paper.col, function (v) { return clamp(Math.round(v), 0, 255); }))},
      strokes: a.strokes.map(function (s) {
        var flat = [];
        s.p.forEach(function (p) { flat.push(q(p[0]), q(p[1]), q(p[2])); });
        return {k: s.k, c: s.c, t0: +s.t0.toFixed(3), t1: +s.t1.toFixed(3), p: flat};
      })
    };
  }
  function fromJSON(j) {
    if (typeof j === 'string') { j = JSON.parse(j); }
    return {
      v: j.v, w: j.w, h: j.h, lo: j.lo, hi: j.hi, seconds: j.seconds,
      paper: {B: j.paper.B, gw: j.paper.gw, gh: j.paper.gh, W: j.paper.W, H: j.paper.H, col: Float32Array.from(unb64(j.paper.col))},
      strokes: j.strokes.map(function (s) {
        var p = [];
        for (var i = 0; i < s.p.length; i += 3) { p.push([s.p[i], s.p[i + 1], s.p[i + 2]]); }
        return {k: s.k, c: s.c, t0: s.t0, t1: s.t1, p: p, len: polyLength(p)};
      })
    };
  }

  // ------------------------------------------------------------------------------------------------- player --
  function lessMotion() {
    return (root.document && document.documentElement.getAttribute('data-mov') === 'menos') ||
      (root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  /**
   * @param {HTMLCanvasElement} canvas where it is drawn (its width and height are the drawing's pixels)
   * @param {HTMLImageElement|HTMLCanvasElement} img the photo
   * @param {Object} a from analyze() or fromJSON()
   * @param {Object} [o] after: 'boil' | 'still' ('still'); boilPx: how far the ink wanders (0.9 px at 1000 px);
   *   boilFps (7); settle: seconds for the whole photo to settle in (0.5); onDone
   */
  function Player(canvas, img, a, o) {
    o = o || {};
    this.o = o; this.a = a; this.canvas = canvas; this.ctx = canvas.getContext('2d');
    var DW = canvas.width, DH = canvas.height, t0 = now();
    this.DW = DW; this.DH = DH;
    // the photo at the size it is shown
    var photo = canvasOf(DW, DH), pc = photo.getContext('2d', {willReadFrequently: true});
    pc.imageSmoothingQuality = 'high';
    pc.drawImage(img, 0, 0, DW, DH);
    this.photo = photo;
    var pd = pc.getImageData(0, 0, DW, DH), px = pd.data, N = DW * DH, i;
    var P = paperAt(a.paper, DW, DH);
    // soft ink alpha at this size, grown by a pixel: what moves in the boil and what the clean sheet paints out
    var alpha = new Float32Array(N);
    for (i = 0; i < N; i++) {
      var s = clamp(1 - Math.min(px[4 * i] / P[3 * i], px[4 * i + 1] / P[3 * i + 1], px[4 * i + 2] / P[3 * i + 2]), 0, 1);
      alpha[i] = smooth(a.lo * 0.7, a.hi, s);
    }
    alpha = grow(alpha, DW, DH, Math.max(1, Math.round(DW / 700)));
    this.alpha = alpha; this.P = P; this.px = px;
    // what the strokes will uncover, all of it: on the clean sheet it is paper, so the pale paper between the waxy bits
    // of a coloured-in area does not show before it is drawn
    var full = canvasOf(a.w, a.h), fx = full.getContext('2d');
    fx.strokeStyle = '#000'; fx.lineCap = 'round'; fx.lineJoin = 'round';
    a.strokes.forEach(function (st) { segment(fx, st.p, 0, polyLength(st.p) + 1); });
    var fullBig = canvasOf(DW, DH), fb = fullBig.getContext('2d', {willReadFrequently: true});
    fb.imageSmoothingEnabled = true; fb.drawImage(full, 0, 0, DW, DH);
    var cover = fb.getImageData(0, 0, DW, DH).data, coverF = new Float32Array(N);
    for (i = 0; i < N; i++) { coverF[i] = cover[4 * i + 3] / 255; }
    // Only ink near the strokes is painted out (and comes back at the end, slowly, if the strokes missed it); the rest
    // of the photo (a shadow in a corner, a speck far from everything) stays as it is from start to end, so the drawing
    // ends on the same sheet it was drawn on, with nothing that jumps.
    var nearS = grow(coverF, DW, DH, Math.max(2, Math.round(4 * DW / 1000))), soft = alpha;
    alpha = new Float32Array(N);
    for (i = 0; i < N; i++) { alpha[i] = Math.max(coverF[i], nearS[i] > 0.02 ? soft[i] : 0); }
    this.alpha = alpha;
    // the clean sheet: the photo with its ink painted out with the paper (and a grain like the paper's)
    var clean = canvasOf(DW, DH), cc = clean.getContext('2d'), cd = cc.createImageData(DW, DH), c = cd.data;
    var grainAmp = paperGrain(px, P, soft, DW, DH), rnd = rng(11);
    for (i = 0; i < N; i++) {
      var k = alpha[i], gn = (rnd() + rnd() - 1) * grainAmp;
      c[4 * i] = px[4 * i] * (1 - k) + (P[3 * i] + gn) * k;
      c[4 * i + 1] = px[4 * i + 1] * (1 - k) + (P[3 * i + 1] + gn) * k;
      c[4 * i + 2] = px[4 * i + 2] * (1 - k) + (P[3 * i + 2] + gn) * k;
      c[4 * i + 3] = 255;
    }
    cc.putImageData(cd, 0, 0);
    this.clean = clean;
    // the end: the clean sheet with all its ink, which is what the strokes leave plus what they missed near them
    var fin = canvasOf(DW, DH), fc = fin.getContext('2d'), fd = fc.createImageData(DW, DH), f = fd.data;
    for (i = 0; i < N; i++) {
      var ka = alpha[i];
      f[4 * i] = c[4 * i] * (1 - ka) + px[4 * i] * ka;
      f[4 * i + 1] = c[4 * i + 1] * (1 - ka) + px[4 * i + 1] * ka;
      f[4 * i + 2] = c[4 * i + 2] * (1 - ka) + px[4 * i + 2] * ka;
      f[4 * i + 3] = 255;
    }
    fc.putImageData(fd, 0, 0);
    this.final = fin;
    // the brush mask, at the size of the analysis, and a scratch layer at the size shown
    this.mask = canvasOf(a.w, a.h); this.mctx = this.mask.getContext('2d');
    this.layer = canvasOf(DW, DH); this.lctx = this.layer.getContext('2d');
    this.done = 0; this.drawnTo = -1;
    this.boilFrames = null;
    this.setup = now() - t0;
    if ((o.after || 'still') === 'boil') { this.makeBoil(); }
  }

  function grow(a, W, H, r) {
    var out = new Float32Array(W * H), tmp = new Float32Array(W * H), x, y, k;
    for (y = 0; y < H; y++) {
      for (x = 0; x < W; x++) {
        var m = 0;
        for (k = -r; k <= r; k++) { var xx = x + k; if (xx >= 0 && xx < W && a[y * W + xx] > m) { m = a[y * W + xx]; } }
        tmp[y * W + x] = m;
      }
    }
    for (y = 0; y < H; y++) {
      for (x = 0; x < W; x++) {
        var n = 0;
        for (k = -r; k <= r; k++) { var yy = y + k; if (yy >= 0 && yy < H && tmp[yy * W + x] > n) { n = tmp[yy * W + x]; } }
        out[y * W + x] = n;
      }
    }
    return out;
  }

  function paperGrain(px, P, alpha, W, H) {
    var s = 0, n = 0;
    for (var i = 0; i < W * H; i += 7) {
      if (alpha[i] > 0.01) { continue; }
      var d = px[4 * i + 1] - P[3 * i + 1]; s += d * d; n++;
    }
    return n ? Math.min(6, Math.sqrt(s / n)) : 1.5;
  }

  Player.prototype.makeBoil = function () {
    var t0 = now(), DW = this.DW, DH = this.DH, N = DW * DH, px = this.px, alpha = this.alpha;
    var cd0 = this.clean.getContext('2d').getImageData(0, 0, DW, DH).data;
    var amp = (this.o.boilPx || 0.9) * DW / 1000, cell = Math.max(24, DW / 16), frames = [];
    for (var f = 0; f < 3; f++) {
      var rnd = rng(101 + f * 37), gw = Math.ceil(DW / cell) + 2, gh = Math.ceil(DH / cell) + 2;
      var gx = new Float32Array(gw * gh), gy = new Float32Array(gw * gh), i;
      for (i = 0; i < gw * gh; i++) { var an = rnd() * Math.PI * 2, m = 0.5 + 0.5 * rnd(); gx[i] = Math.cos(an) * m; gy[i] = Math.sin(an) * m; }
      var cvs = canvasOf(DW, DH), ctx = cvs.getContext('2d'), id = ctx.createImageData(DW, DH), o = id.data;
      for (var y = 0; y < DH; y++) {
        var fy = y / cell, iy = Math.floor(fy), ty = fy - iy; ty = ty * ty * (3 - 2 * ty);
        for (var x = 0; x < DW; x++) {
          var fx = x / cell, ix = Math.floor(fx), tx = fx - ix; tx = tx * tx * (3 - 2 * tx);
          var a00 = iy * gw + ix, a01 = a00 + 1, a10 = a00 + gw, a11 = a10 + 1;
          var dx = ((gx[a00] * (1 - tx) + gx[a01] * tx) * (1 - ty) + (gx[a10] * (1 - tx) + gx[a11] * tx) * ty) * amp;
          var dy = ((gy[a00] * (1 - tx) + gy[a01] * tx) * (1 - ty) + (gy[a10] * (1 - tx) + gy[a11] * tx) * ty) * amp;
          var sx = clamp(x + dx, 0, DW - 1.001), sy = clamp(y + dy, 0, DH - 1.001), x0 = sx | 0, y0 = sy | 0,
            u = sx - x0, v = sy - y0, j00 = y0 * DW + x0, j01 = j00 + 1, j10 = j00 + DW, j11 = j10 + 1;
          var w00 = (1 - u) * (1 - v), w01 = u * (1 - v), w10 = (1 - u) * v, w11 = u * v;
          var k = alpha[j00] * w00 + alpha[j01] * w01 + alpha[j10] * w10 + alpha[j11] * w11, oi = 4 * (y * DW + x);
          for (var c = 0; c < 3; c++) {
            var src = px[4 * j00 + c] * w00 + px[4 * j01 + c] * w01 + px[4 * j10 + c] * w10 + px[4 * j11 + c] * w11;
            o[oi + c] = cd0[oi + c] * (1 - k) + src * k;
          }
          o[oi + 3] = 255;
        }
      }
      ctx.putImageData(id, 0, 0);
      frames.push(cvs);
    }
    this.boilFrames = frames;
    this.boilSetup = now() - t0;
  };

  // what the strokes missed comes in slowly at the end (1.6 s), over a frame that is otherwise already the end
  Player.prototype.settle = function () { return this.o.settle == null ? 1.6 : this.o.settle; };
  Player.prototype.total = function () { return this.a.seconds + this.settle(); };

  // Draw the strokes into the brush mask up to time t (forward only; going back starts again).
  Player.prototype.brushTo = function (t) {
    var m = this.mctx, S = this.a.strokes;
    if (t < this.drawnTo) { m.clearRect(0, 0, this.a.w, this.a.h); this.done = 0; this.drawnTo = -1; S.forEach(function (s) { s._at = 0; }); }
    m.strokeStyle = '#000'; m.fillStyle = '#000'; m.lineCap = 'round'; m.lineJoin = 'round';
    for (var i = this.done; i < S.length; i++) {
      var s = S[i];
      if (s.t0 > t) { break; }
      var u = clamp((t - s.t0) / (s.t1 - s.t0), 0, 1);
      u = 0.75 * u + 0.25 * (0.5 - 0.5 * Math.cos(Math.PI * u));   // the pen starts and ends a touch slower
      var upto = u * s.len, from = s._at || 0;
      if (upto > from) { segment(m, s.p, from, upto); s._at = upto; }
      if (u >= 1) { this.done = i + 1; }
    }
    this.drawnTo = t;
  };

  function segment(m, p, from, upto) {
    var acc = 0;
    for (var j = 1; j < p.length; j++) {
      var a = p[j - 1], b = p[j], L = Math.hypot(b[0] - a[0], b[1] - a[1]);
      if (acc + L < from) { acc += L; continue; }
      if (acc > upto) { break; }
      var u0 = L ? clamp((from - acc) / L, 0, 1) : 0, u1 = L ? clamp((upto - acc) / L, 0, 1) : 1;
      var x0 = a[0] + (b[0] - a[0]) * u0, y0 = a[1] + (b[1] - a[1]) * u0, x1 = a[0] + (b[0] - a[0]) * u1,
        y1 = a[1] + (b[1] - a[1]) * u1, r = a[2] + (b[2] - a[2]) * (u0 + u1) / 2;
      m.lineWidth = 2 * r;
      m.beginPath(); m.moveTo(x0, y0); m.lineTo(x1 + 0.01, y1); m.stroke();
      acc += L;
    }
  }

  /** The frame at t seconds from the start (blank sheet at 0). */
  Player.prototype.renderAt = function (t) {
    var c = this.ctx, DW = this.DW, DH = this.DH, T = this.a.seconds, settle = this.settle();
    if (t >= T + settle) {
      var wait = this.o.boilDelay || 0;
      if (this.boilFrames && (this.o.after || 'still') === 'boil' && !this.noBoil && t >= T + settle + wait) {
        var fps = this.o.boilFps || 7, f = Math.floor((t - T - settle - wait) * fps) % 3;
        c.drawImage(this.boilFrames[f], 0, 0);
      } else {
        c.drawImage(this.final, 0, 0);
      }
      return;
    }
    this.brushTo(Math.min(t, T));
    c.globalAlpha = 1; c.globalCompositeOperation = 'source-over';
    c.drawImage(this.clean, 0, 0);
    var l = this.lctx;
    l.globalCompositeOperation = 'copy';
    l.imageSmoothingEnabled = true;
    l.drawImage(this.mask, 0, 0, DW, DH);
    l.globalCompositeOperation = 'source-in';
    l.drawImage(this.photo, 0, 0);
    l.globalCompositeOperation = 'source-over';
    c.drawImage(this.layer, 0, 0);
    if (t > T) {
      c.globalAlpha = smooth(0, 1, (t - T) / settle);
      c.drawImage(this.final, 0, 0);
      c.globalAlpha = 1;
    }
  };

  Player.prototype.play = function () {
    var self = this;
    cancelAnimationFrame(this.raf);
    if (lessMotion()) { this.ctx.drawImage(this.final, 0, 0); return; }
    var start = null;
    function step(ts) {
      if (start === null) { start = ts; }
      var t = (ts - start) / 1000;
      self.renderAt(t);
      if (t < self.total() || ((self.o.after || 'still') === 'boil' && !self.noBoil)) {
        self.raf = requestAnimationFrame(step);
      } else if (self.o.onDone) { self.o.onDone(); }
    }
    this.raf = requestAnimationFrame(step);
  };
  Player.prototype.stop = function () { cancelAnimationFrame(this.raf); };

  root.DibujoVivo = {analyze: analyze, toJSON: toJSON, fromJSON: fromJSON, Player: Player, paperAt: paperAt,
    edt: edt, morph: morph, components: components, thin: thin, lessMotion: lessMotion, canvasOf: canvasOf};
})(typeof window !== 'undefined' ? window : this);
