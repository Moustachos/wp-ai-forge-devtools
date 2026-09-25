// Ground truth for the spike: parses every exported document with the lab
// site's own editor (its exact Gutenberg and every block the site registers)
// and records which blocks come out isValid === false.
// Runs on the host with the smoke harness's Playwright. Writes out/editor.jsonl.
//
//   node editor.mjs

import { createReadStream, createWriteStream } from 'node:fs';
import { createInterface } from 'node:readline';
import { chromium } from '../../smoke/node_modules/playwright/index.mjs';
import { ADMIN_PASSWORD, ADMIN_USER, BASE_URL } from '../../smoke/lib/config.js';

const browser = await chromium.launch();
const page = await browser.newPage();

await page.goto(`${BASE_URL}/wp-login.php`);
await page.fill('#user_login', ADMIN_USER);
await page.fill('#user_pass', ADMIN_PASSWORD);
await page.click('#wp-submit');
await page.waitForURL((url) => url.pathname.includes('/wp-admin/'));

await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`);
await page.waitForFunction(() => window.wp?.blocks?.getBlockTypes?.().length > 50, null, { timeout: 60000 });

const version = await page.evaluate(() => ({
    blockTypes: window.wp.blocks.getBlockTypes().length,
    wp: document.querySelector('meta[name="generator"]')?.content ?? null,
}));
process.stderr.write(`editor ready: ${JSON.stringify(version)}\n`);

const sink = createWriteStream('out/editor.jsonl');

async function run(file, kind) {
    const lines = createInterface({ input: createReadStream(file), crlfDelay: Infinity });
    let n = 0;
    for await (const line of lines) {
        if (!line.trim()) continue;
        const doc = JSON.parse(line);
        if (!doc.content) continue;

        const res = await page.evaluate((markup) => {
            const flatten = (list, out = []) => {
                for (const b of list) {
                    out.push(b);
                    flatten(b.innerBlocks ?? [], out);
                }
                return out;
            };
            const all = flatten(window.wp.blocks.parse(markup)).filter((b) => b.name !== null);
            return {
                blocks: all.length,
                invalid: all.filter((b) => b.isValid === false && b.name !== 'core/missing').map((b) => b.name),
                missing: all.filter((b) => b.name === 'core/missing').map((b) => b.attributes?.originalName ?? '?'),
            };
        }, doc.content);

        sink.write(JSON.stringify({ kind, id: doc.id ?? null, hash: doc.hash ?? null, ...res }) + '\n');
        if (++n % 200 === 0) process.stderr.write(`${kind}: ${n}\n`);
    }
    return n;
}

const t = await run('out/templates.jsonl', 'template');
const g = await run('out/generations.jsonl', 'generation');
sink.end();
await browser.close();
process.stderr.write(`done: ${t} templates, ${g} generations\n`);
