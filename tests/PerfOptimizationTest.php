<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use PHPUnit\Framework\TestCase;

/**
 * 性能优化相关的正确性回归测试
 *
 * 锁定 v1.14.0 的优化不破坏既有语义：
 * - getListeners 在「仅精确桶」路径跳过 usort，依赖注册时已排序；
 * - 命中通配符路径仍需重新合并排序。
 */
class PerfOptimizationTest extends TestCase
{
    public function testExactOnlyOrderingPreservedWithoutResort(): void
    {
        $d = new Dispatcher();
        $order = [];

        // 乱序注册，验证最终按优先级降序（注册时已排序，getListeners 不再排序）
        $d->listen('order.check', static function (Event $e) use (&$order): void {
            $order[] = 'p0';
        }, 0);
        $d->listen('order.check', static function (Event $e) use (&$order): void {
            $order[] = 'p10';
        }, 10);
        $d->listen('order.check', static function (Event $e) use (&$order): void {
            $order[] = 'p5';
        }, 5);

        $d->dispatch('order.check');

        self::assertSame(['p10', 'p5', 'p0'], $order);
    }

    public function testWildcardMergeStillSorted(): void
    {
        $d = new Dispatcher();
        $order = [];

        $d->listen('user.created', static function (Event $e) use (&$order): void {
            $order[] = 'exact-5';
        }, 5);
        $d->listen('*', static function (Event $e) use (&$order): void {
            $order[] = 'star-1';
        }, 1);
        $d->listen('user.*', static function (Event $e) use (&$order): void {
            $order[] = 'wc-8';
        }, 8);

        $d->dispatch('user.created');

        // 命中通配符路径必须重新合并排序：8 > 5 > 1
        self::assertSame(['wc-8', 'exact-5', 'star-1'], $order);
    }

    public function testListenerRegistryGetListenersOrdering(): void
    {
        $registry = new \Kode\Event\ListenerRegistry();
        $seq = [];

        $registry->listen('evt.x', static function (Event $e) use (&$seq): void {
            $seq[] = 'a';
        }, 1);
        $registry->listen('evt.x', static function (Event $e) use (&$seq): void {
            $seq[] = 'b';
        }, 3);
        $registry->listen('evt.x', static function (Event $e) use (&$seq): void {
            $seq[] = 'c';
        }, 2);

        $listeners = $registry->getListeners('evt.x');
        self::assertCount(3, $listeners);
        self::assertSame([3, 2, 1], array_column($listeners, 'priority'));
    }
}
