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
node classify.mjs --since=2026-08-01 --verbose=1   # failure families
node recovery.mjs --since=2026-08-01             # replay createBlock() recovery, diff the root element
```

The bare `--verbose` flag parses as undefined and silently caps each family at 4 lines; always pass `--verbose=1`.

The scripts reuse the smoke harness's Playwright and credentials (`../../smoke`),
so `smoke/npm install` must have run once. The verdict comes from the lab
site's own editor: its exact Gutenberg, and every block the site registers.

## Net check (spec S18 Phase A)

```bash
node net-check.mjs
```

Creates three AI Forge drafts from generations 6363, 6379 and 7059 plus one non-AI
draft, opens each in the lab editor, and checks that nothing was saved on open,
that the dark section kept its `background-color`, that the non-AI draft's invalid
blocks were left to Gutenberg, and that the Site Editor does not get the draft flag.
The posts are deleted in a `finally`. Exit 0 when every check passes; screenshots in
`out/net-check/`.

## Repair replay (spec S18 Phase B)

```bash
npx wp-env run cli -- timeout 900 wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/block-validity/repair.php   # from wp-lab
node editor.mjs --dir=out/repaired
node compare.mjs --since=2026-08-01
npx wp-env run cli -- timeout 900 wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/qg-replay/signature-repair.php   # from wp-lab
```

`repair.php` applies the plugin's final repair pass alone to the exported corpus
and checks it is idempotent. `compare.mjs` fails when a valid document or template
was changed, or when a document has more invalid blocks of a kind after the pass
than before. `signature-repair.php` replays the Quality Gate's signature score on
every changed generation. Record: `repair-2026-09.md`.

## Things that will mislead you

- **A block that differs from its current `save()` can still be valid.** A
  deprecated save with narrower supports accepts a paragraph, heading or plain
  group whose margin (paragraph: colour too) is in the JSON only, and a
  paragraph or quote missing its preset classes. `getSaveContent()` is not the
  oracle; `parse()`'s `isValid` is. See `repair-2026-09.md`, calibration.

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
