// Extracts gettext calls for one text domain from a plugin's PHP files.
// Used by build.mjs. Returns [{ctx, id, plural, refs, comment}] in file order.
import fs from 'fs';
import path from 'path';

export default function extract(dir, domain) {
const files = [];
(function walk(d) {
  for (const f of fs.readdirSync(d)) {
    const p = path.join(d, f);
    if (['ttrp-common', 'node_modules', '.git', 'tools'].includes(f)) continue;
    if (fs.statSync(p).isDirectory()) walk(p);
    else if (p.endsWith('.php')) files.push(p);
  }
})(dir);

// PHP single-quoted string literal.
const STR = String.raw`'((?:[^'\\]|\\.)*)'`;
const unq = (s) => s.replace(/\\(['\\])/g, '$1');
const specs = [
  { re: new RegExp(String.raw`\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*${STR}\s*,\s*'${domain}'`, 'g'), map: (m) => ({ id: m[1] }) },
  { re: new RegExp(String.raw`\b(?:_x|esc_html_x|esc_attr_x)\(\s*${STR}\s*,\s*${STR}\s*,\s*'${domain}'`, 'g'), map: (m) => ({ id: m[1], ctx: m[2] }) },
  { re: new RegExp(String.raw`\b(?:_n|_n_noop)\(\s*${STR}\s*,\s*${STR}\s*,(?:[^,]*,)?\s*'${domain}'`, 'g'), map: (m) => ({ id: m[1], plural: m[2] }) },
];

const entries = new Map();
for (const file of files.sort()) {
  const src = fs.readFileSync(file, 'utf8');
  const rel = path.relative(dir, file).replace(/\\/g, '/');
  for (const { re, map } of specs) {
    for (const m of src.matchAll(re)) {
      const e = map(m);
      for (const k of Object.keys(e)) e[k] = unq(e[k]);
      const line = src.slice(0, m.index).split('\n').length;
      // "translators:" comment within the 3 lines above the call.
      const before = src.slice(0, m.index).split('\n').slice(-4).join('\n');
      const tc = [...before.matchAll(/\/\*\s*(translators:[^*]*)\*\//g)].pop();
      const key = (e.ctx || '') + '\u0004' + e.id;
      const cur = entries.get(key) || { ...e, refs: [], comment: '' };
      if (!cur.refs.includes(`${rel}:${line}`)) cur.refs.push(`${rel}:${line}`);
      if (tc && !cur.comment) cur.comment = tc[1].trim();
      entries.set(key, cur);
    }
  }
}
return [...entries.values()];
}
