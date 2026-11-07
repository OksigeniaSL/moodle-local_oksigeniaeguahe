// A try of the Worker without Cloudflare: it reads an email (.eml) and does what the Worker would do with it.
//   EGUAHE_MAP='{"prueba@entrada.eguahe.com": {"url": "https://…/hook.php?e=…", "key": "…"}}' \
//     node test.mjs prueba@entrada.eguahe.com sample.eml
// It prints what it did: rejected (and why), or the answer of the entry.
import { readFile } from 'node:fs/promises';
import worker from './worker.js';

const [to, file] = process.argv.slice(2);
const raw = await readFile(file);
let rejected = null;
const message = {
  to,
  rawSize: raw.length,
  raw: new Blob([raw]).stream(),
  setReject: (reason) => { rejected = reason; },
};
const fetched = globalThis.fetch;
globalThis.fetch = async (url, options) => {
  const reply = await fetched(url, options);
  console.log('entry answered', reply.status, await reply.clone().text());
  return reply;
};
await worker.email(message, { EGUAHE_MAP: process.env.EGUAHE_MAP || '{}' });
console.log(rejected ? 'rejected: ' + rejected : 'sent');
