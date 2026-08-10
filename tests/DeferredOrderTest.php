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
}
