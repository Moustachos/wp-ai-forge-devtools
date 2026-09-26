# Block validity spike

Measures how many blocks in stored Content Integrator generations the block
editor rejects, and what AI Forge's editor recovery net (`admin/src/editor.js`)
changes when it repairs them. Read-only on the database; no generation is run.

## Running it

From `wp-lab`, dump the corpus (writes `out/generations.jsonl`, `out/templates.jsonl`):

```bash
npx wp-env run cli wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/block-validity/export.php
```

Then, from this directory:

```bash
node editor.mjs                                  # verdict + validateBlock() diff per rejected block
node classify.mjs --since=2026-08-01 --verbose   # failure families
node recovery.mjs --since=2026-08-01             # replay createBlock() recovery, diff the root element
```

The scripts reuse the smoke harness's Playwright and credentials (`../../smoke`),
so `smoke/npm install` must have run once. The verdict comes from the lab
site's own editor: its exact Gutenberg, and every block the site registers.

## Things that will mislead you

- **Read `isValid` after `parse()`, never re-validate every block.** Markup
  the editor accepts through a deprecation (list without `wp-block-list`,
  heading without `wp-block-heading`, old quote shape) fails a fresh
  `validateBlock()`. Humanmade's block-runner does exactly that and flagged
  260 documents the editor accepts, against 82 real ones. `editor.mjs` only
  asks `validateBlock()` for the diff of blocks `parse()` already rejected.
- `response_snapshot` and `result_content` are the same post-sanitizer string;
  the raw LLM output is not stored, so this measures what the editor receives.
- `recovery.mjs` compares root elements only. Its text lengths are not a
  content-loss measure: `originalContent` excludes inner blocks' text.
