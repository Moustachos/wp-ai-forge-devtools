<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Vision;

use AIForge\DevTools\Tests\Unit\TestCase;
use AIForge\Vision\IndexDivergence;

class IndexDivergenceTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $overrides
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function rows(array $overrides): array
    {
        $models = [];
        foreach ($overrides as $model => $byId) {
            foreach ($byId as $id => $row) {
                $models[$model][(string) $id] = array_merge([
                    'status' => 'indexed',
                    'description' => 'a description',
                    'people_count' => 'none',
                    'has_text' => '0',
                    'image_type' => 'photo',
                ], $row);
            }
        }

        return $models;
    }

    public function testAModelAloneAgainstTheOthersGetsTheMinorityReport(): void
    {
        $models = $this->rows([
            'a' => [1 => ['people_count' => 'single']],
            'b' => [1 => ['people_count' => 'single']],
            'c' => [1 => ['people_count' => 'none']],
        ]);

        $result = IndexDivergence::compare($models, ['people_count']);

        $this->assertSame(0, $result['minority']['a']);
        $this->assertSame(0, $result['minority']['b']);
        $this->assertSame(1, $result['minority']['c']);
    }

    public function testAttributesTheMinorityOnAFacetAnsweredWithDigits(): void
    {
        // has_text answers are "0" and "1". Used as array keys they become
        // integers, and a strict search for the majority value then matches
        // nobody — which handed every has_text disagreement to whichever model
        // happened to come first.
        // The odd model is deliberately not the first one: with the bug in
        // place the blame fell on whoever came first, which is the right answer
        // half the time and hides the defect.
        $models = $this->rows([
            'a' => [1 => ['has_text' => '0']],
            'b' => [1 => ['has_text' => '0']],
            'c' => [1 => ['has_text' => '0']],
            'd' => [1 => ['has_text' => '1']],
        ]);

        $result = IndexDivergence::compare($models, ['has_text']);

        $this->assertSame(1, $result['minority']['d'], 'd is the one seeing text');
        $this->assertSame(0, $result['minority']['a']);
        $this->assertSame(0, $result['minority']['b']);
        $this->assertSame(0, $result['minority']['c']);
        $this->assertSame(0, $result['undecided']);
    }

    public function testAnEvenSplitBlamesNobody(): void
    {
        $models = $this->rows([
            'a' => [1 => ['has_text' => '1']],
            'b' => [1 => ['has_text' => '1']],
            'c' => [1 => ['has_text' => '0']],
            'd' => [1 => ['has_text' => '0']],
        ]);

        $result = IndexDivergence::compare($models, ['has_text']);

        $this->assertSame([0, 0, 0, 0], array_values($result['minority']));
        $this->assertSame(1, $result['undecided']);
    }

    public function testImagesAModelFailedAreExcludedFromEveryComparison(): void
    {
        // A failed row carries no facet at all. Counted in, the model that
        // failed looks like it disagrees with everyone on every facet — which
        // inverted the ranking on the first run of this analysis.
        $models = $this->rows([
            'a' => [1 => ['people_count' => 'single'], 2 => ['people_count' => 'single']],
            'b' => [1 => ['people_count' => 'single'], 2 => ['people_count' => 'single']],
            'c' => [1 => ['status' => 'failed', 'people_count' => null, 'description' => ''], 2 => ['people_count' => 'couple']],
        ]);

        $result = IndexDivergence::compare($models, ['people_count']);

        $this->assertSame(1, $result['compared'], 'only image 2 was indexed by everyone');
        $this->assertSame(1, $result['minority']['c'], 'image 1 must not count against c');
    }

    public function testUnanimityIsCountedApartFromAgreement(): void
    {
        $models = $this->rows([
            'a' => [1 => [], 2 => ['image_type' => 'graphic']],
            'b' => [1 => [], 2 => ['image_type' => 'photo']],
            'c' => [1 => [], 2 => ['image_type' => 'photo']],
        ]);

        $result = IndexDivergence::compare($models, ['image_type']);

        $this->assertSame(1, $result['unanimous']);
        $this->assertSame(1, $result['split']);
    }

    public function testDetectsTwoImagesWhoseDescriptionsWereSwapped(): void
    {
        $models = $this->rows([
            'a' => [
                1 => ['description' => 'black headphones on a white desk beside a keyboard'],
                2 => ['description' => 'pancakes with berries on a checkered tablecloth'],
            ],
            'b' => [
                1 => ['description' => 'black headphones resting on a white desk near a keyboard'],
                2 => ['description' => 'pancakes topped with berries on a checkered cloth'],
            ],
            'swapper' => [
                1 => ['description' => 'pancakes topped with berries on a checkered tablecloth'],
                2 => ['description' => 'black headphones on a white desk beside a keyboard'],
            ],
        ]);

        $swaps = IndexDivergence::swaps($models);

        $this->assertSame([['swapper', '1', '2']], $swaps);
    }

    public function testTwoImagesOfTheSameSubjectAreNotASwap(): void
    {
        // Both images are snowy peaks, so either description fits either image.
        // Only a mutual best match with a poor self-fit is a swap.
        $models = $this->rows([
            'a' => [
                1 => ['description' => 'snow covered mountain peaks under a blue sky'],
                2 => ['description' => 'snow covered mountain peaks veiled by clouds'],
            ],
            'b' => [
                1 => ['description' => 'snowy mountain peaks under a clear blue sky'],
                2 => ['description' => 'snowy mountain peaks behind soft clouds'],
            ],
            'c' => [
                1 => ['description' => 'snow covered mountain peaks veiled by clouds'],
                2 => ['description' => 'snow covered mountain peaks under a blue sky'],
            ],
        ]);

        $this->assertSame([], IndexDivergence::swaps($models));
    }

    public function testBuildsOneReviewCasePerDecidableDisagreement(): void
    {
        $models = $this->rows([
            'a' => [1 => ['has_text' => '1'], 2 => []],
            'b' => [1 => ['has_text' => '0'], 2 => []],
            'c' => [1 => ['has_text' => '0'], 2 => []],
        ]);

        $cases = IndexDivergence::cases($models, ['has_text']);

        $this->assertCount(1, $cases, 'image 2 was unanimous, nothing to adjudicate');
        $this->assertSame('1', $cases[0]['attachment_id']);
        $this->assertSame('has_text', $cases[0]['facet']);
        $this->assertSame(['0', '1'], $cases[0]['options'], 'options are sorted, so position leaks no answer');
        $this->assertSame(['a' => '1', 'b' => '0', 'c' => '0'], $cases[0]['answers']);
    }
}
