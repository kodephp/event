<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Attribute\Listener;
use Kode\Event\AttributeListenerRegistry;
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use PHPUnit\Framework\TestCase;

class AttributeListenerRegistryTest extends TestCase
{
    public function testCanRegisterAttributeBasedListener(): void
    {
        $dispatcher = new Dispatcher();
        $registry = new AttributeListenerRegistry($dispatcher);

        $handled = false;

        $subscriber = new class($handled) {
            public bool $flag;

            public function __construct(private bool &$ref)
            {
                $this->flag = &$ref;
            }

            #[Listener('test.event')]
            public function onTest(): void
            {
                $this->flag = true;
            }
        };

        $registry->register($subscriber);

        $this->assertFalse($handled);
        $dispatcher->dispatch(new Event('test.event'));
        $this->assertTrue($handled);
    }

    public function testCanRegisterMultipleEvents(): void
    {
        $dispatcher = new Dispatcher();
        $registry = new AttributeListenerRegistry($dispatcher);

        $subscriber = new class {
            public array $handled = [];

            #[Listener(['event.a', 'event.b', 'event.c'])]
            public function onEvents(): void
            {
                $this->handled[] = 'fired';
            }
        };

        $registry->register($subscriber);

        $this->assertEmpty($subscriber->handled);

        $dispatcher->dispatch(new Event('event.a'));
        $this->assertCount(1, $subscriber->handled);

        $dispatcher->dispatch(new Event('event.b'));
        $this->assertCount(2, $subscriber->handled);
    }

    public function testCanSetPriority(): void
    {
        $dispatcher = new Dispatcher();
        $registry = new AttributeListenerRegistry($dispatcher);

        $subscriber = new class {
            public array $order = [];

            #[Listener('test', priority: -100)]
            public function lowPriority(): void
            {
                $this->order[] = 'low';
            }

            #[Listener('test', priority: 100)]
            public function highPriority(): void
            {
                $this->order[] = 'high';
            }
        };

        $registry->register($subscriber);
        $dispatcher->dispatch(new Event('test'));

        $this->assertSame(['high', 'low'], $subscriber->order);
    }
}
