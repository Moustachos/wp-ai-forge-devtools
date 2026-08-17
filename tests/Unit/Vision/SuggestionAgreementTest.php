<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Vision;

use AIForge\DevTools\Tests\Unit\TestCase;
use AIForge\Vision\SuggestionAgreement;

class SuggestionAgreementTest extends TestCase
{
    public function testEmptyEverywhereIsNotAgreement(): void
    {
        // The first run of this bench reported "12 blocks where every index
        // proposed the same set" while every set was empty — a broken parser
        // reading as a unanimous verdict.
        $models = [
            'a' => [['block' => 0, 'suggestions' => []]],
            'b' => [['block' => 0, 'suggestions' => []]],
        ];

        $result = SuggestionAgreement::compute($models, 1);

        $this->assertSame(0, $result['unanimous'], 'nobody answered, nobody agreed');
        $this->assertSame(1, $result['no_answer']);
        $this->assertSame(0, $result['comparable']);
    }

    public function testCountsUnanimityOnlyOverBlocksEverybodyAnswered(): void
    {
        $models = [
            'a' => [['block' => 0, 'suggestions' => [1, 2]], ['block' => 1, 'suggestions' => [5]]],
            'b' => [['block' => 0, 'suggestions' => [2, 1]], ['block' => 1, 'suggestions' => []]],
        ];

        $result = SuggestionAgreement::compute($models, 2);

        $this->assertSame(1, $result['unanimous'], 'block 0: same set, order ignored');
        $this->assertSame(1, $result['comparable'], 'block 1 had a silent model, not comparable');
        $this->assertSame(0, $result['no_answer'], 'block 1 was answered by someone');
        $this->assertSame(1, $result['partial'], 'block 1 answered by some but not all');
    }

    public function testTracksWhetherTheFirstPickMatches(): void
    {
        $models = [
            'a' => [['block' => 0, 'suggestions' => [7, 8, 9]]],
            'b' => [['block' => 0, 'suggestions' => [7, 1, 2]]],
        ];

        $result = SuggestionAgreement::compute($models, 1);

        $this->assertTrue($result['per_block'][0]['same_first_pick'], 'both lead with 7');
        $this->assertSame(0, $result['unanimous'], 'the sets still differ beyond the first');
        $this->assertSame(1, $result['same_first']);
    }

    public function testFullySplitCountsBlocksWhereEveryIndexDiffers(): void
    {
        $models = [
            'a' => [['block' => 0, 'suggestions' => [1]]],
            'b' => [['block' => 0, 'suggestions' => [2]]],
            'c' => [['block' => 0, 'suggestions' => [3]]],
        ];

        $result = SuggestionAgreement::compute($models, 1);

        $this->assertSame(1, $result['fully_split']);
    }
}
