<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Event;
use Kode\Event\EventInterceptorInterface;
use Kode\Event\InterceptorRegistry;
use PHPUnit\Framework\TestCase;

class InterceptorRegistryTest extends TestCase
{
    public function testCanAddAndRemoveInterceptors(): void
    {
        $registry = new InterceptorRegistry();

        $interceptor = new class implements EventInterceptorInterface {
            public function intercept(Event $event): ?Event
            {
                return $event;
            }

            public function getName(): string
            {
                return 'test';
            }

            public function getPriority(): int
            {
                return 0;
            }
        };

        $registry->add($interceptor);
        $this->assertCount(1, $registry->all());

        $registry->remove('test');
        $this->assertCount(0, $registry->all());
    }

    public function testCanIntercept(): void
    {
        $registry = new InterceptorRegistry();
        $intercepted = false;

        $interceptor = new class($intercepted) implements EventInterceptorInterface {
            public function __construct(private bool &$flag)
            {
            }

            public function intercept(Event $event): ?Event
            {
                $this->flag = true;
                return $event;
            }

            public function getName(): string
            {
                return 'test';
            }

            public function getPriority(): int
            {
                return 0;
            }
        };

        $registry->add($interceptor);

        $event = new Event('test');
        $result = $registry->intercept($event);

        $this->assertSame($event, $result);
        $this->assertTrue($intercepted);
    }

    public function testInterceptorPriority(): void
    {
        $registry = new InterceptorRegistry();
        $order = [];

        $registry->add(new class($order, 'low', -100) implements EventInterceptorInterface {
            public function __construct(private array &$order, private string $name, private int $priority)
            {
            }

            public function intercept(Event $event): Event
            {
                $this->order[] = $this->name;
                return $event;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getPriority(): int
            {
                return $this->priority;
            }
        });

        $registry->add(new class($order, 'high', 100) implements EventInterceptorInterface {
            public function __construct(private array &$order, private string $name, private int $priority)
            {
            }

            public function intercept(Event $event): Event
            {
                $this->order[] = $this->name;
                return $event;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getPriority(): int
            {
                return $this->priority;
            }
        });

        $registry->add(new class($order, 'normal', 0) implements EventInterceptorInterface {
            public function __construct(private array &$order, private string $name, private int $priority)
            {
            }

            public function intercept(Event $event): Event
            {
                $this->order[] = $this->name;
                return $event;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getPriority(): int
            {
                return $this->priority;
            }
        });

        $registry->intercept(new Event('test'));

        $this->assertSame(['high', 'normal', 'low'], $order);
    }

    public function testCanClearInterceptors(): void
    {
        $registry = new InterceptorRegistry();

        $interceptor = new class implements EventInterceptorInterface {
            public function intercept(Event $event): Event
            {
                return $event;
            }

            public function getName(): string
            {
                return 'test';
            }

            public function getPriority(): int
            {
                return 0;
            }
        };

        $registry->add($interceptor);
        $registry->clear();

        $this->assertCount(0, $registry->all());
    }
}
