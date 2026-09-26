// Classifies the blocks the real editor rejects, from the validateBlock()
// diffs editor.mjs stores in out/editor.jsonl.
//
//   node classify.mjs [--since=2026-08-01] [--verbose]

import { readFileSync } from 'node:fs';

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const since = args.since ?? '0000';

const read = (f) => readFileSync(f, 'utf8').split('\n').filter(Boolean).map(JSON.parse);

const decls = (s) => new Map(s.split(';').map((d) => d.trim()).filter(Boolean).map((d) => {
    const i = d.indexOf(':');
    return [d.slice(0, i).trim(), d.slice(i + 1).trim()];
}));

function styleDiff(expected, saw) {
    const e = decls(expected), s = decls(saw);
    const out = [];
    for (const [k, v] of e) if (s.get(k) !== v) out.push(s.has(k) ? `${k}: ${s.get(k)} -> want ${v}` : `html lacks ${k}:${v}`);
    for (const [k, v] of s) if (!e.has(k)) out.push(`html extra ${k}:${v}`);
    return out;
}

function classify(reason) {
    if (/["']wp-block-columns [^"']*wp-block-column /.test(reason)) return ['wrapper: columns comment around a column', [reason.slice(0, 200)]];
    let m = reason.match(/Expected attribute `style` of value `([^`]*)`, saw `([^`]*)`/);
    if (m) {
        const diff = styleDiff(m[1], m[2]);
        if (diff.some((d) => /#(?![0-9a-f]{3,8}\b)[a-z]+/i.test(d))) return ['style: invalid css value', diff];
        if (diff.some((d) => /spacing--spacing/.test(d))) return ['style: malformed preset token in json', diff];
        if (diff.every((d) => d.startsWith('html extra'))) return ['style: html carries css the json does not', diff];
        if (diff.every((d) => d.startsWith('html lacks'))) return ['style: json carries css the html lost', diff];
        return ['style: value mismatch', diff];
    }
    if (/Expected attributes \[.*\], instead saw \[.*["']style["']/s.test(reason) && !/Expected attributes \[[^\]]*["']style["']/s.test(reason)) {
        return ['style: html carries css the json does not', [reason.slice(0, 300)]];
    }
    if (/Expected attribute `class`/.test(reason)) return ['class mismatch', [reason.slice(0, 300)]];
    if (/end of content|EndTag|StartTag|Comment/.test(reason)) return ['structure: unbalanced or misnested html', [reason.slice(0, 200)]];
    if (/Expected attributes/.test(reason)) return ['attributes mismatch', [reason.slice(0, 300)]];
    return ['other', [reason.slice(0, 300)]];
}

const families = new Map();
let docs = 0;
for (const r of read('out/editor.jsonl')) {
    if (r.kind !== 'generation' || r.created_at < since || !r.invalid.length) continue;
    docs++;
    for (const item of r.invalid) {
        const [family, detail] = classify(item.reason);
        if (!families.has(family)) families.set(family, []);
        families.get(family).push({ id: r.id, model: r.model_id ?? r.provider, block: item.block, detail });
    }
}

console.log(`${docs} invalid generations since ${since}`);
for (const [family, list] of [...families].sort((a, b) => b[1].length - a[1].length)) {
    console.log(`\n${family}: ${list.length} blocks in ${new Set(list.map((x) => x.id)).size} docs`);
    for (const x of list.slice(0, args.verbose ? 50 : 4)) console.log(`   ${x.id} ${x.model} ${x.block}  ${x.detail.join(' | ').slice(0, 260)}`);
}
