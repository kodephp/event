<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Context\Context as KodeContext;
use Kode\Event\Dispatcher;
use Kode\Event\DistributedEventTracer;
use Kode\Event\Event;
use PHPUnit\Framework\TestCase;

/**
 * Dispatcher 与 DistributedEventTracer 的可选自动接线测试
 */
class DispatcherTracerTest extends TestCase
{
    protected function setUp(): void
    {
        KodeContext::reset();
    }

    protected function tearDown(): void
    {
        KodeContext::reset();
    }

    public function testSetTracerReturnsStaticAndGetTracer(): void
    {
        $dispatcher = new Dispatcher();
        $tracer = new DistributedEventTracer();

        $same = $dispatcher->setTracer($tracer);
        $this->assertSame($dispatcher, $same);
        $this->assertSame($tracer, $dispatcher->getTracer());
    }

    public function testDispatchInjectsTraceparentWhenTracerPresent(): void
    {
        $dispatcher = new Dispatcher();
        $tracer = new DistributedEventTracer();
        $dispatcher->setTracer($tracer);

        $captured = null;
        $dispatcher->listen('order.created', function (Event $event) use (&$captured) {
            $captured = $event;
        });

        $dispatcher->dispatch('order.created', ['id' => 42]);

        $this->assertInstanceOf(Event::class, $captured);
        $this->assertTrue($captured->has('traceparent'));
        $this->assertSame($tracer->getTraceparent(), $captured->get('traceparent'));
    }

    public function testDispatchDoesNotInjectWithoutTracer(): void
    {
        $dispatcher = new Dispatcher();

        $captured = null;
        $dispatcher->listen('order.created', function (Event $event) use (&$captured) {
            $captured = $event;
        });

        $dispatcher->dispatch('order.created', ['id' => 42]);

        $this->assertInstanceOf(Event::class, $captured);
        $this->assertFalse($captured->has('traceparent'));
    }

    public function testUntilAlsoPropagatesTrace(): void
    {
        $dispatcher = new Dispatcher();
        $tracer = new DistributedEventTracer();
        $dispatcher->setTracer($tracer);

        $captured = null;
        $dispatcher->listen('item.lookup', function (Event $event) use (&$captured) {
            $captured = $event;
            return 'found';
        });

        $result = $dispatcher->until('item.lookup', ['id' => 1]);

        $this->assertSame('found', $result);
        $this->assertInstanceOf(Event::class, $captured);
        $this->assertTrue($captured->has('traceparent'));
        $this->assertSame($tracer->getTraceparent(), $captured->get('traceparent'));
    }

    public function testStringNamedEventGetsTraceparentWhenTracerPresent(): void
    {
        $dispatcher = new Dispatcher();
        $tracer = new DistributedEventTracer();
        $dispatcher->setTracer($tracer);

        $captured = null;
        $dispatcher->listen('ping', function (Event $event) use (&$captured) {
            $captured = $event;
        });

        $dispatcher->dispatch('ping');

        $this->assertInstanceOf(Event::class, $captured);
        $this->assertTrue($captured->has('traceparent'));
    }

    public function testTypedObjectEventNotInjectedWithTraceparent(): void
    {
        $dispatcher = new Dispatcher();
        $tracer = new DistributedEventTracer();
        $dispatcher->setTracer($tracer);

        $captured = null;
        $dispatcher->listen(TypedPingEvent::class, function (object $event) use (&$captured) {
            $captured = $event;
        });

        $dispatcher->dispatch(new TypedPingEvent());

        // 非 Event 实例，无法注入 traceparent（保持类型化对象原样）
        $this->assertInstanceOf(TypedPingEvent::class, $captured);
    }
}

/**
 * 用于测试「类型化事件对象」不会被动注入 traceparent
 */
class TypedPingEvent
{
}
