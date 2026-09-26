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
