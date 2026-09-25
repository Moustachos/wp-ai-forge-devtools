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
node editor.mjs                        # ground truth: the lab editor's parse() + isValid
node recovery.mjs --since=2026-08-01   # replay createBlock() recovery, diff the root element

# block-runner needs Node >= 22.13; run it in a container, node_modules in a named volume
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/w" -v aiforge-block-validity-nm:/app/node_modules node:22 \
  sh -c "cp /w/package.json /w/package-lock.json /w/validate.mjs /app && cd /app && npm ci && ln -sf /w/out out && node validate.mjs"

node classify.mjs --since=2026-08-01 --verbose   # failure families, from block-runner's diffs
```

`editor.mjs` and `recovery.mjs` reuse the smoke harness's Playwright and
credentials (`../../smoke`), so `smoke/npm install` must have run once.

## Things that will mislead you

- **block-runner's `validate()` is not the editor's verdict.** It re-validates
  each block after deprecation migration, so markup the editor silently accepts
  through a deprecation (list without `wp-block-list`, heading without
  `wp-block-heading`, old quote shape) is reported invalid. On the 2026-09
  corpus that was 260 false documents against 82 true ones. Use it for the
  diff text, and `editor.mjs` for the verdict.
- `response_snapshot` and `result_content` are the same post-sanitizer string;
  the raw LLM output is not stored, so this measures what the editor receives.
- Blocks the headless registry does not know (`rank-math/faq-block`) are
  invisible to block-runner; the lab editor sees them.
