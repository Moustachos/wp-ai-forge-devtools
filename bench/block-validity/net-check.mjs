// Acceptance check for spec S18 Phase A: the editor recovery net must not save
// on open, must not rebuild a block into one that loses CSS, and must not run
// on content AI Forge did not generate, nor in the Site Editor.
//
//   node net-check.mjs          (from bench/block-validity/, lab running)

import { spawn } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { chromium } from '../../smoke/node_modules/playwright/index.mjs';
import { ADMIN_PASSWORD, ADMIN_USER, BASE_URL, LAB_DIR } from '../../smoke/lib/config.js';

const FIXTURES = 'wp-content/plugins/wp-ai-forge-devtools/bench/block-validity/net-fixtures.php';
const DARK = 'background-color:#18181b';
const SETTLE_MS = 6000;
const OUT = path.resolve('out/net-check');

function fixtures(...args) {
    return new Promise((resolve, reject) => {
        const child = spawn('npx', ['wp-env', 'run', 'cli', '--', 'timeout', '120', 'wp', 'eval-file', FIXTURES, ...args.map(String)], {
            cwd: LAB_DIR,
            shell: true,
            env: { ...process.env, MSYS_NO_PATHCONV: '1' },
        });
        let out = '';
        child.stdout.on('data', (c) => (out += c));
        child.stderr.on('data', (c) => (out += c));
        child.on('error', reject);
        child.on('close', () => {
            const line = out.split(/\r?\n/).reverse().find((l) => l.trim().startsWith('XRESULT:'));
            if (!line) return reject(new Error(`net-fixtures ${args.join(' ')} gave no result:\n${out}`));
            resolve(JSON.parse(line.trim().slice('XRESULT:'.length)));
        });
    });
}

async function openEditor(page, postId) {
    await page.goto(`${BASE_URL}/wp-admin/post.php?post=${postId}&action=edit`);
    await page.waitForFunction(
        (id) => window.wp?.data?.select('core/editor')?.getCurrentPostId?.() === id
            && window.wp.data.select('core/block-editor').getBlocks().length > 0,
        postId,
        { timeout: 60000 }
    );
    await page.waitForTimeout(SETTLE_MS);
}

async function editorState(page) {
    return page.evaluate((dark) => {
        const invalid = (blocks) => blocks.reduce((n, b) => n + (b.isValid === false ? 1 : 0) + invalid(b.innerBlocks ?? []), 0);
        // A block that carried the dark background must still be the original
        // (left to Gutenberg) or rebuild with it. A block the net replaced has
        // no originalContent, so a lossy rebuild shows as found === 0.
        const { getSaveContent } = window.wp.blocks;
        let found = 0;
        let kept = 0;
        const walk = (list) => list.forEach((b) => {
            if ((b.originalContent ?? '').includes(dark)) {
                found++;
                if (b.isValid === false || getSaveContent(b.name, b.attributes, b.innerBlocks).includes(dark)) kept++;
            }
            walk(b.innerBlocks ?? []);
        });
        const blocks = window.wp.data.select('core/block-editor').getBlocks();
        walk(blocks);
        return { invalid: invalid(blocks), found, kept, flag: window.aiforgeData?.isAiforgeDraft };
    }, DARK);
}

mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail = '') => {
    results.push(ok);
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
};

const created = await fixtures('create');
const allIds = [...created.ai.map((a) => a.post), created.plain, created.mixed];
const browser = await chromium.launch();

try {
    const before = await fixtures('state', ...allIds);
    const page = await browser.newPage({ viewport: { width: 1680, height: 1050 } });
    await page.goto(`${BASE_URL}/wp-login.php`);
    await page.fill('#user_login', ADMIN_USER);
    await page.fill('#user_pass', ADMIN_PASSWORD);
    await page.click('#wp-submit');
    await page.waitForURL((url) => url.pathname.includes('/wp-admin/'));

    for (const { post, task } of created.ai) {
        await openEditor(page, post);
        const state = await editorState(page);
        await page.screenshot({ path: path.join(OUT, `ai-${task}.png`) });
        check(`AI draft ${task}: flag set`, state.flag === true);
        check(`AI draft ${task}: dark background kept`, state.found > 0 && state.kept === state.found, `${state.kept}/${state.found}`);
    }

    // A refused container holding a rebuildable block, with another after it:
    // the refused subtree stays as parsed, and the edited content still carries
    // the container's original markup.
    await openEditor(page, created.mixed);
    const mixed = await editorState(page);
    const leaves = await page.evaluate((dark) => {
        const holds = (b, text) => (b.innerBlocks ?? []).some((p) => (p.attributes?.content?.toString?.() ?? '').includes(text));
        const find = (list, text) => list.reduce((hit, b) => hit
            ?? (b.name === 'core/group' && holds(b, text) ? b : find(b.innerBlocks ?? [], text)), null);
        const blocks = window.wp.data.select('core/block-editor').getBlocks();
        return {
            sibling: find(blocks, 'net-check sibling leaf')?.isValid,
            child: find(blocks, 'net-check child leaf')?.isValid,
            edited: window.wp.data.select('core/editor').getEditedPostContent().includes(dark),
            dirty: window.wp.data.select('core/editor').isEditedPostDirty(),
        };
    }, DARK);
    await page.screenshot({ path: path.join(OUT, 'mixed.png') });
    check('mixed draft: flag set', mixed.flag === true);
    check('mixed draft: sibling leaf recovered', leaves.sibling === true);
    check('mixed draft: refused group left whole, child included', leaves.child === false);
    check('mixed draft: dark background kept', mixed.found > 0 && mixed.kept === mixed.found, `${mixed.kept}/${mixed.found}`);
    check('mixed draft: edited content keeps the dark background', leaves.edited === true, `dirty: ${leaves.dirty}`);

    await openEditor(page, created.plain);
    const plain = await editorState(page);
    await page.screenshot({ path: path.join(OUT, 'plain.png') });
    check('non-AI draft: flag not set', plain.flag !== true);
    check('non-AI draft: invalid blocks left for Gutenberg', plain.invalid > 0, `${plain.invalid} invalid`);

    await page.goto(`${BASE_URL}/wp-admin/site-editor.php`);
    await page.waitForTimeout(SETTLE_MS);
    const siteFlag = await page.evaluate(() => window.aiforgeData?.isAiforgeDraft);
    await page.screenshot({ path: path.join(OUT, 'site-editor.png') });
    check('Site Editor: flag not set', siteFlag !== true);

    const after = await fixtures('state', ...allIds);
    for (const id of allIds) {
        check(`post ${id}: not saved on open`, before[id] === after[id], `${before[id]} -> ${after[id]}`);
    }
} finally {
    await browser.close();
    await fixtures('delete', ...allIds);
}

console.log(`\n${results.filter(Boolean).length}/${results.length} checks passed; screenshots in ${OUT}`);
process.exit(results.every(Boolean) ? 0 : 1);
