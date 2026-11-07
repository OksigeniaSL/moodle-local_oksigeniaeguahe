// This file is part of Oksigenia Eguahe, a local plugin for Moodle.
//
// Eguahe is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
//
// It is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
//
// Copyright 2026 Oksigenia SL <dev@oksigenia.cc>

/**
 * A Cloudflare Email Worker for the entry addresses of Eguahe: each centre forwards its mailbox of admissions to its
 * address, and each email goes, signed, to the entry of its centre. Nothing is kept here.
 *
 * - An address that is not in the map is rejected.
 * - No key travels in the address: the map is a secret of the Worker, EGUAHE_MAP, in JSON:
 *     {"almendros@entrada.eguahe.com": {"url": "https://moodle.example/local/oksigeniaeguahe/hook.php?e=…",
 *                                       "key": "the key of the entry"}}
 * - Larger than MAX_RAW, it is rejected; attachments are taken out before sending, and what is sent is at most MAX_SENT.
 */

const MAX_RAW = 2 * 1024 * 1024;
const MAX_SENT = 256 * 1024;

export default {
  async email(message, env) {
    let map;
    try {
      map = JSON.parse(env.EGUAHE_MAP || '{}');
    } catch (e) {
      message.setReject('Eguahe: the map is not valid');
      return;
    }
    const door = map[String(message.to || '').toLowerCase()];
    if (!door || !door.url || !door.key) {
      message.setReject('Unknown address');
      return;
    }
    if (message.rawSize > MAX_RAW) {
      message.setReject('Message too large');
      return;
    }
    const bytes = new Uint8Array(await new Response(message.raw).arrayBuffer());
    const text = withoutAttachments(bytes);
    const body = JSON.stringify({ items: [{ raw: base64(text) }] });
    const reply = await fetch(door.url, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-eguahe-signature': 'sha256=' + await sign(body, door.key) },
      body,
    });
    if (!reply.ok) {
      // The centre keeps the email in its own mailbox; here it is only said that it did not come in.
      message.setReject('Eguahe: ' + reply.status);
    }
  },
};

/**
 * The message without its attachments: the parts of the top multipart that are not text, or say they are attached.
 * One byte, one character (windows-1252), so positions in the text are positions in the bytes.
 *
 * @param {Uint8Array} bytes
 * @returns {Uint8Array}
 */
export function withoutAttachments(bytes) {
  const text = new TextDecoder('windows-1252').decode(bytes);
  const head = text.slice(0, Math.max(0, text.search(/\r?\n\r?\n/)));
  const boundary = /content-type:\s*multipart\/[^;]+;[\s\S]*?boundary="?([^"\r\n;]+)"?/i.exec(head);
  let keep = [[0, bytes.length]];
  if (boundary) {
    const mark = '--' + boundary[1];
    keep = [];
    let from = 0;
    let at = text.indexOf(mark);
    while (at !== -1) {
      const next = text.indexOf(mark, at + mark.length);
      const part = text.slice(at, next === -1 ? text.length : next);
      const partHead = part.slice(0, Math.max(0, part.search(/\r?\n\r?\n/))).toLowerCase();
      const attached = /content-disposition:\s*attachment/.test(partHead) ||
        (/content-type:/.test(partHead) && !/content-type:\s*(text|multipart)\//.test(partHead));
      keep.push([from, at]);
      if (!attached) {
        keep.push([at, next === -1 ? text.length : next]);
      }
      from = next === -1 ? text.length : next;
      at = next;
    }
    keep.push([from, text.length]);
  }
  const out = new Uint8Array(MAX_SENT);
  let length = 0;
  for (const [start, end] of keep) {
    const piece = bytes.subarray(start, Math.min(end, start + MAX_SENT - length));
    out.set(piece, length);
    length += piece.length;
    if (length >= MAX_SENT) {
      break;
    }
  }
  return out.subarray(0, length);
}

/**
 * Base64 of some bytes.
 *
 * @param {Uint8Array} bytes
 * @returns {string}
 */
function base64(bytes) {
  let binary = '';
  for (let i = 0; i < bytes.length; i += 0x8000) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  }
  return btoa(binary);
}

/**
 * The HMAC-SHA256 of the body with the key of the entry, in hexadecimal.
 *
 * @param {string} body
 * @param {string} secret
 * @returns {Promise<string>}
 */
async function sign(body, secret) {
  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false,
    ['sign']);
  const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, encoder.encode(body)));
  return Array.from(mac, (b) => b.toString(16).padStart(2, '0')).join('');
}
