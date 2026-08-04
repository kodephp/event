<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\DispatcherStats;
use PHPUnit\Framework\TestCase;

class DispatcherStatsTest extends TestCase
{
    public function testRecordsDispatchesAndErrors(): void
    {
        $stats = new DispatcherStats();
        $stats->record('event.a', 1000.0, 2, 0);
        $stats->record('event.a', 3000.0, 2, 1);
        $stats->record('event.b', 500.0, 1, 0);

        $this->assertSame(3, $stats->getTotalDispatches());
        $this->assertSame(1, $stats->getTotalErrors());
        $this->assertSame(2, $stats->getCount('event.a'));
        $this->assertSame(1, $stats->getCount('event.b'));
    }

    public function testAverageAndMaxTiming(): void
    {
        $stats = new DispatcherStats();
        $stats->record('event.a', 1000.0, 1, 0);
        $stats->record('event.a', 3000.0, 1, 0);

        // (1000 + 3000) / 2 = 2000
        $this->assertSame(2000.0, $stats->getAverageNs('event.a'));
        // max should be 3000
        $this->assertSame(3000.0, $stats->getMetrics()['event.a']['max_ns']);
    }

    public function testUnknownEventReturnsZero(): void
    {
        $stats = new DispatcherStats();
        $this->assertSame(0, $stats->getCount('missing'));
        $this->assertSame(0.0, $stats->getAverageNs('missing'));
    }

    public function testSlowEventsCapturedAboveThreshold(): void
    {
        // 1ms threshold -> 1000ns. Two events: one below, one above.
        $stats = new DispatcherStats(0.001);
        $stats->record('fast', 500.0, 1, 0);
        $stats->record('slow', 5000.0, 1, 0);

        $slow = $stats->getSlowEvents();
        $this->assertCount(1, $slow);
        $this->assertSame('slow', $slow[0]['event']);
        $this->assertSame(5000.0, $slow[0]['elapsed_ns']);
    }

    public function testTopByTotalTimeOrderedDescending(): void
    {
        $stats = new DispatcherStats();
        $stats->record('a', 1000.0, 1, 0);
        $stats->record('b', 9000.0, 1, 0);
        $stats->record('c', 3000.0, 1, 0);

        $top = $stats->getTopByTotalTime(2);
        $this->assertCount(2, $top);
        $this->assertSame('b', $top[0]['event']);
        $this->assertSame('c', $top[1]['event']);
    }

    public function testResetClearsState(): void
    {
        $stats = new DispatcherStats();
        $stats->record('a', 1000.0, 1, 0);
        $stats->reset();

        $this->assertSame(0, $stats->getTotalDispatches());
        $this->assertSame(0, $stats->getTotalErrors());
        $this->assertSame([], $stats->getMetrics());
        $this->assertSame([], $stats->getSlowEvents());
    }

    public function testToArrayExportsSummary(): void
    {
        $stats = new DispatcherStats();
        $stats->record('a', 1000.0, 1, 0);

        $arr = $stats->toArray();
        $this->assertSame(1, $arr['total_dispatches']);
        $this->assertSame(1, $arr['events']);
        $this->assertArrayHasKey('a', $arr['metrics']);
    }
}
