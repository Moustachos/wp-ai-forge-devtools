<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Vision;

use AIForge\DevTools\Tests\Unit\TestCase;
use AIForge\Vision\SuggestionBlockSelector;

class SuggestionBlockSelectorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function candidate(int $post, int $index, string $position, string $density, string $heading = 'H'): array
    {
        return [
            'post_id' => $post,
            'block_index' => $index,
            'context' => [
                'image_position' => $position,
                'text_density' => $density,
                'section_heading' => $heading,
                'post_title' => "Post $post",
            ],
        ];
    }

    public function testSpreadsThePickAcrossPostsRatherThanTakingOneWhole(): void
    {
        $candidates = [];
        foreach ([10, 20, 30] as $post) {
            for ($i = 0; $i < 8; $i++) {
                $candidates[] = $this->candidate($post, $i, 'middle', 'medium');
            }
        }

        $picked = SuggestionBlockSelector::select($candidates, 6);

        $posts = array_count_values(array_column($picked, 'post_id'));
        $this->assertCount(3, $posts, 'all three posts represented');
        $this->assertSame([2, 2, 2], array_values($posts), 'evenly, not eight from the first');
    }

    public function testPrefersUnseenContextProfilesBeforeRepeatingOne(): void
    {
        $candidates = [
            $this->candidate(1, 0, 'middle', 'medium'),
            $this->candidate(1, 1, 'middle', 'medium'),
            $this->candidate(1, 2, 'first', 'low'),
            $this->candidate(1, 3, 'last', 'high'),
        ];

        $picked = SuggestionBlockSelector::select($candidates, 3);

        $profiles = array_map(
            static fn ($p) => $p['context']['image_position'] . '/' . $p['context']['text_density'],
            $picked
        );

        $this->assertSame(\count($profiles), \count(array_unique($profiles)), 'three distinct profiles exist, take them');
    }

    public function testReturnsEverythingWhenAskedForMoreThanAvailable(): void
    {
        $candidates = [$this->candidate(1, 0, 'first', 'low'), $this->candidate(2, 0, 'last', 'high')];

        $this->assertCount(2, SuggestionBlockSelector::select($candidates, 10));
    }

    public function testSelectionIsStableForAGivenInput(): void
    {
        $candidates = [];
        foreach ([1, 2, 3, 4] as $post) {
            foreach (['first', 'middle', 'last'] as $i => $pos) {
                $candidates[] = $this->candidate($post, $i, $pos, 'medium');
            }
        }

        $this->assertSame(
            SuggestionBlockSelector::select($candidates, 5),
            SuggestionBlockSelector::select($candidates, 5),
            'a benchmark that reshuffles its own sample cannot be compared to itself'
        );
    }
}
