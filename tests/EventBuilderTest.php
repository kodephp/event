<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\EventBuilder;
use Kode\Event\EventDispatcherTrait;
use PHPUnit\Framework\TestCase;

class EventBuilderTest extends TestCase
{
    public function testCanCreateEventWithBuilder(): void
    {
        $event = EventBuilder::create('user.created')
            ->with('name', '张三')
            ->with('email', 'zhangsan@example.com')
            ->data(['age' => 25])
            ->traceId('trace-123')
            ->meta('source', 'api')
            ->build();

        $this->assertSame('user.created', $event->getName());
        $this->assertSame('张三', $event->get('name'));
        $this->assertSame('zhangsan@example.com', $event->get('email'));
        $this->assertSame(25, $event->get('age'));
        $this->assertSame('trace-123', $event->get('trace_id'));
        $this->assertSame('api', $event->get('meta.source'));
    }

    public function testCanDispatchFromBuilder(): void
    {
        $dispatcher = new Dispatcher();
        $handled = false;

        $dispatcher->listen('test', function (Event $event) use (&$handled) {
            $handled = true;
            $this->assertSame('data-value', $event->get('key'));
        });

        EventBuilder::create('test')
            ->with('key', 'data-value')
            ->dispatch($dispatcher);

        $this->assertTrue($handled);
    }
}

class TraitBasedListenerTest extends TestCase
{
    public function testCanUseTrait(): void
    {
        $listener = new class {
            use EventDispatcherTrait;

            public array $handled = [];

            public function process(): void
            {
                $this->emit('start', ['step' => 1]);
                $this->emit('process', ['step' => 2]);
                $this->emit('end', ['step' => 3]);
            }
        };

        $listener->on('start', fn(Event $e) => $listener->handled[] = 'start:' . $e->get('step'));
        $listener->on('process', fn(Event $e) => $listener->handled[] = 'process:' . $e->get('step'));
        $listener->on('end', fn(Event $e) => $listener->handled[] = 'end:' . $e->get('step'));

        $listener->process();

        $this->assertSame(['start:1', 'process:2', 'end:3'], $listener->handled);
    }

    public function testOnceListener(): void
    {
        $listener = new class {
            use EventDispatcherTrait;

            public int $count = 0;

            public function trigger(): void
            {
                $this->emit('click');
            }
        };

        $listener->once('click', function () use ($listener) {
            $listener->count++;
        });

        $listener->trigger();
        $listener->trigger();
        $listener->trigger();

        $this->assertSame(1, $listener->count);
    }
}
