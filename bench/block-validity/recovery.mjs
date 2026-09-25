// Replays AI Forge's editor recovery (createBlock(name, attributes, innerBlocks))
// on every block the real editor rejects, and reports what the recovered block's
// root element lost or gained compared to the generated markup.
//
//   node recovery.mjs [--since=2026-08-01]

import { createReadStream, readFileSync } from 'node:fs';
import { createInterface } from 'node:readline';
import { chromium } from '../../smoke/node_modules/playwright/index.mjs';
import { ADMIN_PASSWORD, ADMIN_USER, BASE_URL } from '../../smoke/lib/config.js';

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const since = args.since ?? '2026-08-01';

const bad = new Set(readFileSync('out/editor.jsonl', 'utf8').split('\n').filter(Boolean).map(JSON.parse)
    .filter((r) => r.kind === 'generation' && r.invalid.length).map((r) => r.id));

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${BASE_URL}/wp-login.php`);
await page.fill('#user_login', ADMIN_USER);
await page.fill('#user_pass', ADMIN_PASSWORD);
await page.click('#wp-submit');
await page.waitForURL((url) => url.pathname.includes('/wp-admin/'));
await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`);
await page.waitForFunction(() => window.wp?.blocks?.getBlockTypes?.().length > 50, null, { timeout: 60000 });

const lines = createInterface({ input: createReadStream('out/generations.jsonl'), crlfDelay: Infinity });
for await (const line of lines) {
    const doc = JSON.parse(line);
    if (!bad.has(doc.id) || doc.created_at < since) continue;

    const diffs = await page.evaluate((markup) => {
        const { parse, createBlock, getSaveContent, getBlockType } = window.wp.blocks;
        const root = (html) => {
            const el = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html').body.firstElementChild;
            if (!el) return { tag: null, cls: [], style: {}, text: '' };
            const style = {};
            (el.getAttribute('style') ?? '').split(';').map((d) => d.trim()).filter(Boolean).forEach((d) => {
                const i = d.indexOf(':');
                style[d.slice(0, i).trim()] = d.slice(i + 1).trim();
            });
            return { tag: el.tagName, cls: [...el.classList], style, text: el.textContent.replace(/\s+/g, ' ').trim().length };
        };
        const out = [];
        const walk = (list) => list.forEach((b) => {
            if (b.isValid === false && getBlockType(b.name)) {
                const fixed = createBlock(b.name, b.attributes, b.innerBlocks);
                const before = root(b.originalContent ?? '');
                const after = root(getSaveContent(fixed.name, fixed.attributes, fixed.innerBlocks));
                const lost = [], gained = [];
                for (const [k, v] of Object.entries(before.style)) if (after.style[k] !== v) lost.push(`${k}:${v}`);
                for (const [k, v] of Object.entries(after.style)) if (before.style[k] !== v) gained.push(`${k}:${v}`);
                before.cls.filter((c) => !after.cls.includes(c)).forEach((c) => lost.push(`.${c}`));
                after.cls.filter((c) => !before.cls.includes(c)).forEach((c) => gained.push(`.${c}`));
                if (before.tag !== after.tag) lost.push(`<${before.tag}> became <${after.tag}>`);
                out.push({ block: b.name, lost, gained, textBefore: before.text, textAfter: after.text });
            }
            walk(b.innerBlocks ?? []);
        });
        walk(parse(markup));
        return out;
    }, doc.content);

    console.log(`## ${doc.id} ${doc.model_id ?? doc.provider} ${doc.template_name}`);
    for (const d of diffs) {
        console.log(`   ${d.block}  lost [${d.lost.join(' ')}]  gained [${d.gained.join(' ')}]  root text ${d.textBefore}->${d.textAfter}`);
    }
}

await browser.close();
