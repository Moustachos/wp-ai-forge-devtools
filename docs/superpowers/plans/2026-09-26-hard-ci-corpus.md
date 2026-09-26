# Hard Content Integrator Corpus Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A frozen 12-file corpus of scarce-content pages, a mechanical `TrapChecker` scored against a manifest's ground truth, and `qg-campaign --corpus` / `trap-check` support, so the campaign can separate models on "omit rather than stretch".

**Architecture:** Everything lives in `wp-ai-forge-devtools`. Pure, WordPress-free classes in `src/Campaign/` (`TextTools`, `MarkdownDocument`, `GenerationDocument`, `ManifestEntry`, `CheckSettings`, `CorpusManifest`, `CorpusPreconditions`, `TrapChecker`, `PlanExcerpt`, `TrapReport`, `TrapEvaluation`, `PluginCommit`) carry all the logic and are unit-tested on fixtures extracted once from the lab. The WordPress glue (`CampaignRunner`, `QgCampaignCommand`, the new `TrapCheckCommand`) is verified by running real campaigns. Corpus texts live in `bench/ci-corpus/hard/`, which never ships.

**Tech Stack:** PHP 8.2 (strict types), DOMDocument, WP-CLI, PHPUnit 11 + Brain Monkey, the main plugin's REST task API and providers.

**Spec:** `../wp-ai-forge/docs/specs/2026-09-hard-ci-corpus.md` (S17). The plan argues from it. Read both.

## Global Constraints

- **Nothing ships in `wp-ai-forge`.** Every file this plan creates or modifies is in `wp-ai-forge-devtools`, except the spec's `Status:` header line in the plugin repo (Task 0, Task 16).
- **Never mention the dev-tools plugin from the main plugin**: no code comment, hook or string.
- **The plugin must carry the Quality Gate fixes of `feat/model-refresh-2026-09`** (`4516b2a`, `74e3919`, `7b8ec6c`, `9139e0d`). Check that branch out in `wp-ai-forge` before any campaign.
- **PHP 8.2+, `declare(strict_types=1);`** in every new PHP file. PSR-4 `AIForge\` → `src/`; tests `AIForge\DevTools\Tests\` → `tests/`.
- **Pure classes call no WordPress function** (`wp_strip_all_tags`, `parse_blocks`, `esc_*`…): they run in unit tests and from `php bench/ci-corpus/check.php` without WordPress.
- **Test command:** `cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools vendor/bin/phpunit --testsuite=Unit` (add `--filter X`). Never `composer test` on the host.
- **Corpus:** 12 files, 600 to 2,500 words each, 10 French + 2 English, authors 3 × `claude` / `gemini` / `gpt` / `adapted`, every trap T1-T6 in ≥ 3 files.
- **Authoring constraints on every corpus file:** links only as `[text](url)` with `example.com` URLs; no `<` character; none of `Lorem ipsum`, `consectetur`, `dolor sit`, `adipiscing`; no images; exactly one `#` title; original text; no third-party block names.
- **Generation models for authored drafts:** `gemini-3.7-flash` (author `gemini`) and `gpt-5.4` (author `gpt`), through the plugin's own providers.
- **Default thresholds:** orphan heading overlap `0.5`, content retention `0.80` on 5-word shingles, crammed stat-value `> 40` characters, quote match `0.6`. Calibrated on the first campaign, recorded in the manifest.
- **`generic_cta` seed:** `nous contacter`, `prendre contact`, `contactez-nous`, `en savoir plus`, `découvrir`, `contact us`, `get in touch`, `learn more`.
- **Reports give k/n, never percentages.** The terminal shows task ids only; MAPPING and NOTES go in the JSON.
- **Never join `aiforge_task_meta` into a query that also reads payload longtext** (it wedged MySQL in Docker). Fetch meta per task.
- **Timeouts go inside the container:** `npx wp-env run cli -- timeout N wp …`. A host-side timeout kills `npx` and leaves PHP stuck.
- **From Git Bash**, pass WordPress-root-relative paths (`wp-content/uploads/…`) or prefix `MSYS_NO_PATHCONV=1`.
- **Mandatory stop after Task 6:** the user reads the 12 texts for realism. Nothing is committed from `bench/ci-corpus/hard/*.md` before that approval.
- **Commits:** English messages ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. No version bump: devtools changes merge into `main` by fast-forward, no tag.

## Corrections to the spec (verified 2026-09-26, read before Task 8)

1. **`2 interviews` (7014) is grounded, not invented.** The stored source of 7014 (parent 7012) reads "deux interviews presse nationale". With a truthful manifest entry, `2` is a figure. The plan tests 7014 as a **fully grounded** case (`7 personnes`, `10 ans`, `2 interviews` all pass) and draws the invented cases from the `100%` runs (7128/7144/7151/7173/7188/7195). Tell the user; do not quietly re-label.
2. **No stored run invents a testimonial** (the scan found 0/237), so "one invented quote" cannot be a raw real run. It is **derived from a real run**: 6041 (a real quote from the source, credited to "Les Numériques") with its quote text replaced in the test. The "source words with an invented attribution" case is the real run **7335** (source prose credited to "Les fondateurs de Volta", a name quoted nowhere); confirm it in Task 8 and fall back to 7334/7331/7327/7326 if its quote does not match a source sentence.
3. **"`equal-3` columns section" is read as "a columns block of ≥ 3 equal-width columns, each holding a heading".** The landing's stats row is also an equal 3-column block; without the heading condition the stats row would count as a card grid.
4. **CTA grounding needs a tolerated vocabulary.** Under the spec's rule taken literally, "Réserver un bilan" can never pass, since `réserver` is in no offer. The manifest gets a closed top-level `cta_vocabulary` (action verbs and function words, never an offer noun). `réserver` + `bilan` then passes only if `bilan` is an offer, which is the spec's own example.

## Environment note (2026-09-26)

At planning time **wp-cli hung on the lab**: every access to `/var/www/html` inside the `cli` container blocked with 0 CPU, even `grep` on `wp-config.php`, while MySQL answered normally. That is a wedged Docker Desktop bind mount, not a plugin bug. Before Task 5, check with `docker exec <cli-container> timeout 10 ls /var/www/html`; if it hangs, restart Docker Desktop, then `cd wp-lab && npx wp-env start`. Kill any leftover `php /usr/local/bin/wp` processes in the container first.

## Review Focus

- **Entity-encoded and non-breaking-space numbers in outputs** (`15&nbsp;000`, `15\u{202F}000`, `4,8`): expected to normalise to the same figure as the manifest's key. Pinned in Task 1 (`TextTools`) and Task 9 (`GenerationDocument`).
- **Numeric JSON keys in `figures`** (`"1998"` decodes to an int key in PHP): must still match the string `1998` read from a stat-value. Pinned in Task 2.
- **A run with no `source_filename`**, or a file id absent from the manifest (a report from an older runner, a manual task in the batch): reported "not evaluated", never a crash or a fail. Pinned in Task 14 (`TrapEvaluation`).
- **Comparing a corpus report with a batch report, or with another corpus**: refused with a message naming both, not a misleading delta table. Pinned in Task 14.
- **A template without the slot** (no stat-value, no testimonial, no card grid, no button): gives `n/a`, never `pass`, so a template swap cannot pass a check silently. Pinned in Task 12.

---

## File Structure

```
wp-ai-forge-devtools/
├── bench/ci-corpus/
│   ├── check.php                         # Task 3: plain-PHP precondition runner
│   ├── fixtures/extract.php              # Task 8: wp eval-file fixture extractor
│   ├── scan-2026-09-26/                  # existing, untracked: committed in Task 7
│   └── hard/
│       ├── briefs.json                   # Task 4
│       ├── manifest.json                 # Task 4 skeleton, completed in Tasks 5-6
│       ├── generate.php                  # Task 5: wp eval-file draft generator
│       ├── hard-01-….md … hard-12-….md   # Tasks 5-6
│       └── results-2026-09.md            # Task 16: campaign record
├── src/Campaign/
│   ├── TextTools.php                     # Task 1
│   ├── MarkdownDocument.php              # Task 1
│   ├── CheckSettings.php                 # Task 2
│   ├── ManifestEntry.php                 # Task 2
│   ├── CorpusManifest.php                # Task 2
│   ├── CorpusPreconditions.php           # Task 3
│   ├── GenerationDocument.php            # Task 9
│   ├── TrapChecker.php                   # Tasks 10-12
│   ├── PlanExcerpt.php                   # Task 13
│   ├── TrapReport.php                    # Task 13
│   ├── TrapEvaluation.php                # Task 14
│   ├── PluginCommit.php                  # Task 14
│   ├── RunResult.php                     # Task 14 (modified)
│   ├── CampaignReport.php                # Task 14 (modified)
│   ├── BaselineComparator.php            # Task 14 (modified)
│   └── CampaignRunner.php                # Task 15 (modified)
├── src/Cli/
│   ├── QgCampaignCommand.php             # Task 15 (modified)
│   └── TrapCheckCommand.php              # Task 15
├── src/DevTools.php                      # Task 15 (register trap-check)
├── CLAUDE.md                             # Task 15 (document)
└── tests/
    ├── fixtures/trap/<task-id>.{html,md,tpl.html,plan.txt}   # Task 8
    └── Unit/Campaign/
        ├── TrapFixtures.php              # Task 8 (trait)
        ├── TextToolsTest.php, MarkdownDocumentTest.php                  # Task 1
        ├── ManifestEntryTest.php, CorpusManifestTest.php               # Task 2
        ├── CorpusPreconditionsTest.php                                  # Task 3
        ├── HardCorpusTest.php                                           # Task 7
        ├── GenerationDocumentTest.php                                   # Task 9
        ├── TrapCheckerStatsTest.php, TrapCheckerTestimonialTest.php,
        │   TrapCheckerStructureTest.php                                 # Tasks 10-12
        ├── PlanExcerptTest.php, TrapReportTest.php                      # Task 13
        └── TrapEvaluationTest.php, PluginCommitTest.php (+ existing report/comparator/runner tests)  # Task 14
```

---

### Task 0: Branch, spec status, environment check

**Files:**
- Modify: `../wp-ai-forge/docs/specs/2026-09-hard-ci-corpus.md:3` (status line)

- [ ] **Step 1: Branch devtools**

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge-devtools"
git status --short          # expect only untracked bench/block-validity/review-2026-09-26/ and bench/ci-corpus/
git checkout -b feat/hard-ci-corpus
```

Leave `bench/block-validity/review-2026-09-26/` alone: it belongs to S18, not this plan.

- [ ] **Step 2: Mark the spec in progress**

In `../wp-ai-forge/docs/specs/2026-09-hard-ci-corpus.md`, replace the first word of line 3's status (`revised 2026-09-26 …`) with `in progress (implementation plan: wp-ai-forge-devtools/docs/superpowers/plans/2026-09-26-hard-ci-corpus.md); revised 2026-09-26 …`. Commit on the plugin's current branch `feat/model-refresh-2026-09`:

```bash
cd "e:/Travaux/Travaux Web/wp-lab/plugins/wp-ai-forge"
git add docs/specs/2026-09-hard-ci-corpus.md
git commit -m "Mark spec S17 in progress

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 3: Check the containers and DOM**

```bash
docker ps --format '{{.Names}}' | grep -E 'cli-1|tests-cli-1'
docker exec <tests-cli-container> timeout 10 php -m | grep -i -E '^dom$|^libxml$'
docker exec <cli-container> timeout 10 ls /var/www/html >/dev/null && echo mount-ok
```

Expected: `dom`, `libxml`, `mount-ok`. If the `ls` times out, apply the environment note above before going further.

- [ ] **Step 4: Run the existing suite as a baseline**

Run the test command. Expected: all existing devtools tests pass (about 90). Record the count.

---

### Task 1: TextTools and MarkdownDocument

**Files:**
- Create: `src/Campaign/TextTools.php`, `src/Campaign/MarkdownDocument.php`
- Test: `tests/Unit/Campaign/TextToolsTest.php`, `tests/Unit/Campaign/MarkdownDocumentTest.php`

**Interfaces:**
- Produces:
  - `TextTools::fold(string): string`: lower-case, accents folded (`é`→`e`, `œ`→`oe`), typographic apostrophes to `'`.
  - `TextTools::words(string): string[]`: folded tokens split on anything outside `[a-z0-9]`.
  - `TextTools::contentWords(string, int $minLength = 4): string[]`: unique words of at least `$minLength` characters.
  - `TextTools::overlap(string[] $words, string[] $reference): float`: share of `$words` found in `$reference`, 0.0 for no words.
  - `TextTools::shingles(string, int $size = 5): string[]`: unique word n-grams joined by one space.
  - `TextTools::numbers(string): string[]`: normalised numeric tokens (`15 000`→`15000`, `4,8`→`4.8`, French phone → its digits).
  - `TextTools::wordCount(string): int`.
  - `new MarkdownDocument(string $markdown)` with `title(): ?string`, `headings(): array<int, array{level:int, text:string}>`, `sections(): array<int, array{heading:string, body:string}>` (cut at `##`; the part above the first `##`, title included, is `[intro]`), `boldLines(): string[]`, `boldLeads(): string[]`, `links(): string[]`, `plainText(): string`, `sentences(): string[]`, static `toPlain(string): string`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Campaign/TextToolsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\TextTools;
use AIForge\DevTools\Tests\Unit\TestCase;

class TextToolsTest extends TestCase
{
    public function testFoldLowercasesAndStripsAccents(): void
    {
        $this->assertSame("ete a noel oeuvre l'ecole", TextTools::fold('Été À Noël Œuvre l’École'));
    }

    public function testContentWordsKeepsFourLettersAndMore(): void
    {
        $this->assertSame(['reserver', 'bilan'], TextTools::contentWords('Réserver un bilan'));
    }

    public function testContentWordsAreUnique(): void
    {
        $this->assertSame(['bois', 'massif'], TextTools::contentWords('Bois massif, bois massif'));
    }

    public function testOverlapIsTheShareOfWordsFound(): void
    {
        $this->assertSame(0.5, TextTools::overlap(['bilan', 'offert'], ['bilan', 'initial']));
        $this->assertSame(0.0, TextTools::overlap([], ['bilan']));
    }

    public function testShinglesAreFiveWordWindows(): void
    {
        $this->assertSame(['a b c d e', 'b c d e f'], TextTools::shingles('A b, c; d e f'));
        $this->assertSame([], TextTools::shingles('trop court'));
    }

    /**
     * @return array<string, array{string, string[]}>
     */
    public static function numberCases(): array
    {
        return [
            'thousands with a space' => ['15 000 exemplaires', ['15000']],
            'thousands with a no-break space' => ["15\u{00A0}000", ['15000']],
            'thousands with a narrow no-break space' => ["2\u{202F}400 salariés", ['2400']],
            'decimal comma' => ['noté 4,8/5', ['4.8', '5']],
            'year and postcode stay whole' => ['depuis 2010, Paris 75010', ['2010', '75010']],
            'french phone is one token' => ['appelez le 01 23 45 67 89', ['0123456789']],
            'range' => ['2 à 3 carrés', ['2', '3']],
            'percent' => ['100%', ['100']],
            'step number keeps its zero' => ['01', ['01']],
            'no number' => ['Formations régulières', []],
        ];
    }

    /**
     * @dataProvider numberCases
     * @param string[] $expected
     */
    public function testNumbersAreNormalised(string $text, array $expected): void
    {
        $this->assertSame($expected, TextTools::numbers($text));
    }

    public function testWordCount(): void
    {
        $this->assertSame(4, TextTools::wordCount("L'atelier ouvre demain"));
    }
}
```

`l'atelier` splits into `l` and `atelier`, so the word count is 4 (`l`, `atelier`, `ouvre`, `demain`).

`tests/Unit/Campaign/MarkdownDocumentTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\MarkdownDocument;
use AIForge\DevTools\Tests\Unit\TestCase;

class MarkdownDocumentTest extends TestCase
{
    private const PAGE = <<<MD
# Atelier Brun

Menuiserie depuis 1998. [Nous écrire](https://example.com/contact).

## Nos deux ateliers

### Sur mesure

Des **meubles** pensés pour vos murs.

### Restauration

**Ce que nous restaurons :**

- **Tables anciennes** : décapage et cire.
- Chaises paillées.

## Contact

Passez nous voir. Ou appelez-nous !
MD;

    public function testTitle(): void
    {
        $this->assertSame('Atelier Brun', (new MarkdownDocument(self::PAGE))->title());
    }

    public function testSectionsCutAtLevelTwoAndKeepTheIntro(): void
    {
        $sections = (new MarkdownDocument(self::PAGE))->sections();

        $this->assertSame(['[intro]', 'Nos deux ateliers', 'Contact'], array_column($sections, 'heading'));
        $this->assertStringContainsString('# Atelier Brun', $sections[0]['body']);
        $this->assertStringContainsString('### Restauration', $sections[1]['body']);
    }

    public function testHeadingsCarryTheirLevel(): void
    {
        $headings = (new MarkdownDocument(self::PAGE))->headings();

        $this->assertSame(['level' => 1, 'text' => 'Atelier Brun'], $headings[0]);
        $this->assertSame(['level' => 3, 'text' => 'Sur mesure'], $headings[2]);
        $this->assertCount(5, $headings);
    }

    public function testBoldLinesAreStandaloneOnly(): void
    {
        $this->assertSame(['Ce que nous restaurons'], (new MarkdownDocument(self::PAGE))->boldLines());
    }

    public function testBoldLeadsAddListItemLeads(): void
    {
        $this->assertSame(
            ['Ce que nous restaurons', 'Tables anciennes'],
            (new MarkdownDocument(self::PAGE))->boldLeads()
        );
    }

    public function testLinks(): void
    {
        $this->assertSame(['https://example.com/contact'], (new MarkdownDocument(self::PAGE))->links());
    }

    public function testPlainTextDropsSyntaxAndUrls(): void
    {
        $plain = (new MarkdownDocument(self::PAGE))->plainText();

        $this->assertStringContainsString('Nous écrire.', $plain);
        $this->assertStringNotContainsString('example.com', $plain);
        $this->assertStringNotContainsString('**', $plain);
        $this->assertStringNotContainsString('#', $plain);
        $this->assertStringContainsString('Tables anciennes : décapage et cire.', $plain);
    }

    public function testSentencesSplitOnPunctuationAndLines(): void
    {
        $sentences = (new MarkdownDocument(self::PAGE))->sentences();

        $this->assertContains('Passez nous voir.', $sentences);
        $this->assertContains('Ou appelez-nous !', $sentences);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: test command with `--filter 'TextToolsTest|MarkdownDocumentTest'`. Expected: FAIL, `Class "AIForge\Campaign\TextTools" not found`.

- [ ] **Step 3: Implement `src/Campaign/TextTools.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Text normalisation shared by the corpus checks.
 *
 * No WordPress calls: the checks run in unit tests and from a plain PHP script.
 */
final class TextTools
{
    private const FOLD = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        '’' => "'", '‘' => "'",
    ];

    private const SPACES = ["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}"];

    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::FOLD);
    }

    /**
     * @return string[]
     */
    public static function words(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', self::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return string[]
     */
    public static function contentWords(string $text, int $minLength = 4): array
    {
        return array_values(array_unique(array_filter(
            self::words($text),
            static fn (string $word): bool => \strlen($word) >= $minLength
        )));
    }

    /**
     * @param string[] $words
     * @param string[] $reference
     */
    public static function overlap(array $words, array $reference): float
    {
        if ($words === []) {
            return 0.0;
        }

        return \count(array_intersect($words, $reference)) / \count($words);
    }

    /**
     * @return string[]
     */
    public static function shingles(string $text, int $size = 5): array
    {
        $words = self::words($text);
        $shingles = [];

        for ($i = 0; $i + $size <= \count($words); $i++) {
            $shingles[implode(' ', \array_slice($words, $i, $size))] = true;
        }

        return array_map('strval', array_keys($shingles));
    }

    /**
     * Numeric tokens, normalised so that the same figure written two ways
     * compares equal: "15 000" and "15000", "4,8" and "4.8".
     *
     * @return string[]
     */
    public static function numbers(string $text): array
    {
        $text = str_replace(self::SPACES, ' ', $text);
        $numbers = [];

        // A French phone number is one token, not five two-digit figures.
        $text = (string) preg_replace_callback(
            '/(?<![\d.,])0\d(?:[ .]\d{2}){4}(?![\d])/',
            static function (array $match) use (&$numbers): string {
                $numbers[] = (string) preg_replace('/\D/', '', $match[0]);
                return ' ';
            },
            $text
        );

        preg_match_all(
            '/(?<![\d.,])\d{1,3}(?: \d{3})+(?:[.,]\d+)?(?!\d)|(?<![\d.,])\d+(?:[.,]\d+)?(?!\d)/',
            $text,
            $matches
        );

        foreach ($matches[0] as $raw) {
            $numbers[] = str_replace([' ', ','], ['', '.'], $raw);
        }

        return $numbers;
    }

    public static function wordCount(string $text): int
    {
        return \count(self::words($text));
    }
}
```

Note on ordering: the phone callback runs first, so a phone never lands after an ordinary number in the output. The data-provider cases hold one kind per string, so the order within `numbers()` is only tested within a kind.

- [ ] **Step 4: Implement `src/Campaign/MarkdownDocument.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Read-only view of a corpus markdown file, or of a stored markdown_snapshot.
 */
final class MarkdownDocument
{
    /** @var string[] */
    private array $lines;

    public function __construct(string $markdown)
    {
        $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
    }

    public function title(): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^#\s+(.+?)\s*$/u', $line, $m) === 1) {
                return self::inline($m[1]);
            }
        }

        return null;
    }

    /**
     * @return array<int, array{level: int, text: string}>
     */
    public function headings(): array
    {
        $headings = [];

        foreach ($this->lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $m) === 1) {
                $headings[] = ['level' => \strlen($m[1]), 'text' => self::inline($m[2])];
            }
        }

        return $headings;
    }

    /**
     * The page cut at its `##` headings. Everything above the first one,
     * title included, is the "[intro]" section.
     *
     * @return array<int, array{heading: string, body: string}>
     */
    public function sections(): array
    {
        $sections = [['heading' => '[intro]', 'lines' => []]];

        foreach ($this->lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/u', $line, $m) === 1) {
                $sections[] = ['heading' => self::inline($m[1]), 'lines' => []];
                continue;
            }

            $sections[array_key_last($sections)]['lines'][] = $line;
        }

        $out = [];

        foreach ($sections as $section) {
            $body = trim(implode("\n", $section['lines']));

            if ($section['heading'] === '[intro]' && $body === '') {
                continue;
            }

            $out[] = ['heading' => $section['heading'], 'body' => $body];
        }

        return $out;
    }

    /**
     * Lines that hold nothing but bold text: the shape a model turns into a heading.
     *
     * @return string[]
     */
    public function boldLines(): array
    {
        $bold = [];

        foreach ($this->lines as $line) {
            if (preg_match('/^\s*\*\*([^*]+?)\s*:?\s*\*\*\s*:?\s*$/u', $line, $m) === 1) {
                $bold[] = trim($m[1]);
            }
        }

        return $bold;
    }

    /**
     * Standalone bold lines, then the bold lead of list items.
     *
     * @return string[]
     */
    public function boldLeads(): array
    {
        $leads = $this->boldLines();

        foreach ($this->lines as $line) {
            if (preg_match('/^\s*(?:[-*+]|\d+\.)\s+\*\*([^*]+?)\s*:?\s*\*\*/u', $line, $m) === 1) {
                $leads[] = trim($m[1]);
            }
        }

        return $leads;
    }

    /**
     * @return string[]
     */
    public function links(): array
    {
        preg_match_all('/(?<!!)\[[^\]]*\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/u', implode("\n", $this->lines), $m);

        return $m[1];
    }

    public function plainText(): string
    {
        return self::toPlain(implode("\n", $this->lines));
    }

    /**
     * @return string[]
     */
    public function sentences(): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+|\n+/u', $this->plainText(), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $sentence): bool => $sentence !== ''
        ));
    }

    public static function toPlain(string $markdown): string
    {
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $markdown);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*\|?\s*:?-{3,}/u', $line) === 1) {
                continue;
            }

            $line = (string) preg_replace('/^\s{0,3}(?:#{1,6}\s+|>\s?|[-*+]\s+|\d+\.\s+)/u', '', $line);
            $line = str_replace(['**', '__', '`', '|'], ['', '', '', ' '], $line);
            $line = (string) preg_replace('/(?<![\p{L}\d])[*_]|[*_](?![\p{L}\d])/u', '', $line);
            $lines[] = trim((string) preg_replace('/\s+/u', ' ', $line));
        }

        return trim(implode("\n", $lines));
    }

    private static function inline(string $text): string
    {
        return trim(str_replace(['**', '__', '`'], '', (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text)));
    }
}
```

`boldLines()` accepts `**Ce que nous restaurons :**` (colon inside the bold) and `**Ce que nous restaurons** :` (colon outside), both yielding `Ce que nous restaurons`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: test command with `--filter 'TextToolsTest|MarkdownDocumentTest'`. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Campaign/TextTools.php src/Campaign/MarkdownDocument.php tests/Unit/Campaign/TextToolsTest.php tests/Unit/Campaign/MarkdownDocumentTest.php
git commit -m "Add text and markdown readers for the corpus checks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Manifest model and validation

**Files:**
- Create: `src/Campaign/CheckSettings.php`, `src/Campaign/ManifestEntry.php`, `src/Campaign/CorpusManifest.php`
- Test: `tests/Unit/Campaign/ManifestEntryTest.php`, `tests/Unit/Campaign/CorpusManifestTest.php`

**Interfaces:**
- Consumes: `TextTools::numbers()`.
- Produces:
  - `new CheckSettings(float $orphanOverlap = 0.5, float $retention = 0.8, float $quoteMatch = 0.6, int $cramChars = 40, array $genericCta = CheckSettings::DEFAULT_GENERIC_CTA, array $ctaVocabulary = [])`, `CheckSettings::fromManifest(array $manifest): self`.
  - `ManifestEntry` with public readonly `id, file, author, locale, sector` (string), `traps` (string[]), `figures` (array<string, string[]>, keys normalised), `quotes` (array<int, array{text:string, attribution:string}>), `offers`, `claims` (string[]), `grid` (`array{section:string, items:string[]}|null`), `source` (?string); `ManifestEntry::fromArray(array): self` (lenient, defaults for optional keys), `hasTrap(string): bool`, `isFigure(string $number): bool`, `phrasesFor(string $number): string[]`; constants `AUTHORS`, `TRAPS`, `LOCALES`.
  - `CorpusManifest::load(string $directory): self` (throws `RuntimeException` on missing dir, unreadable or invalid JSON), `CorpusManifest::fromName(string $benchRoot, string $name): self`, public readonly `name`, `directory`, `entries` (ManifestEntry[] in manifest order), `settings` (CheckSettings); `errors(): string[]`, `entry(string $id): ?ManifestEntry`, `markdown(ManifestEntry): ?string` (null when the file is missing), `hashes(): array<string, string>` (file name → sha1, `manifest.json` included), static `validate(array $data, string $directory): string[]`.

**Manifest shape** (top level; `files` entries follow the spec's example plus the fields below):

```json
{
  "version": 1,
  "thresholds": {"orphan_overlap": 0.5, "retention": 0.8, "quote_match": 0.6, "cram_chars": 40},
  "generic_cta": ["nous contacter", "prendre contact", "contactez-nous", "en savoir plus", "découvrir", "contact us", "get in touch", "learn more"],
  "cta_vocabulary": ["réserver", "demander", "prendre", "obtenir", "contacter", "appeler", "écrire", "venir", "rencontrer", "parler", "échanger", "poser", "question", "questions", "votre", "notre", "vous", "nous", "avec", "pour", "dans", "plus", "book", "request", "call", "contact", "write", "visit", "talk", "with", "your", "about", "more", "touch"],
  "files": [
    {
      "id": "hard-01-menuiserie-atelier",
      "file": "hard-01-menuiserie-atelier.md",
      "author": "claude",
      "locale": "fr_FR",
      "sector": "artisan",
      "traps": ["T1", "T3"],
      "figures": {"1998": ["depuis 1998"]},
      "quotes": [],
      "offers": ["meubles sur mesure"],
      "claims": [],
      "grid": {"section": "Nos deux ateliers", "items": ["Sur mesure", "Restauration"]},
      "source": null
    }
  ]
}
```

Field rules: `grid` is required for T3 files and holds exactly 2 items; `claims` (the non-numeric claims sitting where a stat slot would) is non-empty for T6 files; `source` (`"<batch>/<index>"`) is required for `adapted` files; `quotes[]` are `{"text", "attribution"}`; `cta_vocabulary` never holds an offer noun (no `rendez-vous`, `devis`, `essai`, `bilan`).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Campaign/ManifestEntryTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\ManifestEntry;
use AIForge\DevTools\Tests\Unit\TestCase;

class ManifestEntryTest extends TestCase
{
    public function testIntegerJsonKeysStillMatchStringFigures(): void
    {
        // json_decode turns {"1998": [...]} into an int key.
        $entry = ManifestEntry::fromArray(json_decode('{"id":"x","figures":{"1998":["depuis 1998"]}}', true));

        $this->assertTrue($entry->isFigure('1998'));
    }

    public function testFigureKeysAreNormalised(): void
    {
        $entry = ManifestEntry::fromArray(['id' => 'x', 'figures' => ['15 000' => ['15 000 exemplaires'], '4,8' => ['4,8 sur 5']]]);

        $this->assertTrue($entry->isFigure('15000'));
        $this->assertTrue($entry->isFigure('4.8'));
        $this->assertSame(['15 000 exemplaires'], $entry->phrasesFor('15000'));
        $this->assertFalse($entry->isFigure('10'));
        $this->assertSame([], $entry->phrasesFor('10'));
    }

    public function testMissingOptionalFieldsDefaultToEmpty(): void
    {
        $entry = ManifestEntry::fromArray(['id' => 'x']);

        $this->assertSame([], $entry->traps);
        $this->assertSame([], $entry->quotes);
        $this->assertSame([], $entry->offers);
        $this->assertNull($entry->grid);
        $this->assertSame('x.md', $entry->file);
    }

    public function testHasTrap(): void
    {
        $entry = ManifestEntry::fromArray(['id' => 'x', 'traps' => ['T1', 'T3']]);

        $this->assertTrue($entry->hasTrap('T3'));
        $this->assertFalse($entry->hasTrap('T2'));
    }
}
```

`tests/Unit/Campaign/CorpusManifestTest.php` builds a temp corpus per test:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusManifest;
use AIForge\DevTools\Tests\Unit\TestCase;
use RuntimeException;

class CorpusManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/corpus-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * Nine files, each trap carried by three of them.
     *
     * @return array<string, mixed>
     */
    private function validData(): array
    {
        $traps = [['T1', 'T2'], ['T3', 'T4'], ['T5', 'T6'], ['T1', 'T2'], ['T3', 'T4'], ['T5', 'T6'], ['T1', 'T3'], ['T2', 'T4'], ['T5', 'T6']];
        $files = [];

        foreach ($traps as $i => $pair) {
            $id = sprintf('hard-%02d-page', $i + 1);
            file_put_contents("{$this->dir}/{$id}.md", "# Page\n");
            $files[] = [
                'id' => $id,
                'file' => "{$id}.md",
                'author' => 'claude',
                'locale' => 'fr_FR',
                'sector' => 'artisan',
                'traps' => $pair,
                'figures' => [],
                'quotes' => [],
                'offers' => [],
                'claims' => \in_array('T6', $pair, true) ? ['formations régulières'] : [],
                'grid' => \in_array('T3', $pair, true) ? ['section' => 'Nos ateliers', 'items' => ['A', 'B']] : null,
                'source' => null,
            ];
        }

        return ['version' => 1, 'generic_cta' => ['nous contacter'], 'cta_vocabulary' => [], 'files' => $files];
    }

    private function write(array $data): void
    {
        file_put_contents("{$this->dir}/manifest.json", json_encode($data));
    }

    public function testValidManifestHasNoErrors(): void
    {
        $this->write($this->validData());

        $manifest = CorpusManifest::load($this->dir);

        $this->assertSame([], $manifest->errors());
        $this->assertCount(9, $manifest->entries);
        $this->assertSame('hard-01-page', $manifest->entries[0]->id);
    }

    public function testDuplicateIdsAreReported(): void
    {
        $data = $this->validData();
        $data['files'][1]['id'] = $data['files'][0]['id'];
        $data['files'][1]['file'] = $data['files'][0]['file'];
        $this->write($data);

        $this->assertContains('duplicate id hard-01-page', CorpusManifest::load($this->dir)->errors());
    }

    public function testMissingFileIsReported(): void
    {
        $this->write($this->validData());
        unlink("{$this->dir}/hard-02-page.md");

        $manifest = CorpusManifest::load($this->dir);

        $this->assertContains('hard-02-page: file hard-02-page.md is missing', $manifest->errors());
        $this->assertNull($manifest->markdown($manifest->entries[1]));
    }

    public function testATrapInFewerThanThreeFilesIsReported(): void
    {
        $data = $this->validData();
        $data['files'][8]['traps'] = ['T5'];
        $data['files'][8]['claims'] = [];
        $this->write($data);

        $this->assertContains('trap T6 is carried by 2 file(s), needs at least 3', CorpusManifest::load($this->dir)->errors());
    }

    public function testFieldRulesAreEnforced(): void
    {
        $data = $this->validData();
        $data['files'][0]['author'] = 'mistral';
        $data['files'][1]['grid'] = null;
        $data['files'][2]['claims'] = [];
        $data['files'][3]['author'] = 'adapted';
        $data['files'][4]['quotes'] = [['text' => 'Bien']];
        $data['files'][5]['figures'] = ['beaucoup' => ['beaucoup']];
        $this->write($data);

        $errors = CorpusManifest::load($this->dir)->errors();

        $this->assertContains('hard-01-page: unknown author mistral', $errors);
        $this->assertContains('hard-02-page: T3 needs a grid with a section and exactly 2 items', $errors);
        $this->assertContains('hard-03-page: T6 needs at least one claim', $errors);
        $this->assertContains('hard-04-page: an adapted file needs its source', $errors);
        $this->assertContains('hard-05-page: every quote needs a text and an attribution', $errors);
        $this->assertContains('hard-06-page: figure key beaucoup is not a number', $errors);
    }

    public function testThresholdsAndVocabularyFeedTheSettings(): void
    {
        $data = $this->validData();
        $data['thresholds'] = ['retention' => 0.75];
        $data['cta_vocabulary'] = ['réserver'];
        $this->write($data);

        $settings = CorpusManifest::load($this->dir)->settings;

        $this->assertSame(0.75, $settings->retention);
        $this->assertSame(0.5, $settings->orphanOverlap);
        $this->assertSame(['réserver'], $settings->ctaVocabulary);
        $this->assertSame(['nous contacter'], $settings->genericCta);
    }

    public function testHashesCoverTheManifestAndEveryPresentFile(): void
    {
        $this->write($this->validData());

        $hashes = CorpusManifest::load($this->dir)->hashes();

        $this->assertSame(sha1_file("{$this->dir}/manifest.json"), $hashes['manifest.json']);
        $this->assertSame(sha1("# Page\n"), $hashes['hard-01-page.md']);
    }

    public function testFromNameExplainsAMissingBenchDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bench/ is not in the release zip');

        CorpusManifest::fromName($this->dir . '/nowhere', 'hard');
    }

    public function testInvalidJsonThrows(): void
    {
        file_put_contents("{$this->dir}/manifest.json", '{nope');

        $this->expectException(RuntimeException::class);

        CorpusManifest::load($this->dir);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `--filter 'ManifestEntryTest|CorpusManifestTest'`. Expected: FAIL, classes not found.

- [ ] **Step 3: Implement `src/Campaign/CheckSettings.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Thresholds and closed word lists the trap checks read from the manifest.
 */
final class CheckSettings
{
    public const DEFAULT_GENERIC_CTA = [
        'nous contacter', 'prendre contact', 'contactez-nous', 'en savoir plus', 'découvrir',
        'contact us', 'get in touch', 'learn more',
    ];

    /**
     * @param string[] $genericCta Whole labels that pass whatever the source offers.
     * @param string[] $ctaVocabulary Words a label may use besides the offers': verbs
     *                                and function words, never an offer noun.
     */
    public function __construct(
        public readonly float $orphanOverlap = 0.5,
        public readonly float $retention = 0.8,
        public readonly float $quoteMatch = 0.6,
        public readonly int $cramChars = 40,
        public readonly array $genericCta = self::DEFAULT_GENERIC_CTA,
        public readonly array $ctaVocabulary = [],
    ) {
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function fromManifest(array $manifest): self
    {
        $thresholds = \is_array($manifest['thresholds'] ?? null) ? $manifest['thresholds'] : [];

        return new self(
            (float) ($thresholds['orphan_overlap'] ?? 0.5),
            (float) ($thresholds['retention'] ?? 0.8),
            (float) ($thresholds['quote_match'] ?? 0.6),
            (int) ($thresholds['cram_chars'] ?? 40),
            self::strings($manifest['generic_cta'] ?? self::DEFAULT_GENERIC_CTA),
            self::strings($manifest['cta_vocabulary'] ?? []),
        );
    }

    /**
     * @return string[]
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
```

- [ ] **Step 4: Implement `src/Campaign/ManifestEntry.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * One corpus file's ground truth: every number it states, the quotes and
 * offers it really holds, and the traps it was written to carry.
 */
final class ManifestEntry
{
    public const AUTHORS = ['claude', 'gemini', 'gpt', 'adapted'];
    public const TRAPS = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6'];
    public const LOCALES = ['fr_FR', 'en_US'];

    /**
     * @param string[] $traps
     * @param array<string, string[]> $figures Normalised number => phrases stating it.
     * @param array<int, array{text: string, attribution: string}> $quotes
     * @param string[] $offers
     * @param string[] $claims
     * @param array{section: string, items: string[]}|null $grid
     */
    public function __construct(
        public readonly string $id,
        public readonly string $file,
        public readonly string $author,
        public readonly string $locale,
        public readonly string $sector,
        public readonly array $traps,
        public readonly array $figures,
        public readonly array $quotes,
        public readonly array $offers,
        public readonly array $claims,
        public readonly ?array $grid,
        public readonly ?string $source,
    ) {
    }

    /**
     * Lenient: validation lives in CorpusManifest::validate(), so tests can
     * build an entry from the fields they care about.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['id'] ?? '');
        $figures = [];

        foreach ((\is_array($data['figures'] ?? null) ? $data['figures'] : []) as $key => $phrases) {
            $number = TextTools::numbers((string) $key)[0] ?? (string) $key;
            $figures[$number] = \is_array($phrases) ? array_values(array_map('strval', $phrases)) : [];
        }

        $quotes = [];

        foreach ((\is_array($data['quotes'] ?? null) ? $data['quotes'] : []) as $quote) {
            $quotes[] = [
                'text' => (string) ($quote['text'] ?? ''),
                'attribution' => (string) ($quote['attribution'] ?? ''),
            ];
        }

        $grid = null;

        if (\is_array($data['grid'] ?? null)) {
            $grid = [
                'section' => (string) ($data['grid']['section'] ?? ''),
                'items' => array_values(array_map('strval', (array) ($data['grid']['items'] ?? []))),
            ];
        }

        return new self(
            $id,
            (string) ($data['file'] ?? "{$id}.md"),
            (string) ($data['author'] ?? ''),
            (string) ($data['locale'] ?? ''),
            (string) ($data['sector'] ?? ''),
            array_values(array_map('strval', (array) ($data['traps'] ?? []))),
            $figures,
            $quotes,
            array_values(array_map('strval', (array) ($data['offers'] ?? []))),
            array_values(array_map('strval', (array) ($data['claims'] ?? []))),
            $grid,
            isset($data['source']) ? (string) $data['source'] : null,
        );
    }

    public function hasTrap(string $trap): bool
    {
        return \in_array($trap, $this->traps, true);
    }

    public function isFigure(string $number): bool
    {
        return \array_key_exists($number, $this->figures);
    }

    /**
     * @return string[]
     */
    public function phrasesFor(string $number): array
    {
        return $this->figures[$number] ?? [];
    }
}
```

- [ ] **Step 5: Implement `src/Campaign/CorpusManifest.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use RuntimeException;

/**
 * A frozen corpus: its manifest.json plus the markdown files it lists.
 */
final class CorpusManifest
{
    private const MIN_FILES_PER_TRAP = 3;

    /**
     * @param array<string, mixed> $data
     * @param ManifestEntry[] $entries
     */
    private function __construct(
        public readonly string $name,
        public readonly string $directory,
        public readonly array $data,
        public readonly array $entries,
        public readonly CheckSettings $settings,
    ) {
    }

    public static function fromName(string $benchRoot, string $name): self
    {
        $directory = rtrim($benchRoot, '/') . '/' . $name;

        if (!is_dir($directory)) {
            throw new RuntimeException(
                "Corpus '{$name}' not found at {$directory}. bench/ is not in the release zip: run from a git checkout of wp-ai-forge-devtools."
            );
        }

        return self::load($directory);
    }

    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/') . '/manifest.json';

        if (!is_readable($path)) {
            throw new RuntimeException("No readable manifest.json in {$directory}.");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!\is_array($data)) {
            throw new RuntimeException("{$path} is not valid JSON: " . json_last_error_msg());
        }

        $entries = array_map(
            static fn (mixed $file): ManifestEntry => ManifestEntry::fromArray(\is_array($file) ? $file : []),
            \is_array($data['files'] ?? null) ? array_values($data['files']) : []
        );

        return new self(basename(rtrim($directory, '/')), rtrim($directory, '/'), $data, $entries, CheckSettings::fromManifest($data));
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return self::validate($this->data, $this->directory);
    }

    public function entry(string $id): ?ManifestEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function markdown(ManifestEntry $entry): ?string
    {
        $path = "{$this->directory}/{$entry->file}";

        return is_readable($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * @return array<string, string>
     */
    public function hashes(): array
    {
        $hashes = ['manifest.json' => (string) sha1_file("{$this->directory}/manifest.json")];

        foreach ($this->entries as $entry) {
            $path = "{$this->directory}/{$entry->file}";

            if (is_readable($path)) {
                $hashes[$entry->file] = (string) sha1_file($path);
            }
        }

        return $hashes;
    }

    /**
     * Schema and invariants only. No hard-coded counts, so adding files never breaks it.
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    public static function validate(array $data, string $directory): array
    {
        $errors = [];
        $files = $data['files'] ?? null;

        if (!\is_array($files) || $files === []) {
            return ['files must be a non-empty list'];
        }

        if (!\is_array($data['generic_cta'] ?? null) || $data['generic_cta'] === []) {
            $errors[] = 'generic_cta must be a non-empty list';
        }

        foreach (['orphan_overlap', 'retention', 'quote_match'] as $ratio) {
            $value = $data['thresholds'][$ratio] ?? null;

            if ($value !== null && (!is_numeric($value) || $value <= 0 || $value > 1)) {
                $errors[] = "threshold {$ratio} must be in (0, 1]";
            }
        }

        $seen = [];
        $trapCounts = array_fill_keys(ManifestEntry::TRAPS, 0);

        foreach ($files as $raw) {
            $raw = \is_array($raw) ? $raw : [];
            $id = (string) ($raw['id'] ?? '');

            if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1) {
                $errors[] = "invalid id '{$id}'";
                continue;
            }

            if (isset($seen[$id])) {
                $errors[] = "duplicate id {$id}";
                continue;
            }

            $seen[$id] = true;
            $entry = ManifestEntry::fromArray($raw);

            if ($entry->file !== "{$id}.md") {
                $errors[] = "{$id}: file must be {$id}.md";
            } elseif (!is_readable("{$directory}/{$entry->file}")) {
                $errors[] = "{$id}: file {$entry->file} is missing";
            }

            if (!\in_array($entry->author, ManifestEntry::AUTHORS, true)) {
                $errors[] = "{$id}: unknown author {$entry->author}";
            }

            if (!\in_array($entry->locale, ManifestEntry::LOCALES, true)) {
                $errors[] = "{$id}: unknown locale {$entry->locale}";
            }

            if ($entry->sector === '') {
                $errors[] = "{$id}: sector is empty";
            }

            if ($entry->traps === [] || array_diff($entry->traps, ManifestEntry::TRAPS) !== []) {
                $errors[] = "{$id}: traps must be a non-empty subset of " . implode(', ', ManifestEntry::TRAPS);
            }

            foreach (array_intersect($entry->traps, ManifestEntry::TRAPS) as $trap) {
                $trapCounts[$trap]++;
            }

            foreach ((array) ($raw['figures'] ?? []) as $key => $phrases) {
                if (TextTools::numbers((string) $key) === []) {
                    $errors[] = "{$id}: figure key {$key} is not a number";
                } elseif (!\is_array($phrases) || $phrases === []) {
                    $errors[] = "{$id}: figure {$key} needs at least one phrase";
                }
            }

            foreach ($entry->quotes as $quote) {
                if ($quote['text'] === '' || $quote['attribution'] === '') {
                    $errors[] = "{$id}: every quote needs a text and an attribution";
                    break;
                }
            }

            if ($entry->hasTrap('T3') && ($entry->grid === null || $entry->grid['section'] === '' || \count($entry->grid['items']) !== 2)) {
                $errors[] = "{$id}: T3 needs a grid with a section and exactly 2 items";
            }

            if ($entry->hasTrap('T6') && $entry->claims === []) {
                $errors[] = "{$id}: T6 needs at least one claim";
            }

            if ($entry->author === 'adapted' && ($entry->source === null || $entry->source === '')) {
                $errors[] = "{$id}: an adapted file needs its source";
            }
        }

        foreach ($trapCounts as $trap => $count) {
            if ($count < self::MIN_FILES_PER_TRAP) {
                $errors[] = \sprintf('trap %s is carried by %d file(s), needs at least %d', $trap, $count, self::MIN_FILES_PER_TRAP);
            }
        }

        return $errors;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `--filter 'ManifestEntryTest|CorpusManifestTest'`. Expected: PASS. In `testATrapInFewerThanThreeFilesIsReported`, T6 is carried by files 3, 6 and 9 of `validData()`; dropping it from file 9 leaves 2.

- [ ] **Step 7: Commit**

```bash
git add src/Campaign/CheckSettings.php src/Campaign/ManifestEntry.php src/Campaign/CorpusManifest.php tests/Unit/Campaign/ManifestEntryTest.php tests/Unit/Campaign/CorpusManifestTest.php
git commit -m "Add the corpus manifest and its schema checks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Static precondition checks, gate lint, check script

**Files:**
- Create: `src/Campaign/CorpusPreconditions.php`, `bench/ci-corpus/check.php`
- Test: `tests/Unit/Campaign/CorpusPreconditionsTest.php`

**Interfaces:**
- Consumes: `ManifestEntry`, `MarkdownDocument`, `TextTools`, `CorpusManifest`.
- Produces: `CorpusPreconditions::check(ManifestEntry $entry, string $markdown): string[]` (errors), `CorpusPreconditions::lint(string $markdown): string[]` (warnings); constants `MIN_WORDS = 600`, `MAX_WORDS = 2500`, `DENSE_MIN_WORDS = 450`, `HEADING_INFLATION = 1.8`. Script `php bench/ci-corpus/check.php <corpus>` exits 1 on any error.

**What each rule checks** (all files unless a trap is named):

| rule | error when |
|---|---|
| authoring | a `<` anywhere; `![`; a leak pattern (`lorem ipsum`, `consectetur`, `dolor sit`, `adipiscing`, folded); not exactly one `# ` line; a link URL not matching `^https?://([a-z0-9-]+\.)*example\.com(/|$)` |
| length | plain-text word count outside 600..2500 |
| figures | a number in the plain text (URLs already gone) that is not a `figures` key; a `figures` phrase absent from the text (folded) |
| quotes | a quote text or attribution absent from the text |
| offers, claims | a phrase absent from the text |
| T1 | a `%`, `pour cent` or `percent` in the text |
| T2 | `quotes` not empty; a line starting `> ` followed by `«`, `"` or `“`; a closing quote followed by a dash and a capital (`» — Marie`) |
| T3 | the `grid.section` `##` section does not hold exactly 2 `###` headings equal (folded) to `grid.items`, or, if it has no `###`, exactly 2 top-level list items whose lead starts with them |
| T4 | no `##` section with ≥ 450 words and ≥ 2 × the median `##` section word count |
| T5 | `offers` not empty |

Lint warnings: `(H + B) / H > 1.8` where `H` counts `^#{1,3}\s` lines and `B` standalone bold lines (the Quality Gate counts only `#{1,3}` in the source, so each bold line a model turns into a heading inflates the ratio); the last non-empty line holds `&`, `«` or `»`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Campaign/CorpusPreconditionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusPreconditions;
use AIForge\Campaign\ManifestEntry;
use AIForge\DevTools\Tests\Unit\TestCase;

class CorpusPreconditionsTest extends TestCase
{
    /** 60 distinct-enough words; repeated to reach a length. */
    private const FILLER = 'Nous travaillons le chêne et le noyer dans notre atelier de quartier, avec des assemblages traditionnels, des finitions à la cire et une attention constante portée aux détails que vous remarquerez chaque jour en ouvrant un tiroir ou en posant une tasse sur le plateau de la table familiale. ';

    private function page(string $body, int $fillerRepeats = 12): string
    {
        return "# Atelier Brun\n\n" . $body . "\n\n## Notre métier\n\n" . str_repeat(self::FILLER, $fillerRepeats) . "\n";
    }

    private function entry(array $overrides = []): ManifestEntry
    {
        return ManifestEntry::fromArray($overrides + [
            'id' => 'hard-01', 'traps' => ['T1'], 'figures' => ['1998' => ['depuis 1998']],
        ]);
    }

    public function testACleanPagePasses(): void
    {
        $this->assertSame([], CorpusPreconditions::check($this->entry(), $this->page('Menuiserie depuis 1998.')));
    }

    public function testAnUnlistedNumberFails(): void
    {
        $errors = CorpusPreconditions::check($this->entry(), $this->page('Menuiserie depuis 1998, 40 chantiers par an.'));

        $this->assertContains('number 40 is not in figures', $errors);
    }

    public function testAFigurePhraseMustBeInTheText(): void
    {
        $errors = CorpusPreconditions::check($this->entry(['figures' => ['1998' => ['fondée en 1998']]]), $this->page('Menuiserie depuis 1998.'));

        $this->assertContains('figure phrase «fondée en 1998» is not in the text', $errors);
    }

    public function testAuthoringConstraints(): void
    {
        $markdown = $this->page("Menuiserie depuis 1998. Voir <https://brun.fr> et [ici](https://brun.fr/x). Lorem ipsum.\n\n![photo](a.jpg)\n\n# Second titre");
        $errors = CorpusPreconditions::check($this->entry(), $markdown);

        $this->assertContains('contains a < character', $errors);
        $this->assertContains('contains an image', $errors);
        $this->assertContains('contains the leak pattern «lorem ipsum»', $errors);
        $this->assertContains('needs exactly one # title, found 2', $errors);
        $this->assertContains('link https://brun.fr/x is not an example.com URL', $errors);
    }

    public function testLengthBounds(): void
    {
        $errors = CorpusPreconditions::check($this->entry(), $this->page('Menuiserie depuis 1998.', 2));

        $this->assertMatchesRegularExpression('/^\d+ words, outside 600\.\.2500$/', implode("\n", array_filter($errors, static fn ($e) => str_contains($e, 'words'))));
    }

    public function testT1RejectsPercentages(): void
    {
        $errors = CorpusPreconditions::check($this->entry(), $this->page('Menuiserie depuis 1998, clients satisfaits à cent pour cent.'));

        $this->assertContains('T1 file states a percentage', $errors);
    }

    public function testT2RejectsQuotesAndQuoteShapes(): void
    {
        $entry = $this->entry(['traps' => ['T2'], 'quotes' => [['text' => 'Parfait', 'attribution' => 'Marie']]]);
        $errors = CorpusPreconditions::check($entry, $this->page("Menuiserie depuis 1998.\n\n> « Un travail parfait. » — Marie Durand"));

        $this->assertContains('T2 file lists quotes', $errors);
        $this->assertContains('T2 file holds an attributed quote', $errors);
    }

    public function testT3NeedsExactlyTheTwoGridItems(): void
    {
        $entry = $this->entry(['traps' => ['T3'], 'grid' => ['section' => 'Nos deux ateliers', 'items' => ['Sur mesure', 'Restauration']]]);
        $good = $this->page("Menuiserie depuis 1998.\n\n## Nos deux ateliers\n\n### Sur mesure\n\nTexte.\n\n### Restauration\n\nTexte.");
        $bad = $this->page("Menuiserie depuis 1998.\n\n## Nos deux ateliers\n\n### Sur mesure\n\n### Restauration\n\n### Conseil");

        $this->assertSame([], CorpusPreconditions::check($entry, $good));
        $this->assertContains('T3 section «Nos deux ateliers» holds 3 parallel items, expected Sur mesure | Restauration', CorpusPreconditions::check($entry, $bad));
    }

    public function testT3AcceptsTwoListItems(): void
    {
        $entry = $this->entry(['traps' => ['T3'], 'grid' => ['section' => 'Nos deux ateliers', 'items' => ['Sur mesure', 'Restauration']]]);
        $page = $this->page("Menuiserie depuis 1998.\n\n## Nos deux ateliers\n\n- **Sur mesure** : cuisines.\n- **Restauration** : meubles anciens.");

        $this->assertSame([], CorpusPreconditions::check($entry, $page));
    }

    public function testT4NeedsOneDenseSection(): void
    {
        $entry = $this->entry(['traps' => ['T4']]);
        $flat = "# A\n\nMenuiserie depuis 1998.\n\n## Un\n\n" . str_repeat(self::FILLER, 4) . "\n\n## Deux\n\n" . str_repeat(self::FILLER, 4) . "\n\n## Trois\n\n" . str_repeat(self::FILLER, 4);
        $dense = "# A\n\nMenuiserie depuis 1998.\n\n## Un\n\n" . str_repeat(self::FILLER, 9) . "\n\n## Deux\n\n" . str_repeat(self::FILLER, 2) . "\n\n## Trois\n\n" . str_repeat(self::FILLER, 2);

        $this->assertContains('T4 file has no section of 450+ words at twice the median', CorpusPreconditions::check($entry, $flat));
        $this->assertNotContains('T4 file has no section of 450+ words at twice the median', CorpusPreconditions::check($entry, $dense));
    }

    public function testT5RejectsOffers(): void
    {
        $entry = $this->entry(['traps' => ['T5'], 'offers' => ['meubles']]);

        $this->assertContains('T5 file lists offers', CorpusPreconditions::check($entry, $this->page('Menuiserie depuis 1998, meubles.')));
    }

    public function testLintWarnsOnBoldLinesAndEntityEnding(): void
    {
        $markdown = "# A\n\n## B\n\n**Un**\n\n**Deux**\n\n**Trois**\n\nFin & « suite »";
        $warnings = CorpusPreconditions::lint($markdown);

        $this->assertContains('3 bold line(s) over 2 heading(s): ratio 2.5 would pass the 1.8 heading-inflation gate if each became a heading', $warnings);
        $this->assertContains('last line holds entity-prone characters (&, « »)', $warnings);
    }
}
```

`FILLER` is 56 words (count them once when implementing, and adjust the `$fillerRepeats` defaults if your count differs: the clean page must land between 600 and 2,500 words, the `$flat` T4 case must have no section at 450+ words and the `$dense` one must have one at 450+ words and twice the median).

- [ ] **Step 2: Run to verify they fail**

Run: `--filter CorpusPreconditionsTest`. Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Campaign/CorpusPreconditions.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Checks a corpus file really holds the traps its manifest entry claims, and
 * warns when it would trip a Quality Gate artifact instead of a model.
 */
final class CorpusPreconditions
{
    public const MIN_WORDS = 600;
    public const MAX_WORDS = 2500;
    public const DENSE_MIN_WORDS = 450;
    public const HEADING_INFLATION = 1.8;

    private const LEAK_PATTERNS = ['lorem ipsum', 'consectetur', 'dolor sit', 'adipiscing'];
    private const LINK_HOST = '#^https?://([a-z0-9-]+\.)*example\.com(/|$)#i';

    /**
     * @return string[]
     */
    public static function check(ManifestEntry $entry, string $markdown): array
    {
        $document = new MarkdownDocument($markdown);
        $plain = $document->plainText();
        $folded = TextTools::fold($plain);
        $errors = [];

        if (str_contains($markdown, '<')) {
            $errors[] = 'contains a < character';
        }

        if (str_contains($markdown, '![')) {
            $errors[] = 'contains an image';
        }

        foreach (self::LEAK_PATTERNS as $pattern) {
            if (str_contains(TextTools::fold($markdown), $pattern)) {
                $errors[] = "contains the leak pattern «{$pattern}»";
            }
        }

        $titles = \count(array_filter($document->headings(), static fn (array $h): bool => $h['level'] === 1));

        if ($titles !== 1) {
            $errors[] = "needs exactly one # title, found {$titles}";
        }

        foreach ($document->links() as $href) {
            if (preg_match(self::LINK_HOST, $href) !== 1) {
                $errors[] = "link {$href} is not an example.com URL";
            }
        }

        $words = TextTools::wordCount($plain);

        if ($words < self::MIN_WORDS || $words > self::MAX_WORDS) {
            $errors[] = \sprintf('%d words, outside %d..%d', $words, self::MIN_WORDS, self::MAX_WORDS);
        }

        foreach (array_unique(TextTools::numbers($plain)) as $number) {
            if (!$entry->isFigure($number)) {
                $errors[] = "number {$number} is not in figures";
            }
        }

        foreach ($entry->figures as $phrases) {
            foreach ($phrases as $phrase) {
                if (!str_contains($folded, TextTools::fold($phrase))) {
                    $errors[] = "figure phrase «{$phrase}» is not in the text";
                }
            }
        }

        foreach ($entry->quotes as $quote) {
            foreach (['text', 'attribution'] as $field) {
                if (!str_contains($folded, TextTools::fold($quote[$field]))) {
                    $errors[] = "quote {$field} «{$quote[$field]}» is not in the text";
                }
            }
        }

        foreach (['offers' => $entry->offers, 'claims' => $entry->claims] as $field => $phrases) {
            foreach ($phrases as $phrase) {
                if (!str_contains($folded, TextTools::fold($phrase))) {
                    $errors[] = "{$field} phrase «{$phrase}» is not in the text";
                }
            }
        }

        if ($entry->hasTrap('T1') && (str_contains($plain, '%') || str_contains($folded, 'pour cent') || str_contains($folded, 'percent'))) {
            $errors[] = 'T1 file states a percentage';
        }

        if ($entry->hasTrap('T2')) {
            if ($entry->quotes !== []) {
                $errors[] = 'T2 file lists quotes';
            }

            if (preg_match('/^>\s*[«"“]/mu', $markdown) === 1 || preg_match('/[»"”]\s*[—–-]\s*\p{Lu}/u', $plain) === 1) {
                $errors[] = 'T2 file holds an attributed quote';
            }
        }

        if ($entry->hasTrap('T3') && $entry->grid !== null) {
            $error = self::gridError($document, $entry->grid);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if ($entry->hasTrap('T4') && !self::hasDenseSection($document)) {
            $errors[] = 'T4 file has no section of 450+ words at twice the median';
        }

        if ($entry->hasTrap('T5') && $entry->offers !== []) {
            $errors[] = 'T5 file lists offers';
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    public static function lint(string $markdown): array
    {
        $document = new MarkdownDocument($markdown);
        $warnings = [];

        preg_match_all('/^#{1,3}\s+/m', $markdown, $headings);
        $headingCount = \count($headings[0]);
        $boldCount = \count($document->boldLines());

        if ($headingCount > 0 && $boldCount > 0) {
            $ratio = ($headingCount + $boldCount) / $headingCount;

            if ($ratio > self::HEADING_INFLATION) {
                $warnings[] = \sprintf(
                    '%d bold line(s) over %d heading(s): ratio %.1f would pass the %.1f heading-inflation gate if each became a heading',
                    $boldCount,
                    $headingCount,
                    $ratio,
                    self::HEADING_INFLATION
                );
            }
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $markdown)), static fn (string $l): bool => $l !== ''));
        $last = $lines === [] ? '' : $lines[\count($lines) - 1];

        if (preg_match('/[&«»]/u', $last) === 1) {
            $warnings[] = 'last line holds entity-prone characters (&, « »)';
        }

        return $warnings;
    }

    /**
     * @param array{section: string, items: string[]} $grid
     */
    private static function gridError(MarkdownDocument $document, array $grid): ?string
    {
        $expected = implode(' | ', $grid['items']);

        foreach ($document->sections() as $section) {
            if (TextTools::fold($section['heading']) !== TextTools::fold($grid['section'])) {
                continue;
            }

            preg_match_all('/^###\s+(.+?)\s*$/mu', $section['body'], $h3);
            $items = $h3[1];

            if ($items === []) {
                preg_match_all('/^[-*+]\s+(?:\*\*)?([^*\n:]+)/mu', $section['body'], $list);
                $items = $list[1];
            }

            $items = array_map('trim', $items);

            if (\count($items) !== \count($grid['items'])) {
                return \sprintf('T3 section «%s» holds %d parallel items, expected %s', $grid['section'], \count($items), $expected);
            }

            foreach ($grid['items'] as $i => $item) {
                if (!str_starts_with(TextTools::fold($items[$i]), TextTools::fold($item))) {
                    return \sprintf('T3 section «%s» items are %s, expected %s', $grid['section'], implode(' | ', $items), $expected);
                }
            }

            return null;
        }

        return "T3 section «{$grid['section']}» not found";
    }

    private static function hasDenseSection(MarkdownDocument $document): bool
    {
        $counts = [];

        foreach ($document->sections() as $section) {
            if ($section['heading'] !== '[intro]') {
                $counts[] = TextTools::wordCount(MarkdownDocument::toPlain($section['body']));
            }
        }

        if ($counts === []) {
            return false;
        }

        $sorted = $counts;
        sort($sorted);
        $middle = intdiv(\count($sorted), 2);
        $median = \count($sorted) % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;

        foreach ($counts as $count) {
            if ($count >= self::DENSE_MIN_WORDS && $count >= 2 * $median) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Write `bench/ci-corpus/check.php`**

```php
<?php

/**
 * Static precondition checks and gate lint over a corpus. Plain PHP, no WordPress:
 *
 *     cd wp-lab && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools php bench/ci-corpus/check.php hard
 */

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\CorpusPreconditions;
use AIForge\Campaign\MarkdownDocument;
use AIForge\Campaign\TextTools;

$name = $argv[1] ?? 'hard';

try {
    $manifest = CorpusManifest::fromName(__DIR__, $name);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$errors = 0;

foreach ($manifest->errors() as $error) {
    echo "ERROR manifest: {$error}\n";
    $errors++;
}

foreach ($manifest->entries as $entry) {
    $markdown = $manifest->markdown($entry);

    if ($markdown === null) {
        continue;
    }

    printf(
        "%-36s %-8s %5d words  %s\n",
        $entry->id,
        $entry->author,
        TextTools::wordCount((new MarkdownDocument($markdown))->plainText()),
        implode(',', $entry->traps)
    );

    foreach (CorpusPreconditions::check($entry, $markdown) as $error) {
        echo "  ERROR {$error}\n";
        $errors++;
    }

    foreach (CorpusPreconditions::lint($markdown) as $warning) {
        echo "  warn  {$warning}\n";
    }
}

echo $errors === 0 ? "clean\n" : "{$errors} error(s)\n";
exit($errors === 0 ? 0 : 1);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `--filter CorpusPreconditionsTest`. Expected: PASS. If a length-dependent case fails, count `FILLER`'s words and adjust the repeat counts, not the thresholds.

- [ ] **Step 6: Commit**

```bash
git add src/Campaign/CorpusPreconditions.php bench/ci-corpus/check.php tests/Unit/Campaign/CorpusPreconditionsTest.php
git commit -m "Check a corpus file really carries the traps its manifest claims

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Briefs and manifest skeleton

**Files:**
- Create: `bench/ci-corpus/hard/briefs.json`, `bench/ci-corpus/hard/manifest.json`

**The twelve files.** Every trap lands in 4 files, above the minimum of 3, so one weak file cannot sink a trap:

| # | id | author | locale | sector | traps | target words |
|---|---|---|---|---|---|---|
| 01 | `hard-01-menuiserie-atelier` | claude | fr_FR | artisan (joinery) | T1, T3 | 900 |
| 02 | `hard-02-cabinet-kine` | gemini | fr_FR | health (physiotherapy) | T1, T2 | 1,100 |
| 03 | `hard-03-cabinet-comptable` | gpt | fr_FR | B2B service (accounting) | T3, T5 | 1,000 |
| 04 | `hard-04-saas-planning` | claude | en_US | SaaS (staff scheduling) | T2, T4 | 1,900 |
| 05 | `hard-05-association-quartier` | gemini | fr_FR | association (neighbourhood) | T5, T6 | 1,000 |
| 06 | `hard-06-fromagerie` | gpt | fr_FR | local shop (cheese) | T2, T6 | 900 |
| 07 | `hard-07-organisme-formation` | adapted | fr_FR | training body | T1, T4 | 1,800 |
| 08 | `hard-08-concession-auto` | adapted (3915/0) | fr_FR | car dealership | T3, T6 | 1,300 |
| 09 | `hard-09-plomberie` | claude | fr_FR | artisan (plumbing) | T4, T5 | 1,700 |
| 10 | `hard-10-vet-clinic` | gemini | en_US | health (veterinary) | T1, T5 | 1,000 |
| 11 | `hard-11-infogerance` | gpt | fr_FR | B2B service (IT support) | T3, T4 | 2,000 |
| 12 | `hard-12-…` (named from its source) | adapted | fr_FR | from its source | T2, T6 | 1,200 |

Totals: T1 01/02/07/10, T2 02/04/06/12, T3 01/03/08/11, T4 04/07/09/11, T5 03/05/09/10, T6 05/06/08/12; 10 French, 2 English; about 15,800 words.

- [ ] **Step 1: Pick the two other adapted sources**

List the titles of batches 3915 and 6223 (MariaDB directly, which works even when wp-cli does not):

```bash
M=$(docker ps --format '{{.Names}}' | grep -E -- '-mysql-1$' | grep -v tests)
for b in 3915 6223; do for i in $(seq 0 12); do
  docker exec "$M" mariadb -uroot -ppassword wordpress -N --raw -e \
    "SELECT CONCAT('$b/$i ', SUBSTRING_INDEX(payload, '\n', 1)) FROM wp_aiforge_task_payloads WHERE task_id=$b AND payload_type='markdown_snapshot_$i'"
done; done
```

Pick, besides 3915/0 (the car dealership that holds "formations Renault régulières"): one page that suits a training body for 07 (or the closest business, changing 07's sector to match), and one page for 12 that can be rewritten with no attributed quote and non-numeric claims in place of stats. Record the choices as `source` (`"3915/0"` form).

- [ ] **Step 2: Write `bench/ci-corpus/hard/briefs.json`**

One object per file, in the table's order:

```json
[
  {
    "id": "hard-02-cabinet-kine",
    "author": "gemini",
    "business": "Cabinet Kiné des Tilleuls",
    "sector": "cabinet de kinésithérapie",
    "language": "French",
    "locale": "fr_FR",
    "words": 1100,
    "traps": ["T1", "T2"],
    "facts": "the practice opened in 1998; three physiotherapists work there; the address is 12 rue des Tilleuls, 69003 Lyon; the phone number is 04 72 00 00 00",
    "trap_instructions": "Do not include any customer quote or testimonial, and no statistic, percentage, rating or count of patients. Numbers are limited to the facts above.",
    "links": ["[Prendre rendez-vous](https://example.com/rendez-vous)"]
  }
]
```

Rules for writing briefs:
- Businesses, people, streets and phones are fictional. French phones `0X XX XX XX XX` with `00` blocks, English phones `555-01XX`; postcodes may be real city postcodes.
- `facts` lists **every** number the text may state, each in the words the text should use. T1 briefs hold only incidental numbers (founding year, postcode, phone, a headcount at most).
- `trap_instructions` translates each trap: T1 "no statistic, percentage, rating or count beyond the facts"; T2 "no customer quote or testimonial"; T3 "under the section '## …', describe exactly two parallel services as `###` subsections, not three"; T4 "the section '## …' runs about N words (at least 450, at least twice any other section); the other sections stay under M words"; T5 "do not mention any price, offer, free trial, quote request or appointment"; T6 "where a business would put figures, state only non-numeric claims such as …".
- T5 briefs carry `links` to informational pages only (no booking or pricing link).
- The Claude and adapted files get briefs too, so every file's facts are written down before its text exists.

- [ ] **Step 3: Write the manifest skeleton**

`bench/ci-corpus/hard/manifest.json` with the top-level block of Task 2 and one entry per file: `id`, `file`, `author`, `locale`, `sector`, `traps`, `grid` (T3), `source` (adapted), and `figures` / `quotes` / `offers` / `claims` pre-filled from the brief (`figures` keys are the numbers, phrases the exact wording the brief asks for). They are corrected against the real texts in Tasks 5-6.

- [ ] **Step 4: Validate the skeleton**

```bash
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools php bench/ci-corpus/check.php hard
```

Expected: exit 1, with 12 `file … is missing` errors and **no other manifest error** (no trap-count error, no schema error). Fix anything else.

No commit: the corpus is committed at freeze (Task 7).

---

### Task 5: Draft generator and the Gemini / GPT texts

**Files:**
- Create: `bench/ci-corpus/hard/generate.php`, `bench/ci-corpus/hard/hard-02-….md`, `-05-`, `-10-` (gemini), `-03-`, `-06-`, `-11-` (gpt)

**Interfaces:**
- Consumes: `briefs.json`; the plugin's `AIForge\AI\ProviderFactory::make(string $id, ConfigRepository $config): ProviderInterface`, `ProviderInterface::setModel(string)`, `ProviderInterface::complete(string $prompt, array $options): CompletionResult` (`success`, `content`, `error`, `usage`), option `max_tokens`.

- [ ] **Step 1: Write `bench/ci-corpus/hard/generate.php`**

```php
<?php

/**
 * Drafts one corpus file through the plugin's own provider, from its brief.
 *
 *     cd wp-lab && npx wp-env run cli -- timeout 300 wp eval-file \
 *       wp-content/plugins/wp-ai-forge-devtools/bench/ci-corpus/hard/generate.php hard-02-cabinet-kine [overwrite]
 *
 * Refuses to replace an existing file unless "overwrite" is passed: edited
 * drafts are worth more than a fresh one.
 */

declare(strict_types=1);

use AIForge\AI\ProviderFactory;
use AIForge\Config\ConfigRepository;

const MODELS = ['gemini' => ['gemini', 'gemini-3.7-flash'], 'gpt' => ['openai', 'gpt-5.4']];

$id = (string) ($args[0] ?? '');
$overwrite = in_array('overwrite', $args, true);
$briefs = json_decode((string) file_get_contents(__DIR__ . '/briefs.json'), true);
$brief = null;

foreach ((array) $briefs as $candidate) {
    if (($candidate['id'] ?? '') === $id) {
        $brief = $candidate;
    }
}

if ($brief === null) {
    WP_CLI::error("No brief for '{$id}'.");
}

if (!isset(MODELS[$brief['author']])) {
    WP_CLI::error("{$id} is authored by {$brief['author']}; only gemini and gpt drafts are generated.");
}

$target = __DIR__ . "/{$id}.md";

if (file_exists($target) && !$overwrite) {
    WP_CLI::error("{$target} exists. Pass 'overwrite' to replace it.");
}

$prompt = sprintf(
    'Write the full text of a web page for %s, a %s business, in %s, as Markdown: one `#` title, then `##` sections, `###` where natural. About %d words. Tone: a real small business describing itself to prospects, no marketing clichés. It must state exactly these facts and no other figure: %s. %s Links only as `[text](url)` with example.com URLs. No images, no HTML, no `<` character.',
    $brief['business'],
    $brief['sector'],
    $brief['language'],
    (int) $brief['words'],
    $brief['facts'],
    $brief['trap_instructions']
);

if (!empty($brief['links'])) {
    $prompt .= ' Use these links where natural: ' . implode(', ', $brief['links']) . '.';
}

[$providerId, $model] = MODELS[$brief['author']];
$provider = ProviderFactory::make($providerId, new ConfigRepository());
$provider->setModel($model);
$result = $provider->complete($prompt, ['max_tokens' => 8000]);

if (!$result->success || $result->content === null) {
    WP_CLI::error("{$model} failed: " . ($result->error ?? 'empty response'));
}

$markdown = trim((string) preg_replace('/^```(?:markdown|md)?\s*\n|\n```\s*$/', '', trim($result->content))) . "\n";
file_put_contents($target, $markdown);

WP_CLI::success(sprintf('%s written by %s (%d words).', basename($target), $model, str_word_count(strip_tags($markdown))));
```

The prompt is the spec's, filled from the brief; the only addition is the optional link list, so T5 files point at informational pages. Keep the prompt wording otherwise verbatim.

- [ ] **Step 2: Generate the six drafts**

For each of `hard-02-cabinet-kine`, `hard-05-association-quartier`, `hard-10-vet-clinic`, `hard-03-cabinet-comptable`, `hard-06-fromagerie`, `hard-11-infogerance`:

```bash
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli -- timeout 300 wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/ci-corpus/hard/generate.php <id>
```

Expected: `Success: <id>.md written by <model>`. If wp-cli hangs, see the environment note.

- [ ] **Step 3: Check each draft against its brief**

Run `check.php hard`. For each gemini/gpt file:
- Correct the manifest's `figures` / `offers` / `quotes` / `claims` phrases to the exact wording the text uses (the ground truth follows the text, as long as the text obeys the brief).
- If the text breaks the brief (a third service, an invented stat, a testimonial, a `<`), either regenerate (`overwrite`) or edit the text by hand. The `author` field keeps recording who wrote the first draft.
- Iterate until `check.php` prints no ERROR line for these six files. Read the lint warnings: remove a bold line or rephrase the last line when a warning names a pattern the file does not need.

---

### Task 6: Claude and adapted texts, precondition pass, STOP

**Files:**
- Create: `bench/ci-corpus/hard/hard-01-….md`, `-04-`, `-09-` (claude), `-07-`, `-08-`, `-12-` (adapted)
- Modify: `bench/ci-corpus/hard/manifest.json`

- [ ] **Step 1: Write the three Claude texts from their briefs**

`hard-01-menuiserie-atelier` (fr, T1+T3), `hard-04-saas-planning` (en, T2+T4), `hard-09-plomberie` (fr, T4+T5). Same constraints as the generation prompt: a real small business describing itself, no marketing clichés, only the brief's facts, `[text](https://example.com/…)` links, no `<`. The T4 dense section should read as a real page's heavy section (a detailed "how we work", a process walk-through), not as padding.

- [ ] **Step 2: Write the three adapted texts**

For each source (`3915/0` and the two picked in Task 4), dump the snapshot:

```bash
docker exec "$M" mariadb -uroot -ppassword wordpress -N --raw -e \
  "SELECT payload FROM wp_aiforge_task_payloads WHERE task_id=3915 AND payload_type='markdown_snapshot_0'" > /tmp/3915-0.md
```

Rewrite it to carry its traps and **anonymise**: fictional business name, people, address, phone, every URL to `https://example.com/…`. Keep the real copy's texture; that is what makes adapted files worth having. For 08, keep "formations … régulières" as the T6 bait (the brand name may be replaced) and reduce the parallel section to two items for T3.

- [ ] **Step 3: Complete the manifest and pass every precondition**

Fill each entry's `figures`, `quotes`, `offers`, `claims`, `grid` from the actual texts. Run:

```bash
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools php bench/ci-corpus/check.php hard
```

Expected: 12 lines of `id author words traps`, **no ERROR**, last line `clean`, exit 0. Word total between 15,000 and 20,000. Review every remaining lint warning and either fix the file or keep it on purpose.

- [ ] **Step 4: STOP. Hand the texts to the user**

Do not commit the corpus. Report to the user, in French: the 12 file paths, author, locale, traps, word count, total words, the adapted sources, and any lint warning kept on purpose. Ask them to read the texts for realism. **Nothing is frozen, and no task below starts, before their approval.** Apply their edits, re-run `check.php` until `clean`.

---

### Task 7: Freeze the corpus

**Files:**
- Create: `tests/Unit/Campaign/HardCorpusTest.php`
- Commit: `bench/ci-corpus/hard/*`, `bench/ci-corpus/scan-2026-09-26/`

- [ ] **Step 1: Write the acceptance test (criterion 1)**

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\CorpusPreconditions;
use AIForge\DevTools\Tests\Unit\TestCase;

/**
 * The frozen corpus itself: schema, invariants, and every file's preconditions.
 */
class HardCorpusTest extends TestCase
{
    private static function manifest(): CorpusManifest
    {
        return CorpusManifest::fromName(\dirname(__DIR__, 3) . '/bench/ci-corpus', 'hard');
    }

    public function testManifestHasNoErrors(): void
    {
        $this->assertSame([], self::manifest()->errors());
    }

    public function testEveryFilePassesItsPreconditions(): void
    {
        $manifest = self::manifest();
        $failures = [];

        foreach ($manifest->entries as $entry) {
            foreach (CorpusPreconditions::check($entry, (string) $manifest->markdown($entry)) as $error) {
                $failures[] = "{$entry->id}: {$error}";
            }
        }

        $this->assertSame([], $failures);
    }
}
```

- [ ] **Step 2: Run it**

Run: `--filter HardCorpusTest`. Expected: PASS (2 tests).

- [ ] **Step 3: Commit the frozen corpus**

```bash
git add bench/ci-corpus/hard bench/ci-corpus/scan-2026-09-26 tests/Unit/Campaign/HardCorpusTest.php
git commit -m "Freeze the hard Content Integrator corpus

Twelve scarce-content pages (3 each by Claude, Gemini, GPT and adapted
from real client copy), with the manifest that records every figure,
quote and offer they state. Read for realism before freezing.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Fixture extraction

**Files:**
- Create: `bench/ci-corpus/fixtures/extract.php`, `tests/fixtures/trap/*`, `tests/Unit/Campaign/TrapFixtures.php`

**Interfaces:**
- Produces: trait `TrapFixtures` with `fixture(string $name): string`, reading `tests/fixtures/trap/<name>`, failing the test when the file is missing.

- [ ] **Step 1: Write `bench/ci-corpus/fixtures/extract.php`**

```php
<?php

/**
 * Extracts TrapChecker fixtures from stored runs, once. For each llm_generate
 * task id: its result_content and integration_plan, and its parent's
 * markdown_snapshot and template_snapshot.
 *
 *     cd wp-lab && npx wp-env run cli -- timeout 120 wp eval-file \
 *       wp-content/plugins/wp-ai-forge-devtools/bench/ci-corpus/fixtures/extract.php 6687 7014
 */

declare(strict_types=1);

global $wpdb;

$out = \dirname(__DIR__, 3) . '/tests/fixtures/trap';

if (!wp_mkdir_p($out)) {
    WP_CLI::error("Cannot create {$out}.");
}

$payload = static function (int $taskId, string $type) use ($wpdb): ?string {
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT payload FROM {$wpdb->prefix}aiforge_task_payloads WHERE task_id = %d AND payload_type = %s",
        $taskId,
        $type
    ));

    return $value === null ? null : (string) $value;
};

foreach ($args as $arg) {
    $id = (int) $arg;
    $parent = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT parent_id FROM {$wpdb->prefix}aiforge_tasks WHERE id = %d AND task_type = 'llm_generate'",
        $id
    ));

    if ($parent === 0) {
        WP_CLI::warning("{$id}: not an llm_generate task with a parent, skipped.");
        continue;
    }

    $files = [
        'html' => $payload($id, 'result_content'),
        'md' => $payload($parent, 'markdown_snapshot'),
        'tpl.html' => $payload($parent, 'template_snapshot'),
        'plan.txt' => $payload($id, 'integration_plan'),
    ];

    foreach ($files as $extension => $content) {
        if ($content === null) {
            WP_CLI::warning("{$id}: no {$extension}.");
            continue;
        }

        file_put_contents("{$out}/{$id}.{$extension}", $content);
    }

    WP_CLI::log("{$id} (parent {$parent}) extracted.");
}
```

- [ ] **Step 2: Extract**

```bash
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli -- timeout 300 wp eval-file \
  wp-content/plugins/wp-ai-forge-devtools/bench/ci-corpus/fixtures/extract.php \
  5859 6041 6687 6699 6774 6843 7014 7128 7144 7151 7173 7188 7195 7335
```

Expected: 14 `extracted` lines, `.html`, `.md`, `.tpl.html` for each (a `.plan.txt` may be missing on old runs).

- [ ] **Step 3: Confirm each fixture carries its case**

```bash
cd tests/fixtures/trap
for f in 6687 6699 6774 6843; do grep -o 'is-style-stat-value[^>]*>[^<]*' $f.html | sed 's/.*>//' | tr '\n' ' '; echo " ← $f"; grep -ci 'quinze minutes' $f.md; done
for f in 7128 7144 7151 7173 7188 7195; do grep -c '>100%<' $f.html; done
grep -o 'is-style-stat-value[^>]*>[^<]*' 5859.html | sed 's/.*>//' | tr '\n' ' '; echo
grep -ci 'deux interviews' 7014.md
grep -A14 'is-style-testimonial' 6041.html | grep -o '<strong>[^<]*' | head -1
grep -A14 'is-style-testimonial' 7335.html | sed 's/<[^>]*>//g' | grep -v '^\s*$' | head -3
```

Expected: `15 min` in each 6687-family output and `Quinze minutes` in its source; one `100%` per 7128-family output; `01 02 03` among 5859's stat-values; `deux interviews` present in 7014's source (correction 1); `Les Numériques` as 6041's first attribution; 7335's testimonial is source prose credited to "Les fondateurs de Volta". Drop any id that does not carry its case (the data providers below list only the ids that do); if 7335 does not hold, extract 7334/7331/7327/7326 and use the first that does.

- [ ] **Step 4: Write the fixture trait**

`tests/Unit/Campaign/TrapFixtures.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

trait TrapFixtures
{
    private function fixture(string $name): string
    {
        $path = \dirname(__DIR__, 2) . '/fixtures/trap/' . $name;

        if (!is_readable($path)) {
            $this->fail("Missing fixture {$name}; extract it with bench/ci-corpus/fixtures/extract.php.");
        }

        return (string) file_get_contents($path);
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add bench/ci-corpus/fixtures/extract.php tests/fixtures/trap tests/Unit/Campaign/TrapFixtures.php
git commit -m "Extract TrapChecker fixtures from stored runs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: GenerationDocument

**Files:**
- Create: `src/Campaign/GenerationDocument.php`
- Test: `tests/Unit/Campaign/GenerationDocumentTest.php`

**Interfaces:**
- Produces: `new GenerationDocument(string $html)` with `hasStatSlot(): bool`, `hasTestimonialSlot(): bool`, `hasButton(): bool`, `hasCardGrid(int $minCards = 3): bool`, `statValues(): string[]`, `testimonials(): array<int, array{quote: string, attribution: ?string}>`, `cardGrids(): array<int, string[]>` (card headings per equal-width columns block holding ≥ 2 headings, stat rows excluded), `buttons(): string[]`, `headings(): string[]`, `links(): string[]`, `plainText(): string`, static `textOf(\DOMNode): string`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\GenerationDocument;
use AIForge\DevTools\Tests\Unit\TestCase;

class GenerationDocumentTest extends TestCase
{
    use TrapFixtures;

    public function testStatValuesAreDecoded(): void
    {
        $doc = new GenerationDocument('<!-- wp:paragraph --><p class="has-text-align-center is-style-stat-value">15&nbsp;000</p><!-- /wp:paragraph --><p class="is-style-stat-value">l&#8217;an</p>');

        $this->assertSame(['15 000', 'l’an'], $doc->statValues());
        $this->assertTrue($doc->hasStatSlot());
    }

    public function testTestimonialAttributionIsTheNextStrongBeforeTheNextTestimonial(): void
    {
        $html = '<div><p class="is-style-testimonial">« Un travail soigné. »</p>'
            . '<div class="wp-block-group"><figure><img src="x" alt=""/></figure><div><p><strong>Marie Durand</strong></p><p>Lyon</p></div></div>'
            . '<p class="is-style-testimonial">"Rapide."</p><p>Sans nom</p></div>';

        $this->assertSame(
            [['quote' => 'Un travail soigné.', 'attribution' => 'Marie Durand'], ['quote' => 'Rapide.', 'attribution' => null]],
            (new GenerationDocument($html))->testimonials()
        );
    }

    public function testCiteInsideTheTestimonialIsTheAttribution(): void
    {
        $html = '<blockquote class="wp-block-quote is-style-testimonial"><p>Très bien.</p><cite>Paul</cite></blockquote>';

        $this->assertSame([['quote' => 'Très bien.', 'attribution' => 'Paul']], (new GenerationDocument($html))->testimonials());
    }

    public function testCardGridsSkipStatRowsAndWeightedColumns(): void
    {
        $cards = '<div class="wp-block-columns"><div class="wp-block-column"><h3>Sur mesure</h3><p>a</p></div><div class="wp-block-column"><h3>Restauration</h3></div></div>';
        $stats = '<div class="wp-block-columns"><div class="wp-block-column"><p class="is-style-stat-value">1998</p><h3>x</h3></div><div class="wp-block-column"><p class="is-style-stat-value">3</p><h3>y</h3></div></div>';
        $media = '<div class="wp-block-columns"><div class="wp-block-column" style="flex-basis:55%"><h2>Texte</h2></div><div class="wp-block-column" style="flex-basis:45%"><h3>z</h3></div></div>';

        $doc = new GenerationDocument($cards . $stats . $media);

        $this->assertSame([['Sur mesure', 'Restauration']], $doc->cardGrids());
        $this->assertFalse($doc->hasCardGrid(3));
        $this->assertTrue($doc->hasCardGrid(2));
    }

    public function testTheLandingTemplateHasEverySlot(): void
    {
        $template = new GenerationDocument($this->fixture('7014.tpl.html'));

        $this->assertTrue($template->hasStatSlot());
        $this->assertTrue($template->hasTestimonialSlot());
        $this->assertTrue($template->hasCardGrid(3));
        $this->assertTrue($template->hasButton());
    }

    public function testButtonsHeadingsAndLinks(): void
    {
        $html = '<h1>Titre</h1><div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/a?x=1&amp;y=2">Nous <strong>contacter</strong></a></div></div><h2>Deux</h2>';
        $doc = new GenerationDocument($html);

        $this->assertSame(['Nous contacter'], $doc->buttons());
        $this->assertSame(['Titre', 'Deux'], $doc->headings());
        $this->assertSame(['https://example.com/a?x=1&y=2'], $doc->links());
    }

    public function testPlainTextSeparatesBlocksButNotInlineTags(): void
    {
        $doc = new GenerationDocument('<p>Un <strong>mot</strong>s</p><p>Deux</p><!-- wp:paragraph -->');

        $this->assertSame('Un mots Deux', $doc->plainText());
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `--filter GenerationDocumentTest`. Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Campaign/GenerationDocument.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Read-only view of serialized Gutenberg HTML: a template or a generation.
 *
 * Parsed with DOMDocument rather than parse_blocks() so the checks run without
 * WordPress. Slots are recognised by the classes their blocks render.
 */
final class GenerationDocument
{
    private const INLINE = ['a', 'abbr', 'b', 'code', 'em', 'i', 'mark', 's', 'small', 'span', 'strong', 'sub', 'sup', 'u'];
    private const HEADINGS = './/h1|.//h2|.//h3|.//h4|.//h5|.//h6';
    private const QUOTE_MARKS = '/^[\s"\'«»“”„‹›]+|[\s"\'«»“”„‹›]+$/u';

    private DOMXPath $xpath;
    private DOMNode $root;

    public function __construct(string $html)
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="aiforge-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($document);
        $this->root = $this->xpath->query('//div[@id="aiforge-root"]')->item(0) ?? $document;
    }

    public function hasStatSlot(): bool
    {
        return $this->withClass('is-style-stat-value') !== [];
    }

    public function hasTestimonialSlot(): bool
    {
        return $this->withClass('is-style-testimonial') !== [];
    }

    public function hasButton(): bool
    {
        return $this->withClass('wp-block-button__link') !== [];
    }

    public function hasCardGrid(int $minCards = 3): bool
    {
        foreach ($this->cardGrids() as $cards) {
            if (\count($cards) >= $minCards) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function statValues(): array
    {
        return array_map(self::textOf(...), $this->withClass('is-style-stat-value'));
    }

    /**
     * @return array<int, array{quote: string, attribution: ?string}>
     */
    public function testimonials(): array
    {
        $testimonials = [];

        foreach ($this->withClass('is-style-testimonial') as $element) {
            $cite = $this->xpath->query('.//cite', $element)->item(0);
            $quote = self::textOf($element);
            $attribution = null;

            if ($cite !== null) {
                $attribution = self::textOf($cite);
                $quote = trim(str_replace($attribution, '', $quote));
            } else {
                $attribution = $this->attributionAfter($element);
            }

            $testimonials[] = [
                'quote' => (string) preg_replace(self::QUOTE_MARKS, '', $quote),
                'attribution' => $attribution === '' ? null : $attribution,
            ];
        }

        return $testimonials;
    }

    /**
     * Card headings of every equal-width columns block that holds at least two
     * of them. A stat row is not a card grid, whatever its columns hold.
     *
     * @return array<int, string[]>
     */
    public function cardGrids(): array
    {
        $grids = [];

        foreach ($this->withClass('wp-block-columns') as $columns) {
            $cols = [];

            foreach ($columns->childNodes as $child) {
                if ($child instanceof DOMElement && self::hasClass($child, 'wp-block-column')) {
                    $cols[] = $child;
                }
            }

            if (\count($cols) < 2) {
                continue;
            }

            foreach ($cols as $col) {
                if (str_contains($col->getAttribute('style'), 'flex-basis')) {
                    continue 2;
                }
            }

            if ($this->xpath->query('.//*[' . self::classPredicate('is-style-stat-value') . ']', $columns)->length > 0) {
                continue;
            }

            $cards = [];

            foreach ($cols as $col) {
                $heading = $this->xpath->query(self::HEADINGS, $col)->item(0);

                if ($heading !== null) {
                    $cards[] = self::textOf($heading);
                }
            }

            if (\count($cards) >= 2) {
                $grids[] = $cards;
            }
        }

        return $grids;
    }

    /**
     * @return string[]
     */
    public function buttons(): array
    {
        return array_map(self::textOf(...), $this->withClass('wp-block-button__link'));
    }

    /**
     * @return string[]
     */
    public function headings(): array
    {
        $headings = [];

        foreach ($this->xpath->query(self::HEADINGS, $this->root) as $heading) {
            $headings[] = self::textOf($heading);
        }

        return $headings;
    }

    /**
     * @return string[]
     */
    public function links(): array
    {
        $links = [];

        foreach ($this->xpath->query('.//a[@href]', $this->root) as $anchor) {
            if ($anchor instanceof DOMElement) {
                $links[] = trim($anchor->getAttribute('href'));
            }
        }

        return $links;
    }

    public function plainText(): string
    {
        return self::textOf($this->root);
    }

    public static function textOf(DOMNode $node): string
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}"], ' ', self::collect($node));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function collect(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return (string) $node->nodeValue;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE && $node->nodeType !== XML_DOCUMENT_NODE) {
            return '';
        }

        if (!$node->hasChildNodes()) {
            return $node->nodeName === 'br' ? ' ' : '';
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= self::collect($child);
        }

        return \in_array($node->nodeName, self::INLINE, true) ? $text : ' ' . $text . ' ';
    }

    private function attributionAfter(DOMElement $testimonial): ?string
    {
        for ($node = $testimonial->nextSibling; $node !== null; $node = $node->nextSibling) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (self::hasClass($node, 'is-style-testimonial')) {
                return null;
            }

            $strong = $node->nodeName === 'strong' ? $node : $this->xpath->query('.//strong', $node)->item(0);

            if ($strong !== null) {
                return self::textOf($strong);
            }
        }

        return null;
    }

    /**
     * @return DOMElement[]
     */
    private function withClass(string $class): array
    {
        $elements = [];

        foreach ($this->xpath->query('.//*[' . self::classPredicate($class) . ']', $this->root) as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    private static function classPredicate(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
    }

    private static function hasClass(DOMElement $element, string $class): bool
    {
        return \in_array($class, preg_split('/\s+/', $element->getAttribute('class')) ?: [], true);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `--filter GenerationDocumentTest`. Expected: PASS. If `testTheLandingTemplateHasEverySlot` fails on `hasCardGrid(3)`, dump `cardGrids()` on the fixture before touching the rule: the landing's features section is three equal columns with an H3 each (lines 70-118 of the lab template).

- [ ] **Step 5: Commit**

```bash
git add src/Campaign/GenerationDocument.php tests/Unit/Campaign/GenerationDocumentTest.php
git commit -m "Read slots out of Gutenberg HTML without WordPress

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: TrapChecker, stat checks

**Files:**
- Create: `src/Campaign/TrapChecker.php`
- Test: `tests/Unit/Campaign/TrapCheckerStatsTest.php`

**Interfaces:**
- Consumes: `CheckSettings`, `ManifestEntry`, `MarkdownDocument`, `GenerationDocument`, `TextTools`.
- Produces: `new TrapChecker(CheckSettings $settings = new CheckSettings())`; `check(ManifestEntry $entry, string $markdown, string $template, string $output): array<string, array{status: string, findings: string[]}>` keyed by `TrapChecker::CHECKS` in order: `stat_grounding`, `stat_has_figure`, `stat_cram`, `testimonial_grounding`, `testimonial_repurposed`, `card_count`, `cta_grounding`, `orphan_headings`, `content_retention`, `link_preservation`. Constants `PASS = 'pass'`, `FAIL = 'fail'`, `NA = 'n/a'`, `TRAP_OF_CHECK` (check → trap it was built for: stat_grounding T1, testimonial_grounding T2, card_count T3, content_retention T4, cta_grounding T5, stat_has_figure T6).

This task writes the class with the three stat checks; `check()` returns `n/a` for the other seven until Tasks 11-12 fill them. The report treats `n/a` as "not applicable", so nothing downstream breaks meanwhile.

**Rules:**
- All three stat checks are `n/a` when the **template** has no `is-style-stat-value`.
- `stat_grounding`: for every stat-value except a bare `01`-`09`, every number (`TextTools::numbers`) must be a manifest figure; a value with `%` (or `pour cent` / `percent`) also needs a phrase of that figure containing one of those. Runs on every file (the manifest's figures are complete); the report also scores it on T1 files alone.
- `stat_has_figure`: a stat-value holding no number (step numbers excluded) is a finding. Reported as its own column, labelled "model + addendum" in the report, never as fabrication.
- `stat_cram`: a stat-value over `cramChars` characters (mb) is a finding.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\ManifestEntry;
use AIForge\Campaign\TrapChecker;
use AIForge\DevTools\Tests\Unit\TestCase;

class TrapCheckerStatsTest extends TestCase
{
    use TrapFixtures;

    private function checkRun(string $id, array $figures): array
    {
        $entry = ManifestEntry::fromArray(['id' => $id, 'figures' => $figures]);

        return (new TrapChecker())->check($entry, $this->fixture("{$id}.md"), $this->fixture("{$id}.tpl.html"), $this->fixture("{$id}.html"));
    }

    private static function mentions(array $check, string $needle): bool
    {
        foreach ($check['findings'] as $finding) {
            if (str_contains($finding, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function quinzeMinutes(): array
    {
        return ['6687' => ['6687'], '6699' => ['6699'], '6774' => ['6774'], '6843' => ['6843']];
    }

    /**
     * @dataProvider quinzeMinutes
     */
    public function testFifteenMinutesIsGroundedByQuinzeMinutes(string $id): void
    {
        $result = $this->checkRun($id, ['15' => ['Quinze minutes']]);

        $this->assertFalse(self::mentions($result['stat_grounding'], '«15 min»'));
    }

    public function testFifteenMinutesIsNotAStepNumber(): void
    {
        $result = $this->checkRun('6687', []);

        $this->assertSame(TrapChecker::FAIL, $result['stat_grounding']['status']);
        $this->assertTrue(self::mentions($result['stat_grounding'], '«15 min»'));
    }

    public function testNumberWordsGroundDigits(): void
    {
        // 7014's source: "Dix ans après, Volta compte sept personnes", "deux interviews presse nationale".
        $result = $this->checkRun('7014', [
            '2014' => ['en 2014'], '2015' => ['l\'année suivante'], '7' => ['sept personnes'], '10' => ['Dix ans'],
            '15000' => ['15 000'], '18' => ['18 mois'], '2' => ['deux interviews'], '2400' => ['2 400'],
        ]);

        $this->assertSame(TrapChecker::PASS, $result['stat_grounding']['status'], implode("\n", $result['stat_grounding']['findings']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hundredPercent(): array
    {
        return ['7128' => ['7128'], '7144' => ['7144'], '7151' => ['7151'], '7173' => ['7173'], '7188' => ['7188'], '7195' => ['7195']];
    }

    /**
     * @dataProvider hundredPercent
     */
    public function testHundredPercentIsInvented(string $id): void
    {
        // Every other number the source states is a figure; 100% is not among them.
        $figures = [];
        foreach (\AIForge\Campaign\TextTools::numbers((new \AIForge\Campaign\MarkdownDocument($this->fixture("{$id}.md")))->plainText()) as $n) {
            $figures[$n] = ['(source)'];
        }

        $result = $this->checkRun($id, $figures);

        $this->assertSame(TrapChecker::FAIL, $result['stat_grounding']['status']);
        $this->assertTrue(self::mentions($result['stat_grounding'], '«100%»'));
    }

    public function testStepNumbersAreExempt(): void
    {
        $result = $this->checkRun('5859', []);

        foreach (['«01»', '«02»', '«03»'] as $step) {
            $this->assertFalse(self::mentions($result['stat_grounding'], $step));
            $this->assertFalse(self::mentions($result['stat_has_figure'], $step));
        }
    }

    public function testALabelInStatStyleIsReportedApartFromFabrication(): void
    {
        $template = '<p class="is-style-stat-value">240+</p>';
        $output = '<p class="is-style-stat-value">Formations régulières</p><p class="is-style-stat-value">Un interlocuteur unique, du brief à la livraison.</p>';

        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), "# T\n", $template, $output);

        $this->assertSame(TrapChecker::PASS, $result['stat_grounding']['status']);
        $this->assertSame(TrapChecker::FAIL, $result['stat_has_figure']['status']);
        $this->assertTrue(self::mentions($result['stat_has_figure'], '«Formations régulières»'));
        $this->assertSame(TrapChecker::FAIL, $result['stat_cram']['status']);
        $this->assertCount(1, $result['stat_cram']['findings']);
    }

    public function testPercentNeedsAPercentPhrase(): void
    {
        $template = '<p class="is-style-stat-value">98%</p>';
        $entry = ManifestEntry::fromArray(['id' => 'x', 'figures' => ['100' => ['100 m²']]]);

        $result = (new TrapChecker())->check($entry, "# T\n", $template, '<p class="is-style-stat-value">100%</p>');

        $this->assertSame(TrapChecker::FAIL, $result['stat_grounding']['status']);
        $this->assertTrue(self::mentions($result['stat_grounding'], 'percentage'));
    }

    public function testNoStatSlotInTheTemplateIsNotApplicable(): void
    {
        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), "# T\n", '<p>no slot</p>', '<p class="is-style-stat-value">100%</p>');

        foreach (['stat_grounding', 'stat_has_figure', 'stat_cram'] as $check) {
            $this->assertSame(TrapChecker::NA, $result[$check]['status']);
        }
    }

    public function testEveryCheckIsPresentInOrder(): void
    {
        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), "# T\n", '', '');

        $this->assertSame(TrapChecker::CHECKS, array_keys($result));
    }
}
```

The 7014 figure phrases above are placeholders for the words the source really uses: open `tests/fixtures/trap/7014.md` and put the exact phrases (the test only reads the keys, but a reader should see the truth). Remove any id from `hundredPercent()` whose source does state 100 as a percentage.

- [ ] **Step 2: Run to verify they fail**

Run: `--filter TrapCheckerStatsTest`. Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Campaign/TrapChecker.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Mechanical checks of one generation against its corpus file's ground truth.
 *
 * A check whose slot the template lacks is n/a, never pass: a template swap
 * must not make a model look careful.
 */
final class TrapChecker
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const NA = 'n/a';

    public const CHECKS = [
        'stat_grounding',
        'stat_has_figure',
        'stat_cram',
        'testimonial_grounding',
        'testimonial_repurposed',
        'card_count',
        'cta_grounding',
        'orphan_headings',
        'content_retention',
        'link_preservation',
    ];

    /** The trap each check was built for; the report also scores it on those files alone. */
    public const TRAP_OF_CHECK = [
        'stat_grounding' => 'T1',
        'testimonial_grounding' => 'T2',
        'card_count' => 'T3',
        'content_retention' => 'T4',
        'cta_grounding' => 'T5',
        'stat_has_figure' => 'T6',
    ];

    /** The generation prompt asks for zero-padded step numbers as decorative filler. */
    private const STEP_NUMBER = '/^0[1-9]$/';

    private const PERCENT_MARKERS = ['%', 'pour cent', 'pourcent', 'percent'];

    public function __construct(
        private readonly CheckSettings $settings = new CheckSettings(),
    ) {
    }

    /**
     * @return array<string, array{status: string, findings: string[]}>
     */
    public function check(ManifestEntry $entry, string $markdown, string $template, string $output): array
    {
        $templateDoc = new GenerationDocument($template);
        $outputDoc = new GenerationDocument($output);
        $hasStats = $templateDoc->hasStatSlot();

        $results = array_fill_keys(self::CHECKS, self::na());
        $results['stat_grounding'] = $hasStats ? $this->statGrounding($entry, $outputDoc) : self::na();
        $results['stat_has_figure'] = $hasStats ? $this->statHasFigure($outputDoc) : self::na();
        $results['stat_cram'] = $hasStats ? $this->statCram($outputDoc) : self::na();

        return $results;
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function statGrounding(ManifestEntry $entry, GenerationDocument $output): array
    {
        $findings = [];

        foreach ($output->statValues() as $value) {
            if (preg_match(self::STEP_NUMBER, trim($value)) === 1) {
                continue;
            }

            foreach (TextTools::numbers($value) as $number) {
                if (!$entry->isFigure($number)) {
                    $findings[] = "«{$value}»: {$number} is stated nowhere in the source";
                } elseif (self::isPercent($value) && !self::anyPercent($entry->phrasesFor($number))) {
                    $findings[] = "«{$value}»: the source never gives {$number} as a percentage";
                }
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function statHasFigure(GenerationDocument $output): array
    {
        $findings = [];

        foreach ($output->statValues() as $value) {
            if (preg_match(self::STEP_NUMBER, trim($value)) !== 1 && TextTools::numbers($value) === []) {
                $findings[] = "«{$value}» holds no figure";
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function statCram(GenerationDocument $output): array
    {
        $findings = [];

        foreach ($output->statValues() as $value) {
            if (mb_strlen(trim($value)) > $this->settings->cramChars) {
                $findings[] = \sprintf('«%s»: %d characters', $value, mb_strlen(trim($value)));
            }
        }

        return self::outcome($findings);
    }

    private static function isPercent(string $text): bool
    {
        $folded = TextTools::fold($text);

        foreach (self::PERCENT_MARKERS as $marker) {
            if (str_contains($folded, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $phrases
     */
    private static function anyPercent(array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (self::isPercent($phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $findings
     * @return array{status: string, findings: string[]}
     */
    private static function outcome(array $findings): array
    {
        return ['status' => $findings === [] ? self::PASS : self::FAIL, 'findings' => array_values($findings)];
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private static function na(): array
    {
        return ['status' => self::NA, 'findings' => []];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `--filter TrapCheckerStatsTest`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Campaign/TrapChecker.php tests/Unit/Campaign/TrapCheckerStatsTest.php
git commit -m "Check every stat-value figure against the corpus ground truth

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: TrapChecker, testimonial checks

**Files:**
- Modify: `src/Campaign/TrapChecker.php`
- Test: `tests/Unit/Campaign/TrapCheckerTestimonialTest.php`

**Rules** (both checks `n/a` when the template has no `is-style-testimonial`):
- For each output testimonial with at least one content word: it is **real** if some manifest quote's text overlaps its words by ≥ `quoteMatch` and its attribution is absent or matches that quote's attribution. Otherwise its best overlap against every source sentence **and every pair of consecutive sentences** decides: below `quoteMatch` → `testimonial_grounding` finding "matches no source sentence"; at or above with an attribution that matches no manifest quote → `testimonial_grounding` finding "credited to «…», who is quoted nowhere"; at or above with no attribution → `testimonial_repurposed` finding.
- Attribution match: the manifest attribution's words of 3+ letters are all in the output attribution's (so "Marie Durand, gérante" matches "Marie Durand"); when the manifest attribution has no such word, compare folded strings.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\GenerationDocument;
use AIForge\Campaign\ManifestEntry;
use AIForge\Campaign\TrapChecker;
use AIForge\DevTools\Tests\Unit\TestCase;

class TrapCheckerTestimonialTest extends TestCase
{
    use TrapFixtures;

    private const REAL_QUOTE = 'Les AirFlow Pro sont une référence dans leur catégorie. La qualité audio rivalise avec des casques à 600€.';

    private function airflowEntry(): ManifestEntry
    {
        return ManifestEntry::fromArray(['id' => '6041', 'quotes' => [
            ['text' => self::REAL_QUOTE, 'attribution' => 'Les Numériques'],
            ['text' => 'Confort exceptionnel et autonomie démente. Mon nouveau compagnon de voyage.', 'attribution' => 'CNET'],
            ['text' => 'Probablement le meilleur casque Bluetooth de 2025.', 'attribution' => 'What Hi-Fi?'],
        ]]);
    }

    private function check(ManifestEntry $entry, string $id, ?string $output = null): array
    {
        return (new TrapChecker())->check($entry, $this->fixture("{$id}.md"), $this->fixture("{$id}.tpl.html"), $output ?? $this->fixture("{$id}.html"));
    }

    public function testARealQuoteWithItsRealAttributionPasses(): void
    {
        $result = $this->check($this->airflowEntry(), '6041');

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status'], implode("\n", $result['testimonial_grounding']['findings']));
        $this->assertSame(TrapChecker::PASS, $result['testimonial_repurposed']['status']);
    }

    public function testAnInventedQuoteFails(): void
    {
        $html = $this->fixture('6041.html');
        $this->assertStringContainsString(self::REAL_QUOTE, (new GenerationDocument($html))->testimonials()[0]['quote']);

        $invented = str_replace(self::REAL_QUOTE, 'Un casque qui a changé ma façon de travailler au quotidien, je le recommande sans hésiter.', $html);
        $result = $this->check($this->airflowEntry(), '6041', $invented);

        $this->assertSame(TrapChecker::FAIL, $result['testimonial_grounding']['status']);
        $this->assertStringContainsString('matches no source sentence', $result['testimonial_grounding']['findings'][0]);
    }

    public function testSourceWordsCreditedToSomeoneQuotedNowhereFail(): void
    {
        $result = $this->check(ManifestEntry::fromArray(['id' => '7335']), '7335');

        $this->assertSame(TrapChecker::FAIL, $result['testimonial_grounding']['status']);
        $this->assertStringContainsString('who is quoted nowhere', implode("\n", $result['testimonial_grounding']['findings']));
    }

    public function testSourceProseWithoutAttributionIsRepurposed(): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# T\n\nNous réparons les vélos de tout le quartier depuis la rue principale.\n";
        $output = '<p class="is-style-testimonial">« Nous réparons les vélos de tout le quartier. »</p>';

        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), $markdown, $template, $output);

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status']);
        $this->assertSame(TrapChecker::FAIL, $result['testimonial_repurposed']['status']);
    }

    public function testAQuoteSpanningTwoSentencesIsMatched(): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# T\n\nLe chantier a duré trois semaines. Tout était propre chaque soir, sans exception.\n";
        $output = '<p class="is-style-testimonial">« Le chantier a duré trois semaines. Tout était propre chaque soir. »</p>';

        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), $markdown, $template, $output);

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status']);
    }

    public function testAnAttributionWithARoleStillMatches(): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# T\n\n> « Un atelier sérieux et ponctuel. » — Marie Durand\n";
        $entry = ManifestEntry::fromArray(['id' => 'x', 'quotes' => [['text' => 'Un atelier sérieux et ponctuel.', 'attribution' => 'Marie Durand']]]);
        $output = '<p class="is-style-testimonial">« Un atelier sérieux et ponctuel. »</p><p><strong>Marie Durand, gérante</strong></p>';

        $this->assertSame(TrapChecker::PASS, (new TrapChecker())->check($entry, $markdown, $template, $output)['testimonial_grounding']['status']);
    }

    public function testNoTestimonialSlotIsNotApplicable(): void
    {
        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), "# T\n", '<p>x</p>', '<p class="is-style-testimonial">« Inventé de toutes pièces. »</p>');

        $this->assertSame(TrapChecker::NA, $result['testimonial_grounding']['status']);
        $this->assertSame(TrapChecker::NA, $result['testimonial_repurposed']['status']);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `--filter TrapCheckerTestimonialTest`. Expected: FAIL (the checks return `n/a`).

- [ ] **Step 3: Implement**

In `check()`, after the stat checks:

```php
        if ($templateDoc->hasTestimonialSlot()) {
            [$results['testimonial_grounding'], $results['testimonial_repurposed']] =
                $this->testimonials($entry, new MarkdownDocument($markdown), $outputDoc);
        }
```

Add to the class:

```php
    /**
     * @return array{0: array{status: string, findings: string[]}, 1: array{status: string, findings: string[]}}
     */
    private function testimonials(ManifestEntry $entry, MarkdownDocument $source, GenerationDocument $output): array
    {
        $sentences = $source->sentences();
        $candidates = [];

        foreach ($sentences as $i => $sentence) {
            $candidates[] = TextTools::contentWords($sentence);

            if (isset($sentences[$i + 1])) {
                $candidates[] = TextTools::contentWords($sentence . ' ' . $sentences[$i + 1]);
            }
        }

        $invented = [];
        $repurposed = [];

        foreach ($output->testimonials() as $testimonial) {
            $words = TextTools::contentWords($testimonial['quote']);

            if ($words === [] || $this->isRealQuote($entry, $words, $testimonial['attribution'])) {
                continue;
            }

            $label = '«' . mb_strimwidth($testimonial['quote'], 0, 60, '…') . '»';
            $best = 0.0;

            foreach ($candidates as $candidate) {
                $best = max($best, TextTools::overlap($words, $candidate));
            }

            if ($best < $this->settings->quoteMatch) {
                $invented[] = "{$label}: matches no source sentence";
            } elseif ($testimonial['attribution'] !== null && !$this->isKnownAttribution($entry, $testimonial['attribution'])) {
                $invented[] = "{$label}: source words credited to «{$testimonial['attribution']}», who is quoted nowhere";
            } else {
                $repurposed[] = "{$label}: source prose styled as a testimonial";
            }
        }

        return [self::outcome($invented), self::outcome($repurposed)];
    }

    /**
     * @param string[] $words
     */
    private function isRealQuote(ManifestEntry $entry, array $words, ?string $attribution): bool
    {
        foreach ($entry->quotes as $quote) {
            if (TextTools::overlap($words, TextTools::contentWords($quote['text'])) < $this->settings->quoteMatch) {
                continue;
            }

            if ($attribution === null || self::attributionMatches($attribution, $quote['attribution'])) {
                return true;
            }
        }

        return false;
    }

    private function isKnownAttribution(ManifestEntry $entry, string $attribution): bool
    {
        foreach ($entry->quotes as $quote) {
            if (self::attributionMatches($attribution, $quote['attribution'])) {
                return true;
            }
        }

        return false;
    }

    private static function attributionMatches(string $given, string $expected): bool
    {
        $expectedWords = TextTools::contentWords($expected, 3);

        if ($expectedWords === []) {
            return trim(TextTools::fold($given)) === trim(TextTools::fold($expected));
        }

        return array_diff($expectedWords, TextTools::contentWords($given, 3)) === [];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `--filter 'TrapCheckerTestimonialTest|TrapCheckerStatsTest'`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Campaign/TrapChecker.php tests/Unit/Campaign/TrapCheckerTestimonialTest.php
git commit -m "Check testimonials against the quotes the source really holds

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: TrapChecker, cards, CTA and universal checks

**Files:**
- Modify: `src/Campaign/TrapChecker.php`
- Test: `tests/Unit/Campaign/TrapCheckerStructureTest.php`

**Rules:**
- `card_count` (`n/a` unless the template has a card grid of ≥ 3 and the entry has a `grid`): among the output's card grids, take the one whose headings match the most `grid.items` (a heading matches an item when its content words overlap the item's by ≥ `orphanOverlap`). No matching grid → pass (the items were rendered outside a grid: nothing stretched). More cards than items → fail, listing the cards.
- `cta_grounding` (`n/a` unless the template has a button): each output button label passes when its folded words equal a `generic_cta` entry's, or when every content word is a content word of some `offers` phrase or of `cta_vocabulary`. The finding names the stray words.
- `orphan_headings` (every run): an output heading with content words, overlapping every source heading and every bold lead by less than `orphanOverlap`, is a finding.
- `content_retention` (every run): per source section (`[intro]` included), recall of its body's 5-word shingles in the output's plain text; below `retention` is a finding `"<heading>: 0.62"`. A section with no shingle is skipped.
- `link_preservation` (every run): every source href must be an output href (exact, after HTML decoding).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CheckSettings;
use AIForge\Campaign\ManifestEntry;
use AIForge\Campaign\TrapChecker;
use AIForge\DevTools\Tests\Unit\TestCase;

class TrapCheckerStructureTest extends TestCase
{
    private const GRID_TEMPLATE = '<div class="wp-block-columns"><div class="wp-block-column"><h3>A</h3></div><div class="wp-block-column"><h3>B</h3></div><div class="wp-block-column"><h3>C</h3></div></div>'
        . '<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link">Go</a></div></div>';

    private const SOURCE = "# Atelier Brun\n\nMenuiserie de quartier, meubles en chêne massif pour toute la maison.\n\n## Nos deux ateliers\n\n### Sur mesure\n\nNous dessinons et fabriquons chaque meuble pour la pièce qui l'attend, du placard sous l'escalier à la bibliothèque du salon.\n\n### Restauration\n\n**Tables anciennes**\n\nNous reprenons les tables de famille, les chaises paillées et les commodes abîmées par le temps.\n\n[Nous écrire](https://example.com/contact)\n";

    private function entry(array $extra = []): ManifestEntry
    {
        return ManifestEntry::fromArray($extra + [
            'id' => 'x',
            'traps' => ['T3', 'T5'],
            'grid' => ['section' => 'Nos deux ateliers', 'items' => ['Sur mesure', 'Restauration']],
            'offers' => [],
        ]);
    }

    private function checker(): TrapChecker
    {
        return new TrapChecker(new CheckSettings(ctaVocabulary: ['réserver', 'votre', 'nous']));
    }

    private function grid(string ...$headings): string
    {
        $cols = array_map(static fn (string $h): string => "<div class=\"wp-block-column\"><h3>{$h}</h3><p>texte</p></div>", $headings);

        return '<div class="wp-block-columns">' . implode('', $cols) . '</div>';
    }

    public function testTwoCardsForTwoItemsPass(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, self::GRID_TEMPLATE, $this->grid('Sur mesure', 'Restauration'));

        $this->assertSame(TrapChecker::PASS, $result['card_count']['status']);
    }

    public function testAThirdCardFails(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, self::GRID_TEMPLATE, $this->grid('Sur mesure', 'Restauration', 'Conseil déco'));

        $this->assertSame(TrapChecker::FAIL, $result['card_count']['status']);
        $this->assertSame(['3 cards for 2 items: Sur mesure | Restauration | Conseil déco'], $result['card_count']['findings']);
    }

    public function testItemsRenderedOutsideAGridPass(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, self::GRID_TEMPLATE, '<h2>Nos deux ateliers</h2><p>Sur mesure, restauration.</p>');

        $this->assertSame(TrapChecker::PASS, $result['card_count']['status']);
    }

    public function testCardCountNeedsAGridInTheTemplateAndTheEntry(): void
    {
        $noGrid = $this->checker()->check($this->entry(), self::SOURCE, '<p>x</p>', $this->grid('A', 'B', 'C'));
        $noItems = $this->checker()->check(ManifestEntry::fromArray(['id' => 'x']), self::SOURCE, self::GRID_TEMPLATE, $this->grid('A', 'B', 'C'));

        $this->assertSame(TrapChecker::NA, $noGrid['card_count']['status']);
        $this->assertSame(TrapChecker::NA, $noItems['card_count']['status']);
    }

    /**
     * @return array<string, array{string, string[], string}>
     */
    public static function ctaCases(): array
    {
        return [
            'generic invitation' => ['Contactez-nous', [], TrapChecker::PASS],
            'generic with different case and spacing' => ['  EN SAVOIR PLUS ', [], TrapChecker::PASS],
            'offer word with a tolerated verb' => ['Réserver votre bilan', ['bilan initial'], TrapChecker::PASS],
            'offer word absent from offers' => ['Réserver un bilan', [], TrapChecker::FAIL],
            'invented trial' => ['Démarrer l\'essai gratuit', [], TrapChecker::FAIL],
        ];
    }

    /**
     * @dataProvider ctaCases
     * @param string[] $offers
     */
    public function testCtaGrounding(string $label, array $offers, string $expected): void
    {
        $output = "<div class=\"wp-block-button\"><a class=\"wp-block-button__link\">{$label}</a></div>";

        $result = $this->checker()->check($this->entry(['offers' => $offers]), self::SOURCE, self::GRID_TEMPLATE, $output);

        $this->assertSame($expected, $result['cta_grounding']['status'], implode("\n", $result['cta_grounding']['findings']));
    }

    public function testCtaNeedsAButtonInTheTemplate(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, '<p>x</p>', '<a class="wp-block-button__link">Essai gratuit</a>');

        $this->assertSame(TrapChecker::NA, $result['cta_grounding']['status']);
    }

    public function testOrphanHeadings(): void
    {
        $output = '<h1>Atelier Brun</h1><h2>Nos ateliers</h2><h3>Tables anciennes</h3><h2>Pourquoi nous choisir</h2>';

        $result = $this->checker()->check($this->entry(), self::SOURCE, '', $output);

        $this->assertSame(['«Pourquoi nous choisir»'], $result['orphan_headings']['findings']);
    }

    public function testContentRetentionFlagsTheDroppedSection(): void
    {
        $output = '<h1>Atelier Brun</h1><p>Menuiserie de quartier, meubles en chêne massif pour toute la maison.</p>'
            . '<p>Nous dessinons et fabriquons chaque meuble pour la pièce qui l\'attend, du placard sous l\'escalier à la bibliothèque du salon.</p>';

        $result = $this->checker()->check($this->entry(), self::SOURCE, '', $output);

        $this->assertSame(TrapChecker::FAIL, $result['content_retention']['status']);
        $this->assertCount(1, $result['content_retention']['findings']);
        $this->assertStringStartsWith('Nos deux ateliers: 0.', $result['content_retention']['findings'][0]);
    }

    public function testLinkPreservation(): void
    {
        $kept = $this->checker()->check($this->entry(), self::SOURCE, '', '<a href="https://example.com/contact">x</a>');
        $lost = $this->checker()->check($this->entry(), self::SOURCE, '', '<a href="https://example.com/autre">x</a>');

        $this->assertSame(TrapChecker::PASS, $kept['link_preservation']['status']);
        $this->assertSame(['https://example.com/contact'], $lost['link_preservation']['findings']);
    }
}
```

In `testContentRetentionFlagsTheDroppedSection`, the output keeps the intro and the "Sur mesure" paragraph but drops "Restauration", so the `Nos deux ateliers` section's recall lands near 0.5 and the intro's at 1.0.

- [ ] **Step 2: Run to verify they fail**

Run: `--filter TrapCheckerStructureTest`. Expected: FAIL.

- [ ] **Step 3: Implement**

In `check()`, before `return $results;`:

```php
        $source = new MarkdownDocument($markdown);

        if ($templateDoc->hasCardGrid(3) && $entry->grid !== null) {
            $results['card_count'] = $this->cardCount($entry->grid['items'], $outputDoc);
        }

        if ($templateDoc->hasButton()) {
            $results['cta_grounding'] = $this->ctaGrounding($entry, $outputDoc);
        }

        $results['orphan_headings'] = $this->orphanHeadings($source, $outputDoc);
        $results['content_retention'] = $this->contentRetention($source, $outputDoc);
        $results['link_preservation'] = $this->linkPreservation($source, $outputDoc);
```

(Move the testimonial block's `new MarkdownDocument($markdown)` to reuse `$source`, declared once above both.)

Add:

```php
    /**
     * @param string[] $items
     * @return array{status: string, findings: string[]}
     */
    private function cardCount(array $items, GenerationDocument $output): array
    {
        $itemWords = array_map(static fn (string $item): array => TextTools::contentWords($item), $items);
        $best = null;
        $bestHits = 0;

        foreach ($output->cardGrids() as $cards) {
            $hits = 0;

            foreach ($cards as $card) {
                if ($this->matchesAny(TextTools::contentWords($card), $itemWords)) {
                    $hits++;
                }
            }

            if ($hits > $bestHits) {
                $best = $cards;
                $bestHits = $hits;
            }
        }

        if ($best === null || \count($best) <= \count($items)) {
            return self::outcome([]);
        }

        return self::outcome([\sprintf('%d cards for %d items: %s', \count($best), \count($items), implode(' | ', $best))]);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function ctaGrounding(ManifestEntry $entry, GenerationDocument $output): array
    {
        $generic = array_map(static fn (string $cta): string => implode(' ', TextTools::words($cta)), $this->settings->genericCta);
        $allowed = [];

        foreach (array_merge($entry->offers, $this->settings->ctaVocabulary) as $phrase) {
            foreach (TextTools::contentWords($phrase) as $word) {
                $allowed[$word] = true;
            }
        }

        $findings = [];

        foreach ($output->buttons() as $label) {
            $words = TextTools::words($label);

            if ($words === [] || \in_array(implode(' ', $words), $generic, true)) {
                continue;
            }

            $stray = array_values(array_filter(
                TextTools::contentWords($label),
                static fn (string $word): bool => !isset($allowed[$word])
            ));

            if ($stray !== []) {
                $findings[] = "«{$label}»: " . implode(', ', $stray) . ' named in no offer';
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function orphanHeadings(MarkdownDocument $source, GenerationDocument $output): array
    {
        $references = [];

        foreach ($source->headings() as $heading) {
            $references[] = TextTools::contentWords($heading['text']);
        }

        foreach ($source->boldLeads() as $lead) {
            $references[] = TextTools::contentWords($lead);
        }

        $findings = [];

        foreach ($output->headings() as $heading) {
            $words = TextTools::contentWords($heading);

            if ($words !== [] && !$this->matchesAny($words, $references)) {
                $findings[] = "«{$heading}»";
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function contentRetention(MarkdownDocument $source, GenerationDocument $output): array
    {
        $kept = array_flip(TextTools::shingles($output->plainText()));
        $findings = [];

        foreach ($source->sections() as $section) {
            $shingles = TextTools::shingles(MarkdownDocument::toPlain($section['body']));

            if ($shingles === []) {
                continue;
            }

            $recall = \count(array_filter($shingles, static fn (string $s): bool => isset($kept[$s]))) / \count($shingles);

            if ($recall < $this->settings->retention) {
                $findings[] = \sprintf('%s: %.2f', $section['heading'], $recall);
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function linkPreservation(MarkdownDocument $source, GenerationDocument $output): array
    {
        return self::outcome(array_values(array_diff(array_unique($source->links()), $output->links())));
    }

    /**
     * @param string[] $words
     * @param array<int, string[]> $references
     */
    private function matchesAny(array $words, array $references): bool
    {
        foreach ($references as $reference) {
            if (TextTools::overlap($words, $reference) >= $this->settings->orphanOverlap) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 4: Run all TrapChecker tests**

Run: `--filter TrapChecker`. Expected: PASS (stats, testimonial and structure suites).

- [ ] **Step 5: Commit**

```bash
git add src/Campaign/TrapChecker.php tests/Unit/Campaign/TrapCheckerStructureTest.php
git commit -m "Check card count, CTA grounding, headings, retention and links

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Plan excerpt and trap report

**Files:**
- Create: `src/Campaign/PlanExcerpt.php`, `src/Campaign/TrapReport.php`
- Test: `tests/Unit/Campaign/PlanExcerptTest.php`, `tests/Unit/Campaign/TrapReportTest.php`

**Interfaces:**
- Produces:
  - `PlanExcerpt::extract(string $plan): array{mapping: string, notes: string}`.
  - `new TrapReport(array $rows)`, each row `array{task_id: int, root_id: int, combo: string, file: string, author: string, traps: string[], checks: ?array<string, array{status: string, findings: string[]}>, mapping: string, notes: string}` (`checks` null = not evaluated). Methods: `byCombo()`, `byRepeat()`, `byAuthor()`, `onTrapFiles()`: each `array<string, array<string, array{pass: int, fail: int, na: int}>>` (group → check → tally); `notEvaluated(): int[]`; `failures(): array<int, array{task_id: int, file: string, check: string, findings: string[], mapping: string, notes: string}>`; static `cell(array{pass:int, fail:int, na:int}): string` (`"k/n"`, `"n/a"` when n = 0); `toArray(): array`; `toMarkdown(): string`. Repeat groups are keyed `"<combo> #<root_id>"`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Campaign/PlanExcerptTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\PlanExcerpt;
use AIForge\DevTools\Tests\Unit\TestCase;

class PlanExcerptTest extends TestCase
{
    use TrapFixtures;

    public function testExtractsMappingAndNotesFromARealPlan(): void
    {
        $excerpt = PlanExcerpt::extract($this->fixture('7014.plan.txt'));

        $this->assertStringStartsWith('[intro] → [0] hero', $excerpt['mapping']);
        $this->assertStringNotContainsString('NOTES', $excerpt['mapping']);
        $this->assertStringContainsString('2 interviews', $excerpt['notes']);
        $this->assertStringNotContainsString('MAPPING_JSON', $excerpt['notes']);
    }

    public function testMissingBlocksAreEmpty(): void
    {
        $this->assertSame(['mapping' => '', 'notes' => ''], PlanExcerpt::extract('(planning failed or returned invalid output)'));
    }
}
```

`tests/Unit/Campaign/TrapReportTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\TrapChecker;
use AIForge\Campaign\TrapReport;
use AIForge\DevTools\Tests\Unit\TestCase;

class TrapReportTest extends TestCase
{
    private static function checks(array $statuses): array
    {
        $checks = [];

        foreach (TrapChecker::CHECKS as $check) {
            $status = $statuses[$check] ?? TrapChecker::PASS;
            $checks[$check] = ['status' => $status, 'findings' => $status === TrapChecker::FAIL ? ["{$check} finding"] : []];
        }

        return $checks;
    }

    private static function row(int $task, int $root, string $combo, string $file, array $traps, ?array $checks, string $author = 'claude'): array
    {
        return ['task_id' => $task, 'root_id' => $root, 'combo' => $combo, 'file' => $file, 'author' => $author, 'traps' => $traps, 'checks' => $checks, 'mapping' => "map {$task}", 'notes' => "notes {$task}"];
    }

    private function report(): TrapReport
    {
        return new TrapReport([
            self::row(1, 10, 'g:b:l', 'hard-01', ['T1'], self::checks(['stat_grounding' => TrapChecker::FAIL])),
            self::row(2, 10, 'g:b:l', 'hard-02', ['T2'], self::checks(['card_count' => TrapChecker::NA])),
            self::row(3, 11, 'g:b:l', 'hard-01', ['T1'], self::checks([]), 'gemini'),
            self::row(4, 11, 'g:b:l', 'hard-02', ['T2'], null),
        ]);
    }

    public function testTalliesByCombo(): void
    {
        $tally = $this->report()->byCombo()['g:b:l'];

        $this->assertSame(['pass' => 2, 'fail' => 1, 'na' => 0], $tally['stat_grounding']);
        $this->assertSame(['pass' => 2, 'fail' => 0, 'na' => 1], $tally['card_count']);
    }

    public function testTalliesByRepeat(): void
    {
        $byRepeat = $this->report()->byRepeat();

        $this->assertSame(['g:b:l #10', 'g:b:l #11'], array_keys($byRepeat));
        $this->assertSame(['pass' => 1, 'fail' => 0, 'na' => 0], $byRepeat['g:b:l #11']['stat_grounding']);
    }

    public function testOnTrapFilesCountsOnlyTheTrapsOwnFiles(): void
    {
        $tally = $this->report()->onTrapFiles()['g:b:l'];

        // stat_grounding belongs to T1: only rows 1 and 3.
        $this->assertSame(['pass' => 1, 'fail' => 1, 'na' => 0], $tally['stat_grounding']);
        // testimonial_grounding belongs to T2: only row 2 (row 4 is not evaluated).
        $this->assertSame(['pass' => 1, 'fail' => 0, 'na' => 0], $tally['testimonial_grounding']);
        $this->assertArrayNotHasKey('orphan_headings', $tally);
    }

    public function testByAuthor(): void
    {
        $this->assertSame(['claude', 'gemini'], array_keys($this->report()->byAuthor()));
    }

    public function testNotEvaluatedAndFailures(): void
    {
        $report = $this->report();

        $this->assertSame([4], $report->notEvaluated());
        $this->assertSame(
            [['task_id' => 1, 'file' => 'hard-01', 'check' => 'stat_grounding', 'findings' => ['stat_grounding finding'], 'mapping' => 'map 1', 'notes' => 'notes 1']],
            $report->failures()
        );
    }

    public function testCell(): void
    {
        $this->assertSame('2/3', TrapReport::cell(['pass' => 2, 'fail' => 1, 'na' => 4]));
        $this->assertSame('n/a', TrapReport::cell(['pass' => 0, 'fail' => 0, 'na' => 4]));
    }

    public function testMarkdownShowsKOverNAndTaskIdsOnly(): void
    {
        $markdown = $this->report()->toMarkdown();

        $this->assertStringContainsString('| g:b:l | 2/3 |', $markdown);
        $this->assertStringContainsString('stat_grounding: 1', $markdown);
        $this->assertStringContainsString('Not evaluated: 4', $markdown);
        $this->assertStringNotContainsString('map 1', $markdown);
        $this->assertStringNotContainsString('%', $markdown);
    }

    public function testArrayCarriesThePlanNextToFailures(): void
    {
        $array = $this->report()->toArray();

        $this->assertSame('map 1', $array['failures'][0]['mapping']);
        $this->assertSame([4], $array['not_evaluated']);
        $this->assertSame('1/2', $array['on_trap_files']['g:b:l']['stat_grounding']);
        $this->assertSame('2/3', $array['by_combo']['g:b:l']['stat_grounding']);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `--filter 'PlanExcerptTest|TrapReportTest'`. Expected: FAIL.

- [ ] **Step 3: Implement `src/Campaign/PlanExcerpt.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * The MAPPING and NOTES blocks of a stored integration plan. Most losses in a
 * campaign are planner decisions, so a failed check ships with them.
 */
final class PlanExcerpt
{
    /**
     * @return array{mapping: string, notes: string}
     */
    public static function extract(string $plan): array
    {
        return [
            'mapping' => self::block($plan, 'MAPPING', ['NOTES', 'MAPPING_JSON']),
            'notes' => self::block($plan, 'NOTES', ['MAPPING_JSON']),
        ];
    }

    /**
     * @param string[] $next Headers that end the block.
     */
    private static function block(string $plan, string $header, array $next): string
    {
        $pattern = '/^' . $header . '\s*$\R(.*?)(?=^(?:' . implode('|', $next) . ')\s*$|\z)/ms';

        return preg_match($pattern, $plan, $m) === 1 ? trim($m[1]) : '';
    }
}
```

- [ ] **Step 4: Implement `src/Campaign/TrapReport.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Trap check outcomes of a campaign, tallied as k/n. Never percentages:
 * twelve files do not make a rate.
 */
final class TrapReport
{
    /**
     * @param array<int, array{task_id: int, root_id: int, combo: string, file: string, author: string, traps: string[], checks: ?array<string, array{status: string, findings: string[]}>, mapping: string, notes: string}> $rows
     */
    public function __construct(
        public readonly array $rows,
    ) {
    }

    /**
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    public function byCombo(): array
    {
        return $this->tally(static fn (array $row): string => $row['combo']);
    }

    /**
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    public function byRepeat(): array
    {
        return $this->tally(static fn (array $row): string => $row['combo'] . ' #' . $row['root_id']);
    }

    /**
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    public function byAuthor(): array
    {
        return $this->tally(static fn (array $row): string => $row['author']);
    }

    /**
     * Each trap check counted on the files written to carry its trap only.
     *
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    public function onTrapFiles(): array
    {
        return $this->tally(
            static fn (array $row): string => $row['combo'],
            static fn (array $row, string $check): bool => isset(TrapChecker::TRAP_OF_CHECK[$check])
                && \in_array(TrapChecker::TRAP_OF_CHECK[$check], $row['traps'], true)
        );
    }

    /**
     * @return int[]
     */
    public function notEvaluated(): array
    {
        return array_values(array_map(
            static fn (array $row): int => $row['task_id'],
            array_filter($this->rows, static fn (array $row): bool => $row['checks'] === null)
        ));
    }

    /**
     * @return array<int, array{task_id: int, file: string, check: string, findings: string[], mapping: string, notes: string}>
     */
    public function failures(): array
    {
        $failures = [];

        foreach ($this->rows as $row) {
            foreach ($row['checks'] ?? [] as $check => $outcome) {
                if ($outcome['status'] === TrapChecker::FAIL) {
                    $failures[] = [
                        'task_id' => $row['task_id'],
                        'file' => $row['file'],
                        'check' => $check,
                        'findings' => $outcome['findings'],
                        'mapping' => $row['mapping'],
                        'notes' => $row['notes'],
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array{pass: int, fail: int, na: int} $tally
     */
    public static function cell(array $tally): string
    {
        $n = $tally['pass'] + $tally['fail'];

        return $n === 0 ? 'n/a' : "{$tally['pass']}/{$n}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'by_combo' => self::cells($this->byCombo()),
            'on_trap_files' => self::cells($this->onTrapFiles()),
            'by_repeat' => self::cells($this->byRepeat()),
            'by_author' => self::cells($this->byAuthor()),
            'not_evaluated' => $this->notEvaluated(),
            'failures' => $this->failures(),
            'rows' => $this->rows,
        ];
    }

    public function toMarkdown(): string
    {
        $lines = ['### Trap checks (k/n; stat_has_figure measures model + addendum)', ''];

        foreach (['All files' => $this->byCombo(), 'On each trap\'s own files' => $this->onTrapFiles(), 'By repeat' => $this->byRepeat(), 'By author' => $this->byAuthor()] as $title => $groups) {
            $lines[] = "#### {$title}";
            $lines[] = '';
            $lines[] = '| group | ' . implode(' | ', TrapChecker::CHECKS) . ' |';
            $lines[] = '|---' . str_repeat('|---', \count(TrapChecker::CHECKS)) . '|';

            foreach ($groups as $group => $checks) {
                $cells = array_map(
                    static fn (string $check): string => isset($checks[$check]) ? self::cell($checks[$check]) : '-',
                    TrapChecker::CHECKS
                );
                $lines[] = "| {$group} | " . implode(' | ', $cells) . ' |';
            }

            $lines[] = '';
        }

        $byCheck = [];

        foreach ($this->failures() as $failure) {
            $byCheck[$failure['check']][] = $failure['task_id'];
        }

        $lines[] = 'Failed checks (task ids; findings, MAPPING and NOTES are in the JSON):';

        foreach ($byCheck as $check => $ids) {
            $lines[] = "- {$check}: " . implode(', ', array_unique($ids));
        }

        if ($this->notEvaluated() !== []) {
            $lines[] = 'Not evaluated: ' . implode(', ', $this->notEvaluated());
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param callable(array): string $groupOf
     * @param (callable(array, string): bool)|null $counts
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    private function tally(callable $groupOf, ?callable $counts = null): array
    {
        $tallies = [];

        foreach ($this->rows as $row) {
            if ($row['checks'] === null) {
                continue;
            }

            $group = $groupOf($row);

            foreach (TrapChecker::CHECKS as $check) {
                if ($counts !== null && !$counts($row, $check)) {
                    continue;
                }

                $tallies[$group][$check] ??= ['pass' => 0, 'fail' => 0, 'na' => 0];
                $status = $row['checks'][$check]['status'] ?? TrapChecker::NA;
                $key = match ($status) {
                    TrapChecker::PASS => 'pass',
                    TrapChecker::FAIL => 'fail',
                    default => 'na',
                };
                $tallies[$group][$check][$key]++;
            }
        }

        return $tallies;
    }

    /**
     * @param array<string, array<string, array{pass: int, fail: int, na: int}>> $groups
     * @return array<string, array<string, string>>
     */
    private static function cells(array $groups): array
    {
        return array_map(
            static fn (array $checks): array => array_map(self::cell(...), $checks),
            $groups
        );
    }
}
```

`testMarkdownShowsKOverNAndTaskIdsOnly` expects `| g:b:l | 2/3 |`: the first column after the group is `stat_grounding` (2 pass, 1 fail over rows 1-3).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `--filter 'PlanExcerptTest|TrapReportTest'`. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Campaign/PlanExcerpt.php src/Campaign/TrapReport.php tests/Unit/Campaign/PlanExcerptTest.php tests/Unit/Campaign/TrapReportTest.php
git commit -m "Tally trap checks as k/n with the plan next to every failure

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Run evaluation, provenance, report and comparator changes

**Files:**
- Create: `src/Campaign/TrapEvaluation.php`, `src/Campaign/PluginCommit.php`
- Modify: `src/Campaign/RunResult.php`, `src/Campaign/CampaignReport.php:15-24,116-138`, `src/Campaign/BaselineComparator.php:288-292`
- Test: `tests/Unit/Campaign/TrapEvaluationTest.php`, `tests/Unit/Campaign/PluginCommitTest.php`, `tests/Unit/Campaign/CampaignReportTest.php`, `tests/Unit/Campaign/BaselineComparatorTest.php`

**Interfaces:**
- Consumes: `TrapChecker`, `TrapReport`, `PlanExcerpt`, `CorpusManifest`.
- Produces:
  - `RunResult` gains trailing optional constructor params `?int $rootId = null, ?string $sourceFile = null`; `toArray()` adds `root_id` and `file` when not null.
  - `TrapEvaluation::runsFromResults(RunResult[] $results): array<int, array{task_id:int, root_id:int, combo:string, file:?string, scored:bool}>`; `TrapEvaluation::runsFromReport(array $report): array` (same shape; throws `InvalidArgumentException` when a run lacks `root_id` or `file`, naming the task); `TrapEvaluation::evaluate(CorpusManifest $manifest, array $runs, callable $load): TrapReport` where `$load(int $taskId): ?array{markdown: string, template: string, output: string, plan: string}`.
  - `PluginCommit::read(string $pluginDir): ?string`.
  - `CampaignReport::__construct(string $label, ?int $sourceBatchId, int $filesPerCombo, array $runs, ?string $corpus = null, array $provenance = [], ?TrapReport $traps = null)`; `toArray()` adds `corpus`, `provenance`, `traps`.
  - `BaselineComparator::compare()` throws `InvalidArgumentException` when the two reports' `corpus` differ (a batch report has `corpus` null).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Campaign/TrapEvaluationTest.php` (uses a temp corpus with one file, like `CorpusManifestTest`):

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\RunResult;
use AIForge\Campaign\TrapChecker;
use AIForge\Campaign\TrapEvaluation;
use AIForge\DevTools\Tests\Unit\TestCase;
use InvalidArgumentException;

class TrapEvaluationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/trap-eval-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/hard-01.md", "# T\n");
        file_put_contents("{$this->dir}/manifest.json", json_encode(['files' => [[
            'id' => 'hard-01', 'author' => 'gemini', 'traps' => ['T1'], 'figures' => [],
        ]]]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private static function artifacts(): array
    {
        return [
            'markdown' => "# T\n",
            'template' => '<p class="is-style-stat-value">1</p>',
            'output' => '<p class="is-style-stat-value">100%</p>',
            'plan' => "MAPPING\n[intro] → [0] hero\n\nNOTES\nkeep it short\n",
        ];
    }

    public function testEvaluatesScoredRunsAgainstTheirFile(): void
    {
        $runs = [['task_id' => 5, 'root_id' => 1, 'combo' => 'g:b:l', 'file' => 'hard-01', 'scored' => true]];

        $report = TrapEvaluation::evaluate(CorpusManifest::load($this->dir), $runs, static fn (int $id): array => self::artifacts());
        $row = $report->rows[0];

        $this->assertSame('gemini', $row['author']);
        $this->assertSame(['T1'], $row['traps']);
        $this->assertSame(TrapChecker::FAIL, $row['checks']['stat_grounding']['status']);
        $this->assertSame('[intro] → [0] hero', $row['mapping']);
        $this->assertSame('keep it short', $row['notes']);
    }

    public function testUnscoredUnknownOrUnloadableRunsAreNotEvaluated(): void
    {
        $runs = [
            ['task_id' => 5, 'root_id' => 1, 'combo' => 'c', 'file' => 'hard-01', 'scored' => false],
            ['task_id' => 6, 'root_id' => 1, 'combo' => 'c', 'file' => 'nope', 'scored' => true],
            ['task_id' => 7, 'root_id' => 1, 'combo' => 'c', 'file' => null, 'scored' => true],
            ['task_id' => 8, 'root_id' => 1, 'combo' => 'c', 'file' => 'hard-01', 'scored' => true],
        ];
        $loaded = [];

        $report = TrapEvaluation::evaluate(CorpusManifest::load($this->dir), $runs, static function (int $id) use (&$loaded): ?array {
            $loaded[] = $id;
            return null;
        });

        $this->assertSame([5, 6, 7, 8], $report->notEvaluated());
        $this->assertSame([8], $loaded);
    }

    public function testRunsFromResults(): void
    {
        $results = [
            new RunResult(5, 'g:b:l', 'm', 'pass', 90, [], 0.1, false, 1, 'hard-01'),
            new RunResult(6, 'g:b:l', 'm', 'unknown', 0, [], 0.0, true, 1, 'hard-02'),
        ];

        $this->assertSame([
            ['task_id' => 5, 'root_id' => 1, 'combo' => 'g:b:l', 'file' => 'hard-01', 'scored' => true],
            ['task_id' => 6, 'root_id' => 1, 'combo' => 'g:b:l', 'file' => 'hard-02', 'scored' => false],
        ], TrapEvaluation::runsFromResults($results));
    }

    public function testRunsFromReport(): void
    {
        $report = ['combos' => ['g:b:l' => ['runs' => [
            ['task_id' => 5, 'root_id' => 1, 'file' => 'hard-01', 'verdict' => 'pass', 'failed' => false],
            ['task_id' => 6, 'root_id' => 1, 'file' => 'hard-02', 'verdict' => 'unknown', 'failed' => true],
        ]]]];

        $runs = TrapEvaluation::runsFromReport($report);

        $this->assertTrue($runs[0]['scored']);
        $this->assertFalse($runs[1]['scored']);
        $this->assertSame('hard-02', $runs[1]['file']);
    }

    public function testRunsFromAnOldReportAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('task 5');

        TrapEvaluation::runsFromReport(['combos' => ['g:b:l' => ['runs' => [['task_id' => 5, 'verdict' => 'pass']]]]]);
    }
}
```

This requires `RunResult::toArray()` to also emit `failed`, so a report says which runs never generated. Add that in Step 3.

`tests/Unit/Campaign/PluginCommitTest.php`:

```php
<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\PluginCommit;
use AIForge\DevTools\Tests\Unit\TestCase;

class PluginCommitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/commit-' . bin2hex(random_bytes(4));
        mkdir("{$this->dir}/.git/refs/heads", 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function testReadsTheBranchRef(): void
    {
        file_put_contents("{$this->dir}/.git/HEAD", "ref: refs/heads/feat/x\n");
        mkdir("{$this->dir}/.git/refs/heads/feat");
        file_put_contents("{$this->dir}/.git/refs/heads/feat/x", str_repeat('a', 40) . "\n");

        $this->assertSame(str_repeat('a', 40), PluginCommit::read($this->dir));
    }

    public function testFallsBackToPackedRefs(): void
    {
        file_put_contents("{$this->dir}/.git/HEAD", "ref: refs/heads/main\n");
        file_put_contents("{$this->dir}/.git/packed-refs", "# pack-refs\n" . str_repeat('b', 40) . " refs/heads/main\n");

        $this->assertSame(str_repeat('b', 40), PluginCommit::read($this->dir));
    }

    public function testDetachedHead(): void
    {
        file_put_contents("{$this->dir}/.git/HEAD", str_repeat('c', 40) . "\n");

        $this->assertSame(str_repeat('c', 40), PluginCommit::read($this->dir));
    }

    public function testNoRepositoryIsNull(): void
    {
        $this->assertNull(PluginCommit::read($this->dir . '/none'));
    }
}
```

Append to `tests/Unit/Campaign/CampaignReportTest.php`:

```php
    public function testACorpusReportCarriesItsCorpusProvenanceAndTraps(): void
    {
        $traps = new \AIForge\Campaign\TrapReport([]);
        $report = new CampaignReport('hard', null, 12, [], 'hard', ['plugin_commit' => 'abc'], $traps);

        $array = $report->toArray();

        $this->assertNull($array['source_batch']);
        $this->assertSame('hard', $array['corpus']);
        $this->assertSame(['plugin_commit' => 'abc'], $array['provenance']);
        $this->assertSame($traps->toArray(), $array['traps']);
        $this->assertStringContainsString('Corpus hard, 12 file(s) per combo', $report->toMarkdown());
    }

    public function testABatchReportKeepsItsShape(): void
    {
        $array = (new CampaignReport('b', 6223, 2, []))->toArray();

        $this->assertSame(6223, $array['source_batch']);
        $this->assertNull($array['corpus']);
        $this->assertNull($array['traps']);
    }
```

Append to `tests/Unit/Campaign/BaselineComparatorTest.php`:

```php
    public function testRefusesToCompareDifferentCorpora(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('corpus hard with one on source batch 6223');

        BaselineComparator::compare(['corpus' => 'hard', 'combos' => []], ['corpus' => null, 'source_batch' => 6223, 'combos' => []]);
    }

    public function testComparesTwoReportsOnTheSameCorpus(): void
    {
        $this->assertSame([], BaselineComparator::compare(['corpus' => 'hard', 'combos' => []], ['corpus' => 'hard', 'combos' => []]));
    }
```

Check the existing BaselineComparator tests still pass: their arrays have no `corpus` key on either side, so `null === null` and nothing changes.

- [ ] **Step 2: Run to verify they fail**

Run: `--filter 'TrapEvaluationTest|PluginCommitTest|CampaignReportTest|BaselineComparatorTest'`. Expected: FAIL on the new tests only.

- [ ] **Step 3: Modify `RunResult`**

Add after `public readonly bool $failed = false,`:

```php
        public readonly ?int $rootId = null,
        public readonly ?string $sourceFile = null,
```

Document them in the docblock (`@param ?int $rootId The batch root: one per repeat.`, `@param ?string $sourceFile Corpus file id, from the parent task's source_filename meta.`). In `toArray()`, after the axes loop:

```php
        $array['failed'] = $this->failed;

        if ($this->rootId !== null) {
            $array['root_id'] = $this->rootId;
        }

        if ($this->sourceFile !== null) {
            $array['file'] = $this->sourceFile;
        }
```

If an existing test asserts the exact `toArray()` shape, update its expectation with `'failed' => false`.

- [ ] **Step 4: Implement `src/Campaign/PluginCommit.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * The commit a plugin checkout sits on, read from .git without running git
 * (the containers have no git binary).
 */
final class PluginCommit
{
    public static function read(string $pluginDir): ?string
    {
        $git = rtrim($pluginDir, '/') . '/.git';
        $head = @file_get_contents("{$git}/HEAD");

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (!str_starts_with($head, 'ref: ')) {
            return $head !== '' ? $head : null;
        }

        $ref = substr($head, 5);
        $loose = @file_get_contents("{$git}/{$ref}");

        if ($loose !== false) {
            return trim($loose);
        }

        foreach (@file("{$git}/packed-refs") ?: [] as $line) {
            $line = trim($line);

            if (str_ends_with($line, " {$ref}")) {
                return substr($line, 0, 40);
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Implement `src/Campaign/TrapEvaluation.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use InvalidArgumentException;

/**
 * Runs TrapChecker over a campaign's runs. The loader is injected so the
 * campaign (right after collection) and trap-check (offline, from a report)
 * share one path, and so this stays testable without a database.
 */
final class TrapEvaluation
{
    /**
     * @param array<int, array{task_id: int, root_id: int, combo: string, file: ?string, scored: bool}> $runs
     * @param callable(int): ?array{markdown: string, template: string, output: string, plan: string} $load
     */
    public static function evaluate(CorpusManifest $manifest, array $runs, callable $load): TrapReport
    {
        $checker = new TrapChecker($manifest->settings);
        $rows = [];

        foreach ($runs as $run) {
            $entry = $run['file'] !== null ? $manifest->entry($run['file']) : null;
            $artifacts = ($run['scored'] && $entry !== null) ? $load($run['task_id']) : null;
            $plan = PlanExcerpt::extract($artifacts['plan'] ?? '');

            $rows[] = [
                'task_id' => $run['task_id'],
                'root_id' => $run['root_id'],
                'combo' => $run['combo'],
                'file' => $run['file'] ?? '(unknown)',
                'author' => $entry?->author ?? '(unknown)',
                'traps' => $entry?->traps ?? [],
                'checks' => $artifacts === null
                    ? null
                    : $checker->check($entry, $artifacts['markdown'], $artifacts['template'], $artifacts['output']),
                'mapping' => $plan['mapping'],
                'notes' => $plan['notes'],
            ];
        }

        return new TrapReport($rows);
    }

    /**
     * @param RunResult[] $results
     * @return array<int, array{task_id: int, root_id: int, combo: string, file: ?string, scored: bool}>
     */
    public static function runsFromResults(array $results): array
    {
        return array_map(static fn (RunResult $run): array => [
            'task_id' => $run->taskId,
            'root_id' => (int) $run->rootId,
            'combo' => $run->comboKey,
            'file' => $run->sourceFile,
            'scored' => $run->isScored(),
        ], array_values($results));
    }

    /**
     * @param array<string, mixed> $report
     * @return array<int, array{task_id: int, root_id: int, combo: string, file: ?string, scored: bool}>
     */
    public static function runsFromReport(array $report): array
    {
        $runs = [];

        foreach ((array) ($report['combos'] ?? []) as $combo => $data) {
            foreach ((array) ($data['runs'] ?? []) as $run) {
                $taskId = (int) ($run['task_id'] ?? 0);

                if (!isset($run['root_id'], $run['file'])) {
                    throw new InvalidArgumentException(
                        "Run of task {$taskId} carries no root_id or file: this report predates corpus campaigns."
                    );
                }

                $runs[] = [
                    'task_id' => $taskId,
                    'root_id' => (int) $run['root_id'],
                    'combo' => (string) $combo,
                    'file' => (string) $run['file'],
                    'scored' => empty($run['failed']) && ($run['verdict'] ?? 'unknown') !== 'unknown',
                ];
            }
        }

        return $runs;
    }
}
```

Note that the `scored` rule mirrors `RunResult::isScored()`.

- [ ] **Step 6: Modify `CampaignReport`**

Constructor:

```php
    /**
     * @param RunResult[] $runs
     * @param array<string, mixed> $provenance Corpus hashes, template hashes, plugin commit.
     */
    public function __construct(
        public readonly string $label,
        public readonly ?int $sourceBatchId,
        public readonly int $filesPerCombo,
        public readonly array $runs,
        public readonly ?string $corpus = null,
        public readonly array $provenance = [],
        public readonly ?TrapReport $traps = null,
    ) {
    }
```

`toArray()` return block:

```php
        return [
            'label' => $this->label,
            'source_batch' => $this->sourceBatchId,
            'corpus' => $this->corpus,
            'provenance' => $this->provenance,
            'files_per_combo' => $this->filesPerCombo,
            'combos' => $combos,
            'totals' => $this->totals(),
            'traps' => $this->traps?->toArray(),
        ];
```

`toMarkdown()` first sentence:

```php
        $lines[] = \sprintf(
            '%s, %d file(s) per combo, %d run(s)%s.',
            $this->corpus !== null ? "Corpus {$this->corpus}" : "Source batch {$this->sourceBatchId}",
            $this->filesPerCombo,
            \count($this->runs),
            $this->totals()['failed'] > 0
                ? \sprintf(', %d of which never generated', $this->totals()['failed'])
                : ''
        );
```

And before the final `return`, append the trap tables:

```php
        if ($this->traps !== null) {
            $lines[] = '';
            $lines[] = rtrim($this->traps->toMarkdown());
        }
```

- [ ] **Step 7: Modify `BaselineComparator::compare()`**

At the top of `compare()`:

```php
        if (($baseline['corpus'] ?? null) !== ($current['corpus'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf(
                'Cannot compare a report on %s with one on %s.',
                self::describe($baseline),
                self::describe($current)
            ));
        }
```

And add:

```php
    /**
     * @param array<string, mixed> $report
     */
    private static function describe(array $report): string
    {
        return isset($report['corpus'])
            ? 'corpus ' . $report['corpus']
            : 'source batch ' . ($report['source_batch'] ?? '?');
    }
```

Update the class docblock: "Refuses reports from different corpora: comparing combo keys across two corpora would diff unrelated pages."

- [ ] **Step 8: Run the whole suite**

Run the test command without filter. Expected: PASS, the existing count plus every test added so far.

- [ ] **Step 9: Commit**

```bash
git add src/Campaign/TrapEvaluation.php src/Campaign/PluginCommit.php src/Campaign/RunResult.php src/Campaign/CampaignReport.php src/Campaign/BaselineComparator.php tests/Unit/Campaign
git commit -m "Evaluate corpus runs and record their provenance in the report

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Runner and CLI wiring

**Files:**
- Modify: `src/Campaign/CampaignRunner.php:130-213` (createCombo, collect, buildRunResult) plus new methods, `src/Cli/QgCampaignCommand.php`, `src/DevTools.php:78-84`, `CLAUDE.md`
- Create: `src/Cli/TrapCheckCommand.php`
- Test: `tests/Unit/Campaign/CampaignRunnerTest.php` (for `filesMeta`)

**Interfaces:**
- Consumes: everything above.
- Produces:
  - `CampaignRunner::filesMeta(array $sources, int $templateId, string $templateName): array` (static; `$sources` = `array<int, array{id: ?string, markdown: string}>`), sets `source_filename` only when `id` is not null.
  - `CampaignRunner::createCombo(ComboSpec $combo, int $sourceBatchId, int $files, ?string $modelId = null): int` (unchanged signature, now delegates) and `createComboFromSources(ComboSpec $combo, array $sources, ?string $modelId = null): int`.
  - `CampaignRunner::templateHash(string $slug): string`, `CampaignRunner::loadRunArtifacts(int $llmTaskId): ?array{markdown: string, template: string, output: string, plan: string}`.
  - `collect()` fills `RunResult::$rootId` and `$sourceFile`.
  - `wp aiforge-dev qg-campaign --corpus=<name>` and `--collect=<roots-file>`; `wp aiforge-dev trap-check --report=<json>`.

- [ ] **Step 1: Write the failing `filesMeta` tests**

Append to `tests/Unit/Campaign/CampaignRunnerTest.php`:

```php
    // --- Files meta ---

    public function testFilesMetaCarriesTheCorpusIdAsSourceFilename(): void
    {
        $meta = CampaignRunner::filesMeta([['id' => 'hard-01', 'markdown' => "# Atelier Brun\n\nTexte"]], 2138, 'Page d’atterrissage');

        $this->assertSame([[
            'index' => 0,
            'template_id' => 2138,
            'template_name' => 'Page d’atterrissage',
            'content_title' => 'Atelier Brun',
            'draft_title' => null,
            'original_format' => 'markdown',
            'source_filename' => 'hard-01',
        ]], $meta);
    }

    public function testFilesMetaFromABatchHasNoSourceFilename(): void
    {
        $meta = CampaignRunner::filesMeta([['id' => null, 'markdown' => 'no title']], 1, 't');

        $this->assertSame('Untitled', $meta[0]['content_title']);
        $this->assertArrayNotHasKey('source_filename', $meta[0]);
    }
```

Run `--filter CampaignRunnerTest`. Expected: FAIL, `filesMeta` undefined.

- [ ] **Step 2: Refactor `CampaignRunner`**

Replace `createCombo()` with:

```php
    /**
     * Files meta for one batch. A corpus file's id travels as source_filename;
     * the batch executor copies it onto each child task.
     *
     * @param array<int, array{id: ?string, markdown: string}> $sources
     * @return array<int, array<string, mixed>>
     */
    public static function filesMeta(array $sources, int $templateId, string $templateName): array
    {
        $meta = [];

        foreach (array_values($sources) as $i => $source) {
            $title = 'Untitled';

            if (preg_match('/^#\s+(.+)$/m', $source['markdown'], $matches) === 1) {
                $title = trim($matches[1]);
            }

            $row = [
                'index' => $i,
                'template_id' => $templateId,
                'template_name' => $templateName,
                'content_title' => $title,
                'draft_title' => null,
                'original_format' => 'markdown',
            ];

            if ($source['id'] !== null) {
                $row['source_filename'] = $source['id'];
            }

            $meta[] = $row;
        }

        return $meta;
    }

    /**
     * Create one batch task for a combo from a source batch's snapshots.
     */
    public function createCombo(ComboSpec $combo, int $sourceBatchId, int $files, ?string $modelId = null): int
    {
        $sources = [];

        for ($i = 0; $i < $files; $i++) {
            $markdown = $this->payload($sourceBatchId, "markdown_snapshot_{$i}");

            if ($markdown === null) {
                throw new RuntimeException("Batch {$sourceBatchId} has no markdown_snapshot_{$i}; lower --files.");
            }

            $sources[] = ['id' => null, 'markdown' => $markdown];
        }

        return $this->createComboFromSources($combo, $sources, $modelId);
    }

    /**
     * Create one batch task for a combo. Returns the root task ID.
     *
     * @param array<int, array{id: ?string, markdown: string}> $sources
     */
    public function createComboFromSources(ComboSpec $combo, array $sources, ?string $modelId = null): int
    {
        $templateId = $this->resolveTemplateId($combo->templateSlug);
        $template = get_post($templateId);
        $templateContent = $template->post_content;
        $payloads = [];

        foreach (array_values($sources) as $i => $source) {
            $payloads[] = ['type' => "markdown_snapshot_{$i}", 'payload' => $source['markdown']];
            $payloads[] = ['type' => "template_snapshot_{$i}", 'payload' => $templateContent];
        }

        $meta = [
            'agent_id' => 'content-integrator',
            'provider' => $combo->provider,
            'preset' => $combo->preset,
            'batch_name' => 'QG campaign ' . $combo->key() . ($modelId !== null ? ' @' . $modelId : ''),
            'file_count' => \count($sources),
            'create_draft' => false,
            'files_meta' => wp_json_encode(self::filesMeta($sources, $templateId, $template->post_title)),
            self::CAMPAIGN_META_KEY => '1',
        ];
```

…followed by the unchanged tail of the old `createCombo()` (model override merge, REST request, error handling, id extraction). Add the helpers:

```php
    public function templateHash(string $slug): string
    {
        return sha1((string) get_post($this->resolveTemplateId($slug))->post_content);
    }

    /**
     * What a trap check reads for one llm_generate run. Payloads and meta are
     * fetched in separate queries: joining task_meta with payload longtext
     * wedged MySQL in Docker.
     *
     * @return array{markdown: string, template: string, output: string, plan: string}|null
     */
    public function loadRunArtifacts(int $llmTaskId): ?array
    {
        $parentId = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT parent_id FROM {$this->wpdb->prefix}aiforge_tasks WHERE id = %d",
            $llmTaskId
        ));

        if ($parentId === 0) {
            return null;
        }

        $markdown = $this->payload($parentId, 'markdown_snapshot');
        $template = $this->payload($parentId, 'template_snapshot');
        $output = $this->payload($llmTaskId, 'result_content');

        if ($markdown === null || $template === null || $output === null) {
            return null;
        }

        return [
            'markdown' => $markdown,
            'template' => $template,
            'output' => $output,
            'plan' => $this->payload($llmTaskId, 'integration_plan') ?? '',
        ];
    }

    private function payload(int $taskId, string $type): ?string
    {
        $value = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT payload FROM {$this->wpdb->prefix}aiforge_task_payloads WHERE task_id = %d AND payload_type = %s",
            $taskId,
            $type
        ));

        return $value === null ? null : (string) $value;
    }
```

In `collect()`, select `parent_id` too and pass root and file:

```php
                $rows = $this->wpdb->get_results($this->wpdb->prepare(
                    "SELECT id, status, parent_id FROM {$this->wpdb->prefix}aiforge_tasks
                     WHERE root_id = %d AND task_type = 'llm_generate' ORDER BY id",
                    $rootId
                ));

                foreach ($rows as $row) {
                    $results[] = $this->buildRunResult(
                        (int) $row->id,
                        (string) $comboKey,
                        (string) $row->status !== 'completed',
                        (int) $rootId,
                        $this->sourceFileOf((int) $row->parent_id)
                    );
                }
```

with

```php
    private function sourceFileOf(int $parentId): ?string
    {
        $value = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->prefix}aiforge_task_meta WHERE task_id = %d AND meta_key = 'source_filename'",
            $parentId
        ));

        return ($value === null || $value === '') ? null : (string) $value;
    }
```

and `buildRunResult(int $llmId, string $comboKey, bool $failed = false, ?int $rootId = null, ?string $sourceFile = null)` passing `rootId: $rootId, sourceFile: $sourceFile` to `new RunResult(...)`.

Run `--filter CampaignRunnerTest`. Expected: PASS.

- [ ] **Step 3: Wire `QgCampaignCommand`**

Docblock options, after `--source-batch`:

```
     * [--corpus=<name>]
     * : Run the frozen corpus bench/ci-corpus/<name>/ in manifest order instead
     *   of a source batch, and score every run with the trap checks. Mutually
     *   exclusive with --source-batch. --files still caps the count.
     *
     * [--collect=<roots-file>]
     * : Launch nothing: wait for the roots listed in a .roots.json file written
     *   by an interrupted campaign, then build its report.
```

Restructure `__invoke()` around three pieces. Keep `$runner`, `$combos`, `$label`, `$timeout`, `$modelId` parsing as today, then:

```php
            if (isset($assoc['corpus'], $assoc['source-batch'])) {
                WP_CLI::error('--corpus and --source-batch are mutually exclusive.');
            }

            $stamp = gmdate('Ymd-His');
            $requested = isset($assoc['files']) ? (int) $assoc['files'] : null;
            $manifest = null;
            $sourceBatch = null;
            $provenance = [];

            if (isset($assoc['corpus'])) {
                $manifest = CorpusManifest::fromName(AIFORGE_DEV_PATH . 'bench/ci-corpus', (string) $assoc['corpus']);

                if ($manifest->errors() !== []) {
                    WP_CLI::error("Corpus {$manifest->name} is invalid:\n  " . implode("\n  ", $manifest->errors()));
                }

                $entries = \array_slice($manifest->entries, 0, CampaignRunner::fileCount($requested, \count($manifest->entries)));
                $sources = array_map(
                    static fn (ManifestEntry $e): array => ['id' => $e->id, 'markdown' => (string) $manifest->markdown($e)],
                    $entries
                );
                $files = \count($sources);
                $create = static fn (ComboSpec $c): int => $runner->createComboFromSources($c, $sources, $modelId);

                $templates = [];
                foreach ($combos as $combo) {
                    $templates[$combo->templateSlug] = $runner->templateHash($combo->templateSlug);
                }

                $provenance = [
                    'corpus' => $manifest->name,
                    'files' => $manifest->hashes(),
                    'templates' => $templates,
                    'plugin_commit' => PluginCommit::read(WP_PLUGIN_DIR . '/wp-ai-forge'),
                ];
            } else {
                // Existing source-batch branch, unchanged, ending in:
                $create = static fn (ComboSpec $c): int => $runner->createCombo($c, $sourceBatch, $files, $modelId);
            }
```

(`$sourceBatch` resolution, the available-count check and the clamp warning move into the `else` branch as they are today. The corpus branch warns the same way when `--files` exceeds the manifest. Add `use` lines for `CorpusManifest`, `ManifestEntry`, `PluginCommit` and `TrapEvaluation`.)

Replace `launchAndWait()`'s signature with `launchAndWait(CampaignRunner $runner, array $combos, callable $create, int $timeout, callable $onLaunch, array $alreadyLaunched = []): array`. It calls `$create($combo)` instead of `$runner->createCombo(...)`, calls `$onLaunch($rootIdsByCombo)` after each successful launch, and starts with `$rootIdsByCombo = $alreadyLaunched` and every not-yet-finished root of it in `$inFlight` (that is how `--collect` reuses the loop with an empty queue).

The roots file, written on every launch:

```php
        $rootsPath = $this->reportPath($label, $stamp, '.roots.json');
        $onLaunch = function (array $roots) use ($rootsPath, $label, $provenance): void {
            if ($rootsPath !== null) {
                file_put_contents($rootsPath, wp_json_encode([
                    'label' => $label,
                    'corpus' => $provenance['corpus'] ?? null,
                    'provenance' => $provenance,
                    'root_ids_by_combo' => $roots,
                ], JSON_PRETTY_PRINT));
            }
        };
```

`--collect=<file>`: read it, rebuild `$rootIdsByCombo`, `$label`, `$provenance`, and `$manifest` (from `provenance.corpus`), then call `launchAndWait($runner, [], $create, $timeout, $onLaunch, $rootIdsByCombo)` with a `$create` that is never reached.

After collection:

```php
        $runs = $runner->collect($rootIdsByCombo);
        $traps = $manifest === null ? null : TrapEvaluation::evaluate(
            $manifest,
            TrapEvaluation::runsFromResults($runs),
            static fn (int $taskId): ?array => $runner->loadRunArtifacts($taskId)
        );

        $report = new CampaignReport($label, $sourceBatch, $files, $runs, $manifest?->name, $provenance, $traps);
```

`maybeCompare()` wraps `BaselineComparator::compare()` in `try { … } catch (\InvalidArgumentException $e) { WP_CLI::warning($e->getMessage() . ' Skipping comparison.'); return; }`.

`writeReport()` takes the stamp and uses `reportPath($label, $stamp, '.json')`; factor the directory creation into:

```php
    private function reportPath(string $label, string $stamp, string $suffix): ?string
    {
        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'aiforge-dev';

        if (!wp_mkdir_p($dir)) {
            WP_CLI::warning("Could not create {$dir}, nothing written.");
            return null;
        }

        return "{$dir}/" . sanitize_file_name($label) . "-{$stamp}{$suffix}";
    }
```

Log the roots file path once at launch: `WP_CLI::log("Root ids are saved to {$rootsPath} as they launch.");`.

- [ ] **Step 4: Write `src/Cli/TrapCheckCommand.php`**

```php
<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\Campaign\CampaignRunner;
use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\TrapEvaluation;
use Throwable;
use WP_CLI;

/**
 * Re-runs the trap checks over a stored campaign, at zero API cost.
 */
final class TrapCheckCommand
{
    /**
     * Re-check a corpus campaign's stored runs with the current manifest.
     *
     * Edit the manifest's thresholds, re-run this, compare: calibration never
     * needs a new generation.
     *
     * ## OPTIONS
     *
     * --report=<path>
     * : A qg-campaign report JSON produced with --corpus.
     *
     * ## EXAMPLES
     *
     *     wp aiforge-dev trap-check --report=wp-content/uploads/aiforge-dev/hard-balanced-20260927-101500.json
     *
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        $path = (string) ($assoc['report'] ?? '');
        $report = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!\is_array($report)) {
            WP_CLI::error("'{$path}' is not a readable report JSON.");
        }

        if (empty($report['corpus'])) {
            WP_CLI::error('This report was not run on a corpus (no "corpus" key); trap checks need one.');
        }

        try {
            $manifest = CorpusManifest::fromName(AIFORGE_DEV_PATH . 'bench/ci-corpus', (string) $report['corpus']);
            $runs = TrapEvaluation::runsFromReport($report);
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
            return;
        }

        $then = (array) ($report['provenance']['files'] ?? []);
        $now = $manifest->hashes();
        $changed = array_keys(array_diff_assoc($now, $then) + array_diff_assoc($then, $now));

        if ($changed !== []) {
            WP_CLI::log('Changed since the campaign: ' . implode(', ', $changed) . '. Checks read the stored markdown; only the manifest\'s ground truth and thresholds apply anew.');
        }

        $runner = new CampaignRunner($wpdb);
        $traps = TrapEvaluation::evaluate($manifest, $runs, static fn (int $id): ?array => $runner->loadRunArtifacts($id));

        WP_CLI::log($traps->toMarkdown());

        $out = preg_replace('/\.json$/', '', $path) . '-trapcheck-' . gmdate('Ymd-His') . '.json';
        $written = file_put_contents($out, wp_json_encode([
            'source_report' => $path,
            'corpus' => $manifest->name,
            'manifest_hashes' => $now,
            'thresholds' => $manifest->data['thresholds'] ?? [],
            'traps' => $traps->toArray(),
        ], JSON_PRETTY_PRINT));

        $written === false ? WP_CLI::warning("Could not write {$out}.") : WP_CLI::success("Re-check written to {$out}");
    }
}
```

Register it in `src/DevTools.php` next to `qg-campaign`:

```php
        \WP_CLI::add_command('aiforge-dev trap-check', TrapCheckCommand::class);
```

(and its `use` line if the file imports commands).

- [ ] **Step 5: Document in `CLAUDE.md`**

Under "QG campaign runner", add a "Hard corpus" subsection:

```markdown
### Hard corpus (S17)

```bash
cd ../.. && npx wp-env run cli -- timeout 6600 wp aiforge-dev qg-campaign --corpus=hard \
  --combos=gemini:balanced:page-datterrissage,gemini:balanced:page-datterrissage \
  --label=hard-gemini --timeout=6000
cd ../.. && npx wp-env run cli -- wp aiforge-dev trap-check --report=wp-content/uploads/aiforge-dev/<report>.json
cd ../.. && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools php bench/ci-corpus/check.php hard
```

Twelve frozen scarce-content pages in `bench/ci-corpus/hard/`, scored by
`TrapChecker` against the manifest's ground truth (every figure, quote and
offer each file states). Listing a combo twice is a repeat. Reports carry
k/n per check, `n/a` wherever the template lacks the slot, file and template
hashes and the plugin commit.

- **Set the in-container `timeout` above `--timeout`.** 24 long runs take
  40-70 minutes. Root ids land in `<label>-<stamp>.roots.json` as they launch;
  `--collect=<that file>` rebuilds the report after a crash.
- **Recalibrate offline.** Edit `thresholds` in the manifest, re-run
  `trap-check`: no generation needed.
- **`stat_has_figure` measures model + addendum**: the OpenAI addendum
  endorses short labels in stat-values. It is not a fabrication count.
- `bench/` is not in the release zip: the corpus runs from a git checkout only.
```

- [ ] **Step 6: Run the whole suite and lint the syntax**

Run the test command without filter. Expected: PASS. Then:

```bash
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools sh -c 'for f in $(find src bench -name "*.php"); do php -l "$f" | grep -v "No syntax errors"; done'
```

Expected: no output.

- [ ] **Step 7: Smoke-test the wiring on one file**

```bash
cd "e:/Travaux/Travaux Web/wp-lab" && npx wp-env run cli -- timeout 1500 wp aiforge-dev qg-campaign --corpus=hard --files=1 \
  --combos=gemini:balanced:page-datterrissage --label=hard-smoke --timeout=1200
```

Expected: a `.roots.json` path logged at launch, one run, the QG table, the trap tables with a `k/n` or `n/a` in every cell, a report whose JSON has `corpus: "hard"`, `provenance.files` (13 hashes), `provenance.templates.page-datterrissage`, `provenance.plugin_commit` (a 40-char sha), and the run's `file: "hard-01-…"` and `root_id`. Then `wp aiforge-dev trap-check --report=<that report>` prints the same tables. Also try `--corpus=hard --source-batch=6223` (expected: the mutual-exclusion error), and `--baseline=<a batch report>` (expected: the refusal warning).

- [ ] **Step 8: Commit**

```bash
git add src/Campaign/CampaignRunner.php src/Cli/QgCampaignCommand.php src/Cli/TrapCheckCommand.php src/DevTools.php CLAUDE.md tests/Unit/Campaign/CampaignRunnerTest.php
git commit -m "Run the hard corpus from qg-campaign and re-check it offline

--corpus loads a frozen corpus in manifest order and scores every run
with the trap checks; root ids are saved as they launch so a crashed
campaign can be collected; trap-check re-scores a stored report at zero
API cost.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 16: First campaign, hand review, control (criteria 3-5)

**Files:**
- Create: `bench/ci-corpus/hard/results-2026-09.md`
- Modify: `bench/ci-corpus/hard/manifest.json` (calibrated thresholds, if any), `../wp-ai-forge/docs/specs/2026-09-hard-ci-corpus.md:3`

Each model is one invocation (2 repeats = the combo listed twice), run in the background, so the three together fit the budget and a wedge costs one model, not three. Before starting, confirm the plugin is on `feat/model-refresh-2026-09` or on a `main` holding its commits (`git -C ../wp-ai-forge log --oneline -1`), and that the Balanced models are the ones the spec compares (read `BalancedPreset::getConfigForProvider()`; Sonnet 5 is the Anthropic one).

- [ ] **Step 1: Run the three Balanced models (criterion 3)**

```bash
cd "e:/Travaux/Travaux Web/wp-lab"
for p in gemini openai anthropic; do
  npx wp-env run cli -- timeout 6600 wp aiforge-dev qg-campaign --corpus=hard \
    --combos=$p:balanced:page-datterrissage,$p:balanced:page-datterrissage \
    --label=hard-balanced-$p --timeout=6000
done
```

Run it in the background and wait for the completion notification. Expected per model: 24 runs, a report with file ids, hashes, k/n per check, `n/a` only where the landing lacks a slot (it has all four, so `n/a` should only appear for `card_count` on non-T3 files). If one invocation dies, collect it with `--collect=<its .roots.json>`.

- [ ] **Step 2: Read every flagged run by hand (criterion 4)**

For each failed check in the three reports' `traps.failures`, open the task (`result_content`, the source, the plan's MAPPING and NOTES) and classify it in `results-2026-09.md`: **model** (a real stretch or loss), **check** (the check is wrong: a threshold, a normalisation, a missed pattern), or **gate** (a Quality Gate artifact). Also read the runs the Quality Gate flagged (`/qg-audit <ids>`). A **check** error is fixed in `TrapChecker`, `TextTools` or the manifest thresholds, with a regression test on a fixture extracted from that run (`extract.php <id>`), then verified with `trap-check` on the stored reports. A **gate** artifact is written up as its own fix in the plugin repo and not fixed on this branch. Record the calibrated `orphan_overlap` and `retention` in the manifest's `thresholds` and say in `results-2026-09.md` what moved them.

- [ ] **Step 3: Run the positive control (criterion 5)**

```bash
npx wp-env run cli -- timeout 6600 wp aiforge-dev qg-campaign --corpus=hard \
  --combos=gemini:balanced:page-datterrissage,gemini:balanced:page-datterrissage \
  --model=gemini-3.5-flash-lite --label=hard-control-flash-lite --timeout=6000
```

If the model id is refused, look it up in `../wp-ai-forge/src/AI/ModelCatalog.php` and use the catalog's exact id.

- [ ] **Step 4: Judge the separation**

For each check and each Balanced model, from `by_repeat` in the reports (after the calibrated `trap-check` re-runs): the model's **spread** is `|pass₁/n₁ − pass₂/n₂|` between its two repeats; the control is **separated** on that check when `|control pass/n (both repeats) − model pass/n (both repeats)| > spread`. Criterion 5 holds when, for every Balanced model, at least one check separates the control. Write the table (check × model: control k/n, model k/n per repeat, separated yes/no) into `results-2026-09.md`. **If the control is not separated, the checks are too weak: fix the checks, not the models**, and re-judge with `trap-check` (no new generation) before deciding a re-run is needed.

- [ ] **Step 5: Record and hand off**

`results-2026-09.md` holds: report file names, cost and wall time per model, the k/n tables (all files, on trap files, by author), the hand-review classification, calibrated thresholds, the criterion 5 table, and any gate artifact filed. Update the spec's status line (`../wp-ai-forge/docs/specs/2026-09-hard-ci-corpus.md:3`) to `shipped in wp-ai-forge-devtools (feat/hard-ci-corpus); results in bench/ci-corpus/hard/results-2026-09.md`.

```bash
git add bench/ci-corpus/hard/results-2026-09.md bench/ci-corpus/hard/manifest.json
git commit -m "Record the first hard-corpus campaign and its control

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Report to the user in French: per-model k/n, whether the control separated and on which checks, the gate artifacts found, and that the devtools branch is ready to fast-forward into `main` (no version bump, no tag). Do not merge without their go-ahead.
