<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\StatsSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * DispatcherStats::snapshot() 只读快照测试（readonly class）
 */
class StatsSnapshotTest extends TestCase
{
    public function testSnapshotReturnsReadOnlyInstance(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->enableStats();
        $dispatcher->listen('demo', function (Event $e): void {
        });
        $dispatcher->dispatch('demo');

        $snapshot = $dispatcher->getStats()->snapshot();

        $this->assertInstanceOf(StatsSnapshot::class, $snapshot);
        $this->assertTrue((new \ReflectionClass($snapshot))->isReadOnly());
    }

    public function testSnapshotFieldsAreCorrect(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->enableStats();
        $dispatcher->listen('demo', function (Event $e): void {
        });
        $dispatcher->listen('demo', function (Event $e): void {
        });
        $dispatcher->dispatch('demo');

        $snapshot = $dispatcher->getStats()->snapshot();

        $this->assertSame(1, $snapshot->totalDispatches);
        $this->assertSame(0, $snapshot->totalErrors);
        $this->assertGreaterThanOrEqual(0, $snapshot->totalMs);
        $this->assertIsArray($snapshot->metrics);
        $this->assertArrayHasKey('demo', $snapshot->metrics);
        $this->assertSame(2, $snapshot->metrics['demo']['listeners']);
    }

    public function testSnapshotIsImmutableView(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->enableStats();
        $dispatcher->listen('demo', function (Event $e): void {
        });
        $dispatcher->dispatch('demo');

        $snapshot = $dispatcher->getStats()->snapshot();
        $this->assertSame(1, $snapshot->totalDispatches);

        // 继续派发后，已生成的快照不应被改变（冻结视图）
        $dispatcher->dispatch('demo');
        $this->assertSame(1, $snapshot->totalDispatches);
        $this->assertSame(2, $dispatcher->getStats()->snapshot()->totalDispatches);
    }
}
