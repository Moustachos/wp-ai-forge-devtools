// Validates every exported generation and template twice:
//  - block-runner's validate(), the tool under evaluation;
//  - parse() + isValid, which is what the editor (and AI Forge's recovery
//    safety net) actually acts on, deprecations included.
// Writes out/results.jsonl, one line per document.

import { createReadStream, createWriteStream } from 'node:fs';
import { createRequire } from 'node:module';
import { createInterface } from 'node:readline';
import { validate } from 'block-runner';

const require = createRequire(createRequire(import.meta.url).resolve('block-runner'));
const blocks = require('@wordpress/blocks');

const muted = (fn) => {
    const saved = {};
    for (const level of ['log', 'info', 'warn', 'error', 'debug', 'group', 'groupCollapsed', 'groupEnd']) {
        saved[level] = console[level];
        console[level] = () => {};
    }
    try {
        return fn();
    } finally {
        Object.assign(console, saved);
    }
};

const flatten = (list, out = []) => {
    for (const block of list) {
        out.push(block);
        flatten(block.innerBlocks ?? [], out);
    }
    return out;
};

function editorPass(markup) {
    const parsed = muted(() => blocks.parse(markup, { __unstableSkipMigrationLogs: true }));
    const all = flatten(parsed).filter((b) => b.name !== null);
    const invalid = all.filter((b) => b.isValid === false && b.name !== 'core/missing');
    const missing = all.filter((b) => b.name === 'core/missing').map((b) => b.attributes?.originalName ?? '?');
    return {
        blocks: all.length,
        invalid: invalid.map((b) => b.name),
        missing,
    };
}

async function run(file, kind, sink) {
    const lines = createInterface({ input: createReadStream(file), crlfDelay: Infinity });
    let n = 0;
    for await (const line of lines) {
        if (!line.trim()) continue;
        const doc = JSON.parse(line);
        const { content, ...rest } = doc;
        if (!content) continue;

        const br = await validate(content);
        const ed = editorPass(content);

        sink.write(JSON.stringify({
            kind,
            ...rest,
            br_blocks: br.summary.blocks,
            br_invalid: br.items.filter((i) => i.status === 'invalid').map((i) => ({ block: i.block, reason: i.reason.slice(0, 4000) })),
            ed_blocks: ed.blocks,
            ed_invalid: ed.invalid,
            ed_missing: ed.missing,
        }) + '\n');

        if (++n % 100 === 0) process.stderr.write(`${kind}: ${n}\n`);
    }
    return n;
}

const sink = createWriteStream('out/results.jsonl');
const t = await run('out/templates.jsonl', 'template', sink);
const g = await run('out/generations.jsonl', 'generation', sink);
sink.end();
process.stderr.write(`done: ${t} templates, ${g} generations\n`);
