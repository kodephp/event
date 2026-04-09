<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\ListenerInterface;
use Kode\Event\SubscriberInterface;
use PHPUnit\Framework\TestCase;

class DispatcherTest extends TestCase
{
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new Dispatcher();
    }

    public function testCanListenAndDispatch(): void
    {
        $handled = false;

        $this->dispatcher->listen('test.event', function (Event $event) use (&$handled) {
            $handled = true;
            $this->assertSame('test.event', $event->getName());
        });

        $this->dispatcher->dispatch(new Event('test.event'));

        $this->assertTrue($handled);
    }

    public function testCanListenWithPriority(): void
    {
        $order = [];

        $this->dispatcher->listen('test', function () use (&$order) {
            $order[] = 'low';
        }, priority: -100);

        $this->dispatcher->listen('test', function () use (&$order) {
            $order[] = 'high';
        }, priority: 100);

        $this->dispatcher->listen('test', function () use (&$order) {
            $order[] = 'normal';
        }, priority: 0);

        $this->dispatcher->dispatch(new Event('test'));

        $this->assertSame(['high', 'normal', 'low'], $order);
    }

    public function testCanUnlisten(): void
    {
        $count = 0;
        $listener = function () use (&$count) {
            $count++;
        };

        $this->dispatcher->listen('test', $listener);
        $this->dispatcher->dispatch(new Event('test'));
        $this->assertSame(1, $count);

        $this->dispatcher->unlisten('test', $listener);
        $this->dispatcher->dispatch(new Event('test'));
        $this->assertSame(1, $count);
    }

    public function testCanSubscribe(): void
    {
        $handled = false;

        $subscriber = new class($handled) implements SubscriberInterface {
            public function __construct(private bool &$handled)
            {
            }

            public function subscribe(Dispatcher $dispatcher): void
            {
                $dispatcher->listen('test', function () {
                    $this->handled = true;
                });
            }
        };

        $this->dispatcher->subscribe($subscriber);
        $this->assertFalse($handled);

        $this->dispatcher->dispatch(new Event('test'));
        $this->assertTrue($handled);
    }

    public function testCanStopPropagation(): void
    {
        $order = [];

        $this->dispatcher->listen('test', function (Event $event) use (&$order) {
            $order[] = 'first';
            $event->stopPropagation();
        });

        $this->dispatcher->listen('test', function () use (&$order) {
            $order[] = 'second';
        });

        $this->dispatcher->dispatch(new Event('test'));

        $this->assertSame(['first'], $order);
    }

    public function testCanDispatchMultipleEvents(): void
    {
        $events = [
            new Event('a', ['data' => 1]),
            new Event('b', ['data' => 2]),
            new Event('c', ['data' => 3]),
        ];

        $results = $this->dispatcher->dispatchMany(...$events);

        $this->assertCount(3, $results);
        $this->assertSame(1, $results[0]->get('data'));
        $this->assertSame(2, $results[1]->get('data'));
        $this->assertSame(3, $results[2]->get('data'));
    }

    public function testCanDispatchStringEvent(): void
    {
        $handled = false;

        $this->dispatcher->listen('test', function (Event $event) use (&$handled) {
            $handled = true;
            $this->assertSame('data', $event->get('key'));
        });

        $this->dispatcher->dispatch('test', ['key' => 'data']);

        $this->assertTrue($handled);
    }

    public function testHasListeners(): void
    {
        $this->assertFalse($this->dispatcher->hasListeners('test'));

        $this->dispatcher->listen('test', function () {});
        $this->assertTrue($this->dispatcher->hasListeners('test'));
    }

    public function testCanClearListeners(): void
    {
        $this->dispatcher->listen('test', function () {});
        $this->assertTrue($this->dispatcher->hasListeners('test'));

        $this->dispatcher->clear('test');
        $this->assertFalse($this->dispatcher->hasListeners('test'));
    }

    public function testCanClearAllListeners(): void
    {
        $this->dispatcher->listen('test1', function () {});
        $this->dispatcher->listen('test2', function () {});

        $this->dispatcher->clear();

        $this->assertFalse($this->dispatcher->hasListeners('test1'));
        $this->assertFalse($this->dispatcher->hasListeners('test2'));
    }

    public function testWildcardMatching(): void
    {
        $events = [];

        $this->dispatcher->listen('user.*', function (Event $event) use (&$events) {
            $events[] = $event->getName();
        });

        $this->dispatcher->dispatch(new Event('user.created'));
        $this->dispatcher->dispatch(new Event('user.updated'));
        $this->dispatcher->dispatch(new Event('user.deleted'));
        $this->dispatcher->dispatch(new Event('post.created'));

        $this->assertSame(['user.created', 'user.updated', 'user.deleted'], $events);
    }

    public function testListenerInterfaceImplementation(): void
    {
        $state = new class {
            public bool $handled = false;
        };

        $listener = new class($state) implements ListenerInterface {
            public function __construct(private object $state)
            {
            }

            public function handle(Event $event): void
            {
                $this->state->handled = true;
            }

            public function events(): string|array
            {
                return 'test';
            }

            public function priority(): int
            {
                return 0;
            }
        };

        $this->dispatcher->listen('test', $listener);
        $this->assertFalse($state->handled);

        $this->dispatcher->dispatch(new Event('test'));
        $this->assertTrue($state->handled);
    }
}
