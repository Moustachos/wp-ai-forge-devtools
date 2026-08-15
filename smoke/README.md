# Browser smoke harness

Five journey-level Playwright checks over the AI Forge surfaces that only a
browser can see: the admin SPA, the iframed block editor (document panels,
block toolbar, media modal) and the media library grid. It is a pre-release
gate you run by hand, and the screenshot source for UI audits.

It is not a test suite. There are no unit tests here, no assertions on
internals, and no visual diffing. A journey either walks the surface a user
walks, or says why it could not.

## Running it

```bash
cd smoke
npm install
npx playwright install chromium   # once per machine

npm run smoke                     # every journey
npm run smoke -- --journey=J4     # one
npm run smoke -- --journey=J2,J3  # several
```

Exit code is `0` when nothing failed and `1` otherwise, so the command can gate
a release directly. Skipped journeys do not fail the run.

`SMOKE_HEADED=1` runs the browser visibly. `AIFORGE_SMOKE_URL`,
`AIFORGE_SMOKE_USER` and `AIFORGE_SMOKE_PASSWORD` override the target.

## Environment assumptions

- The wp-env lab is running at `http://localhost:8888` with `admin` / `password`
  (`cd wp-lab && npx wp-env start`).
- `npx wp-env run cli` works from the lab root: the harness reads the license,
  provider and index states through it, and pushes `admin_email_lifespan` a year
  out so the login never lands on the confirm-email interstitial.
- The lab is `fr_FR`. Every label-based selector accepts the French or the
  English wording, and structural selectors are preferred wherever one exists.
- The full run takes about 45 seconds and spends four fast-tier LLM parses
  (two AI searches, plus the re-parse behind J4's chip removal).

Nothing here ships in the commercial plugin, and nothing in the commercial
plugin knows this exists.

## Journeys

| Id | Surface | What has to happen |
|---|---|---|
| J0 | preflight | The site answers (retrying through maintenance mode), and the WordPress version, locale, license, services and media index are probed and logged |
| J1 | admin SPA | `admin.php?page=ai-forge` mounts `#aiforge-root` with real content |
| J2 | editor | A new page shows the AI Forge document panels in the sidebar |
| J3 | editor | Clicking an image block surfaces the block toolbar carrying the media suggestion button |
| J4 | media grid | `upload.php?mode=grid` shows index badges, an AI search returns results with interpreted-facet chips, and removing a chip re-runs the search |
| J5 | editor | The image block's media modal carries the AI search switch, and a query runs inside it |

J4 and J5 report **skipped** with the probed reason when the lab has no media
index or no usable AI service. They only go red when the surface is there and
broken.

**Every journey must be runnable alone.** `--journey=<one>` has to give the same
verdict as the same journey inside a full run, so no journey may depend on
state a previous one left behind. The editor journeys share a prepared page
through `ensureEditorPage()`, which builds one when the session does not
already have it, and each of them inserts whatever blocks it needs. When you add
a journey, prove it both ways:

```bash
for j in J1 J2 J3 J4 J5; do npm run smoke -- --journey=$j; done
npm run smoke
```

## License

The editor integration is gated on an active license, so a bare lab has nothing
to assert. Before the browser opens, the harness probes the license state:

- `not_activated`: it installs a fake key locally and removes it again in a
  `finally` that runs on success, on failure and on a crash. The container side
  removes the key only when it is the harness's own.
- anything else: it touches nothing at all. A real license is never read,
  written, deactivated or sent anywhere.

`summary.json` records what it found, what it did and what the teardown left
behind.

## Artifacts

`artifacts/<timestamp>/` holds one PNG per journey step at 1680x1050, a
`<journey>-failure.png` for anything that went red, and `summary.json` with the
preflight probe, the license decision, and per-journey status, duration,
console errors and screenshot list. The directory is gitignored.

## Console errors

Every `console.error` and `pageerror` is collected per journey. Anything that
does not match the allowlist in **`lib/console-allowlist.js`** fails that
journey. That file is the only place noise is ever forgiven; it currently holds
one entry, the CORS failures the gutenberg-blocks lab theme causes by pulling
assets from a remote sandbox host.

## Traps this harness already pays for

Each of these cost half a day the first time. They are handled, not documented
as caveats.

- **Login interstitial.** `wp-login` redirects to `confirm_admin_email` once the
  reminder is due. Preflight pushes `admin_email_lifespan` a year out instead of
  clicking through, and the session fails loudly if the interstitial still shows.
- **Welcome guide.** Disabled through `core/preferences` in every scope the
  editor might read.
- **Start-pattern chooser.** A new page opens it over the canvas, and setting the
  preference afterwards does not close the one already mounted. The fixture
  presses Escape until no modal is left.
- **Document panels vanish.** They are `PluginDocumentSettingPanel` instances, so
  they only exist while the sidebar shows the document tab, and typing a
  paragraph selects a block and switches it away. The fixture clicks
  `[role="tab"][id$="edit-post/document"]`, whose id suffix survives translation.
- **The block toolbar needs a real click.** `selectBlock` does not surface it.
  `realClick()` reads the bounding box, hit-tests `elementFromPoint`, then drives
  `page.mouse`. The hit test also refuses a point that lands on a control
  *inside* the target, which is how an early version silently opened the media
  modal while claiming to select the block.
- **One mouse move is not a click.** Gutenberg hides the block toolbar while it
  believes the user is typing, and the only thing that clears that belief is
  `useMouseMoveTypingReset`. Its listener is attached fresh every time typing
  starts, with no previous coordinate to compare against, so **the first
  mousemove after typing is only recorded, never acted on**
  (`wp-includes/js/dist/block-editor.js`, `stopTypingOnMouseMove`).
  `page.mouse.click()` emits exactly one move, which is why J3 passed inside a
  full run (an earlier journey had already moved the pointer) and hung for 20
  seconds on a cold editor. `realClick()` moves twice, one pixel apart, then
  presses.
- **Maintenance mode.** A core or translation update answers 503 for ten minutes.
  Preflight retries with backoff and says so.
- **`wp.media.frame` lies.** It is the last frame created, not the visible one.
  `tagActiveMediaModal()` walks `wp.media.frames`, adds `wp.media.frame` as one
  more candidate, keeps the frames that are connected and visible, and tags the
  modal it resolved. Both halves matter: Gutenberg's image-block modal registers
  no named frame, and the media grid loses `wp.media.frame` the moment an
  attachment detail modal opens over it.
- **`/wp-admin/upload.php`**, never `/upload.php`.
- **The AI search switch is a stored preference** and may already be on. It is
  read before it is touched and given back afterwards.
- **`wp eval` from Git Bash.** Absolute container paths get rewritten by MSYS,
  and wp-env echoes the command text around the output. The harness runs
  `wp eval-file` on a WordPress-root-relative path and reads back only the line
  prefixed `XRESULT:`. `php/harness.php` carries no `declare(strict_types=1)`,
  because `eval-file` runs the body through `eval()` where a declare can never
  be the first statement.
