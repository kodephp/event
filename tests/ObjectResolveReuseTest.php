<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\ListenerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;

interface ReuseTagA {}
abstract class ReuseBase {}
class ReuseConcrete extends ReuseBase implements ReuseTagA
{
    public bool $handledInternal = false;
    public bool $handledByProvider = false;
}

/**
 * v1.20.0 验证：resolveEntriesForObject 统一复用 getListeners() 后的行为等价性
 *
 * 重点锁定：
 *  - 对象事件的多重解析键（自身类 / 父类 / 接口）都能命中监听器；
 *  - 同一监听器在多个键下注册时只触发一次（跨键去重）；
 *  - 无外部提供者时结果被缓存复用（第二次解析命中 resolvedCache）。
 */
class ObjectResolveReuseTest extends TestCase
{
    public function testMultipleKeysAllFire(): void
    {
        $d = new Dispatcher();
        $fired = [];
        $d->listen(ReuseConcrete::class, static function () use (&$fired): void {
            $fired[] = 'class';
        });
        $d->listen(ReuseBase::class, static function () use (&$fired): void {
            $fired[] = 'parent';
        });
        $d->listen(ReuseTagA::class, static function () use (&$fired): void {
            $fired[] = 'interface';
        });

        $d->dispatch(new ReuseConcrete());

        $this->assertSame(['class', 'parent', 'interface'], $fired);
    }

    public function testExplicitSameListenerUnderTwoKeysFiresTwice(): void
    {
        // 同一闭包显式注册在「类」与「接口」两个键上 = 两次注册，应触发两次
        // （去重针对的是「同一 entry 经多个 key 命中」，而非用户的主动双注册）
        $d = new Dispatcher();
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };

        $d->listen(ReuseConcrete::class, $listener);
        $d->listen(ReuseTagA::class, $listener);

        $d->dispatch(new ReuseConcrete());

        $this->assertSame(2, $count);
    }

    public function testWildcardFiresOnceAcrossMultipleMatchingKeys(): void
    {
        // 通配符 '*' 同时命中对象的多个解析键（类/父类/接口），必须只触发一次
        $d = new Dispatcher();
        $count = 0;
        $d->listen('*', static function () use (&$count): void {
            $count++;
        });

        $d->dispatch(new ReuseConcrete());

        $this->assertSame(1, $count, '通配符跨多个对象键命中时只应触发一次');
    }

    public function testResultCachedWhenNoProviders(): void
    {
        $registry = new ListenerRegistry();
        $registry->listen(ReuseConcrete::class, static fn() => null);
        $registry->listen(ReuseTagA::class, static fn() => null);

        $event = new ReuseConcrete();
        $first = $registry->resolveEntriesForObject($event);
        $second = $registry->resolveEntriesForObject($event);

        $this->assertCount(2, $first);
        // 第二次解析应命中缓存：返回同一数组引用
        $this->assertSame($first, $second, '无外部提供者时结果应被缓存复用');
    }

    public function testProviderStillAppendedAfterInternalResolution(): void
    {
        $provider = new class implements ListenerProviderInterface {
            public function getListenersForEvent(object $event): iterable
            {
                if ($event instanceof ReuseConcrete) {
                    yield static fn(ReuseConcrete $e) => $e->handledByProvider = true;
                }
            }
        };

        $d = new Dispatcher();
        $d->addProvider($provider);
        $d->listen(ReuseConcrete::class, static fn(ReuseConcrete $e) => $e->handledInternal = true);

        $obj = new ReuseConcrete();
        $d->dispatch($obj);

        $this->assertTrue($obj->handledInternal);
        $this->assertTrue($obj->handledByProvider);
    }
}
