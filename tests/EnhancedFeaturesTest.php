<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\BatchEventBuilder;
use Kode\Event\DeferredDispatcher;
use Kode\Event\Event;
use Kode\Event\EventBubbles;
use Kode\Event\EventFilter;
use Kode\Event\EventTracer;
use Kode\Event\Dispatcher;
use PHPUnit\Framework\TestCase;

class EnhancedFeaturesTest extends TestCase
{
    public function testEventBubbles(): void
    {
        $dispatcher = new Dispatcher();
        $bubbles = new EventBubbles($dispatcher);

        $parentEvents = [];

        $bubbles->registerParent('user.created', 'user.activity');
        $bubbles->registerParent('user.updated', 'user.activity');

        $bubbles->enable();

        $dispatcher->listen('user.activity', function (Event $e) use (&$parentEvents) {
            $parentEvents[] = $e->getName();
        });

        $bubbles->bubble(new Event('user.created', ['user' => 1]));

        $this->assertSame(['user.activity'], $parentEvents);
    }

    public function testEventBubblesWithMultipleParents(): void
    {
        $dispatcher = new Dispatcher();
        $bubbles = new EventBubbles($dispatcher);

        $parentEvents = [];

        $bubbles->registerParent('order.created', 'order.activity');
        $bubbles->registerParent('order.created', 'audit.log');

        $dispatcher->listen('order.activity', function () use (&$parentEvents) {
            $parentEvents[] = 'order.activity';
        });

        $dispatcher->listen('audit.log', function () use (&$parentEvents) {
            $parentEvents[] = 'audit.log';
        });

        $bubbles->bubble(new Event('order.created'));

        $this->assertSame(['order.activity', 'audit.log'], $parentEvents);
    }

    public function testEventBubblesDisabled(): void
    {
        $dispatcher = new Dispatcher();
        $bubbles = new EventBubbles($dispatcher);

        $parentEvents = [];

        $bubbles->registerParent('user.created', 'user.activity');
        $bubbles->disable();

        $dispatcher->listen('user.activity', function () use (&$parentEvents) {
            $parentEvents[] = 'user.activity';
        });

        $bubbles->bubble(new Event('user.created'));

        $this->assertEmpty($parentEvents);
    }

    public function testEventFilter(): void
    {
        $filter = new EventFilter();

        $filter->add('user.created', function (Event $event) {
            $event->set('filtered', true);
            $event->set('name', strtoupper($event->get('name')));
            return $event;
        });

        $event = new Event('user.created', ['name' => 'test']);
        $filtered = $filter->filter($event);

        $this->assertSame('TEST', $filtered->get('name'));
        $this->assertTrue($filtered->get('filtered'));
    }

    public function testEventFilterWithMultiple(): void
    {
        $filter = new EventFilter();

        $filter->add('test', function (Event $event) {
            $event->set('step1', true);
            return $event;
        }, priority: 1);

        $filter->add('test', function (Event $event) {
            $event->set('step2', true);
            return $event;
        }, priority: 2);

        $event = new Event('test');
        $filtered = $filter->filter($event);

        $this->assertTrue($filtered->get('step1'));
        $this->assertTrue($filtered->get('step2'));
    }

    public function testEventFilterRemove(): void
    {
        $filter = new EventFilter();

        $callable = function (Event $event) {
            $event->set('called', true);
            return $event;
        };

        $filter->add('test', $callable);
        $filter->remove('test', $callable);

        $event = new Event('test');
        $filtered = $filter->filter($event);

        $this->assertNull($filtered->get('called'));
    }

    public function testDeferredDispatcher(): void
    {
        $dispatcher = new Dispatcher();
        $deferred = new DeferredDispatcher($dispatcher);

        $handled = false;

        $dispatcher->listen('test.event', function () use (&$handled) {
            $handled = true;
        });

        $deferred->defer(new Event('test.event'), [], 0);

        $this->assertFalse($handled);
        $this->assertSame(1, $deferred->count());

        $deferred->process();

        $this->assertTrue($handled);
    }

    public function testDeferredDispatcherCancel(): void
    {
        $dispatcher = new Dispatcher();
        $deferred = new DeferredDispatcher($dispatcher);

        $handled = false;
        $dispatcher->listen('test.event', function () use (&$handled) {
            $handled = true;
        });

        $id = $deferred->defer(new Event('test.event'));
        $deferred->cancel($id);

        $this->assertSame(0, $deferred->count());
        $deferred->process();
        $this->assertFalse($handled);
    }

    public function testDeferredDispatcherMultiple(): void
    {
        $dispatcher = new Dispatcher();
        $deferred = new DeferredDispatcher($dispatcher);

        $events = [];
        $dispatcher->listen('e1', function () use (&$events) {
            $events[] = 'e1';
        });
        $dispatcher->listen('e2', function () use (&$events) {
            $events[] = 'e2';
        });

        $deferred->defer(new Event('e1'));
        $deferred->defer(new Event('e2'));

        $deferred->processAll();

        $this->assertSame(['e1', 'e2'], $events);
    }

    public function testEventTracer(): void
    {
        $dispatcher = new Dispatcher();
        $tracer = new EventTracer($dispatcher);

        $tracer->enable();

        $handled = false;
        $dispatcher->listen('test', function () use (&$handled) {
            $handled = true;
        });

        $event = new Event('test', ['data' => 'value']);
        $tracer->trace($event, function () use ($event, $dispatcher) {
            $dispatcher->dispatch($event);
        });

        $this->assertTrue($handled);
        $this->assertSame(1, $tracer->count());

        $trace = $tracer->getRecentTraces(1)[0];
        $this->assertSame('test', $trace['event']);
        $this->assertSame(['data' => 'value'], $trace['data']);
        $this->assertNotNull($trace['duration']);
    }

    public function testEventTracerDisabled(): void
    {
        $dispatcher = new Dispatcher();
        $tracer = new EventTracer($dispatcher);

        $tracer->disable();

        $event = new Event('test');
        $result = $tracer->trace($event, function () {
            return 'test_result';
        });

        $this->assertSame('test_result', $result);
        $this->assertSame(0, $tracer->count());
    }

    public function testBatchEventBuilder(): void
    {
        $dispatcher = new Dispatcher();
        $batch = new BatchEventBuilder($dispatcher);

        $results = [];
        $dispatcher->listen('user.created', function (Event $e) use (&$results) {
            $results[] = $e->getName();
        });
        $dispatcher->listen('user.updated', function (Event $e) use (&$results) {
            $results[] = $e->getName();
        });

        $batch->create('user.created')
              ->create('user.updated')
              ->dispatch();

        $this->assertCount(2, $results);
    }

    public function testBatchEventBuilderWithPrefixSuffix(): void
    {
        $dispatcher = new Dispatcher();
        $batch = BatchEventBuilder::batch($dispatcher);

        $results = [];
        $dispatcher->listen('app.user.created', function () use (&$results) {
            $results[] = 'fired';
        });

        $batch->prefix('app.')
              ->suffix('.created')
              ->create('user')
              ->dispatch();

        $this->assertCount(1, $results);
    }

    public function testBatchEventBuilderWithData(): void
    {
        $dispatcher = new Dispatcher();
        $batch = BatchEventBuilder::batch($dispatcher);

        $received = null;
        $dispatcher->listen('user.created', function (Event $e) use (&$received) {
            $received = $e->getData();
        });

        $batch->defaults(['source' => 'batch'])
              ->with('user.created', ['name' => 'test'])
              ->dispatch();

        $this->assertSame('batch', $received['source']);
        $this->assertSame('test', $received['name']);
    }

    public function testBatchEventBuilderBuild(): void
    {
        $dispatcher = new Dispatcher();
        $batch = BatchEventBuilder::batch($dispatcher);

        $batch->prefix('app.')
              ->create('start')
              ->create('stop');

        $events = $batch->build();

        $this->assertCount(2, $events);
        $this->assertSame('app.start', $events[0]->getName());
        $this->assertSame('app.stop', $events[1]->getName());
    }
}