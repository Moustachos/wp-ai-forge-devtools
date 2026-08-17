# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **AI Forge Dev Tools**, a WordPress plugin that serves as a developer-only add-on for the main AI Forge plugin (`../wp-ai-forge`). The project is in early development stage with scaffolding in place but minimal implementation.

AI Forge is a WordPress productivity plugin that integrates AI agents (Gemini, OpenAI) for content generation via a "Content Integrator" system using templates.

## Commands

### PHP Backend
```bash
composer install    # Install dependencies, generates vendor/autoload.php
```

### Frontend (in main plugin: ../wp-ai-forge/admin/)
```bash
npm run build       # Build production assets
npm run start       # Development server with hot reload
npm run lint:js     # Lint JavaScript
npm run lint:css    # Lint CSS
```

### Tests
```bash
cd ../.. && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit
```

PHPUnit + Brain Monkey cover the pure campaign logic in `src/Campaign/`. The
WordPress glue (REST creation, polling, meta collection) and the WP-CLI command
are verified by running a real campaign.

## Architecture

### Bootstrap Pattern
The plugin follows a singleton bootstrap pattern:
```php
// Entry point: wp-ai-forge-devtools.php
add_action('plugins_loaded', function () {
    DevTools::boot(__FILE__);
});
```

The `DevTools` class in `src/DevTools.php` is the core service class that should orchestrate all functionality.

### QG campaign runner

```bash
cd ../.. && npx wp-env run cli -- wp aiforge-dev qg-campaign \
  --combos=gemini:balanced:article-de-blog-2,openai:balanced:article-de-blog-2 \
  --source-batch=6223 --files=2 --label=my-campaign
```

Runs a provider × preset × template matrix, prints a Quality Gate table and
writes a JSON report to `wp-content/uploads/aiforge-dev/`. Pass
`--baseline=<report.json>` for per-combo deltas against a previous run.

Three things that will bite you:

- **The main plugin caps a user at 5 active root tasks** (HTTP 429), so combos
  are launched through a sliding window of `CampaignRunner::MAX_IN_FLIGHT` (4),
  not all at once. Spacing creations in time does not help.
- **Template slugs are French** on this install (`article-de-blog-2`,
  `page-datterrissage`, `cas-client`…). List them with
  `wp post list --post_type=aiforge_ci_tpl --fields=ID,post_title,post_name`.
- **Always pass `--source-batch`** when you care about the file count.
  Auto-resolution skips campaign-produced batches, but any 1-file batch left by
  other tooling will silently become the corpus (the command warns when it has
  to clamp `--files`).

From Git Bash, an absolute `--baseline=/var/www/...` path gets mangled by MSYS
into `C:/Program Files/Git/var/www/...`. Use a WordPress-root-relative path
(`wp-content/uploads/...`) or prefix the command with `MSYS_NO_PATHCONV=1`.

### Vision benchmark

```bash
cd ../.. && npx wp-env run cli -- wp aiforge-dev vision-bench \
  --models=gemini:gemini-3-flash-preview,openai:gpt-5.6-luna,anthropic:claude-sonnet-5 \
  --images=100 --queries=wp-content/uploads/aiforge-dev/queries.json --label=vision-aug
```

Indexes the same image sample with each vision model and scores the resulting
index on a human-written query set: does the expected image come back, and at
what rank. Ranking is the measure because there is no ground truth for what a
"good" description reads like. Free signals come along for the ride: failure
rate, off-taxonomy values, keyword count, description length, wall time.

The query file is `[{"query": "...", "expect": [attachment ids]}]`. An empty
`expect` marks a **trap**: nothing in the sample answers it, so anything
returned is a false positive. Traps are scored separately so a model that
answers everything cannot look good just for never staying silent.

Four things worth knowing:

- **The index holds one row per attachment** (`UNIQUE KEY attachment_id`), so
  models cannot coexist. Each pass snapshots the sample's rows, wipes them,
  indexes, harvests, and the originals go back in a `finally`. Verified on the
  lab: 346 rows, identical checksum before and after.
- **`indexBatch` fills existing rows, it does not create them.** After a wipe
  you must call `syncMissingEntries()` or every image silently indexes to
  nothing — the run looks successful and produces zero rows.
- **Facet columns are multi-valued**, stored as JSON arrays (`usage_type`,
  `tone`, `category`). Reading them as scalars flags every valid row as
  off-taxonomy; the metric reported 9 violations on 3 clean images before this
  was fixed.
- **The query parser is cached per query** (transient on the parsed facets, not
  on the results), which is what keeps the parsing side constant across models
  while the SQL search is genuinely re-run for each index.

Use `--dry-run` to resolve the sample and validate the query file without
spending anything. Attachments referenced by a query but absent from the sample
are reported as unreachable rather than silently scored as misses.

#### Filling the query file visually

```bash
cd ../.. && npx wp-env run cli -- wp aiforge-dev vision-picker --images=100
# then open http://localhost:8888/wp-content/uploads/aiforge-dev/picker.html
```

Writes a standalone page listing every query with its candidate thumbnails.
Click a tile to add or remove it from `expect`, then Save. Ticking "voir toute
la médiathèque" exposes the whole sample when the suggested candidates are
wrong — which matters, because pre-filled candidates come from the *current*
index and blindly accepting them would enshrine one model's view as ground
truth.

- The page lives in uploads and is opened over the site's origin, so thumbnails
  load from the same host and the browser cookie authenticates the save. **You
  must be logged into wp-admin.** The nonce is baked in at generation time and
  lasts about 12 hours — re-run the command to refresh it.
- Saving goes through `POST aiforge-dev/v1/vision-queries`, which only writes
  `.json` files inside the aiforge-dev upload directory and keeps a `.bak` of
  the previous content first.
- `candidates` and `hint` survive the round trip; only `expect` changes.
- This is not a WordPress admin screen on purpose: it is a one-off data entry
  task, so it costs no menu registration, no asset pipeline and no build step.

### Browser smoke harness

```bash
cd smoke && npm install && npx playwright install chromium
npm run smoke                     # all five journeys
npm run smoke -- --journey=J4     # one
```

Five Playwright journeys over the surfaces only a browser can check: the admin
SPA, the iframed editor (document panels, block toolbar, media modal) and the
media library grid. Non-zero exit on any failure, screenshots and a
`summary.json` under `smoke/artifacts/<timestamp>/`.

It is state-preserving: it installs a fake license only when the lab reports
`not_activated`, removes it again in a `finally`, and never touches a real one.
Journeys that need a media index or a configured service report **skipped**
with the probed reason rather than red. Everything worth knowing about it,
including the environment traps it already pays for, is in `smoke/README.md`.

### Relationship to Main Plugin
This plugin extends the main `wp-ai-forge` plugin. Reference patterns from:
- `../wp-ai-forge/src/Core.php` - Bootstrap and dependency injection pattern
- `../wp-ai-forge/src/REST/` - REST API controller implementations
- `../wp-ai-forge/src/Config/ConfigRepository.php` - Configuration handling

### Key Architectural Decisions (from main plugin)
1. **Templates via CPT**: Content Integrator exclusively uses Templates (Custom Post Type), not pages or block patterns directly
2. **User-Provided API Keys**: No internal credit system; users provide their own API keys for AI providers
3. **Demo Mode**: `DemoMode::isEnabled()` enables testing without real API keys

### Namespace
All PHP classes use the `AIForge\` namespace with PSR-4 autoloading from `src/`.

## Tech Stack
- PHP 8.2+ with strict types
- WordPress plugin architecture
- Composer for PHP dependencies
- React + WordPress Scripts for admin UI (if needed)
- REST API namespace: `aiforge/v1`
