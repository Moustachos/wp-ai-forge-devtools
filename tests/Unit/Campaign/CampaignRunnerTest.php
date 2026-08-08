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

    // --- File count ---

    public function testNoRequestedCountUsesEverythingAvailable(): void
    {
        $this->assertSame(2, CampaignRunner::fileCount(null, 2));
    }

    public function testRequestedCountIsHonouredWhenAvailable(): void
    {
        $this->assertSame(2, CampaignRunner::fileCount(2, 5));
    }

    public function testRequestedCountIsClampedToWhatTheBatchHolds(): void
    {
        // The caller is warned about this; it must never pass silently.
        $this->assertSame(1, CampaignRunner::fileCount(2, 1));
    }

    public function testNonPositiveRequestFallsBackToAvailable(): void
    {
        $this->assertSame(3, CampaignRunner::fileCount(0, 3));
        $this->assertSame(3, CampaignRunner::fileCount(-1, 3));
    }
}
