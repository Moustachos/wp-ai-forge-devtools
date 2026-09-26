<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\ManifestEntry;
use AIForge\Campaign\MarkdownDocument;
use AIForge\Campaign\TextTools;
use AIForge\Campaign\TrapChecker;
use AIForge\DevTools\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('quinzeMinutes')]
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
            '2014' => ['en 2014'], '2015' => ['fondée à Lyon en 2015'], '7' => ['sept personnes'], '10' => ['Dix ans'],
            '15000' => ['15 000 exemplaires'], '18' => ['18 mois'], '2' => ['deux interviews'], '2400' => ['2 400 collaborateurs'],
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

    #[DataProvider('hundredPercent')]
    public function testHundredPercentIsInvented(string $id): void
    {
        // Every other number the source states is a figure; 100% is not among them.
        $figures = [];
        foreach (TextTools::numbers((new MarkdownDocument($this->fixture("{$id}.md")))->plainText()) as $n) {
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

    public function testAnUnpaddedStepSequenceIsExemptOnARealRun(): void
    {
        // 7492 numbers the four steps of the initial assessment 1, 2, 3, 4.
        $result = $this->checkRun('7492', ['1998' => ['en 1998'], '3' => ['trois kinésithérapeutes'], '69003' => ['69003 Lyon'], '0472000000' => ['04 72 00 00 00']]);

        $this->assertSame(TrapChecker::PASS, $result['stat_grounding']['status'], implode("\n", $result['stat_grounding']['findings']));
    }

    public function testALoneOneIsNotAStep(): void
    {
        $template = '<p class="is-style-stat-value">240+</p>';
        $output = '<p class="is-style-stat-value">2019</p><p class="is-style-stat-value">12</p><p class="is-style-stat-value">1</p>'
            . '<p class="is-style-stat-value">1</p><p class="is-style-stat-value">2</p>';
        $entry = ManifestEntry::fromArray(['id' => 'x', 'figures' => ['2019' => ['in 2019'], '12' => ['twelve people']]]);

        $result = (new TrapChecker())->check($entry, "# T\n", $template, $output);

        $this->assertSame(TrapChecker::FAIL, $result['stat_grounding']['status']);
        $this->assertCount(3, $result['stat_grounding']['findings']);
    }

    public function testAFigureSpelledAsTheManifestStatesItHoldsAFigureOnARealRun(): void
    {
        // 7449: «Deux plombiers» and «1 véhicule chacun», from "deux plombiers" and "un véhicule chacun".
        $manifest = CorpusManifest::fromName(\dirname(__DIR__, 3) . '/bench/ci-corpus', 'hard');
        $entry = $manifest->entry('hard-09-plomberie');

        $result = (new TrapChecker($manifest->settings))->check($entry, $this->fixture('7449.md'), $this->fixture('7449.tpl.html'), $this->fixture('7449.html'));

        $this->assertSame(TrapChecker::PASS, $result['stat_grounding']['status'], implode("\n", $result['stat_grounding']['findings']));
        $this->assertSame(TrapChecker::PASS, $result['stat_has_figure']['status'], implode("\n", $result['stat_has_figure']['findings']));
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
