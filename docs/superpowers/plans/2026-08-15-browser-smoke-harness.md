# Browser Smoke Harness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `npm run smoke` Playwright harness in `wp-ai-forge-devtools/smoke/` that drives five journeys through the running wp-env lab, writes audit-grade screenshots and a JSON summary, and exits non-zero on any failure.

**Architecture:** One Node ESM entry point (`run.js`) sequences a preflight probe, a self-managed fake-license install, one browser with one logged-in context, and five journey modules. Everything that talks to WordPress outside the browser goes through a single WP-CLI helper that calls one PHP file inside the container (`php/harness.php`) with a `XRESULT:` marker, which sidesteps both MSYS path mangling and shell quoting. Journeys never fail for environment reasons: they either assert, or raise a `SkipError` carrying the probed reason.

**Tech Stack:** Node 22 (global `fetch`, ESM), `playwright` 1.62.1 pinned, `npx wp-env run cli`, WP-CLI `eval-file`.

**Spec:** `../wp-ai-forge/docs/specs/2026-08-browser-smoke-harness.md`. This plan is the implementation record; the spec is the decision record.

## Global Constraints

- **Nothing ships in `wp-ai-forge`.** Every file created by this plan is inside `wp-ai-forge-devtools/smoke/`. The main plugin is read-only, and it has in-flight work on another branch.
- **`wp-ai-forge-devtools` is its own git repo.** All commits happen there, on `feat/browser-smoke-harness` off its `main`. No push, no tag, no release script, no version bump.
- **Playwright is pinned exactly** (`"playwright": "1.62.1"`, no caret) in `smoke/package.json`. Browsers come from `npx playwright install chromium`, never from a postinstall hook.
- **Invocation:** `npm run smoke` runs everything; `npm run smoke -- --journey=J4` runs one. Exit code is non-zero if any journey failed or the preflight could not complete.
- **Artifacts:** `smoke/artifacts/<timestamp>/` holds the screenshots (viewport 1680x1050) and `summary.json`. `smoke/artifacts/` and `smoke/node_modules/` are gitignored; `package-lock.json` is committed.
- **License:** probe first, install the fake key **only** when the state is `not_activated`, and tear it down in a `finally` that also runs on crash. A real license is never touched, and teardown re-checks that the stored key is the harness's own before removing anything.
- **Console policy:** `console.error` + `pageerror` are collected per journey and filtered through **one** allowlist module, `lib/console-allowlist.js`. Anything not allowlisted fails the journey.
- **Language:** code, comments, commit messages and README in English. Comments sparse, mechanics only, no provenance notes. No em dashes.
- **Runtime budget:** full run under 5 minutes, sequential, one browser, one shared logged-in context.

---

## Environment facts verified on 2026-08-15 (do not re-derive)

| Fact | Value |
|---|---|
| Lab root | `e:/Travaux/Travaux Web/wp-lab`, wp-env running on `http://localhost:8888`, `admin`/`password` |
| WP / locale | 7.0.4, `fr_FR` |
| License | **real, `valid`** (`AIFORGE-JWQT...0PLG`, plan personal). The harness must leave it alone. |
| Providers | gemini, openai, anthropic all configured |
| Media index | 346 indexed entries, AI media search available |
| Playwright | 1.62.1 is latest; chromium build 1234 installed on this host |

**Selectors verified live in the lab** (all present in `admin/build/`, not just in source):

| Surface | Selector |
|---|---|
| Admin SPA root | `#aiforge-root` (menu slug `ai-forge`) |
| Editor canvas | `iframe[name="editor-canvas"]` |
| Editor title | `.editor-post-title__input` (inside the canvas) |
| Sidebar document tab | `[role="tab"][id$="edit-post/document"]` (id suffix is locale-independent; the visible label is "Page") |
| AI Forge doc panels | `.aiforge-doc-panel` (2 on a page: "Content Integrator", "SEO Meta Generator") |
| Block toolbar | `.block-editor-block-contextual-toolbar` (in the **top** document, not the iframe) |
| Media suggestion button | `[aria-label]` matching `/Suggérer une image pertinente\|Suggest a relevant image/` |
| Image placeholder media button | button in `[data-type="core/image"]` with text `/Médiathèque\|Media Library/` (**not** "Bibliothèque de médias") |
| Grid badge | `.aiforge-grid-badge` |
| AI search switch | `.aiforge-search-toggle` with `aria-checked` |
| AI search band | `.aiforge-media-search__band` (has `hidden` when the switch is off) |
| AI search summary | `.aiforge-media-search__summary` |
| Facet chips | `.aiforge-media-search__chips .aiforge-chip--removable` |
| Media search input | `input#media-search-input` |

## Traps this harness must encode

The seven from spec section 6, plus three found during reconnaissance:

1. `wp-login` interstitial: `admin_email_lifespan` is pushed a year out in preflight, before the browser opens.
2. Welcome guide: `core/preferences` `welcomeGuide` and `welcomeGuideTemplate` are set to `false` in every scope the editor might read (`core`, `core/edit-post`, `core/edit-site`).
3. Maintenance mode: preflight polls the site and retries through `503` with exponential backoff instead of failing.
4. `fr_FR`: every label-based selector is a `FR|EN` regex, and structural selectors win when one exists.
5. `/wp-admin/upload.php`, never `/upload.php`.
6. The block toolbar only appears after a **real** click inside the canvas. `locator.click()` on the block wrapper is not enough and neither is `selectBlock`: the harness reads the element's bounding box, hit-tests `document.elementFromPoint` to prove nothing covers it, then drives `page.mouse.click` at those coordinates.
7. `wp.media.frame` is the last frame created. The active modal is resolved by walking `wp.media.frames`, falling back to `wp.media.frame` only as one more candidate, keeping the frames whose `el` is connected and visible, and tagging the resolved `.media-modal` with `data-aiforge-smoke-modal` so every later selector is scoped to it. **Verified:** in the Gutenberg image-block modal, `wp.media.frames` is empty and only the fallback yields a frame, so both halves of the walk are load-bearing.
8. **(new)** A new page opens the start-pattern chooser (`.editor-start-page-options__modal`) over the canvas. Setting `enableChoosePatternModal` after mount does not close the already-open one, so the harness also presses `Escape` until no `.components-modal__frame` is left.
9. **(new)** The doc panels are `PluginDocumentSettingPanel` instances: they exist only while the sidebar shows the **document** tab. Typing a paragraph selects a block and switches the sidebar to the block tab, which unmounts them. The harness clicks `[role="tab"][id$="edit-post/document"]` before asserting.
10. **(new)** The AI search switch is a stored per-user preference and may already be on. The harness reads `aria-checked` and only clicks when the state has to change, then restores the original state when it leaves.
11. `wp eval` from Git Bash mangles absolute container paths and wp-env echoes the command text back. The harness never sends a PHP one-liner: it calls `wp eval-file wp-content/plugins/wp-ai-forge-devtools/smoke/php/harness.php <command>` with a WordPress-root-relative path, sets `MSYS_NO_PATHCONV=1` on the child environment, and parses only the last line prefixed `XRESULT:`.

---

## File Structure

**Created (all under `wp-ai-forge-devtools/smoke/`):**

| File | Responsibility |
|---|---|
| `package.json` | Pinned playwright, `smoke` script |
| `.gitignore` | `node_modules/`, `artifacts/` |
| `README.md` | Invocation, environment assumptions, allowlist location, journey table |
| `run.js` | Argument parsing, orchestration, teardown `finally`, exit code |
| `php/harness.php` | The only PHP that runs in the container: `environment`, `prepare`, `license probe/install/teardown` |
| `lib/config.js` | Base URL, credentials, viewport, timeouts, lab directory resolution |
| `lib/wp-cli.js` | `wp-env run cli` spawn + `XRESULT:` extraction |
| `lib/environment.js` | Site reachability with 503 backoff, `environment`/`prepare` probes |
| `lib/license.js` | Probe / conditional install / unconditional teardown |
| `lib/console-allowlist.js` | The single allowlist |
| `lib/artifacts.js` | Timestamped run directory, screenshot paths |
| `lib/journey.js` | `SkipError`, `AssertionFailure`, `assertTrue`, `waitFor` |
| `lib/session.js` | Browser launch, login, console collection, screenshot helper |
| `lib/editor.js` | Shared editor fixture: open page, dismiss modals, real click, document tab, image block |
| `lib/media.js` | Media surface helpers: ensure switch state, run a query, resolve the active modal |
| `lib/reporter.js` | Console table + `summary.json` |
| `journeys/index.js` | Ordered journey registry |
| `journeys/j1-admin-spa.js` | |
| `journeys/j2-editor-panels.js` | |
| `journeys/j3-block-toolbar.js` | |
| `journeys/j4-media-grid.js` | |
| `journeys/j5-media-modal.js` | |

**Modified:** nothing outside `smoke/`, except this plan file and `CLAUDE.md` (one section documenting the harness).

**Verification model:** the spec's non-goals rule out a JS unit-test suite, so every task's verification step is a real harness run against the lab. That is the same model the QG campaign runner used for its WordPress glue.

---

## Task 1: Scaffold, WP-CLI bridge and preflight

**Files:**
- Create: `smoke/package.json`, `smoke/.gitignore`, `smoke/php/harness.php`, `smoke/lib/config.js`, `smoke/lib/wp-cli.js`, `smoke/lib/environment.js`, `smoke/run.js`

**Interfaces:**
- Produces:
  ```js
  // lib/wp-cli.js
  export async function wpEval(command, args = []): Promise<any>   // throws on a missing XRESULT marker
  // lib/environment.js
  export async function waitForSite(log): Promise<{status:number, attempts:number, waitedMs:number}>
  export async function probeEnvironment(): Promise<Environment>
  export async function prepareLab(): Promise<{adminEmailLifespan:number}>
  // Environment = {
  //   pluginActive: boolean, wpVersion: string, locale: string,
  //   license: { status: string, hasKey: boolean },
  //   providers: { gemini: boolean, openai: boolean, anthropic: boolean },
  //   mediaIndexed: number,
  //   search: { available: boolean, reason: string|null }
  // }
  ```

- [ ] **Step 1: Create `smoke/package.json`**

```json
{
  "name": "wp-ai-forge-smoke",
  "version": "1.0.0",
  "private": true,
  "type": "module",
  "description": "Journey-level browser smoke harness for the AI Forge plugin, run against the wp-env lab.",
  "scripts": {
    "smoke": "node run.js"
  },
  "dependencies": {
    "playwright": "1.62.1"
  }
}
```

- [ ] **Step 2: Create `smoke/.gitignore`**

```
node_modules/
artifacts/
```

- [ ] **Step 3: Install**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools/smoke"
npm install
npx playwright install chromium
```

Expected: `package-lock.json` written, chromium downloaded (about 190 MB, already cached on this host for build 1234).

- [ ] **Step 4: Create `smoke/php/harness.php`**

The only PHP the harness runs. It answers on stdout with one `XRESULT:` line and never assumes the main plugin is loaded, because the red-path proof deactivates it.

```php
<?php
/**
 * Smoke harness bridge, run through `wp eval-file`.
 *
 * Commands: environment, prepare, license probe|install|teardown.
 * Every answer is one XRESULT: line, because wp-env echoes the command back
 * around whatever the script prints.
 */

declare(strict_types=1);

const SMOKE_LICENSE_KEY = 'AIFORGE-SMOK-ETST-0000-0001';

function smoke_reply(array $data): void
{
    echo 'XRESULT:' . json_encode($data) . "\n";
}

function smoke_plugin_loaded(): bool
{
    return class_exists('\AIForge\Core') && \AIForge\Core::getInstance() !== null;
}

$command = $args[0] ?? 'environment';
$sub = $args[1] ?? '';

if ($command === 'prepare') {
    update_option('admin_email_lifespan', time() + YEAR_IN_SECONDS);
    smoke_reply(['admin_email_lifespan' => (int) get_option('admin_email_lifespan')]);
    return;
}

if ($command === 'license') {
    if (!smoke_plugin_loaded()) {
        smoke_reply(['status' => 'unknown', 'has_key' => false, 'plugin_active' => false]);
        return;
    }

    $client = \AIForge\Core::getInstance()->getLicenseClient();

    if ($sub === 'install') {
        $client->setLicenseKey(SMOKE_LICENSE_KEY);
        $client->cacheStatus(\AIForge\License\LicenseStatus::fromApiResponse([
            'status' => \AIForge\License\LicenseStatus::VALID,
            'plan' => 'smoke',
            'customer_name' => 'Browser smoke harness',
            'active_sites' => 1,
            'max_sites' => 1,
        ]));
    }

    // Only ever removes the harness's own key: a real license that appeared
    // mid-run stays where it is.
    if ($sub === 'teardown' && $client->getLicenseKey() === SMOKE_LICENSE_KEY) {
        $client->removeLicenseKey();
    }

    $key = $client->getLicenseKey();

    smoke_reply([
        'status' => $client->getStatus()->status,
        'has_key' => $key !== null,
        'is_smoke_key' => $key === SMOKE_LICENSE_KEY,
        'plugin_active' => true,
    ]);

    return;
}

$environment = [
    'plugin_active' => smoke_plugin_loaded(),
    'wp_version' => get_bloginfo('version'),
    'locale' => get_locale(),
    'license' => ['status' => 'unknown', 'has_key' => false],
    'providers' => [],
    'media_indexed' => 0,
    'search' => ['available' => false, 'reason' => 'AI Forge is not active'],
];

if ($environment['plugin_active']) {
    global $wpdb;

    $core = \AIForge\Core::getInstance();
    $config = $core->getConfig();
    $client = $core->getLicenseClient();
    $repository = new \AIForge\Agent\MediaIntelligence\MediaIndexRepository($wpdb);

    $status = $client->getStatus();
    $indexed = $repository->countIndexed();
    $searchProvider = $config->isProviderConfigured('gemini')
        || $config->isProviderConfigured('openai')
        || $config->isProviderConfigured('anthropic');
    $switchedOn = (bool) get_option('aiforge_media_search_enabled', true);

    $reason = null;
    if (!$switchedOn) {
        $reason = 'media search is switched off in settings';
    } elseif ($indexed < 1) {
        $reason = 'media index is empty';
    } elseif (!$searchProvider) {
        $reason = 'no AI service is configured';
    } elseif (!$status->allowsFeatureAccess()) {
        $reason = 'license does not allow feature access';
    }

    $environment['license'] = [
        'status' => $status->status,
        'has_key' => $client->getLicenseKey() !== null,
    ];
    $environment['providers'] = [
        'gemini' => $config->isProviderConfigured('gemini'),
        'openai' => $config->isProviderConfigured('openai'),
        'anthropic' => $config->isProviderConfigured('anthropic'),
    ];
    $environment['media_indexed'] = $indexed;
    $environment['search'] = ['available' => $reason === null, 'reason' => $reason];
}

smoke_reply($environment);
```

Note on the option name: read `MediaSearchService::OPTION_ENABLED` from
`../wp-ai-forge/src/Agent/MediaIntelligence/MediaSearchService.php` and use its
literal value. The constant is private, so the harness cannot reference it and
must hardcode the same string.

- [ ] **Step 5: Create `smoke/lib/config.js`**

```js
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

export const SMOKE_DIR = path.resolve(here, '..');
export const LAB_DIR = path.resolve(here, '../../../..');
export const HARNESS_PHP = 'wp-content/plugins/wp-ai-forge-devtools/smoke/php/harness.php';

export const BASE_URL = process.env.AIFORGE_SMOKE_URL || 'http://localhost:8888';
export const ADMIN_USER = process.env.AIFORGE_SMOKE_USER || 'admin';
export const ADMIN_PASSWORD = process.env.AIFORGE_SMOKE_PASSWORD || 'password';

export const VIEWPORT = { width: 1680, height: 1050 };

export const TIMEOUTS = {
  action: 20000,
  navigation: 60000,
  editor: 60000,
  search: 45000,
  siteWait: 180000,
};

export const SEARCH_QUERY = 'photo lumineuse d’un intérieur chaleureux';
```

- [ ] **Step 6: Create `smoke/lib/wp-cli.js`**

```js
import { spawn } from 'node:child_process';
import { HARNESS_PHP, LAB_DIR } from './config.js';

const MARKER = 'XRESULT:';

function run(args) {
  return new Promise((resolve, reject) => {
    const child = spawn('npx', args, {
      cwd: LAB_DIR,
      // .cmd shims cannot be spawned without a shell on Windows, and the
      // arguments below carry no spaces or quotes for the shell to mangle.
      shell: true,
      env: { ...process.env, MSYS_NO_PATHCONV: '1' },
    });

    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (chunk) => (stdout += chunk));
    child.stderr.on('data', (chunk) => (stderr += chunk));
    child.on('error', reject);
    child.on('close', (code) => resolve({ code, stdout, stderr }));
  });
}

/**
 * Run one harness command inside the wp-env cli container.
 *
 * wp-env echoes the command text around the output, so the answer is found by
 * its marker rather than by taking stdout whole.
 */
export async function wpEval(command, args = []) {
  const { code, stdout, stderr } = await run([
    'wp-env',
    'run',
    'cli',
    '--',
    'wp',
    'eval-file',
    HARNESS_PHP,
    command,
    ...args,
  ]);

  const line = (stdout + '\n' + stderr)
    .split(/\r?\n/)
    .reverse()
    .find((candidate) => candidate.trim().startsWith(MARKER));

  if (!line) {
    throw new Error(
      `wp eval-file ${command} ${args.join(' ')} produced no ${MARKER} line (exit ${code}).\n` +
        `${stdout}\n${stderr}`
    );
  }

  return JSON.parse(line.trim().slice(MARKER.length));
}
```

- [ ] **Step 7: Create `smoke/lib/environment.js`**

```js
import { BASE_URL, TIMEOUTS } from './config.js';
import { wpEval } from './wp-cli.js';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Wait for the site to answer with something other than maintenance mode.
 *
 * A core or translation update flips WordPress into a ten-minute 503, which is
 * a state to wait out rather than a failure to report.
 */
export async function waitForSite(log) {
  const startedAt = Date.now();
  const deadline = startedAt + TIMEOUTS.siteWait;
  let attempts = 0;
  let backoff = 2000;

  while (Date.now() < deadline) {
    attempts++;

    try {
      const response = await fetch(BASE_URL, { redirect: 'manual' });

      if (response.status !== 503) {
        return { status: response.status, attempts, waitedMs: Date.now() - startedAt };
      }

      log(`site answered 503 (maintenance mode), retrying in ${backoff}ms`);
    } catch (error) {
      log(`site unreachable (${error.message}), retrying in ${backoff}ms`);
    }

    await sleep(backoff);
    backoff = Math.min(backoff * 2, 20000);
  }

  throw new Error(
    `${BASE_URL} never left maintenance mode within ${TIMEOUTS.siteWait / 1000}s (${attempts} attempts)`
  );
}

export async function probeEnvironment() {
  const raw = await wpEval('environment');

  return {
    pluginActive: !!raw.plugin_active,
    wpVersion: raw.wp_version,
    locale: raw.locale,
    license: { status: raw.license.status, hasKey: !!raw.license.has_key },
    providers: raw.providers,
    mediaIndexed: Number(raw.media_indexed || 0),
    search: { available: !!raw.search.available, reason: raw.search.reason },
  };
}

export async function prepareLab() {
  const raw = await wpEval('prepare');

  return { adminEmailLifespan: Number(raw.admin_email_lifespan || 0) };
}
```

- [ ] **Step 8: Create a preflight-only `smoke/run.js`**

This is the walking skeleton: it proves the WP-CLI bridge, the 503 retry and the probe before any browser exists. It is replaced in Task 6 by the full orchestrator.

```js
import { waitForSite, prepareLab, probeEnvironment } from './lib/environment.js';

const log = (message) => console.log(`[smoke] ${message}`);

const site = await waitForSite(log);
log(`site responded ${site.status} after ${site.attempts} attempt(s)`);

const prepared = await prepareLab();
log(`admin_email_lifespan pushed to ${new Date(prepared.adminEmailLifespan * 1000).toISOString()}`);

const environment = await probeEnvironment();
log(JSON.stringify(environment, null, 2));
```

- [ ] **Step 9: Run it**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools/smoke" && npm run smoke
```

Expected: `site responded 302`, an `admin_email_lifespan` a year out, and a probe reporting `pluginActive: true`, `wpVersion 7.0.4`, `locale fr_FR`, license `valid`, three providers, `mediaIndexed 346`, `search.available true`.

- [ ] **Step 10: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add smoke/package.json smoke/package-lock.json smoke/.gitignore smoke/php smoke/lib smoke/run.js
git commit -m "feat(smoke): scaffold the browser smoke harness with a wp-env preflight

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: License management, artifacts and the journey model

**Files:**
- Create: `smoke/lib/license.js`, `smoke/lib/artifacts.js`, `smoke/lib/journey.js`, `smoke/lib/console-allowlist.js`

**Interfaces:**
- Consumes: `wpEval` (Task 1).
- Produces:
  ```js
  // lib/license.js
  export async function ensureLicense(log): Promise<{state:string, managed:boolean, note:string}>
  export async function teardownLicense(managed, log): Promise<{state:string}>
  // lib/artifacts.js
  export function createRunDirectory(): { stamp: string, dir: string }
  // lib/journey.js
  export class SkipError extends Error {}
  export class AssertionFailure extends Error {}
  export function skip(reason): never
  export function assertTrue(condition, message): void
  export async function waitFor(probe, message, timeout): Promise<any>
  // lib/console-allowlist.js
  export function isAllowedConsoleError({ text, url }): boolean
  ```

- [ ] **Step 1: Create `smoke/lib/console-allowlist.js`**

```js
/**
 * Known browser noise, allowed everywhere.
 *
 * This is the only allowlist in the harness. Anything a journey logs that does
 * not match here fails that journey.
 */
const ALLOWED = [
  {
    // The gutenberg-blocks lab theme pulls assets from a remote sandbox that
    // answers without CORS headers. Lab-only, and not AI Forge's.
    pattern: /nemasandbox-rec\.vs20\.nematis\.net/,
    reason: 'gutenberg-blocks lab theme: CORS on remote sandbox assets',
  },
];

export function isAllowedConsoleError({ text, url }) {
  const subject = `${text || ''} ${url || ''}`;

  return ALLOWED.some((entry) => entry.pattern.test(subject));
}

export function allowlistReasons() {
  return ALLOWED.map((entry) => entry.reason);
}
```

- [ ] **Step 2: Create `smoke/lib/journey.js`**

```js
export class SkipError extends Error {
  constructor(reason) {
    super(reason);
    this.name = 'SkipError';
  }
}

export class AssertionFailure extends Error {
  constructor(message) {
    super(message);
    this.name = 'AssertionFailure';
  }
}

export function skip(reason) {
  throw new SkipError(reason);
}

export function assertTrue(condition, message) {
  if (!condition) {
    throw new AssertionFailure(message);
  }
}

/**
 * Poll until the probe returns something truthy.
 *
 * Playwright's own waits cover elements; this covers page state that only a
 * script can read, such as a store value or a rendered result count.
 */
export async function waitFor(probe, message, timeout = 20000) {
  const deadline = Date.now() + timeout;
  let last;

  while (Date.now() < deadline) {
    last = await probe();

    if (last) {
      return last;
    }

    await new Promise((resolve) => setTimeout(resolve, 250));
  }

  throw new AssertionFailure(`${message} (timed out after ${timeout}ms, last value ${JSON.stringify(last)})`);
}
```

- [ ] **Step 3: Create `smoke/lib/artifacts.js`**

```js
import fs from 'node:fs';
import path from 'node:path';
import { SMOKE_DIR } from './config.js';

export function createRunDirectory() {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const dir = path.join(SMOKE_DIR, 'artifacts', stamp);

  fs.mkdirSync(dir, { recursive: true });

  return { stamp, dir };
}
```

- [ ] **Step 4: Create `smoke/lib/license.js`**

```js
import { wpEval } from './wp-cli.js';

/**
 * Give the run a license without ever touching a real one.
 *
 * The plugin gates the editor integration on an active license, so a bare lab
 * needs a fake key to have anything to assert. A lab that already carries a
 * license is left exactly as it is, and so is a lab where the plugin is not
 * even loaded.
 */
export async function ensureLicense(log) {
  const probed = await wpEval('license', ['probe']);

  if (!probed.plugin_active) {
    log('license unmanaged: AI Forge is not active');
    return { state: probed.status, managed: false, note: 'AI Forge is not active' };
  }

  if (probed.status !== 'not_activated') {
    log(`license left alone: already ${probed.status}`);
    return { state: probed.status, managed: false, note: `existing license (${probed.status})` };
  }

  const installed = await wpEval('license', ['install']);
  log(`fake license installed (state ${installed.status}), will be removed in teardown`);

  return { state: installed.status, managed: true, note: 'fake license installed by the harness' };
}

/**
 * Always called, including after a crash. The container side removes the key
 * only when it is the harness's own, so a race with a human activating a real
 * license cannot destroy it.
 */
export async function teardownLicense(managed, log) {
  if (!managed) {
    return { state: 'untouched' };
  }

  const result = await wpEval('license', ['teardown']);
  log(`fake license removed (state now ${result.status})`);

  return { state: result.status };
}
```

- [ ] **Step 5: Verify the license path end to end by hand**

```bash
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli -- wp eval-file wp-content/plugins/wp-ai-forge-devtools/smoke/php/harness.php license probe
```

Expected on this lab: `XRESULT:{"status":"valid","has_key":true,"is_smoke_key":false,"plugin_active":true}` and, because the key is not the harness's, a `license teardown` call must leave it untouched. Run `license teardown` once and re-probe to prove it.

- [ ] **Step 6: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add smoke/lib
git commit -m "feat(smoke): add license lifecycle, artifacts and journey primitives

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Browser session and J1

**Files:**
- Create: `smoke/lib/session.js`, `smoke/journeys/j1-admin-spa.js`, `smoke/journeys/index.js`
- Modify: `smoke/run.js`

**Interfaces:**
- Consumes: `lib/config.js`, `lib/journey.js`, `lib/console-allowlist.js`, `lib/artifacts.js`.
- Produces:
  ```js
  // lib/session.js
  export async function openSession({ dir, log }): Promise<Session>
  // Session = {
  //   page, browser, context,
  //   startJourney(id): void,          // resets the console bucket
  //   consoleErrors(): Array<{text,url}>,
  //   shot(id, name): Promise<string>, // returns the file name
  //   close(): Promise<void>,
  // }
  // journeys/index.js
  export const JOURNEYS: Array<{ id, name, run(ctx): Promise<void> }>
  // ctx = { page, session, env, log, shot(name) }
  ```

- [ ] **Step 1: Create `smoke/lib/session.js`**

```js
import path from 'node:path';
import { chromium } from 'playwright';
import { ADMIN_PASSWORD, ADMIN_USER, BASE_URL, TIMEOUTS, VIEWPORT } from './config.js';
import { AssertionFailure } from './journey.js';

export async function openSession({ dir, log }) {
  const browser = await chromium.launch({ headless: process.env.SMOKE_HEADED !== '1' });
  const context = await browser.newContext({ viewport: VIEWPORT });

  context.setDefaultTimeout(TIMEOUTS.action);
  context.setDefaultNavigationTimeout(TIMEOUTS.navigation);

  const page = await context.newPage();

  let bucket = [];
  let counter = 0;

  page.on('console', (message) => {
    if (message.type() === 'error') {
      bucket.push({ text: message.text(), url: message.location().url });
    }
  });
  page.on('pageerror', (error) => {
    bucket.push({ text: `pageerror: ${error.message}`, url: page.url() });
  });

  await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('#wp-submit'),
  ]);

  // The interstitial is neutralised in preflight by pushing
  // admin_email_lifespan out a year; landing on it means that step did not
  // take, and clicking through would hide the real problem.
  if (page.url().includes('confirm_admin_email')) {
    throw new AssertionFailure(
      'login landed on the confirm_admin_email interstitial: the preflight admin_email_lifespan update did not apply'
    );
  }

  if (!page.url().includes('/wp-admin/')) {
    throw new AssertionFailure(`login did not reach wp-admin, landed on ${page.url()}`);
  }

  log(`logged in as ${ADMIN_USER}`);

  return {
    page,
    browser,
    context,
    startJourney(id) {
      bucket = [];
      counter = 0;
      this.currentJourney = id;
    },
    consoleErrors() {
      return bucket.slice();
    },
    async shot(id, name) {
      counter++;
      const file = `${id.toLowerCase()}-${String(counter).padStart(2, '0')}-${name}.png`;
      await page.screenshot({ path: path.join(dir, file) });
      return file;
    },
    async close() {
      await context.close();
      await browser.close();
    },
  };
}
```

- [ ] **Step 2: Create `smoke/journeys/j1-admin-spa.js`**

```js
import { BASE_URL } from '../lib/config.js';
import { assertTrue, waitFor } from '../lib/journey.js';

const MINIMUM_TEXT = 200;

export default {
  id: 'J1',
  name: 'Admin SPA renders',

  async run({ page, log, shot }) {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ai-forge`, {
      waitUntil: 'domcontentloaded',
    });

    await page.waitForSelector('#aiforge-root', { state: 'visible' });

    const rendered = await waitFor(
      () =>
        page.evaluate((minimum) => {
          const root = document.getElementById('aiforge-root');

          if (!root || root.children.length === 0) {
            return null;
          }

          const text = root.innerText.trim();

          return text.length >= minimum ? { children: root.children.length, length: text.length } : null;
        }, MINIMUM_TEXT),
      `#aiforge-root never rendered ${MINIMUM_TEXT} characters of content`,
      30000
    );

    log(`SPA rendered ${rendered.length} characters in ${rendered.children} root child(ren)`);

    assertTrue(rendered.children > 0, '#aiforge-root has no child elements');

    await shot('dashboard');
  },
};
```

- [ ] **Step 3: Create `smoke/journeys/index.js`**

```js
import j1 from './j1-admin-spa.js';

export const JOURNEYS = [j1];
```

- [ ] **Step 4: Replace `smoke/run.js` with the orchestrator**

```js
import fs from 'node:fs';
import path from 'node:path';
import { createRunDirectory } from './lib/artifacts.js';
import { isAllowedConsoleError } from './lib/console-allowlist.js';
import { prepareLab, probeEnvironment, waitForSite } from './lib/environment.js';
import { SkipError } from './lib/journey.js';
import { ensureLicense, teardownLicense } from './lib/license.js';
import { report } from './lib/reporter.js';
import { openSession } from './lib/session.js';
import { JOURNEYS } from './journeys/index.js';

function parseArguments(argv) {
  const wanted = [];

  for (const argument of argv) {
    const match = /^--journey=(.+)$/.exec(argument);

    if (match) {
      wanted.push(...match[1].split(',').map((id) => id.trim().toUpperCase()).filter(Boolean));
    }
  }

  return { wanted };
}

const { wanted } = parseArguments(process.argv.slice(2));
const selected = wanted.length ? JOURNEYS.filter((journey) => wanted.includes(journey.id)) : JOURNEYS;

if (wanted.length && selected.length !== wanted.length) {
  const known = JOURNEYS.map((journey) => journey.id).join(', ');
  console.error(`[smoke] unknown journey in --journey=${wanted.join(',')} (known: ${known})`);
  process.exit(2);
}

const { stamp, dir } = createRunDirectory();
const log = (message) => console.log(`[smoke] ${message}`);
const startedAt = Date.now();

const summary = {
  startedAt: new Date(startedAt).toISOString(),
  artifacts: dir,
  selected: selected.map((journey) => journey.id),
  preflight: {},
  license: {},
  journeys: [],
};

let session = null;
let license = { managed: false };
let exitCode = 0;

try {
  const site = await waitForSite(log);
  log(`site responded ${site.status} after ${site.attempts} attempt(s)`);

  await prepareLab();

  const env = await probeEnvironment();

  summary.preflight = { site, environment: env };
  log(
    `WordPress ${env.wpVersion} (${env.locale}), AI Forge ${env.pluginActive ? 'active' : 'INACTIVE'}, ` +
      `license ${env.license.status}, media index ${env.mediaIndexed}, ` +
      `AI search ${env.search.available ? 'available' : `unavailable (${env.search.reason})`}`
  );

  license = await ensureLicense(log);
  summary.license = license;

  session = await openSession({ dir, log });

  for (const journey of selected) {
    const journeyStart = Date.now();
    const shots = [];

    session.startJourney(journey.id);
    log(`--- ${journey.id} ${journey.name}`);

    const record = { id: journey.id, name: journey.name, status: 'passed', reason: null, error: null };

    try {
      await journey.run({
        page: session.page,
        session,
        env,
        log: (message) => log(`    ${message}`),
        shot: async (name) => {
          const file = await session.shot(journey.id, name);
          shots.push(file);
          return file;
        },
      });
    } catch (error) {
      if (error instanceof SkipError) {
        record.status = 'skipped';
        record.reason = error.message;
      } else {
        record.status = 'failed';
        record.error = error.message;

        try {
          shots.push(await session.shot(journey.id, 'failure'));
        } catch {
          // A screenshot of a dead page is not worth a second failure.
        }
      }
    }

    const errors = session.consoleErrors();
    const blocking = errors.filter((entry) => !isAllowedConsoleError(entry));

    if (record.status === 'passed' && blocking.length) {
      record.status = 'failed';
      record.error = `${blocking.length} console error(s): ${blocking.map((entry) => entry.text).join(' | ')}`;
    }

    record.consoleErrors = errors;
    record.blockingConsoleErrors = blocking;
    record.screenshots = shots;
    record.durationMs = Date.now() - journeyStart;

    summary.journeys.push(record);
    log(`${journey.id} ${record.status}${record.reason ? `: ${record.reason}` : ''}${record.error ? `: ${record.error}` : ''}`);
  }
} catch (error) {
  summary.preflight.error = error.message;
  log(`preflight failed: ${error.message}`);
  exitCode = 1;
} finally {
  if (session) {
    await session.close().catch(() => {});
  }

  try {
    summary.license.teardown = await teardownLicense(license.managed, log);
  } catch (error) {
    summary.license.teardown = { error: error.message };
    log(`license teardown failed: ${error.message}`);
    exitCode = 1;
  }

  summary.finishedAt = new Date().toISOString();
  summary.durationMs = Date.now() - startedAt;
  summary.status = summary.journeys.some((journey) => journey.status === 'failed') || exitCode !== 0 ? 'failed' : 'passed';

  fs.writeFileSync(path.join(dir, 'summary.json'), JSON.stringify(summary, null, 2));
  report(summary, stamp, log);

  process.exit(summary.status === 'failed' ? 1 : 0);
}
```

- [ ] **Step 5: Create `smoke/lib/reporter.js`**

```js
const PAD = 34;

export function report(summary, stamp, log) {
  log('');
  log(`run ${stamp} (${(summary.durationMs / 1000).toFixed(1)}s)`);

  for (const journey of summary.journeys) {
    const label = `${journey.id} ${journey.name}`.padEnd(PAD, ' ');
    const detail = journey.reason || journey.error || '';
    log(`  ${label} ${journey.status.toUpperCase().padEnd(8, ' ')} ${(journey.durationMs / 1000).toFixed(1)}s ${detail}`);
  }

  log('');
  log(`artifacts: ${summary.artifacts}`);
  log(`result: ${summary.status.toUpperCase()}`);
}
```

- [ ] **Step 6: Run J1**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools/smoke" && npm run smoke -- --journey=J1
```

Expected: `J1 passed`, a `j1-01-dashboard.png` of the dashboard in `artifacts/<stamp>/`, `summary.json` written, exit 0.

- [ ] **Step 7: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add smoke/lib smoke/journeys smoke/run.js
git commit -m "feat(smoke): add the browser session, the reporter and J1

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Editor fixture, J2 and J3

**Files:**
- Create: `smoke/lib/editor.js`, `smoke/journeys/j2-editor-panels.js`, `smoke/journeys/j3-block-toolbar.js`
- Modify: `smoke/journeys/index.js`

**Interfaces:**
- Consumes: `lib/journey.js`, `lib/config.js`.
- Produces:
  ```js
  // lib/editor.js
  export async function ensureEditorPage(ctx): Promise<{ canvas, created: boolean }>
  export async function selectDocumentTab(page): Promise<void>
  export async function ensureImageBlock(ctx): Promise<void>
  export async function realClick(page, locator, what): Promise<void>
  ```
  `ensureEditorPage` memoises on `ctx.session` so a full run creates one page and
  a `--journey=J5` run creates its own.

- [ ] **Step 1: Create `smoke/lib/editor.js`**

```js
import { BASE_URL, TIMEOUTS } from './config.js';
import { AssertionFailure, assertTrue, waitFor } from './journey.js';

const CANVAS = 'iframe[name="editor-canvas"]';
const MEDIA_LIBRARY_LABEL = /Médiathèque|Media Library/i;

/**
 * Click where the element actually is, after proving nothing covers it.
 *
 * The block toolbar only appears for a real pointer event inside the canvas;
 * neither selectBlock nor a synthetic click surfaces it. Playwright's own click
 * would do, except that the editor keeps invisible overlays over the canvas
 * that make it retry until it times out, so the coordinates are driven
 * directly and the hit test replaces the actionability check.
 */
export async function realClick(page, locator, what) {
  await locator.waitFor({ state: 'visible' });
  await locator.scrollIntoViewIfNeeded();

  const box = await locator.boundingBox();

  assertTrue(box !== null, `${what} has no bounding box`);

  const onTarget = await locator.evaluate((node) => {
    const rect = node.getBoundingClientRect();
    const top = node.ownerDocument.elementFromPoint(
      rect.x + rect.width / 2,
      rect.y + rect.height / 2
    );

    return top === node || node.contains(top);
  });

  if (!onTarget) {
    throw new AssertionFailure(`${what} is covered by another element at its own centre`);
  }

  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
}

async function dismissBlockingModals(page) {
  await page.evaluate(() => {
    const preferences = window.wp?.data?.dispatch('core/preferences');

    if (!preferences) {
      return;
    }

    for (const scope of ['core', 'core/edit-post', 'core/edit-site']) {
      try {
        preferences.set(scope, 'welcomeGuide', false);
        preferences.set(scope, 'welcomeGuideTemplate', false);
        preferences.set(scope, 'enableChoosePatternModal', false);
      } catch {
        // Scopes come and go between releases; the ones that exist are enough.
      }
    }
  });

  // The start-pattern chooser is already mounted by the time the preference
  // lands, and it swallows every click on the canvas until it is closed.
  for (let attempt = 0; attempt < 6; attempt++) {
    if ((await page.locator('.components-modal__frame').count()) === 0) {
      return;
    }

    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
  }

  throw new AssertionFailure('a modal is still covering the editor after six Escape presses');
}

export async function selectDocumentTab(page) {
  // PluginDocumentSettingPanel only mounts while the sidebar shows the
  // document tab, and selecting a block switches it to the block tab. The id
  // suffix is stable across locales; the label is not.
  const tab = page.locator('[role="tab"][id$="edit-post/document"]');

  await tab.waitFor({ state: 'visible' });
  await tab.click();
  await page.waitForTimeout(500);
}

export async function ensureEditorPage(ctx) {
  const { page, session, log } = ctx;

  if (session.editorReady) {
    return { canvas: page.frameLocator(CANVAS), created: false };
  }

  await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForSelector(CANVAS, { timeout: TIMEOUTS.editor });

  await dismissBlockingModals(page);

  const canvas = page.frameLocator(CANVAS);

  await canvas.locator('.editor-post-title__input').first().click();
  await page.keyboard.type('AI Forge smoke page');
  await page.keyboard.press('Enter');
  await page.keyboard.type('A paragraph typed by the browser smoke harness.');

  await waitFor(
    () =>
      page.evaluate(() => {
        const title = wp.data.select('core/editor').getEditedPostAttribute('title');
        const blocks = wp.data.select('core/block-editor').getBlocks();

        return title && blocks.some((block) => block.name === 'core/paragraph') ? { title } : null;
      }),
    'the typed title and paragraph never reached the editor store'
  );

  session.editorReady = true;
  log('editor page prepared (title + paragraph typed in the canvas)');

  return { canvas, created: true };
}

export async function ensureImageBlock(ctx) {
  const { page } = ctx;

  const present = await page.evaluate(() =>
    wp.data.select('core/block-editor').getBlocks().some((block) => block.name === 'core/image')
  );

  if (present) {
    return;
  }

  await page.evaluate(() => {
    wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('core/image'));
  });

  await page.frameLocator(CANVAS).locator('[data-type="core/image"]').first().waitFor({ state: 'visible' });
}

export { CANVAS, MEDIA_LIBRARY_LABEL };
```

- [ ] **Step 2: Create `smoke/journeys/j2-editor-panels.js`**

```js
import { ensureEditorPage, selectDocumentTab } from '../lib/editor.js';
import { assertTrue } from '../lib/journey.js';

export default {
  id: 'J2',
  name: 'Editor document panels',

  async run(ctx) {
    const { page, log, shot } = ctx;

    await ensureEditorPage(ctx);
    await shot('canvas');

    await selectDocumentTab(page);

    const panels = page.locator('.aiforge-doc-panel');

    await panels.first().waitFor({ state: 'visible' });

    const titles = await page.locator('.aiforge-doc-panel__title').allInnerTexts();

    log(`document panels: ${titles.join(', ')}`);

    assertTrue(
      titles.some((title) => /Content Integrator/i.test(title)),
      `no Content Integrator document panel in the sidebar (found: ${titles.join(', ') || 'none'})`
    );

    await shot('doc-panels');
  },
};
```

- [ ] **Step 3: Create `smoke/journeys/j3-block-toolbar.js`**

```js
import { CANVAS, ensureEditorPage, ensureImageBlock, realClick } from '../lib/editor.js';
import { assertTrue } from '../lib/journey.js';

const SUGGEST_LABEL = /Suggérer une image pertinente|Suggest a relevant image/i;

export default {
  id: 'J3',
  name: 'Block toolbar integration',

  async run(ctx) {
    const { page, log, shot } = ctx;

    await ensureEditorPage(ctx);
    await ensureImageBlock(ctx);

    const block = page.frameLocator(CANVAS).locator('[data-type="core/image"]').first();

    await realClick(page, block, 'the image block');

    const toolbar = page.locator('.block-editor-block-contextual-toolbar');

    await toolbar.waitFor({ state: 'visible' });

    const labels = await toolbar.locator('button[aria-label]').evaluateAll((buttons) =>
      buttons.map((button) => button.getAttribute('aria-label'))
    );

    log(`toolbar buttons: ${labels.join(', ')}`);

    assertTrue(
      labels.some((label) => SUGGEST_LABEL.test(label || '')),
      `no media suggestion button in the block toolbar (found: ${labels.join(', ') || 'none'})`
    );

    await shot('block-toolbar');
  },
};
```

- [ ] **Step 4: Register both in `smoke/journeys/index.js`**

```js
import j1 from './j1-admin-spa.js';
import j2 from './j2-editor-panels.js';
import j3 from './j3-block-toolbar.js';

export const JOURNEYS = [j1, j2, j3];
```

- [ ] **Step 5: Run them**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools/smoke" && npm run smoke -- --journey=J2,J3
```

Expected: both pass. `j2-02-doc-panels.png` shows the sidebar with "Content Integrator" and "SEO Meta Generator", `j3-01-block-toolbar.png` shows the toolbar carrying "Suggérer une image pertinente".

If J3 fails with `no media suggestion button`, check `license.managed` in the summary: the toolbar button is registered behind `window.aiforgeData.licenseActive`.

- [ ] **Step 6: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add smoke/lib/editor.js smoke/journeys
git commit -m "feat(smoke): add the editor fixture with J2 document panels and J3 block toolbar

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Media helpers, J4 and J5

**Files:**
- Create: `smoke/lib/media.js`, `smoke/journeys/j4-media-grid.js`, `smoke/journeys/j5-media-modal.js`
- Modify: `smoke/journeys/index.js`

**Interfaces:**
- Consumes: `lib/journey.js`, `lib/editor.js`.
- Produces:
  ```js
  // lib/media.js
  export function requireSearch(env): void          // raises SkipError with the probed reason
  export async function setSearchEnabled(page, scope, enabled): Promise<boolean>  // returns the previous state
  export async function runSearch(page, scope, query): Promise<{summary, chips}>
  export async function chipLabels(page, scope): Promise<string[]>
  export async function tagActiveMediaModal(page): Promise<{ selector, frames, fromRegistry }>
  ```
  `scope` is a CSS prefix (`''` for the grid, `'[data-aiforge-smoke-modal] '` for the modal).

- [ ] **Step 1: Create `smoke/lib/media.js`**

```js
import { TIMEOUTS } from './config.js';
import { AssertionFailure, skip, waitFor } from './journey.js';

const MODAL_TAG = 'data-aiforge-smoke-modal';

export const MODAL_SCOPE = `[${MODAL_TAG}] `;

/**
 * Skip rather than fail when the lab cannot host an AI search.
 *
 * A bare lab has no provider and no index, and a red suite for that would say
 * nothing about the product. A lab where the plugin is not even loaded is a
 * different matter: those journeys must go red.
 */
export function requireSearch(env) {
  if (!env.pluginActive) {
    return;
  }

  if (!env.search.available) {
    skip(`AI media search unavailable: ${env.search.reason}`);
  }
}

export async function setSearchEnabled(page, scope, enabled) {
  const toggle = page.locator(`${scope}.aiforge-search-toggle`).first();

  await toggle.waitFor({ state: 'visible' });

  const previous = (await toggle.getAttribute('aria-checked')) === 'true';

  if (previous !== enabled) {
    await toggle.click();
    await waitFor(
      async () => (await toggle.getAttribute('aria-checked')) === String(enabled),
      `the AI search switch never reached aria-checked="${enabled}"`
    );
  }

  return previous;
}

export async function chipLabels(page, scope) {
  return page.locator(`${scope}.aiforge-media-search__chips .aiforge-chip`).allInnerTexts();
}

export async function runSearch(page, scope, query) {
  const input = page.locator(`${scope}input#media-search-input`).first();

  await input.fill(query);
  await input.press('Enter');

  const summary = await waitFor(
    async () => {
      const band = page.locator(`${scope}.aiforge-media-search__band`).first();

      if ((await band.count()) === 0) {
        return null;
      }

      const text = await page.locator(`${scope}.aiforge-media-search__summary`).first().innerText();

      // The band opens on a coverage line; a search has answered once the
      // count of results replaces it and the loading line is gone.
      return /r(é|e)sultat|result/i.test(text) ? text.trim() : null;
    },
    `the AI search never reported a result for "${query}"`,
    TIMEOUTS.search
  );

  return { summary, chips: await chipLabels(page, scope) };
}

/**
 * Find the media modal the user is looking at and mark it.
 *
 * wp.media.frame is the last frame created, not the visible one, so the walk
 * starts from the wp.media.frames registry and only adds wp.media.frame as one
 * more candidate. Gutenberg's image-block modal registers nothing, so the
 * fallback is what resolves it there, and the registry is what saves the grid
 * once an attachment detail modal has been opened over it.
 */
export async function tagActiveMediaModal(page) {
  const resolved = await page.evaluate((tag) => {
    document.querySelectorAll(`[${tag}]`).forEach((node) => node.removeAttribute(tag));

    const media = window.wp && window.wp.media;

    if (!media) {
      return { found: false, reason: 'wp.media is not loaded' };
    }

    const registry = media.frames || {};
    const candidates = Object.keys(registry)
      .map((key) => registry[key])
      .filter(Boolean);
    const fromRegistry = candidates.length;

    if (media.frame && candidates.indexOf(media.frame) === -1) {
      candidates.push(media.frame);
    }

    const visible = candidates.filter(
      (frame) => frame && frame.el && frame.el.isConnected && frame.el.checkVisibility()
    );

    for (const frame of visible) {
      const modal = frame.el.closest('.media-modal');

      if (modal && modal.checkVisibility()) {
        modal.setAttribute(tag, '1');

        return { found: true, frames: candidates.length, visible: visible.length, fromRegistry };
      }
    }

    return { found: false, reason: 'no visible media frame', frames: candidates.length, fromRegistry };
  }, MODAL_TAG);

  if (!resolved.found) {
    throw new AssertionFailure(`could not resolve the open media modal: ${resolved.reason}`);
  }

  return { selector: MODAL_SCOPE, ...resolved };
}
```

- [ ] **Step 2: Create `smoke/journeys/j4-media-grid.js`**

```js
import { BASE_URL, SEARCH_QUERY, TIMEOUTS } from '../lib/config.js';
import { assertTrue, skip, waitFor } from '../lib/journey.js';
import { chipLabels, requireSearch, runSearch, setSearchEnabled } from '../lib/media.js';

export default {
  id: 'J4',
  name: 'Media library grid and AI search',

  async run({ page, env, log, shot }) {
    if (env.pluginActive && env.mediaIndexed === 0) {
      skip('media index is empty: no badges and no AI search to exercise');
    }

    await page.goto(`${BASE_URL}/wp-admin/upload.php?mode=grid`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.attachments-browser', { timeout: TIMEOUTS.navigation });

    const badges = await waitFor(
      async () => {
        const count = await page.locator('.aiforge-grid-badge').count();
        return count > 0 ? count : null;
      },
      'no .aiforge-grid-badge rendered in the media grid'
    );

    log(`${badges} index badge(s) on the grid`);
    await shot('grid-badges');

    requireSearch(env);

    const wasEnabled = await setSearchEnabled(page, '', true);

    try {
      const { summary, chips } = await runSearch(page, '', SEARCH_QUERY);

      log(`search summary: ${summary}`);
      log(`facet chips: ${chips.join(' / ') || 'none'}`);
      await shot('ai-search-results');

      assertTrue(
        chips.length > 0,
        `the AI search returned no interpreted-facet chips for "${SEARCH_QUERY}"`
      );

      const removed = chips[0];

      await page.locator('.aiforge-media-search__chips .aiforge-chip--removable').first().click();

      const after = await waitFor(
        async () => {
          const current = await chipLabels(page, '');
          return current.includes(removed) ? null : current;
        },
        `removing the chip "${removed}" never updated the results`,
        TIMEOUTS.search
      );

      log(`chips after removing "${removed}": ${after.join(' / ') || 'none'}`);
      await shot('ai-search-chip-removed');
    } finally {
      // The switch is a stored per-user preference; the run gives it back.
      await setSearchEnabled(page, '', wasEnabled).catch(() => {});
    }
  },
};
```

- [ ] **Step 3: Create `smoke/journeys/j5-media-modal.js`**

```js
import { SEARCH_QUERY } from '../lib/config.js';
import { CANVAS, MEDIA_LIBRARY_LABEL, ensureEditorPage, ensureImageBlock, realClick } from '../lib/editor.js';
import { assertTrue, skip } from '../lib/journey.js';
import { MODAL_SCOPE, requireSearch, runSearch, setSearchEnabled, tagActiveMediaModal } from '../lib/media.js';

export default {
  id: 'J5',
  name: 'Gutenberg media modal AI search',

  async run(ctx) {
    const { page, env, log, shot } = ctx;

    if (env.pluginActive && env.mediaIndexed === 0) {
      skip('media index is empty: the modal has no AI search to exercise');
    }

    requireSearch(env);

    await ensureEditorPage(ctx);
    await ensureImageBlock(ctx);

    const button = page
      .frameLocator(CANVAS)
      .locator('[data-type="core/image"] button')
      .filter({ hasText: MEDIA_LIBRARY_LABEL })
      .first();

    await realClick(page, button, 'the image block media library button');

    await page.waitForSelector('.media-modal', { state: 'visible' });

    const modal = await tagActiveMediaModal(page);

    log(
      `resolved the open modal from ${modal.frames} frame(s), ` +
        `${modal.fromRegistry} of them registered in wp.media.frames`
    );

    await shot('modal-open');

    const toggles = await page.locator(`${MODAL_SCOPE}.aiforge-search-toggle`).count();

    assertTrue(toggles === 1, `expected one AI search switch inside the modal, found ${toggles}`);

    const wasEnabled = await setSearchEnabled(page, MODAL_SCOPE, true);

    try {
      const { summary, chips } = await runSearch(page, MODAL_SCOPE, SEARCH_QUERY);

      log(`modal search summary: ${summary}`);
      log(`modal facet chips: ${chips.join(' / ') || 'none'}`);

      await shot('modal-ai-search');
    } finally {
      await setSearchEnabled(page, MODAL_SCOPE, wasEnabled).catch(() => {});
    }
  },
};
```

- [ ] **Step 4: Register both in `smoke/journeys/index.js`**

```js
import j1 from './j1-admin-spa.js';
import j2 from './j2-editor-panels.js';
import j3 from './j3-block-toolbar.js';
import j4 from './j4-media-grid.js';
import j5 from './j5-media-modal.js';

export const JOURNEYS = [j1, j2, j3, j4, j5];
```

- [ ] **Step 5: Run the full suite**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools/smoke" && npm run smoke
```

Expected: five journeys pass, total under 5 minutes, `summary.json` and about ten screenshots in `artifacts/<stamp>/`.

Each of J4 and J5 spends two fast-tier LLM parses (one per query, one more for
the chip removal in J4). That is the intended cost of exercising the real
search.

- [ ] **Step 6: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add smoke/lib/media.js smoke/journeys
git commit -m "feat(smoke): add J4 media grid and J5 Gutenberg modal AI search

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: README, CLAUDE.md and the acceptance runs

**Files:**
- Create: `smoke/README.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Write `smoke/README.md`**

Must cover, per the spec's acceptance criteria: invocation, environment
assumptions, and where the allowlist lives. Include the journey table, the
license contract, the artifact layout, and the trap list, so the next session
does not re-learn any of it.

- [ ] **Step 2: Add a `Browser smoke harness` section to `CLAUDE.md`**

Two paragraphs next to the QG campaign runner section: what it is, the two
commands, and the fact that it never touches a real license.

- [ ] **Step 3: Acceptance run 1 — green on the current lab**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools/smoke" && npm run smoke
cat artifacts/<stamp>/summary.json
```

Record the wall-clock duration and the summary.

- [ ] **Step 4: Acceptance run 2 — red path**

```bash
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli -- wp plugin deactivate wp-ai-forge
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools/smoke" && npm run smoke; echo "exit=$?"
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli -- wp plugin activate wp-ai-forge
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools/smoke" && npm run smoke; echo "exit=$?"
```

Expected: J1 to J5 all `failed` with legible messages while the plugin is off,
exit 1; then all green again, exit 0. The lab must end active.

- [ ] **Step 5: Acceptance run 3 — license teardown proof**

The lab carries a **real** license, so it has to be parked and restored around
this proof. Snapshot `aiforge_config`, `aiforge_license_status` and
`aiforge_webhook_token` to a file outside the repo, remove them locally (never
through `LicenseClient::deactivate()`, which would call the license server and
free the site slot), run the two proofs, then write the three options back and
re-probe.

Expected: `not_activated` before, `not_activated` after a full green run, and
`not_activated` after a deliberately failed run.

- [ ] **Step 6: Commit the docs**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add smoke/README.md CLAUDE.md
git commit -m "docs(smoke): document the browser smoke harness

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Self-review against the spec

| Spec item | Where |
|---|---|
| Location `smoke/`, own package.json, pinned playwright, `npx playwright install chromium` | Task 1 steps 1-3 |
| `npm run smoke` / `npm run smoke -- --journey=J4`, non-zero exit | Task 3 step 4 (`parseArguments`, `process.exit`) |
| Artifacts: per-step screenshots + JSON summary in `artifacts/<timestamp>/`, gitignored, 1680x1050 | Task 1 step 2, Task 2 step 3, Task 3 steps 1 and 4 |
| License: probe, install only when `not_activated`, teardown in `finally` including on crash, real license untouched | Task 1 step 4 (PHP), Task 2 step 4, Task 3 step 4 (`finally`) |
| J4/J5 SKIPPED with a reason when providers/index missing | Task 5 steps 1-3 (`requireSearch`, `mediaIndexed === 0`) |
| Console allowlist in one place, CORS noise from the lab theme | Task 2 step 1 |
| J0 preflight: 503 retry, WP version + locale, license/provider/index probes | Task 1 step 7, Task 3 step 4 |
| J1 admin SPA | Task 3 step 2 |
| J2 editor doc panels | Task 4 step 2 |
| J3 block toolbar via a real click | Task 4 steps 1 and 3 |
| J4 grid badges + AI search + chip removal | Task 5 step 2 |
| J5 Gutenberg modal, `wp.media.frames` walk | Task 5 steps 1 and 3 |
| Traps: email interstitial, welcome guide, 503, locale, upload.php path, real click, frame walk, wp eval markers + MSYS | Tasks 1, 3, 4, 5, listed in the trap table above |
| Sequential, one browser, one context, under 5 minutes | Task 3 step 1 (one context, one page), Task 6 step 3 |
| `smoke/README.md` | Task 6 step 1 |
