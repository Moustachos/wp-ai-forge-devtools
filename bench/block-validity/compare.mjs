// Spec S18 Phase B measurement: compares the editor's verdicts before and after
// the final repair pass. Fails when a valid document or template was changed,
// or when a document has more invalid blocks of a kind after than before.
//
//   node compare.mjs [--since=2026-08-01]
//
// Needs out/editor.jsonl (before), out/repaired/editor.jsonl (after) and
// out/repaired/changed.json (from repair.php).

import { readFileSync } from 'node:fs';

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const since = args.since ?? '2026-08-01';

const load = (file) => {
    const rows = new Map();
    for (const line of readFileSync(file, 'utf8').split('\n')) {
        if (!line.trim()) continue;
        const r = JSON.parse(line);
        rows.set(`${r.kind}:${r.id ?? r.hash}`, r);
    }
    return rows;
};

const before = load('out/editor.jsonl');
const after = load('out/repaired/editor.jsonl');
const changed = JSON.parse(readFileSync('out/repaired/changed.json', 'utf8'));
const changedKeys = new Set([
    ...changed.templates.map((h) => `template:${h}`),
    ...changed.generations.map((id) => `generation:${id}`),
]);

let failures = 0;
const fail = (msg) => {
    failures++;
    console.log(`FAIL  ${msg}`);
};

if (changed.templates.length) fail(`${changed.templates.length} templates changed`);

for (const [key, r] of before) {
    if (r.invalid.length === 0 && changedKeys.has(key)) fail(`${key} was valid and the pass changed it`);
}

// Block level: per document, invalid blocks of each kind after <= before.
const tally = (list) => list.reduce((m, x) => m.set(x.block, (m.get(x.block) ?? 0) + 1), new Map());
for (const [key, a] of after) {
    const b = before.get(key);
    if (!b) {
        fail(`${key} is missing from the baseline`);
        continue;
    }
    const was = tally(b.invalid);
    for (const [block, n] of tally(a.invalid)) {
        if (n > (was.get(block) ?? 0)) fail(`${key}: ${block} invalid ${n}x after, ${was.get(block) ?? 0}x before`);
    }
}

const invalidSince = [...before.values()].filter((r) => r.kind === 'generation' && r.created_at >= since && r.invalid.length);
let fixed = 0;
for (const r of invalidSince) {
    const a = after.get(`generation:${r.id}`);
    const ok = a !== undefined && a.invalid.length === 0;
    if (ok) fixed++;
    console.log(`${ok ? 'valid  ' : 'invalid'}  ${r.id}  ${r.model_id ?? ''}  ${ok ? '' : a.invalid.map((x) => x.block).join(', ')}`);
}

const count = (rows) => [...rows.values()].filter((r) => r.kind === 'generation' && r.invalid.length).length;
console.log(`\n${fixed}/${invalidSince.length} documents invalid since ${since} are valid after the pass`);
console.log(`whole corpus: ${count(before)} invalid documents before, ${count(after)} after`);
console.log(failures ? `\n${failures} property failures` : '\nno-op and block-level no-regression hold');
process.exit(failures ? 1 : 0);
