<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\DeferredDispatcher;
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use PHPUnit\Framework\TestCase;

/**
 * DeferredDispatcher 有序索引（按 dispatchAt 升序）+ 早停 + cancel 语义回归测试
 */
final class DeferredOrderTest extends TestCase
{
    public function testDueTasksDispatchedInRegistrationOrder(): void
    {
        $dispatcher = new Dispatcher();
        $dd = new DeferredDispatcher($dispatcher);

        $tags = [];
        $dispatcher->listen('d.evt', static function (Event $e) use (&$tags): void {
            $tags[] = $e->get('tag');
        });

        // 到期与未来任务交错：仅 delay=0 的到期，且必须按注册顺序派发
        $dd->defer(new Event('d.evt', ['tag' => 'a']), delay: 0);
        $dd->defer(new Event('d.evt', ['tag' => 'future1']), delay: 100);
        $dd->defer(new Event('d.evt', ['tag' => 'b']), delay: 0);
        $dd->defer(new Event('d.evt', ['tag' => 'future2']), delay: 100);
        $dd->defer(new Event('d.evt', ['tag' => 'c']), delay: 0);

        $dd->process();

        $this->assertSame(['a', 'b', 'c'], $tags, '到期任务必须按注册顺序派发');
        $this->assertSame(2, $dd->count(), '未来任务应保留在待处理集中');
    }

    public function testCancelRemovesFromOrderWithoutShiftingOthers(): void
    {
        $dispatcher = new Dispatcher();
        $dd = new DeferredDispatcher($dispatcher);

        $tags = [];
        $dispatcher->listen('d.evt', static function (Event $e) use (&$tags): void {
            $tags[] = $e->get('tag');
        });

        $idA = $dd->defer(new Event('d.evt', ['tag' => 'a']), delay: 0);
        $dd->defer(new Event('d.evt', ['tag' => 'b']), delay: 0);
        $idC = $dd->defer(new Event('d.evt', ['tag' => 'c']), delay: 0);

        // 取消队首的 'a'，其余到期任务仍应保持注册顺序
        $this->assertTrue($dd->cancel($idA));

        $dd->process();

        $this->assertSame(['b', 'c'], $tags, 'cancel 后剩余到期任务仍按注册顺序派发');
    }

    public function testCancelKeepsPendingCountAndGetJobConsistent(): void
    {
        $dispatcher = new Dispatcher();
        $dd = new DeferredDispatcher($dispatcher);

        $tags = [];
        $dispatcher->listen('d.evt', static function (Event $e) use (&$tags): void {
            $tags[] = $e->get('tag');
        });

        // 注册 10 个到期任务，模拟「大待处理集 + 频繁取消」场景
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $dd->defer(new Event('d.evt', ['tag' => (string) $i]), delay: 0);
        }
        $this->assertSame(10, $dd->count());

        // 取消偶数下标的 5 个（O(1) 简化后，order 中仅留幽灵占位，由 process 跳过）
        $kept = [];
        foreach ($ids as $i => $id) {
            if ($i % 2 === 0) {
                $this->assertTrue($dd->cancel($id));
                $this->assertNull($dd->getJob($id), '已取消任务的 getJob 必须返回 null');
            } else {
                $kept[] = (string) $i;
            }
        }

        $this->assertSame(5, $dd->count(), 'cancel 后 count 应反映剩余任务数');
        $this->assertCount(5, $dd->pending(), 'pending 不应包含已取消任务');
        $this->assertNull($dd->getJob($ids[0]));

        $dd->process();

        $this->assertSame($kept, $tags, '仅未取消的到期任务被派发，且顺序保持');
        $this->assertSame(0, $dd->count());

        // 重复取消同一 id 应返回 false（幂等）
        $this->assertFalse($dd->cancel($ids[0]));
    }

    public function testProcessAllProcessesAllDue(): void
    {
        $dispatcher = new Dispatcher();
        $dd = new DeferredDispatcher($dispatcher);

        $count = 0;
        $dispatcher->listen('e', static function () use (&$count): void {
            $count++;
        });

        $dd->defer(new Event('e'), delay: 0);
        $dd->defer(new Event('e'), delay: 0);
        $dd->defer(new Event('e'), delay: 0);

        $this->assertSame(3, $dd->processAll());
        $this->assertSame(3, $count);
        $this->assertSame(0, $dd->count());
    }

    public function testNotDueTasksNeverDispatchedWithLargePending(): void
    {
        $dispatcher = new Dispatcher();
        $dd = new DeferredDispatcher($dispatcher);

        $fired = [];
        $dispatcher->listen('bulk', static function (Event $e) use (&$fired): void {
            $fired[] = $e->get('kind');
        });

        // 大量远未来任务
        for ($i = 0; $i < 2000; $i++) {
            $dd->deferAt(new Event('bulk', ['kind' => 'future']), [], time() + 1_000_000);
        }
        // 少量到期任务
        $dd->defer(new Event('bulk', ['kind' => 'now']), delay: 0);

        $this->assertSame(1, $dd->process());
        $this->assertSame(['now'], $fired, '未到期任务不应被提前派发');
        $this->assertSame(2000, $dd->count(), '未到期任务应保留在待处理集中');
    }

    public function testDeferAfterFutureDeferAtSortsBeforeIt(): void
    {
        $dispatcher = new Dispatcher();
        $dd = new DeferredDispatcher($dispatcher);

        $tags = [];
        $dispatcher->listen('d.evt', static function (Event $e) use (&$tags): void {
            $tags[] = $e->get('tag');
        });

        // 先注册一个远未来任务（进入 order 队尾）
        $dd->deferAt(new Event('d.evt', ['tag' => 'future']), [], time() + 1_000_000);
        // 再注册一个立即到期任务（dispatchAt 远小于未来任务 → 前插到队首的罕见路径）
        $dd->defer(new Event('d.evt', ['tag' => 'now']), delay: 0);

        $dd->process();

        $this->assertSame(['now'], $tags, '前插的到期任务应先派发，未来任务保留');
        $this->assertSame(1, $dd->count());
    }

    public function testBackfillPrependsBeforeExisting(): void
    {
        $dispatcher = new Dispatcher();
        $dd = new DeferredDispatcher($dispatcher);

        $fired = [];
        $dispatcher->listen('bf', static function (Event $e) use (&$fired): void {
            $fired[] = $e->get('tag');
        });

        // 现有 3 个远未来任务
        $dd->deferAt(new Event('bf', ['tag' => 'f1']), [], time() + 1_000_000);
        $dd->deferAt(new Event('bf', ['tag' => 'f2']), [], time() + 1_000_000);
        $dd->deferAt(new Event('bf', ['tag' => 'f3']), [], time() + 1_000_000);

        // 回填 3 个历史事件（过去时间戳 → 立即到期，应前插到队首）
        $base = time() - 1000;
        $dd->deferBackfill([
            ['event' => new Event('bf', ['tag' => 'h1']), 'timestamp' => $base + 1],
            ['event' => new Event('bf', ['tag' => 'h2']), 'timestamp' => $base + 2],
            ['event' => new Event('bf', ['tag' => 'h3']), 'timestamp' => $base + 3],
        ]);

        $dd->process();

        $this->assertSame(['h1', 'h2', 'h3'], $fired, '回填的历史任务应在现有未来任务之前按序派发');
        $this->assertSame(3, $dd->count(), '现有未来任务应保留');
    }

    public function testBackfillReturnsInsertedIdsInDispatchOrder(): void
    {
        $dd = new DeferredDispatcher(new Dispatcher());

        $ids = $dd->deferBackfill([
            ['event' => new Event('bf'), 'timestamp' => time() - 30],
            ['event' => new Event('bf'), 'timestamp' => time() - 10],
            ['event' => new Event('bf'), 'timestamp' => time() - 20],
        ]);

        $this->assertCount(3, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)), '返回 id 互不重复');

        // 返回的 id 顺序应与 dispatchAt 升序一致（乱序输入应被排序）
        $ref = new \ReflectionObject($dd);
        $defProp = $ref->getProperty('deferred');
        $defProp->setAccessible(true);
        $def = $defProp->getValue($dd);

        $ats = array_map(static fn (int $id): int => $def[$id]['dispatchAt'], $ids);
        $sorted = $ats;
        sort($sorted);
        $this->assertSame($sorted, $ats, '返回的 id 必须按 dispatchAt 升序');
    }

    public function testBackfillEmptyReturnsEmptyArray(): void
    {
        $dd = new DeferredDispatcher(new Dispatcher());
        $this->assertSame([], $dd->deferBackfill([]));
    }

    public function testBackfillRejectsMissingEvent(): void
    {
        $dd = new DeferredDispatcher(new Dispatcher());
        $this->expectException(\InvalidArgumentException::class);
        $dd->deferBackfill([['timestamp' => time()]]);
    }

    public function testBackfillRejectsMissingTimestamp(): void
    {
        $dd = new DeferredDispatcher(new Dispatcher());
        $this->expectException(\InvalidArgumentException::class);
        $dd->deferBackfill([['event' => new Event('bf')]]);
    }

    public function testBackfillOrderIndexStaysSortedAcrossInterleave(): void
    {
        $dd = new DeferredDispatcher(new Dispatcher());

        // 现有：一个远未来任务
        $dd->deferAt(new Event('bf'), [], time() + 1_000_000);

        // 回填：交错的时间戳（过去 / 更远的未来 / 中间未来）
        $now = time();
        $dd->deferBackfill([
            ['event' => new Event('bf'), 'timestamp' => $now - 500],
            ['event' => new Event('bf'), 'timestamp' => $now + 2_000_000],
            ['event' => new Event('bf'), 'timestamp' => $now + 500],
        ]);

        $ref = new \ReflectionObject($dd);
        $orderProp = $ref->getProperty('order');
        $orderProp->setAccessible(true);
        $defProp = $ref->getProperty('deferred');
        $defProp->setAccessible(true);
        $order = $orderProp->getValue($dd);
        $def = $defProp->getValue($dd);

        $ats = array_map(static fn ($id): int => $def[$id]['dispatchAt'], $order);
        $sorted = $ats;
        sort($sorted);
        $this->assertSame($sorted, $ats, '归并后 order 索引必须按 dispatchAt 升序');
    }

    public function testBackfillKeepsFutureTasksRelativeOrder(): void
    {
        $dispatcher = new Dispatcher();
        $dd = new DeferredDispatcher($dispatcher);

        $fired = [];
        $dispatcher->listen('bf', static function (Event $e) use (&$fired): void {
            $fired[] = $e->get('tag');
        });

        // 现有：一个远未来任务
        $dd->deferAt(new Event('bf', ['tag' => 'F']), [], time() + 1_000_000);

        // 回填：一个历史事件（立即到期）+ 一个更远的未来（应排到 F 之后）
        $dd->deferBackfill([
            ['event' => new Event('bf', ['tag' => 'H']), 'timestamp' => time() - 1000],
            ['event' => new Event('bf', ['tag' => 'FF']), 'timestamp' => time() + 2_000_000],
        ]);

        $dd->process();

        $this->assertSame(['H'], $fired, '仅历史(到期)任务立即派发，未来任务保留');
        $this->assertSame(2, $dd->count(), '保留 F 与 FF 两个未来任务');
    }
}
