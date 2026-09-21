<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\DeferredDispatcher;
use Kode\Event\Dispatcher;
use Kode\Event\ErrorStrategy;
use Kode\Event\Event;
use Kode\Event\EventTracer;
use Kode\Event\Exception\EventDispatchException;
use Kode\Event\ListenerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * 健壮性回归：clear() 失效范围、cancel 幽灵占位、until 聚合、链路注入位置。
 */
final class RegistryDeferredHardeningTest extends TestCase
{
    // ==================== ListenerRegistry::clear() ====================

    public function testClearOfOneEventKeepsPriorityOrderOfAnother(): void
    {
        $d = new Dispatcher();
        $seen = [];
        $d->listen('A', static function () use (&$seen): void { $seen[] = 'low'; });
        $d->listen('A', static function () use (&$seen): void { $seen[] = 'high'; }, 100);

        $d->dispatch('A');
        $seen = [];

        // 清空一个无关事件不得连带抹掉 A 桶的排序状态
        $d->getRegistry()->clear('B');
        $d->dispatch('A');

        $this->assertSame(['high', 'low'], $seen, 'clear(其他事件) 后优先级顺序必须保持');
    }

    public function testSortedOrderIsWrittenBackToBucket(): void
    {
        $registry = new ListenerRegistry();
        $registry->listen('E', static function (): void {}, 0);
        $registry->listen('E', static function (): void {}, 50);

        $this->assertSame([50, 0], array_column($registry->getListeners('E'), 'priority'));

        // 桶自身已回写有序：脏标记已被消费，重复读取顺序仍稳定
        $this->assertSame([50, 0], array_column($registry->getListeners('E'), 'priority'));

        // 全量失效解析缓存后仍从桶本体取序
        $registry->clear('ZZZ');
        $this->assertSame([50, 0], array_column($registry->getListeners('E'), 'priority'));
    }

    // ==================== DeferredDispatcher cancel 幽灵 ====================

    public function testCancelAtTailDoesNotStarveEarlierDueJob(): void
    {
        $d = new Dispatcher();
        $dd = new DeferredDispatcher($d);
        $fired = [];
        $d->listen('due', static function () use (&$fired): void { $fired[] = 'due'; });

        $dd->defer(new Event('later'), delay: 120);
        $ghost = $dd->defer(new Event('later'), delay: 600);
        $dd->cancel($ghost);                       // 队尾留下幽灵占位
        $dd->defer(new Event('due'), delay: 0);    // 更早的时间必须排到队首

        $this->assertSame(1, $dd->process(), '到期任务必须被派发');
        $this->assertSame(['due'], $fired);
        $this->assertSame(1, $dd->count(), '未到期任务留在待处理集');
    }

    public function testBackfillWithGhostTailKeepsAscendingOrder(): void
    {
        $d = new Dispatcher();
        $dd = new DeferredDispatcher($d);
        $order = [];
        $d->listen('bf', static function (Event $e) use (&$order): void { $order[] = $e->get('tag'); });

        $dd->defer(new Event('bf', ['tag' => 'far']), delay: 900);
        $ghost = $dd->defer(new Event('bf', ['tag' => 'ghost']), delay: 1800);
        $dd->cancel($ghost);

        // 全部早于现有任务的回填：不得因幽灵命中空键
        $ids = $dd->deferBackfill([
            ['event' => 'bf', 'data' => ['tag' => 'a'], 'timestamp' => time() + 60],
            ['event' => 'bf', 'data' => ['tag' => 'b'], 'timestamp' => time() + 90],
        ]);
        $this->assertCount(2, $ids);

        $this->assertSame(3, $dd->count());
        // 幽灵不参与派发序列，其余任务按时间升序
        $this->assertSame(0, $dd->process());
        $this->assertSame([], $order);
    }

    // ==================== Dispatcher::until() ====================

    public function testUntilPropagatesCollectedErrors(): void
    {
        $d = new Dispatcher();
        $d->setErrorStrategy(ErrorStrategy::COLLECT);
        $d->listen('chain', static fn(): string => throw new \RuntimeException('boom'));
        $d->listen('chain', static fn(): string => 'answer');

        try {
            $d->until('chain');
            $this->fail('COLLECT 策略下已收集的失败不应被静默吞掉');
        } catch (EventDispatchException $e) {
            $this->assertCount(1, $e->getErrors());
        }
    }

    public function testUntilWithoutErrorsStillShortCircuits(): void
    {
        $d = new Dispatcher();
        $d->listen('chain', static fn(): ?string => null);
        $d->listen('chain', static fn(): string => 'second');
        $d->listen('chain', static fn(): string => throw new \LogicException('unreached'));

        $this->assertSame('second', $d->until('chain'));
    }

    // ==================== 前置钩子替换事件 ====================

    public function testPreDispatcherReplacementDecidesStoppability(): void
    {
        $d = new Dispatcher();
        $hit = 0;
        $d->listen(PlainProbe::class, static function () use (&$hit): void { $hit++; });
        // 前置钩子把「已停止」的可停止事件换成不可停止事件，判定须基于最终对象
        $d->addPreDispatcher(static fn(object $e): object => new PlainProbe());

        $d->dispatch(new StoppedProbe());

        $this->assertSame(1, $hit, '替换后的事件不可停止时不应被提前中止');
    }

    // ==================== EventTracer 注入位置 ====================

    public function testTracerUsesTraceIdFieldNotBusinessData(): void
    {
        $d = new Dispatcher();
        $tracer = new EventTracer($d);
        $event = new Event('traced', ['payload' => 'x']);

        $tracer->trace($event, static fn(): null => null);

        $this->assertNotNull($event->getTraceId());
        $this->assertSame(['payload' => 'x'], $event->getData(), '链路 id 不得混入业务 data');
    }

    public function testTracerCapsRetainedTraces(): void
    {
        $d = new Dispatcher();
        $tracer = new EventTracer($d);

        for ($i = 0; $i < EventTracer::MAX_TRACES + 50; $i++) {
            $tracer->trace(new Event('bulk'), static fn(): null => null);
        }

        $this->assertSame(EventTracer::MAX_TRACES, $tracer->count());
    }
}

/** 前置钩子替换后的普通（不可停止）事件 */
final class PlainProbe
{
}

/** 已停止传播的可停止事件 */
final class StoppedProbe implements StoppableEventInterface
{
    public function isPropagationStopped(): bool
    {
        return true;
    }
}
