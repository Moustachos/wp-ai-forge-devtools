<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Vision;

use AIForge\DevTools\Tests\Unit\TestCase;
use AIForge\Vision\VisionBenchRunner;

class VisionBenchRunnerTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function allowed(): array
    {
        return [
            'category' => ['people', 'landscape', 'food'],
            'tone' => ['natural', 'corporate'],
            'color_mood' => ['dark', 'bright'],
        ];
    }

    public function testAcceptsMultiValueFacetsStoredAsJsonArrays(): void
    {
        $rows = [
            1 => ['status' => 'indexed', 'category' => '["people","landscape"]', 'tone' => '["natural"]', 'color_mood' => 'dark'],
        ];

        $health = VisionBenchRunner::indexHealth($rows, $this->allowed());

        $this->assertSame(0, $health['off_taxonomy'], 'every value is in its allowed list');
    }

    public function testCountsEachValueOutsideItsAllowedList(): void
    {
        $rows = [
            1 => ['status' => 'indexed', 'category' => '["people","ufo"]', 'tone' => '["whimsical"]', 'color_mood' => 'dark'],
        ];

        $health = VisionBenchRunner::indexHealth($rows, $this->allowed());

        $this->assertSame(2, $health['off_taxonomy'], 'ufo and whimsical');
    }

    public function testCountsFailedRowsApartFromIndexedOnes(): void
    {
        $rows = [
            1 => ['status' => 'indexed', 'category' => '["people"]'],
            2 => ['status' => 'failed', 'category' => '["ufo"]'],
            3 => ['status' => 'indexed', 'category' => '["food"]'],
        ];

        $health = VisionBenchRunner::indexHealth($rows, $this->allowed());

        $this->assertSame(2, $health['indexed']);
        $this->assertSame(1, $health['failed']);
        $this->assertSame(33.3, $health['failure_rate']);
        $this->assertSame(0, $health['off_taxonomy'], 'a failed row carries no usable taxonomy');
    }

    public function testRefusesAnUnfilledQueryInsteadOfScoringItAsATrap(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'q');
        file_put_contents($path, json_encode([
            ['query' => 'a real one', 'type' => 'facile', 'expect' => []],
        ]));

        $this->expectExceptionMessageMatches('/expect/');
        VisionBenchRunner::loadQueries($path);
    }

    public function testAcceptsAnEmptyExpectOnlyWhenTheEntryIsTypedAsATrap(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'q');
        file_put_contents($path, json_encode([
            ['query' => 'nothing answers this', 'type' => 'piege', 'expect' => []],
            ['query' => 'a real one', 'type' => 'facile', 'expect' => [12]],
        ]));

        $queries = VisionBenchRunner::loadQueries($path);

        $this->assertCount(2, $queries);
        $this->assertSame('piege', $queries[0]['type']);
        $this->assertSame([12], $queries[1]['expect']);
    }

    public function testAveragesKeywordsAcrossIndexedRows(): void
    {
        $rows = [
            1 => ['status' => 'indexed', 'keywords' => '["a","b","c"]', 'description' => 'four'],
            2 => ['status' => 'indexed', 'keywords' => '["a"]', 'description' => 'six ch'],
        ];

        $health = VisionBenchRunner::indexHealth($rows, []);

        $this->assertSame(2.0, $health['avg_keywords']);
        $this->assertSame(5, $health['avg_description_chars']);
    }
}
