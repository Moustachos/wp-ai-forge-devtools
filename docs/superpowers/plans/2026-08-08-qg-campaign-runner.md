# QG Campaign Runner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `wp aiforge-dev qg-campaign` WP-CLI command that runs a full provider × preset × template validation matrix unattended and emits a comparative Quality Gate report.

**Architecture:** All code lives in the `wp-ai-forge-devtools` plugin, never in the shipped plugin. Pure logic (combo parsing, score aggregation, baseline deltas, in-flight arithmetic) sits in dependency-free classes under `src/Campaign/` and is unit-tested; the WordPress glue (REST task creation, polling, meta collection) sits in `CampaignRunner` and the CLI shell, verified by a real campaign run. Tasks are launched through a **windowed scheduler** that keeps at most 4 root tasks in flight.

**Tech Stack:** PHP 8.2+ (strict types), WP-CLI, PHPUnit 11 + Brain Monkey (new to this plugin), the main plugin's REST task API and `TaskExecutor`.

## Global Constraints

- **Spec:** `../wp-ai-forge/docs/specs/2026-08-qg-campaign-runner.md`. This plan is the implementation record; the spec is the decision record.
- **Nothing ships in `wp-ai-forge`.** Every file created or modified by this plan is inside `wp-ai-forge-devtools`. Do not add, rename, or touch anything in the main plugin.
- **Never mention the dev-tools plugin from the main plugin** — no code comment, hook name, or string in `wp-ai-forge` may reference it. (Project rule, `.claude/CLAUDE.local.md`.)
- **PHP 8.2+, `declare(strict_types=1);`** at the top of every new PHP file.
- **Namespace:** PSR-4 `AIForge\` → `src/` (devtools' own autoloader). Tests: `AIForge\DevTools\Tests\` → `tests/`.
- **`wp-ai-forge-devtools` is its own git repo.** All commits in this plan happen there, on a branch off its `main`.
- **Test command:** `cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit` (never `composer test` on the host).
- **WP-CLI command name:** `wp aiforge-dev qg-campaign` exactly.
- **Report output directory:** `wp-content/uploads/aiforge-dev/` exactly.
- **No UI.** CLI + JSON only. No independent-signal computation (that stays in `/qg-audit`).

## Correction to the spec's premise (verified 2026-08-08)

The spec says creations are dropped by "the REST rate limiter" and prescribes *spacing* them. **That is misattributed.** `TaskController` does not use the `RateLimiter` trait; there is no rate limit on `POST /aiforge/v1/tasks`. The real gate is `../wp-ai-forge/src/REST/TaskController.php:456-465`:

```php
$activeTasks = $this->taskRepo->countActiveByUser($userId);
if ($activeTasks >= 5) { return $this->errorResponse('too_many_tasks', ..., 429); }
```

`TaskRepository::countActiveByUser()` counts root tasks (`parent_id IS NULL`) in status `pending` **or** `running`. It is a **concurrency cap of 5 in-flight roots per user**, not a per-window rate limit.

Consequence: a fixed sleep between creations does not help. On a 7-combo campaign, combos 6 and 7 are rejected with HTTP 429 regardless of delay, because the first 5 are still running for minutes. This plan implements a **windowed scheduler** (`MAX_IN_FLIGHT = 4`, one slot left for the human) instead of the prescribed sleep. The spec's acceptance criterion ("7+ combos created reliably in one invocation") is met by that.

## Second gotcha, found while running (2026-08-08)

`TaskController::createTask()` ends with `triggerImmediateExecution()`, which calls `spawn_cron()`. This wp-env install defines `ALTERNATE_WP_CRON`, and that branch of `spawn_cron()` does `require_once ABSPATH . 'wp-cron.php'` **in-process** — a file that ends in `die()`. The campaign therefore died right after launching its first combo, before writing any report. The task itself completed fine, which makes the failure look like a reporting bug rather than a control-flow one.

`wp-includes/cron.php:904` bails out of `spawn_cron()` when `isset($_GET['doing_wp_cron'])`, so the command sets that key before creating anything. The campaign drives execution itself through `drain()`, so losing the cron spawn costs nothing. Setting `DOING_CRON` would work too but flips `wp_doing_cron()` globally, which could change executor behavior; the `$_GET` key is scoped to `spawn_cron()` alone.

## Template slugs are French on this install

The spec's examples use English slugs (`showcase`, `blog-article`, `landing-page`). The real `aiforge_ci_tpl` slugs are: `article-de-blog-2`, `page-datterrissage`, `cas-client`, `page-de-service`, `showcase-2`, `manifesto`, `dossier`, `smde`. Check with `wp post list --post_type=aiforge_ci_tpl --fields=ID,post_title,post_name` before composing a matrix.

---

## File Structure

**Created (all under `wp-ai-forge-devtools/`):**

| File | Responsibility |
|---|---|
| `phpunit.xml.dist` | PHPUnit config, one `Unit` suite |
| `tests/bootstrap.php` | Composer autoloader + `AIFORGE_DEV_PATH` constant |
| `tests/Unit/TestCase.php` | Brain Monkey setUp/tearDown base class |
| `src/Campaign/ComboSpec.php` | Value object + parser for `provider:preset:template-slug` |
| `src/Campaign/RunResult.php` | Value object for one `llm_generate` run's gate scores |
| `src/Campaign/CampaignReport.php` | Aggregation (publishable rate, means, totals) + markdown/array rendering |
| `src/Campaign/BaselineComparator.php` | Per-combo deltas between two report arrays |
| `src/Campaign/CampaignRunner.php` | WordPress glue: source-batch resolution, windowed creation, polling, meta collection |
| `src/Cli/QgCampaignCommand.php` | WP-CLI shell: arg parsing, report file write, stdout |
| `tests/Unit/Campaign/ComboSpecTest.php` | |
| `tests/Unit/Campaign/CampaignReportTest.php` | |
| `tests/Unit/Campaign/BaselineComparatorTest.php` | |
| `tests/Unit/Campaign/CampaignRunnerTest.php` | Covers only the pure `launchableCount()` helper |

**Modified:**
- `composer.json` — add `phpunit/phpunit`, `brain/monkey`, `mockery/mockery` to `require-dev`; add `autoload-dev`; add `test` script.
- `src/DevTools.php` — register the CLI command when `WP_CLI` is defined.
- `wp-ai-forge-devtools.php` — version bump.
- `CLAUDE.md` — document the test command and the campaign runner.

---

## Task 1: Test harness + ComboSpec

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Unit/TestCase.php`
- Create: `src/Campaign/ComboSpec.php`
- Test: `tests/Unit/Campaign/ComboSpecTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  ```php
  final class ComboSpec {
      public function __construct(
          public readonly string $provider,
          public readonly string $preset,
          public readonly string $templateSlug,
      ) {}
      public function key(): string;                          // "gemini:balanced:showcase"
      public static function parse(string $combo): self;       // throws InvalidArgumentException
      /** @return ComboSpec[] */
      public static function parseList(string $csv): array;    // throws InvalidArgumentException
  }
  ```

- [ ] **Step 1: Add the dev dependencies**

Replace the `require-dev` block in `composer.json` and add `autoload-dev` + `scripts`. The full file becomes:

```json
{
  "name": "wpforge/ai-forge-devtools",
  "type": "wordpress-plugin",
  "license": "proprietary",
  "require": {
    "php": ">=8.2"
  },
  "autoload": {
    "psr-4": {
      "AIForge\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "AIForge\\DevTools\\Tests\\": "tests/"
    }
  },
  "require-dev": {
    "brain/monkey": "^2.6",
    "mockery/mockery": "^1.6",
    "phpunit/phpunit": "^11.0",
    "wpforge/ai-forge": "dev-main"
  },
  "repositories": [
    {
      "type": "path",
      "url": "../wp-ai-forge"
    }
  ],
  "scripts": {
    "test": "phpunit --testsuite Unit"
  },
  "config": {
    "optimize-autoloader": true,
    "sort-packages": true
  }
}
```

- [ ] **Step 2: Install the dependencies**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools composer update --no-interaction
```

Expected: `phpunit`, `brain/monkey` and `mockery` appear under `vendor/`. `vendor/` is already gitignored in this plugin, so nothing from it gets committed.

- [ ] **Step 3: Create the PHPUnit config**

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
    executionOrder="depends,defects"
    requireCoverageMetadata="false"
    beStrictAboutOutputDuringTests="true"
    failOnRisky="true"
    failOnWarning="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Create the bootstrap and base TestCase**

`tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('AIFORGE_DEV_PATH')) {
    define('AIFORGE_DEV_PATH', dirname(__DIR__) . '/');
}
```

`tests/Unit/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
```

Add `.phpunit.cache/` to `.gitignore` (append a line; the file currently ends with `vendor/` and no trailing newline, so add the newline too).

- [ ] **Step 5: Write the failing test**

`tests/Unit/Campaign/ComboSpecTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\ComboSpec;
use AIForge\DevTools\Tests\Unit\TestCase;
use InvalidArgumentException;

class ComboSpecTest extends TestCase
{
    public function testParsesASingleCombo(): void
    {
        $combo = ComboSpec::parse('gemini:balanced:showcase');

        $this->assertSame('gemini', $combo->provider);
        $this->assertSame('balanced', $combo->preset);
        $this->assertSame('showcase', $combo->templateSlug);
    }

    public function testKeyRoundTripsTheInput(): void
    {
        $this->assertSame('openai:economic:landing-page', ComboSpec::parse('openai:economic:landing-page')->key());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $combo = ComboSpec::parse('  anthropic : performance : case-study  ');

        $this->assertSame('anthropic', $combo->provider);
        $this->assertSame('performance', $combo->preset);
        $this->assertSame('case-study', $combo->templateSlug);
    }

    public function testParsesAList(): void
    {
        $combos = ComboSpec::parseList('gemini:balanced:showcase,openai:economic:manifesto');

        $this->assertCount(2, $combos);
        $this->assertSame('gemini:balanced:showcase', $combos[0]->key());
        $this->assertSame('openai:economic:manifesto', $combos[1]->key());
    }

    public function testIgnoresEmptySegmentsInAList(): void
    {
        $combos = ComboSpec::parseList('gemini:balanced:showcase, ,openai:economic:manifesto,');

        $this->assertCount(2, $combos);
    }

    public function testRejectsWrongFieldCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parse('gemini:balanced');
    }

    public function testRejectsEmptyField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parse('gemini::showcase');
    }

    public function testRejectsUnknownProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parse('mistral:balanced:showcase');
    }

    public function testRejectsUnknownPreset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parse('gemini:turbo:showcase');
    }

    public function testRejectsAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parseList('  ');
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit
```

Expected: FAIL with `Class "AIForge\Campaign\ComboSpec" not found`.

- [ ] **Step 7: Write the implementation**

`src/Campaign/ComboSpec.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use InvalidArgumentException;

/**
 * One cell of a validation matrix: which provider, at which preset, against
 * which Content Integrator template.
 */
final class ComboSpec
{
    private const PROVIDERS = ['gemini', 'openai', 'anthropic'];
    private const PRESETS = ['economic', 'balanced', 'performance'];

    public function __construct(
        public readonly string $provider,
        public readonly string $preset,
        public readonly string $templateSlug,
    ) {
    }

    public function key(): string
    {
        return $this->provider . ':' . $this->preset . ':' . $this->templateSlug;
    }

    public static function parse(string $combo): self
    {
        $parts = array_map('trim', explode(':', $combo));

        if (\count($parts) !== 3) {
            throw new InvalidArgumentException(
                "Combo '{$combo}' must have the form provider:preset:template-slug"
            );
        }

        [$provider, $preset, $templateSlug] = $parts;

        if ($provider === '' || $preset === '' || $templateSlug === '') {
            throw new InvalidArgumentException("Combo '{$combo}' has an empty field");
        }

        if (!\in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException(
                "Unknown provider '{$provider}' (expected one of: " . implode(', ', self::PROVIDERS) . ')'
            );
        }

        if (!\in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException(
                "Unknown preset '{$preset}' (expected one of: " . implode(', ', self::PRESETS) . ')'
            );
        }

        return new self($provider, $preset, $templateSlug);
    }

    /**
     * @return self[]
     */
    public static function parseList(string $csv): array
    {
        $combos = [];

        foreach (explode(',', $csv) as $segment) {
            if (trim($segment) === '') {
                continue;
            }

            $combos[] = self::parse($segment);
        }

        if ($combos === []) {
            throw new InvalidArgumentException('No combos given. Use --combos=provider:preset:template-slug,...');
        }

        return $combos;
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit
```

Expected: PASS, 10 tests.

- [ ] **Step 9: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add composer.json composer.lock phpunit.xml.dist .gitignore tests/ src/Campaign/ComboSpec.php
git commit -m "test: add PHPUnit harness and ComboSpec matrix parser

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: RunResult + CampaignReport aggregation

**Files:**
- Create: `src/Campaign/RunResult.php`, `src/Campaign/CampaignReport.php`
- Test: `tests/Unit/Campaign/CampaignReportTest.php`

**Interfaces:**
- Consumes: `ComboSpec::key(): string` from Task 1.
- Produces:
  ```php
  final class RunResult {
      public function __construct(
          public readonly int $taskId,
          public readonly string $comboKey,
          public readonly string $modelId,
          public readonly string $verdict,          // 'pass' | 'warnings' | 'fail' | 'unknown'
          public readonly int $globalScore,
          public readonly array $subscores,         // ['completion','signature','coverage','volume','leaks','dups'] => int
          public readonly float $cost,
      ) {}
      public function isPublishable(): bool;         // verdict is pass or warnings
      public function subscore(string $axis): int;   // 0 when missing
      public function toArray(): array;
  }

  final class CampaignReport {
      public function __construct(
          public readonly string $label,
          public readonly int $sourceBatchId,
          public readonly int $filesPerCombo,
          /** @var RunResult[] */ public readonly array $runs,
      ) {}
      public function comboKeys(): array;            // string[], insertion order
      public function comboStats(string $comboKey): array;
      public function totals(): array;
      public function flaggedTaskIds(): array;       // int[], runs that are not publishable
      public function toArray(): array;
      public function toMarkdown(): string;
  }
  ```
  `comboStats()` and `totals()` both return
  `['runs' => int, 'publishable_rate' => float, 'mean_global' => float, 'mean_signature' => float, 'total_cost' => float]`.
  All floats are rounded to 1 decimal except `total_cost`, rounded to 4.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Campaign/CampaignReportTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CampaignReport;
use AIForge\Campaign\RunResult;
use AIForge\DevTools\Tests\Unit\TestCase;

class CampaignReportTest extends TestCase
{
    private function makeRun(
        int $taskId,
        string $comboKey,
        string $verdict,
        int $global,
        int $signature = 80,
        float $cost = 0.10,
        string $modelId = 'gemini-3.6-flash'
    ): RunResult {
        return new RunResult(
            taskId: $taskId,
            comboKey: $comboKey,
            modelId: $modelId,
            verdict: $verdict,
            globalScore: $global,
            subscores: [
                'completion' => 90,
                'signature' => $signature,
                'coverage' => 85,
                'volume' => 88,
                'leaks' => 100,
                'dups' => 95,
            ],
            cost: $cost,
        );
    }

    // --- RunResult ---

    public function testPassAndWarningsArePublishable(): void
    {
        $this->assertTrue($this->makeRun(1, 'a:b:c', 'pass', 95)->isPublishable());
        $this->assertTrue($this->makeRun(2, 'a:b:c', 'warnings', 85)->isPublishable());
    }

    public function testFailAndUnknownAreNotPublishable(): void
    {
        $this->assertFalse($this->makeRun(3, 'a:b:c', 'fail', 40)->isPublishable());
        $this->assertFalse($this->makeRun(4, 'a:b:c', 'unknown', 0)->isPublishable());
    }

    public function testSubscoreReturnsZeroForUnknownAxis(): void
    {
        $this->assertSame(0, $this->makeRun(5, 'a:b:c', 'pass', 95)->subscore('nonexistent'));
        $this->assertSame(80, $this->makeRun(6, 'a:b:c', 'pass', 95)->subscore('signature'));
    }

    // --- Per-combo aggregation ---

    public function testComboStatsComputePublishableRateAndMeans(): void
    {
        $report = new CampaignReport('test', 100, 2, [
            $this->makeRun(1, 'gemini:balanced:showcase', 'pass', 96, 92, 0.08),
            $this->makeRun(2, 'gemini:balanced:showcase', 'warnings', 88, 60, 0.08),
            $this->makeRun(3, 'gemini:balanced:showcase', 'fail', 40, 30, 0.08),
            $this->makeRun(4, 'gemini:balanced:showcase', 'pass', 92, 90, 0.08),
        ]);

        $stats = $report->comboStats('gemini:balanced:showcase');

        $this->assertSame(4, $stats['runs']);
        // 3 of 4 publishable
        $this->assertSame(75.0, $stats['publishable_rate']);
        // (96 + 88 + 40 + 92) / 4 = 79
        $this->assertSame(79.0, $stats['mean_global']);
        // (92 + 60 + 30 + 90) / 4 = 68
        $this->assertSame(68.0, $stats['mean_signature']);
        $this->assertSame(0.32, $stats['total_cost']);
    }

    public function testMeansAreRoundedToOneDecimal(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'a:b:c', 'pass', 95),
            $this->makeRun(2, 'a:b:c', 'pass', 96),
            $this->makeRun(3, 'a:b:c', 'pass', 98),
        ]);

        // (95 + 96 + 98) / 3 = 96.333...
        $this->assertSame(96.3, $report->comboStats('a:b:c')['mean_global']);
    }

    public function testUnknownComboReturnsZeroedStats(): void
    {
        $report = new CampaignReport('test', 100, 1, []);

        $stats = $report->comboStats('nope:nope:nope');

        $this->assertSame(0, $stats['runs']);
        $this->assertSame(0.0, $stats['publishable_rate']);
        $this->assertSame(0.0, $stats['mean_global']);
        $this->assertSame(0.0, $stats['mean_signature']);
        $this->assertSame(0.0, $stats['total_cost']);
    }

    public function testComboKeysPreserveInsertionOrder(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'z:balanced:one', 'pass', 90),
            $this->makeRun(2, 'a:balanced:two', 'pass', 90),
            $this->makeRun(3, 'z:balanced:one', 'pass', 90),
        ]);

        $this->assertSame(['z:balanced:one', 'a:balanced:two'], $report->comboKeys());
    }

    // --- Totals ---

    public function testTotalsAggregateAcrossCombos(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'gemini:balanced:showcase', 'pass', 100, 90, 0.05),
            $this->makeRun(2, 'openai:economic:manifesto', 'fail', 50, 40, 0.15),
        ]);

        $totals = $report->totals();

        $this->assertSame(2, $totals['runs']);
        $this->assertSame(50.0, $totals['publishable_rate']);
        $this->assertSame(75.0, $totals['mean_global']);
        $this->assertSame(65.0, $totals['mean_signature']);
        $this->assertSame(0.2, $totals['total_cost']);
    }

    public function testTotalsOnAnEmptyCampaignDoNotDivideByZero(): void
    {
        $totals = (new CampaignReport('test', 100, 1, []))->totals();

        $this->assertSame(0, $totals['runs']);
        $this->assertSame(0.0, $totals['publishable_rate']);
        $this->assertSame(0.0, $totals['mean_global']);
    }

    // --- Flagged runs ---

    public function testFlaggedTaskIdsListNonPublishableRuns(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(11, 'a:b:c', 'pass', 95),
            $this->makeRun(12, 'a:b:c', 'fail', 40),
            $this->makeRun(13, 'a:b:c', 'unknown', 0),
            $this->makeRun(14, 'a:b:c', 'warnings', 85),
        ]);

        $this->assertSame([12, 13], $report->flaggedTaskIds());
    }

    // --- Serialisation ---

    public function testToArrayCarriesHeaderCombosAndTotals(): void
    {
        $report = new CampaignReport('v038-revalidation', 6168, 2, [
            $this->makeRun(1, 'gemini:balanced:showcase', 'pass', 96, 92, 0.08),
        ]);

        $array = $report->toArray();

        $this->assertSame('v038-revalidation', $array['label']);
        $this->assertSame(6168, $array['source_batch']);
        $this->assertSame(2, $array['files_per_combo']);
        $this->assertArrayHasKey('gemini:balanced:showcase', $array['combos']);
        $this->assertSame(96, $array['combos']['gemini:balanced:showcase']['runs'][0]['global']);
        $this->assertSame(1, $array['totals']['runs']);
    }

    public function testToArrayIsJsonSerialisable(): void
    {
        $report = new CampaignReport('test', 1, 1, [$this->makeRun(1, 'a:b:c', 'pass', 90)]);

        $json = json_encode($report->toArray());

        $this->assertIsString($json);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testMarkdownContainsComboRowsAndTotals(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(4251, 'openai:balanced:case-study', 'fail', 69, 26, 0.27, 'gpt-5.6-sol'),
        ]);

        $markdown = $report->toMarkdown();

        $this->assertStringContainsString('openai:balanced:case-study', $markdown);
        $this->assertStringContainsString('4251', $markdown);
        $this->assertStringContainsString('gpt-5.6-sol', $markdown);
        $this->assertStringContainsString('fail', $markdown);
        $this->assertStringContainsString('TOTAL', $markdown);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit --filter=CampaignReportTest
```

Expected: FAIL with `Class "AIForge\Campaign\CampaignReport" not found`.

- [ ] **Step 3: Write RunResult**

`src/Campaign/RunResult.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * One llm_generate run's Quality Gate outcome, as stored in task meta.
 */
final class RunResult
{
    public const AXES = ['completion', 'signature', 'coverage', 'volume', 'leaks', 'dups'];

    private const PUBLISHABLE_VERDICTS = ['pass', 'warnings'];

    /**
     * @param array<string, int> $subscores
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $comboKey,
        public readonly string $modelId,
        public readonly string $verdict,
        public readonly int $globalScore,
        public readonly array $subscores,
        public readonly float $cost,
    ) {
    }

    public function isPublishable(): bool
    {
        return \in_array($this->verdict, self::PUBLISHABLE_VERDICTS, true);
    }

    public function subscore(string $axis): int
    {
        return (int) ($this->subscores[$axis] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'task_id' => $this->taskId,
            'model_id' => $this->modelId,
            'verdict' => $this->verdict,
            'global' => $this->globalScore,
            'cost' => round($this->cost, 6),
        ];

        foreach (self::AXES as $axis) {
            $array[$axis] = $this->subscore($axis);
        }

        return $array;
    }
}
```

- [ ] **Step 4: Write CampaignReport**

`src/Campaign/CampaignReport.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Aggregates a campaign's runs into per-combo and grand-total statistics.
 *
 * "Publishable" means the Quality Gate returned pass or warnings; a warning
 * verdict is shippable, a fail is not.
 */
final class CampaignReport
{
    /**
     * @param RunResult[] $runs
     */
    public function __construct(
        public readonly string $label,
        public readonly int $sourceBatchId,
        public readonly int $filesPerCombo,
        public readonly array $runs,
    ) {
    }

    /**
     * @return string[] Combo keys in the order they first appear.
     */
    public function comboKeys(): array
    {
        $keys = [];

        foreach ($this->runs as $run) {
            $keys[$run->comboKey] = true;
        }

        return array_keys($keys);
    }

    /**
     * @return array{runs: int, publishable_rate: float, mean_global: float, mean_signature: float, total_cost: float}
     */
    public function comboStats(string $comboKey): array
    {
        return self::aggregate(array_filter(
            $this->runs,
            static fn (RunResult $run): bool => $run->comboKey === $comboKey
        ));
    }

    /**
     * @return array{runs: int, publishable_rate: float, mean_global: float, mean_signature: float, total_cost: float}
     */
    public function totals(): array
    {
        return self::aggregate($this->runs);
    }

    /**
     * @return int[] Task IDs whose verdict is not publishable.
     */
    public function flaggedTaskIds(): array
    {
        $ids = [];

        foreach ($this->runs as $run) {
            if (!$run->isPublishable()) {
                $ids[] = $run->taskId;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $combos = [];

        foreach ($this->comboKeys() as $key) {
            $stats = $this->comboStats($key);

            $detail = array_values(array_map(
                static fn (RunResult $run): array => $run->toArray(),
                array_filter($this->runs, static fn (RunResult $r): bool => $r->comboKey === $key)
            ));

            // In the serialised form 'runs' is the detail list and the count
            // moves to 'run_count'; comboStats() keeps 'runs' as the count.
            $combos[$key] = [
                'run_count' => $stats['runs'],
                'publishable_rate' => $stats['publishable_rate'],
                'mean_global' => $stats['mean_global'],
                'mean_signature' => $stats['mean_signature'],
                'total_cost' => $stats['total_cost'],
                'runs' => $detail,
            ];
        }

        return [
            'label' => $this->label,
            'source_batch' => $this->sourceBatchId,
            'files_per_combo' => $this->filesPerCombo,
            'combos' => $combos,
            'totals' => $this->totals(),
        ];
    }

    public function toMarkdown(): string
    {
        $lines = [];
        $lines[] = '## QG campaign — ' . $this->label;
        $lines[] = '';
        $lines[] = \sprintf(
            'Source batch %d, %d file(s) per combo, %d run(s).',
            $this->sourceBatchId,
            $this->filesPerCombo,
            \count($this->runs)
        );
        $lines[] = '';
        $lines[] = '| task | combo | model | verdict | global | comp | sign | covr | vol | leak | dup | cost |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|---|';

        foreach ($this->runs as $run) {
            $lines[] = \sprintf(
                '| %d | %s | %s | %s | %d | %d | %d | %d | %d | %d | %d | $%.3f |',
                $run->taskId,
                $run->comboKey,
                $run->modelId,
                $run->verdict,
                $run->globalScore,
                $run->subscore('completion'),
                $run->subscore('signature'),
                $run->subscore('coverage'),
                $run->subscore('volume'),
                $run->subscore('leaks'),
                $run->subscore('dups'),
                $run->cost
            );
        }

        $lines[] = '';
        $lines[] = '| combo | runs | publishable | mean global | mean signature | cost |';
        $lines[] = '|---|---|---|---|---|---|';

        foreach ($this->comboKeys() as $key) {
            $stats = $this->comboStats($key);
            $lines[] = \sprintf(
                '| %s | %d | %.1f%% | %.1f | %.1f | $%.3f |',
                $key,
                $stats['runs'],
                $stats['publishable_rate'],
                $stats['mean_global'],
                $stats['mean_signature'],
                $stats['total_cost']
            );
        }

        $totals = $this->totals();
        $lines[] = \sprintf(
            '| **TOTAL** | %d | %.1f%% | %.1f | %.1f | $%.3f |',
            $totals['runs'],
            $totals['publishable_rate'],
            $totals['mean_global'],
            $totals['mean_signature'],
            $totals['total_cost']
        );

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param RunResult[] $runs
     * @return array{runs: int, publishable_rate: float, mean_global: float, mean_signature: float, total_cost: float}
     */
    private static function aggregate(array $runs): array
    {
        $count = \count($runs);

        if ($count === 0) {
            return [
                'runs' => 0,
                'publishable_rate' => 0.0,
                'mean_global' => 0.0,
                'mean_signature' => 0.0,
                'total_cost' => 0.0,
            ];
        }

        $publishable = 0;
        $globalSum = 0;
        $signatureSum = 0;
        $costSum = 0.0;

        foreach ($runs as $run) {
            if ($run->isPublishable()) {
                $publishable++;
            }

            $globalSum += $run->globalScore;
            $signatureSum += $run->subscore('signature');
            $costSum += $run->cost;
        }

        return [
            'runs' => $count,
            'publishable_rate' => round(100 * $publishable / $count, 1),
            'mean_global' => round($globalSum / $count, 1),
            'mean_signature' => round($signatureSum / $count, 1),
            'total_cost' => round($costSum, 4),
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit
```

Expected: PASS. If `testToArrayCarriesHeaderCombosAndTotals` fails on `$array['combos'][...]['runs'][0]['global']`, the `toArray()` key juggling is wrong — `combos[key]['runs']` must be the **detail list**, and `combos[key]['run_count']` the integer.

- [ ] **Step 6: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add src/Campaign/RunResult.php src/Campaign/CampaignReport.php tests/Unit/Campaign/CampaignReportTest.php
git commit -m "feat: aggregate campaign runs into per-combo and total QG statistics

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: BaselineComparator

**Files:**
- Create: `src/Campaign/BaselineComparator.php`
- Test: `tests/Unit/Campaign/BaselineComparatorTest.php`

**Interfaces:**
- Consumes: the array shape produced by `CampaignReport::toArray()` from Task 2 — specifically `['combos' => [key => ['run_count' => int, 'publishable_rate' => float, 'mean_global' => float, 'mean_signature' => float, 'total_cost' => float, 'runs' => array]], 'totals' => [...]]`.
- Produces:
  ```php
  final class BaselineComparator {
      public const STATUS_REGRESSION  = 'regression';
      public const STATUS_IMPROVEMENT = 'improvement';
      public const STATUS_STABLE      = 'stable';
      public const STATUS_NEW         = 'new';
      public const STATUS_MISSING     = 'missing';

      /** @return array<int, array{combo: string, status: string, publishable_delta: float, global_delta: float, signature_delta: float}> */
      public static function compare(array $baseline, array $current): array;
      public static function toMarkdown(array $rows): string;
  }
  ```
  A combo present only in `current` is `new`; present only in `baseline` is `missing`; both carry `0.0` deltas for the missing side's fields.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Campaign/BaselineComparatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\BaselineComparator;
use AIForge\DevTools\Tests\Unit\TestCase;

class BaselineComparatorTest extends TestCase
{
    private function report(array $combos): array
    {
        return ['combos' => $combos, 'totals' => []];
    }

    private function combo(float $publishable, float $global, float $signature): array
    {
        return [
            'run_count' => 2,
            'publishable_rate' => $publishable,
            'mean_global' => $global,
            'mean_signature' => $signature,
            'total_cost' => 0.2,
            'runs' => [],
        ];
    }

    public function testIdenticalReportsAreStable(): void
    {
        $report = $this->report(['gemini:balanced:showcase' => $this->combo(100.0, 95.0, 90.0)]);

        $rows = BaselineComparator::compare($report, $report);

        $this->assertCount(1, $rows);
        $this->assertSame(BaselineComparator::STATUS_STABLE, $rows[0]['status']);
        $this->assertSame(0.0, $rows[0]['global_delta']);
        $this->assertSame(0.0, $rows[0]['publishable_delta']);
    }

    public function testDroppedPublishableRateIsARegression(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report(['a:balanced:x' => $this->combo(50.0, 94.0, 89.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_REGRESSION, $rows[0]['status']);
        $this->assertSame(-50.0, $rows[0]['publishable_delta']);
    }

    public function testGlobalDropOfTwoOrMoreIsARegression(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report(['a:balanced:x' => $this->combo(100.0, 93.0, 90.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_REGRESSION, $rows[0]['status']);
        $this->assertSame(-2.0, $rows[0]['global_delta']);
    }

    public function testSmallGlobalDropIsStillStable(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report(['a:balanced:x' => $this->combo(100.0, 93.9, 90.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_STABLE, $rows[0]['status']);
    }

    public function testGlobalGainOfTwoOrMoreIsAnImprovement(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 90.0, 80.0)]),
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 85.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_IMPROVEMENT, $rows[0]['status']);
        $this->assertSame(5.0, $rows[0]['global_delta']);
        $this->assertSame(5.0, $rows[0]['signature_delta']);
    }

    public function testGlobalGainWithPublishableDropIsStillARegression(): void
    {
        // A higher mean with fewer publishable runs means the failures got worse, not better.
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 90.0, 80.0)]),
            $this->report(['a:balanced:x' => $this->combo(75.0, 96.0, 85.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_REGRESSION, $rows[0]['status']);
    }

    public function testComboOnlyInCurrentIsNew(): void
    {
        $rows = BaselineComparator::compare(
            $this->report([]),
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_NEW, $rows[0]['status']);
        $this->assertSame(0.0, $rows[0]['global_delta']);
    }

    public function testComboOnlyInBaselineIsMissing(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report([])
        );

        $this->assertSame(BaselineComparator::STATUS_MISSING, $rows[0]['status']);
    }

    public function testRowsCoverBothReportsWithoutDuplicates(): void
    {
        $rows = BaselineComparator::compare(
            $this->report([
                'a:balanced:x' => $this->combo(100.0, 95.0, 90.0),
                'b:balanced:y' => $this->combo(100.0, 95.0, 90.0),
            ]),
            $this->report([
                'b:balanced:y' => $this->combo(100.0, 95.0, 90.0),
                'c:balanced:z' => $this->combo(100.0, 95.0, 90.0),
            ])
        );

        $combos = array_column($rows, 'combo');

        $this->assertSame(['a:balanced:x', 'b:balanced:y', 'c:balanced:z'], $combos);
    }

    public function testMissingCombosKeyIsTreatedAsEmpty(): void
    {
        $rows = BaselineComparator::compare([], ['combos' => ['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]]);

        $this->assertCount(1, $rows);
        $this->assertSame(BaselineComparator::STATUS_NEW, $rows[0]['status']);
    }

    public function testMarkdownRendersEveryRow(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report(['a:balanced:x' => $this->combo(50.0, 80.0, 70.0)])
        );

        $markdown = BaselineComparator::toMarkdown($rows);

        $this->assertStringContainsString('a:balanced:x', $markdown);
        $this->assertStringContainsString('regression', $markdown);
        $this->assertStringContainsString('-15.0', $markdown);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit --filter=BaselineComparatorTest
```

Expected: FAIL with `Class "AIForge\Campaign\BaselineComparator" not found`.

- [ ] **Step 3: Write the implementation**

`src/Campaign/BaselineComparator.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Per-combo deltas between a stored baseline report and a fresh one.
 *
 * A drop in publishable rate always outranks a rise in mean score: a higher
 * mean with fewer publishable runs means the failures got worse, not better.
 */
final class BaselineComparator
{
    public const STATUS_REGRESSION = 'regression';
    public const STATUS_IMPROVEMENT = 'improvement';
    public const STATUS_STABLE = 'stable';
    public const STATUS_NEW = 'new';
    public const STATUS_MISSING = 'missing';

    /** Mean-score movement below this is noise. */
    private const GLOBAL_THRESHOLD = 2.0;

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return array<int, array{combo: string, status: string, publishable_delta: float, global_delta: float, signature_delta: float}>
     */
    public static function compare(array $baseline, array $current): array
    {
        $baseCombos = \is_array($baseline['combos'] ?? null) ? $baseline['combos'] : [];
        $currCombos = \is_array($current['combos'] ?? null) ? $current['combos'] : [];

        $keys = array_keys($baseCombos + $currCombos);
        $rows = [];

        foreach ($keys as $key) {
            $inBase = \array_key_exists($key, $baseCombos);
            $inCurr = \array_key_exists($key, $currCombos);

            if (!$inBase) {
                $rows[] = self::row((string) $key, self::STATUS_NEW, 0.0, 0.0, 0.0);
                continue;
            }

            if (!$inCurr) {
                $rows[] = self::row((string) $key, self::STATUS_MISSING, 0.0, 0.0, 0.0);
                continue;
            }

            $publishableDelta = self::delta($currCombos[$key], $baseCombos[$key], 'publishable_rate');
            $globalDelta = self::delta($currCombos[$key], $baseCombos[$key], 'mean_global');
            $signatureDelta = self::delta($currCombos[$key], $baseCombos[$key], 'mean_signature');

            $rows[] = self::row(
                (string) $key,
                self::classify($publishableDelta, $globalDelta),
                $publishableDelta,
                $globalDelta,
                $signatureDelta
            );
        }

        return $rows;
    }

    /**
     * @param array<int, array{combo: string, status: string, publishable_delta: float, global_delta: float, signature_delta: float}> $rows
     */
    public static function toMarkdown(array $rows): string
    {
        $lines = [];
        $lines[] = '| combo | status | Δ publishable | Δ global | Δ signature |';
        $lines[] = '|---|---|---|---|---|';

        foreach ($rows as $row) {
            $lines[] = \sprintf(
                '| %s | %s | %+.1f%% | %+.1f | %+.1f |',
                $row['combo'],
                $row['status'],
                $row['publishable_delta'],
                $row['global_delta'],
                $row['signature_delta']
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private static function classify(float $publishableDelta, float $globalDelta): string
    {
        if ($publishableDelta < 0.0 || $globalDelta <= -self::GLOBAL_THRESHOLD) {
            return self::STATUS_REGRESSION;
        }

        if ($globalDelta >= self::GLOBAL_THRESHOLD) {
            return self::STATUS_IMPROVEMENT;
        }

        return self::STATUS_STABLE;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $baseline
     */
    private static function delta(array $current, array $baseline, string $field): float
    {
        return round((float) ($current[$field] ?? 0.0) - (float) ($baseline[$field] ?? 0.0), 1);
    }

    /**
     * @return array{combo: string, status: string, publishable_delta: float, global_delta: float, signature_delta: float}
     */
    private static function row(
        string $combo,
        string $status,
        float $publishableDelta,
        float $globalDelta,
        float $signatureDelta
    ): array {
        return [
            'combo' => $combo,
            'status' => $status,
            'publishable_delta' => $publishableDelta,
            'global_delta' => $globalDelta,
            'signature_delta' => $signatureDelta,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit
```

Expected: PASS.

Note on `testRowsCoverBothReportsWithoutDuplicates`: `array_keys($baseCombos + $currCombos)` preserves baseline order first, then appends current-only keys — giving `a, b, c`. The `+` operator on arrays keeps the left operand's values for duplicate keys, which is fine here because only the key list is used.

- [ ] **Step 5: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add src/Campaign/BaselineComparator.php tests/Unit/Campaign/BaselineComparatorTest.php
git commit -m "feat: compare a campaign report against a stored baseline

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: CampaignRunner (windowed scheduler + polling + collection)

**Files:**
- Create: `src/Campaign/CampaignRunner.php`
- Test: `tests/Unit/Campaign/CampaignRunnerTest.php` (pure `launchableCount()` only)

**Interfaces:**
- Consumes: `ComboSpec` (Task 1), `RunResult` and `CampaignReport` (Task 2). From the main plugin: `\AIForge\Core::getInstance()->getTaskExecutor()`, `WP_REST_Request`, `rest_do_request()`.
- Produces:
  ```php
  final class CampaignRunner {
      public const MAX_IN_FLIGHT = 4;   // main plugin caps active roots at 5 per user

      public function __construct(private readonly \wpdb $wpdb) {}

      public static function launchableCount(int $activeRoots, int $maxInFlight, int $remainingCombos): int;

      public function resolveSourceBatch(): int;                    // throws RuntimeException
      public function countMarkdownSnapshots(int $batchId): int;
      public function resolveTemplateId(string $slug): int;         // throws RuntimeException
      public function activeRootCount(): int;
      public function createCombo(ComboSpec $combo, int $sourceBatchId, int $files): int;  // returns root task id
      public function isRootFinished(int $rootId): bool;
      public function drain(int $budgetSeconds): void;
      /** @return RunResult[] */
      public function collect(array $rootIdsByComboKey): array;
  }
  ```
  `collect()` takes `array<string comboKey, int[] rootIds>` and returns a flat `RunResult[]`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Campaign/CampaignRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CampaignRunner;
use AIForge\DevTools\Tests\Unit\TestCase;

/**
 * Only the pure scheduling arithmetic is unit-tested here. The WordPress glue
 * (REST creation, polling, meta collection) is verified by a real campaign run.
 */
class CampaignRunnerTest extends TestCase
{
    public function testFillsEveryFreeSlotWhenNothingIsRunning(): void
    {
        $this->assertSame(4, CampaignRunner::launchableCount(0, 4, 7));
    }

    public function testLaunchesNothingWhenTheWindowIsFull(): void
    {
        $this->assertSame(0, CampaignRunner::launchableCount(4, 4, 3));
    }

    public function testLaunchesOnlyTheFreeSlots(): void
    {
        $this->assertSame(1, CampaignRunner::launchableCount(3, 4, 7));
    }

    public function testNeverLaunchesMoreThanTheCombosLeft(): void
    {
        $this->assertSame(1, CampaignRunner::launchableCount(2, 4, 1));
    }

    public function testClampsToZeroWhenAlreadyOverTheWindow(): void
    {
        // A human may have launched tasks alongside the campaign.
        $this->assertSame(0, CampaignRunner::launchableCount(6, 4, 7));
    }

    public function testLaunchesNothingWhenNoCombosRemain(): void
    {
        $this->assertSame(0, CampaignRunner::launchableCount(0, 4, 0));
    }

    public function testDefaultWindowLeavesOneSlotUnderThePluginCap(): void
    {
        // The main plugin rejects creation at 5 active roots per user (HTTP 429).
        $this->assertSame(4, CampaignRunner::MAX_IN_FLIGHT);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit --filter=CampaignRunnerTest
```

Expected: FAIL with `Class "AIForge\Campaign\CampaignRunner" not found`.

- [ ] **Step 3: Write the implementation**

`src/Campaign/CampaignRunner.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use RuntimeException;
use WP_REST_Request;
use wpdb;

/**
 * Runs a validation matrix against the main plugin's task pipeline.
 *
 * Tasks are created through the real REST endpoint so the campaign exercises
 * the same path a user hits. That endpoint rejects creation once the user
 * already has 5 active root tasks, so combos are launched through a sliding
 * window rather than all at once.
 */
final class CampaignRunner
{
    /** One slot below the main plugin's per-user active-root cap. */
    public const MAX_IN_FLIGHT = 4;

    private const SNAPSHOT_PROBE_LIMIT = 50;

    public function __construct(
        private readonly wpdb $wpdb
    ) {
    }

    /**
     * How many combos may be launched right now.
     */
    public static function launchableCount(int $activeRoots, int $maxInFlight, int $remainingCombos): int
    {
        return max(0, min($maxInFlight - $activeRoots, $remainingCombos));
    }

    /**
     * Most recent completed batch that still carries markdown snapshots.
     */
    public function resolveSourceBatch(): int
    {
        $ids = $this->wpdb->get_col(
            "SELECT id FROM {$this->wpdb->prefix}aiforge_tasks
             WHERE task_type = 'batch_markdown_to_gutenberg' AND status = 'completed'
             ORDER BY id DESC LIMIT 20"
        );

        foreach ($ids as $id) {
            if ($this->countMarkdownSnapshots((int) $id) > 0) {
                return (int) $id;
            }
        }

        throw new RuntimeException(
            'No completed batch_markdown_to_gutenberg task with markdown snapshots found. Pass --source-batch=<id>.'
        );
    }

    public function countMarkdownSnapshots(int $batchId): int
    {
        $count = 0;

        for ($i = 0; $i < self::SNAPSHOT_PROBE_LIMIT; $i++) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}aiforge_task_payloads
                 WHERE task_id = %d AND payload_type = %s",
                $batchId,
                "markdown_snapshot_{$i}"
            ));

            if ((int) $exists === 0) {
                break;
            }

            $count++;
        }

        return $count;
    }

    public function resolveTemplateId(string $slug): int
    {
        $posts = get_posts([
            'post_type' => 'aiforge_ci_tpl',
            'name' => $slug,
            'numberposts' => 1,
            'post_status' => 'any',
        ]);

        if ($posts === []) {
            throw new RuntimeException("Template '{$slug}' not found in aiforge_ci_tpl.");
        }

        return (int) $posts[0]->ID;
    }

    public function activeRootCount(): int
    {
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}aiforge_tasks
             WHERE created_by = %d AND status IN ('pending', 'running') AND parent_id IS NULL",
            get_current_user_id()
        ));
    }

    /**
     * Create one batch task for a combo. Returns the root task ID.
     */
    public function createCombo(ComboSpec $combo, int $sourceBatchId, int $files): int
    {
        $templateId = $this->resolveTemplateId($combo->templateSlug);
        $template = get_post($templateId);
        $templateContent = $template->post_content;

        $payloads = [];
        $filesMeta = [];

        for ($i = 0; $i < $files; $i++) {
            $markdown = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT payload FROM {$this->wpdb->prefix}aiforge_task_payloads
                 WHERE task_id = %d AND payload_type = %s",
                $sourceBatchId,
                "markdown_snapshot_{$i}"
            ));

            if ($markdown === null) {
                throw new RuntimeException(
                    "Batch {$sourceBatchId} has no markdown_snapshot_{$i}; lower --files."
                );
            }

            $title = 'Untitled';
            if (preg_match('/^#\s+(.+)$/m', $markdown, $matches) === 1) {
                $title = trim($matches[1]);
            }

            $filesMeta[] = [
                'index' => $i,
                'template_id' => $templateId,
                'template_name' => $template->post_title,
                'content_title' => $title,
                'draft_title' => null,
                'original_format' => 'markdown',
            ];

            $payloads[] = ['type' => "markdown_snapshot_{$i}", 'payload' => $markdown];
            $payloads[] = ['type' => "template_snapshot_{$i}", 'payload' => $templateContent];
        }

        $request = new WP_REST_Request('POST', '/aiforge/v1/tasks');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'taskType' => 'batch_markdown_to_gutenberg',
            'meta' => [
                'agent_id' => 'content-integrator',
                'provider' => $combo->provider,
                'preset' => $combo->preset,
                'batch_name' => 'QG campaign ' . $combo->key(),
                'file_count' => $files,
                'create_draft' => false,
                'files_meta' => wp_json_encode($filesMeta),
            ],
            'payloads' => $payloads,
        ]));

        $response = rest_do_request($request);

        if ($response->get_status() >= 400) {
            throw new RuntimeException(\sprintf(
                'Task creation failed for %s (HTTP %d): %s',
                $combo->key(),
                $response->get_status(),
                wp_json_encode($response->get_data())
            ));
        }

        $data = $response->get_data();
        $taskId = (int) ($data['data']['id'] ?? $data['id'] ?? 0);

        if ($taskId === 0) {
            throw new RuntimeException("Task creation for {$combo->key()} returned no ID.");
        }

        return $taskId;
    }

    public function isRootFinished(int $rootId): bool
    {
        $status = (string) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT status FROM {$this->wpdb->prefix}aiforge_tasks WHERE id = %d",
            $rootId
        ));

        return \in_array($status, ['completed', 'failed', 'cancelled'], true);
    }

    /**
     * Advance the pipeline without waiting for cron.
     */
    public function drain(int $budgetSeconds): void
    {
        $core = \AIForge\Core::getInstance();

        if ($core === null) {
            throw new RuntimeException('AI Forge core is not booted.');
        }

        $core->getTaskExecutor()->executePending($budgetSeconds);
    }

    /**
     * Pull the Quality Gate meta off every llm_generate descendant.
     *
     * @param array<string, int[]> $rootIdsByComboKey
     * @return RunResult[]
     */
    public function collect(array $rootIdsByComboKey): array
    {
        $results = [];

        foreach ($rootIdsByComboKey as $comboKey => $rootIds) {
            foreach ($rootIds as $rootId) {
                $llmIds = $this->wpdb->get_col($this->wpdb->prepare(
                    "SELECT id FROM {$this->wpdb->prefix}aiforge_tasks
                     WHERE root_id = %d AND task_type = 'llm_generate' ORDER BY id",
                    $rootId
                ));

                foreach ($llmIds as $llmId) {
                    $results[] = $this->buildRunResult((int) $llmId, (string) $comboKey);
                }
            }
        }

        return $results;
    }

    private function buildRunResult(int $llmId, string $comboKey): RunResult
    {
        $meta = [];

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->wpdb->prefix}aiforge_task_meta WHERE task_id = %d",
            $llmId
        ));

        foreach ($rows as $row) {
            $meta[$row->meta_key] = $row->meta_value;
        }

        $subscores = [];
        foreach (RunResult::AXES as $axis) {
            $subscores[$axis] = (int) ($meta["quality_score_{$axis}"] ?? 0);
        }

        return new RunResult(
            taskId: $llmId,
            comboKey: $comboKey,
            modelId: (string) ($meta['model_id'] ?? 'unknown'),
            verdict: (string) ($meta['quality_gate_verdict'] ?? 'unknown'),
            globalScore: (int) ($meta['quality_score_global'] ?? 0),
            subscores: $subscores,
            cost: (float) ($meta['cost'] ?? 0.0),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add src/Campaign/CampaignRunner.php tests/Unit/Campaign/CampaignRunnerTest.php
git commit -m "feat: run a QG matrix through a windowed task scheduler

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: WP-CLI command

**Files:**
- Create: `src/Cli/QgCampaignCommand.php`
- Modify: `src/DevTools.php`

**Interfaces:**
- Consumes: everything from Tasks 1-4.
- Produces: the `wp aiforge-dev qg-campaign` command. No PHP API other tasks depend on.

- [ ] **Step 1: Write the command**

`src/Cli/QgCampaignCommand.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\Campaign\BaselineComparator;
use AIForge\Campaign\CampaignReport;
use AIForge\Campaign\CampaignRunner;
use AIForge\Campaign\ComboSpec;
use Throwable;
use WP_CLI;

/**
 * Runs a Quality Gate validation matrix and reports the outcome.
 */
final class QgCampaignCommand
{
    private const POLL_BUDGET_SECONDS = 25;
    private const DEFAULT_TIMEOUT_SECONDS = 3600;

    /**
     * Run a provider × preset × template validation campaign.
     *
     * ## OPTIONS
     *
     * --combos=<combos>
     * : Comma-separated list of provider:preset:template-slug.
     *
     * [--source-batch=<id>]
     * : Batch task to pull markdown snapshots from. Defaults to the most recent
     *   completed batch that still has snapshots.
     *
     * [--files=<n>]
     * : Markdown files per combo. Defaults to every snapshot on the source batch.
     *
     * [--label=<name>]
     * : Campaign name, used in the report. Defaults to "campaign".
     *
     * [--baseline=<path>]
     * : Path to a previous report JSON to diff against.
     *
     * [--timeout=<seconds>]
     * : Give up waiting after this long. Default 3600.
     *
     * ## EXAMPLES
     *
     *     wp aiforge-dev qg-campaign --combos=gemini:balanced:showcase,openai:balanced:showcase --files=2
     *     wp aiforge-dev qg-campaign --combos=gemini:balanced:showcase --baseline=wp-content/uploads/aiforge-dev/campaign-1.json
     *
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        wp_set_current_user(1);

        $runner = new CampaignRunner($wpdb);

        try {
            $combos = ComboSpec::parseList((string) ($assoc['combos'] ?? ''));
            $label = (string) ($assoc['label'] ?? 'campaign');
            $timeout = (int) ($assoc['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);

            $sourceBatch = isset($assoc['source-batch'])
                ? (int) $assoc['source-batch']
                : $runner->resolveSourceBatch();

            $available = $runner->countMarkdownSnapshots($sourceBatch);

            if ($available === 0) {
                WP_CLI::error("Batch {$sourceBatch} has no markdown snapshots.");
            }

            $files = isset($assoc['files']) ? min((int) $assoc['files'], $available) : $available;
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
            return;
        }

        WP_CLI::log(\sprintf(
            'Campaign "%s": %d combo(s) × %d file(s), source batch %d, window %d.',
            $label,
            \count($combos),
            $files,
            $sourceBatch,
            CampaignRunner::MAX_IN_FLIGHT
        ));

        $rootIdsByCombo = $this->launchAndWait($runner, $combos, $sourceBatch, $files, $timeout);

        $report = new CampaignReport($label, $sourceBatch, $files, $runner->collect($rootIdsByCombo));

        WP_CLI::log('');
        WP_CLI::log($report->toMarkdown());

        $this->maybeCompare($assoc, $report);
        $this->writeReport($label, $report);
        $this->printAuditHint($report);
    }

    /**
     * @param ComboSpec[] $combos
     * @return array<string, int[]>
     */
    private function launchAndWait(
        CampaignRunner $runner,
        array $combos,
        int $sourceBatch,
        int $files,
        int $timeout
    ): array {
        $queue = $combos;
        $rootIdsByCombo = [];
        $inFlight = [];
        $deadline = time() + $timeout;

        while ($queue !== [] || $inFlight !== []) {
            if (time() > $deadline) {
                WP_CLI::warning(\sprintf(
                    'Timed out with %d combo(s) queued and %d still running.',
                    \count($queue),
                    \count($inFlight)
                ));
                break;
            }

            $launchable = CampaignRunner::launchableCount(
                $runner->activeRootCount(),
                CampaignRunner::MAX_IN_FLIGHT,
                \count($queue)
            );

            for ($i = 0; $i < $launchable; $i++) {
                $combo = array_shift($queue);

                try {
                    $rootId = $runner->createCombo($combo, $sourceBatch, $files);
                } catch (Throwable $e) {
                    WP_CLI::warning($e->getMessage());
                    continue;
                }

                $rootIdsByCombo[$combo->key()][] = $rootId;
                $inFlight[$rootId] = $combo->key();
                WP_CLI::log("  launched {$combo->key()} as task {$rootId}");
            }

            $runner->drain(self::POLL_BUDGET_SECONDS);

            foreach ($inFlight as $rootId => $comboKey) {
                if ($runner->isRootFinished((int) $rootId)) {
                    unset($inFlight[$rootId]);
                    WP_CLI::log("  finished {$comboKey} (task {$rootId})");
                }
            }
        }

        return $rootIdsByCombo;
    }

    /**
     * @param array<string, string> $assoc
     */
    private function maybeCompare(array $assoc, CampaignReport $report): void
    {
        if (!isset($assoc['baseline'])) {
            return;
        }

        $path = (string) $assoc['baseline'];

        if (!is_readable($path)) {
            WP_CLI::warning("Baseline '{$path}' is not readable, skipping comparison.");
            return;
        }

        $baseline = json_decode((string) file_get_contents($path), true);

        if (!\is_array($baseline)) {
            WP_CLI::warning("Baseline '{$path}' is not valid JSON, skipping comparison.");
            return;
        }

        WP_CLI::log('');
        WP_CLI::log('### Comparison against baseline');
        WP_CLI::log('');
        WP_CLI::log(BaselineComparator::toMarkdown(BaselineComparator::compare($baseline, $report->toArray())));
    }

    private function writeReport(string $label, CampaignReport $report): void
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'aiforge-dev';

        if (!wp_mkdir_p($dir)) {
            WP_CLI::warning("Could not create {$dir}, report not written.");
            return;
        }

        $slug = sanitize_file_name($label);
        $path = "{$dir}/{$slug}-" . gmdate('Ymd-His') . '.json';

        if (file_put_contents($path, wp_json_encode($report->toArray(), JSON_PRETTY_PRINT)) === false) {
            WP_CLI::warning("Could not write {$path}.");
            return;
        }

        WP_CLI::success("Report written to {$path}");
    }

    private function printAuditHint(CampaignReport $report): void
    {
        $flagged = $report->flaggedTaskIds();

        if ($flagged === []) {
            WP_CLI::log('No flagged runs.');
            return;
        }

        WP_CLI::log('');
        WP_CLI::log('Flagged runs, audit them with:');
        WP_CLI::log('  /qg-audit ' . implode(' ', $flagged));
    }
}
```

- [ ] **Step 2: Register the command**

In `src/DevTools.php`, add the import next to the existing ones:

```php
use AIForge\Admin\DevAssetLoader;
use AIForge\Admin\DeveloperPage;
use AIForge\Cli\QgCampaignCommand;
use AIForge\REST\DemoModeController;
```

and add a registration call at the end of `init()`, plus the method:

```php
    private function init(): void
    {
        if (!$this->isMainPluginActive()) {
            add_action('admin_notices', [$this, 'showMissingPluginNotice']);
            return;
        }

        DemoMode::register();
        DevMode::register();
        Demo\DemoLicenseProvider::register();
        $this->registerRest();
        $this->registerAdminAssets();
        $this->registerDeveloperPage();
        $this->registerCli();
    }

    /**
     * Register WP-CLI commands.
     */
    private function registerCli(): void
    {
        if (!\defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command('aiforge-dev qg-campaign', QgCampaignCommand::class);
    }
```

- [ ] **Step 3: Verify the command is registered**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli wp aiforge-dev qg-campaign --help
```

Expected: WP-CLI prints the synopsis with `--combos`, `--source-batch`, `--files`, `--label`, `--baseline`, `--timeout`.

- [ ] **Step 4: Verify argument validation without launching anything**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli wp aiforge-dev qg-campaign --combos=mistral:balanced:showcase
```

Expected: `Error: Unknown provider 'mistral' (expected one of: gemini, openai, anthropic)` and a non-zero exit code. Nothing created.

- [ ] **Step 5: Run the unit suite (nothing should have broken)**

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add src/Cli/QgCampaignCommand.php src/DevTools.php
git commit -m "feat: add wp aiforge-dev qg-campaign command

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Live validation, version bump, docs

**Files:**
- Modify: `wp-ai-forge-devtools.php` (version), `CLAUDE.md`

**Interfaces:**
- Consumes: the finished command from Task 5.
- Produces: a validated tool and updated docs. No code API.

- [ ] **Step 1: Smoke-test with a single cheap combo**

This spends real API credits. Confirm with the user before running.

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli wp aiforge-dev qg-campaign --combos=gemini:economic:article-de-blog-2 --files=1 --label=smoke
```

Expected: one task launched, polling messages, a markdown table with one run, a JSON file under `wp-content/uploads/aiforge-dev/`, and either "No flagged runs." or a `/qg-audit <id>` hint.

- [ ] **Step 2: Verify the window actually holds on a 7-combo matrix**

This is the spec's acceptance criterion. Confirm the cost with the user first; it reproduces the v0.38 campaign.

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli wp aiforge-dev qg-campaign \
  --combos=gemini:economic:article-de-blog-2,gemini:balanced:article-de-blog-2,gemini:performance:page-datterrissage,openai:economic:article-de-blog-2,openai:balanced:article-de-blog-2,openai:performance:page-datterrissage,anthropic:balanced:article-de-blog-2 \
  --files=2 --label=v038-revalidation --timeout=5400
```

Expected: **all 7 combos launched** (never more than 4 in flight at once), 14 runs collected, no `too_many_tasks` warning in the output. Compare the per-combo publishable rates against the hand-collected v0.38 figures in memory `project_model_landscape_2026_08.md` § QG validation results.

If any combo reports `Task creation failed ... HTTP 429`, the window is too wide: lower `CampaignRunner::MAX_IN_FLIGHT`.

- [ ] **Step 3: Verify the baseline diff**

Re-run one combo against the report written in Step 2. Step 2 printed the exact report path in its `Success: Report written to ...` line — copy that path verbatim into `--baseline` (the file name carries a generation timestamp, so it differs on every run).

```
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli wp aiforge-dev qg-campaign \
  --combos=gemini:balanced:article-de-blog-2 --files=2 --label=recheck \
  --baseline=<paste the path printed by Step 2>
```

Expected: a "Comparison against baseline" table listing `gemini:balanced:article-de-blog-2` with deltas, and the other six combos as `missing`.

- [ ] **Step 4: Bump the version**

`wp-ai-forge-devtools.php` line 7:

```php
* Version: 0.2.0
```

Minor bump: this adds a feature.

- [ ] **Step 5: Document it in CLAUDE.md**

Replace the line `No testing infrastructure is currently set up.` with:

```markdown
### Tests
```bash
cd ../.. && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit
```

Unit tests cover the pure campaign logic (`src/Campaign/`) with PHPUnit + Brain Monkey. The WordPress glue and the WP-CLI command are verified by running a real campaign.
```

and add a section after "### Relationship to Main Plugin":

```markdown
### QG campaign runner

`wp aiforge-dev qg-campaign --combos=provider:preset:template-slug,...` runs a
validation matrix and writes a JSON report to `wp-content/uploads/aiforge-dev/`.
Pass `--baseline=<report.json>` for per-combo deltas against a previous run.

The main plugin rejects task creation once a user has 5 active root tasks
(HTTP 429), so combos are launched through a sliding window of
`CampaignRunner::MAX_IN_FLIGHT` (4) rather than all at once.
```

- [ ] **Step 6: Commit**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git add wp-ai-forge-devtools.php CLAUDE.md docs/
git commit -m "chore: v0.2.0 — QG campaign runner, docs and test command

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Notes for the implementer

- **The 5-task cap is the whole reason the scheduler exists.** If you find yourself adding a `sleep()` between creations, re-read the "Correction to the spec's premise" section — spacing does not help.
- **`wp_set_current_user(1)`** must run before any `rest_do_request`, otherwise the endpoint's permission check rejects the request and `countActiveByUser(0)` counts the wrong user's tasks.
- **`drain()` uses the booted `TaskExecutor`** via `Core::getInstance()->getTaskExecutor()`, not a fresh instance — a fresh one would have no executors registered, since `TaskSystemRegistrar` wires them onto the booted instance.
- **Never touch `wp-ai-forge`.** If something seems to need a change there, stop and report instead.
- Verdict strings are `pass`, `warnings`, `fail`. Anything else (including a missing meta key) collects as `unknown` and counts as not publishable — that is deliberate, a run whose gate meta is absent must not inflate the publishable rate.
