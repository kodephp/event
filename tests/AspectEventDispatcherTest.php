<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Aop\AspectEventDispatcher;
use Kode\Event\Event;
use PHPUnit\Framework\TestCase;

/**
 * AspectEventDispatcher 在 v1.7.0 修复了 dispatch() 签名与父类不兼容
 * （曾导致类无法加载）以及调用了两个不存在的方法的致命缺陷。
 */
class AspectEventDispatcherTest extends TestCase
{
    public function testClassIsLoadable(): void
    {
        $this->assertTrue(class_exists(AspectEventDispatcher::class));
    }

    public function testDispatchWithStringNameFiresListener(): void
    {
        $dispatcher = new AspectEventDispatcher();
        $hit = false;

        $dispatcher->listen('user.created', function (Event $event) use (&$hit): void {
            $hit = true;
            $this->assertSame('user.created', $event->getName());
        });

        $result = $dispatcher->dispatch('user.created', ['id' => 1]);

        $this->assertTrue($hit);
        $this->assertInstanceOf(Event::class, $result);
    }

    public function testDispatchWithEventObjectReturnsSameInstance(): void
    {
        $dispatcher = new AspectEventDispatcher();
        $event = new Event('order.paid');
        $hit = false;

        $dispatcher->listen('order.paid', function (Event $e) use (&$hit): void {
            $hit = true;
        });

        $result = $dispatcher->dispatch($event);

        $this->assertTrue($hit);
        $this->assertSame($event, $result);
    }

    public function testDispatchRespectsPropagationStop(): void
    {
        $dispatcher = new AspectEventDispatcher();
        $second = false;

        $dispatcher->listen('app.start', function (Event $event): void {
            $event->stopPropagation('halted');
        });
        $dispatcher->listen('app.start', function (Event $event) use (&$second): void {
            $second = true;
        });

        $dispatcher->dispatch('app.start');

        $this->assertFalse($second, '后续监听器在传播停止后不应执行');
    }

    public function testAopUnavailableWithoutKodeAop(): void
    {
        $this->assertFalse(AspectEventDispatcher::isAopAvailable());
    }
}
