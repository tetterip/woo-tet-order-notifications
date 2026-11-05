// Regenerates languages/<domain>.pot, -el.po and -el.mo from the PHP sources + el.mjs.
// The text domain and plugin name are read from the main plugin file's header.
// No WP-CLI / gettext needed. Usage (from the plugin root): node tools/i18n/build.mjs
// Fails if a string has no Greek translation, a translation is unused, or placeholders differ.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import el from './el.mjs';
import extract from './extract.mjs';

const dir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const mainFile = fs.readdirSync(dir)
  .filter((f) => f.endsWith('.php'))
  .map((f) => fs.readFileSync(path.join(dir, f), 'utf8'))
  .find((src) => /^\s*\*\s*Plugin Name:/m.test(src));
const headerField = (name) => mainFile.match(new RegExp(String.raw`^\s*\*\s*${name}:\s*(.+)$`, 'm'))[1].trim();
const domain = headerField('Text Domain');
const pluginName = headerField('Plugin Name');
const strings = extract(dir, domain);
const date = process.env.PO_DATE || '2026-09-25 12:00+0300';
const key = (e) => (e.ctx ? e.ctx + '\u0004' : '') + e.id;

// ── Checks ────────────────────────────────────────────────
const errors = [];
const ph = (s) => (s.match(/%(\d+\$)?[sd]/g) || []).sort().join(',');
for (const e of strings) {
  const t = el[key(e)];
  if (t === undefined) { errors.push('missing: ' + e.id); continue; }
  if (Array.isArray(t) !== Boolean(e.plural)) errors.push('plural mismatch: ' + e.id);
  for (const s of [].concat(t)) if (ph(s) !== ph(e.id)) errors.push(`placeholders differ: ${e.id} → ${s}`);
  if (/<span/.test(e.id)) for (const s of [].concat(t)) if (!s.includes('<span class="count">')) errors.push('markup lost: ' + e.id);
}
const known = new Set(strings.map(key));
for (const k of Object.keys(el)) if (!known.has(k)) errors.push('unused translation: ' + k);
if (errors.length) { console.error(errors.join('\n')); process.exit(1); }

// ── PO / POT ──────────────────────────────────────────────
const q = (s) => '"' + s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n') + '"';
const header = (lang) => [
  `Project-Id-Version: ${pluginName}`,
  'Report-Msgid-Bugs-To: https://ttrp.gr',
  `POT-Creation-Date: ${date}`,
  `PO-Revision-Date: ${lang ? date : 'YEAR-MO-DA HO:MI+ZONE'}`,
  `Last-Translator: ${lang ? 'ttrp.gr' : 'FULL NAME <EMAIL@ADDRESS>'}`,
  `Language-Team: ${lang ? 'ttrp.gr' : 'LANGUAGE <LL@li.org>'}`,
  ...(lang ? [`Language: ${lang}`] : []),
  'MIME-Version: 1.0',
  'Content-Type: text/plain; charset=UTF-8',
  'Content-Transfer-Encoding: 8bit',
  ...(lang ? ['Plural-Forms: nplurals=2; plural=(n != 1);'] : []),
  `X-Domain: ${domain}`,
].map((l) => l + '\n').join('');

function po(lang) {
  const out = [
    lang ? `# Greek translation for ${pluginName}.` : `# Translation template for ${pluginName}.`,
    '# Copyright (C) ttrp.gr',
    '# This file is distributed under the GPL-2.0+ license.',
    ...(lang ? ['#'] : ['#, fuzzy']),
    'msgid ""',
    'msgstr ""',
    ...header(lang).split(/(?<=\n)/).map(q),
    '',
  ];
  for (const e of strings) {
    if (e.comment) out.push('#. ' + e.comment);
    out.push('#: ' + e.refs.join(' '));
    if (e.ctx) out.push('msgctxt ' + q(e.ctx));
    out.push('msgid ' + q(e.id));
    const t = lang ? el[key(e)] : null;
    if (e.plural) {
      out.push('msgid_plural ' + q(e.plural));
      out.push('msgstr[0] ' + q(t ? t[0] : ''), 'msgstr[1] ' + q(t ? t[1] : ''));
    } else {
      out.push('msgstr ' + q(t || ''));
    }
    out.push('');
  }
  return out.join('\n');
}

// ── MO (GNU gettext binary, no hash table) ────────────────
function mo() {
  const entries = [['', header('el')]];
  for (const e of strings) {
    const t = el[key(e)];
    const orig = (e.ctx ? e.ctx + '\u0004' : '') + e.id + (e.plural ? '\0' + e.plural : '');
    entries.push([orig, Array.isArray(t) ? t.join('\0') : t]);
  }
  entries.sort((a, b) => Buffer.compare(Buffer.from(a[0]), Buffer.from(b[0])));
  const n = entries.length;
  const origTable = 28, transTable = origTable + n * 8, dataStart = transTable + n * 8;
  const bufs = [];
  let offset = dataStart;
  const table = Buffer.alloc(n * 16);
  const place = (s, idx, tableOffset) => {
    const b = Buffer.from(s, 'utf8');
    table.writeUInt32LE(b.length, tableOffset + idx * 8);
    table.writeUInt32LE(offset, tableOffset + idx * 8 + 4);
    bufs.push(b, Buffer.from([0]));
    offset += b.length + 1;
  };
  entries.forEach(([o], i) => place(o, i, 0));
  entries.forEach(([, t], i) => place(t, i, n * 8));
  const head = Buffer.alloc(28);
  [0x950412de, 0, n, origTable, transTable, 0, dataStart].forEach((v, i) => head.writeUInt32LE(v, i * 4));
  return Buffer.concat([head, table, ...bufs]);
}

const out = path.join(dir, 'languages');
fs.writeFileSync(path.join(out, `${domain}.pot`), po(null));
fs.writeFileSync(path.join(out, `${domain}-el.po`), po('el'));
fs.writeFileSync(path.join(out, `${domain}-el.mo`), mo());
console.log(`${strings.length} strings → ${domain}.pot, ${domain}-el.po, ${domain}-el.mo`);
