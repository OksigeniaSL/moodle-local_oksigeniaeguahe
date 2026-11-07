# The Email Worker of Eguahe

A Cloudflare Email Worker for the entry addresses of Eguahe (`<centre>@entrada.eguahe.com`, or any domain of whoever
runs it). Each centre forwards its mailbox of admissions to its address; each email goes, signed, to the entry of kind
«email» of its centre, in its own Moodle. Nothing is kept in the Worker.

## Setting it up

1. Email Routing on the domain (or subdomain) of the entry addresses, with a catch-all rule to this Worker.
2. The Worker: `worker.js`, as it is.
3. Its secret `EGUAHE_MAP`, in JSON: each address with the address of its entry and its key, as the page of the entry
   shows them («Entries» › the entry of kind email):

   ```json
   {"almendros@entrada.eguahe.com": {"url": "https://moodle.example/local/oksigeniaeguahe/hook.php?e=…", "key": "…"}}
   ```

## Adding a centre

Create in its Moodle an entry of kind «email», write in its settings the address it will have, and add a line to the
map with that address, the address of the entry and its key (`wrangler secret put EGUAHE_MAP`, the whole JSON again).
If the key of the entry is changed, the map has to change too.

## What it does

- An address that is not in the map: rejected. No key travels in the address.
- Larger than 2 MB: rejected. Attachments are taken out before sending, and at most 256 KB is sent.
- The body is `{"items": [{"raw": "<the message in base64>"}]}`, signed with HMAC-SHA256 in `X-Eguahe-Signature`.
- If the entry does not take it, the email is rejected; the centre still has it in its own mailbox.

## Trying it without Cloudflare

With Node 18 or newer:

```sh
EGUAHE_MAP='{"prueba@entrada.eguahe.com": {"url": "https://…/hook.php?e=…", "key": "…"}}' \
  node test.mjs prueba@entrada.eguahe.com sample.eml
```

It prints the answer of the entry (`{"ok":true,"new":1,…}`) and the family appears in the inbox of the centre. The
same email again gives `"already":1`.
