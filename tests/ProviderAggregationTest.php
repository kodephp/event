<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\ListenerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * 外部 PSR-14 监听器提供者聚合测试
 */
class ProviderAggregationTest extends TestCase
{
    /**
     * 构造一个只监听 user.created 的 PSR-14 提供者
     */
    private function userProvider(): ListenerProviderInterface
    {
        return new class implements ListenerProviderInterface {
            public function getListenersForEvent(object $event): iterable
            {
                if ($event instanceof Event && $event->getName() === 'user.created') {
                    yield function (Event $e): void {
                        $e->setMeta('provider_called', true);
                    };
                }
            }
        };
    }

    public function testRegistryAddProviderReturnsSelf(): void
    {
        $registry = new ListenerRegistry();
        $this->assertSame($registry, $registry->addProvider($this->userProvider()));
    }

    public function testDispatcherAddProviderReturnsStatic(): void
    {
        $dispatcher = new Dispatcher();
        $this->assertSame($dispatcher, $dispatcher->addProvider($this->userProvider()));
    }

    public function testHasAndGetProviders(): void
    {
        $registry = new ListenerRegistry();
        $this->assertFalse($registry->hasProviders());
        $this->assertSame([], $registry->getProviders());

        $registry->addProvider($this->userProvider());
        $this->assertTrue($registry->hasProviders());
        $this->assertCount(1, $registry->getProviders());
    }

    public function testProviderListenerFiresOnNamedEvent(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->addProvider($this->userProvider());

        $event = $dispatcher->dispatch('user.created', ['id' => 1]);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertTrue($event->getMeta('provider_called'));
    }

    public function testInternalAndProviderListenersBothFire(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->addProvider($this->userProvider());
        $dispatcher->listen('user.created', function (Event $e): void {
            $e->setMeta('internal_called', true);
        });

        $event = $dispatcher->dispatch('user.created', []);

        $this->assertTrue($event->getMeta('internal_called'));
        $this->assertTrue($event->getMeta('provider_called'));
    }

    public function testProviderRunsAfterInternalSamePriority(): void
    {
        // 同优先级下，外部提供者的 seq 为 PHP_INT_MAX，应最后执行
        $provider = new class implements ListenerProviderInterface {
            public function getListenersForEvent(object $event): iterable
            {
                if ($event instanceof Event && $event->getName() === 'user.created') {
                    yield function (Event $e): void {
                        $e->setMeta('last_writer', 'provider');
                    };
                }
            }
        };

        $dispatcher = new Dispatcher();
        $dispatcher->addProvider($provider);
        $dispatcher->listen('user.created', function (Event $e): void {
            $e->setMeta('last_writer', 'internal');
        });

        $event = $dispatcher->dispatch('user.created', []);

        $this->assertSame('provider', $event->getMeta('last_writer'));
    }

    public function testGetListenersForEventIncludesProvider(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->addProvider($this->userProvider());

        $count = 0;
        foreach ($dispatcher->getRegistry()->getListenersForEvent(new Event('user.created')) as $listener) {
            $count++;
        }
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testObjectEventTriggersProvider(): void
    {
        $provider = new class implements ListenerProviderInterface {
            public function getListenersForEvent(object $event): iterable
            {
                if ($event instanceof \stdClass && ($event->name ?? null) === 'order') {
                    yield function (\stdClass $e): void {
                        $e->handled = true;
                    };
                }
            }
        };

        $dispatcher = new Dispatcher();
        $dispatcher->addProvider($provider);

        $object = new \stdClass();
        $object->name = 'order';
        $dispatcher->dispatch($object);

        $this->assertTrue($object->handled);
    }

    public function testClearProviders(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->addProvider($this->userProvider());
        $this->assertTrue($dispatcher->getRegistry()->hasProviders());

        $dispatcher->getRegistry()->clearProviders();
        $this->assertFalse($dispatcher->getRegistry()->hasProviders());

        $event = $dispatcher->dispatch('user.created', []);
        $this->assertNull($event->getMeta('provider_called'));
    }
}
