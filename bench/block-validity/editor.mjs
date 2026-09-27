// Parses every exported document with the lab site's own editor (its exact
// Gutenberg and every block the site registers), records which blocks come out
// isValid === false, and keeps validateBlock()'s diff for each of them.
// Runs on the host with the smoke harness's Playwright. Writes out/editor.jsonl.
//
//   node editor.mjs [--dir=out/repaired]

import { createReadStream, createWriteStream } from 'node:fs';
import { createInterface } from 'node:readline';
import { chromium } from '../../smoke/node_modules/playwright/index.mjs';
import { ADMIN_PASSWORD, ADMIN_USER, BASE_URL } from '../../smoke/lib/config.js';

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const dir = args.dir ?? 'out';

const browser = await chromium.launch();
const page = await browser.newPage();

await page.goto(`${BASE_URL}/wp-login.php`);
await page.fill('#user_login', ADMIN_USER);
await page.fill('#user_pass', ADMIN_PASSWORD);
await page.click('#wp-submit');
await page.waitForURL((url) => url.pathname.includes('/wp-admin/'));

await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`);
await page.waitForFunction(() => window.wp?.blocks?.getBlockTypes?.().length > 50, null, { timeout: 60000 });
process.stderr.write(`editor ready: ${await page.evaluate(() => window.wp.blocks.getBlockTypes().length)} block types\n`);

const sink = createWriteStream(`${dir}/editor.jsonl`);

async function run(file, kind) {
    const lines = createInterface({ input: createReadStream(file), crlfDelay: Infinity });
    let n = 0;
    for await (const line of lines) {
        if (!line.trim()) continue;
        const { content, ...rest } = JSON.parse(line);
        if (!content) continue;

        const res = await page.evaluate((markup) => {
            const { parse, validateBlock } = window.wp.blocks;
            const show = (v) => (typeof v === 'string' ? v : JSON.stringify(v));
            const format = (issue) => {
                const [fmt, ...values] = issue?.args ?? [String(issue)];
                if (typeof fmt !== 'string' || fmt.startsWith('Block validation failed for')) return '';
                return fmt.replace(/%[oOsdi]/g, () => show(values.shift()));
            };
            const flatten = (list, out = []) => {
                for (const b of list) {
                    out.push(b);
                    flatten(b.innerBlocks ?? [], out);
                }
                return out;
            };

            const saved = console.error;
            const savedInfo = console.info;
            const savedWarn = console.warn;
            console.error = console.info = console.warn = () => {};
            try {
                const all = flatten(parse(markup)).filter((b) => b.name !== null);
                return {
                    blocks: all.length,
                    invalid: all.filter((b) => b.isValid === false && b.name !== 'core/missing').map((b) => {
                        const [, issues] = validateBlock(b);
                        return { block: b.name, reason: (issues ?? []).map(format).filter(Boolean).join('; ').slice(0, 4000) };
                    }),
                    missing: all.filter((b) => b.name === 'core/missing').map((b) => b.attributes?.originalName ?? '?'),
                };
            } finally {
                console.error = saved;
                console.info = savedInfo;
                console.warn = savedWarn;
            }
        }, content);

        sink.write(JSON.stringify({ kind, ...rest, ...res }) + '\n');
        if (++n % 200 === 0) process.stderr.write(`${kind}: ${n}\n`);
    }
    return n;
}

const t = await run(`${dir}/templates.jsonl`, 'template');
const g = await run(`${dir}/generations.jsonl`, 'generation');
sink.end();
await browser.close();
process.stderr.write(`done: ${t} templates, ${g} generations\n`);
