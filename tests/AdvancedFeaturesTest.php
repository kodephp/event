<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\AbstractEvent;
use Kode\Event\Attribute\Listener;
use Kode\Event\Attribute\Priority;
use Kode\Event\Attribute\Subscriber;
use Kode\Event\AttributeListenerRegistry;
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\EventGroup;
use Kode\Event\EventHelper;
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

class EventGroupTest extends TestCase
{
    public function testCanCreateEventGroup(): void
    {
        $group = EventGroup::create('user.', '.event');

        $this->assertSame('user.', $group->getPrefix());
        $this->assertSame('.event', $group->getSuffix());
    }

    public function testCanRegisterListeners(): void
    {
        $group = EventGroup::prefix('user.');
        $handled = false;

        $group->on('created', function () use (&$handled) {
            $handled = true;
        });

        $dispatcher = new Dispatcher();
        $group->attach($dispatcher);

        $this->assertTrue($dispatcher->hasListeners('user.created'));
        $dispatcher->dispatch(new Event('user.created'));
        $this->assertTrue($handled);
    }

    public function testCanDetach(): void
    {
        $group = EventGroup::prefix('user.');
        $count = 0;

        $listener = function () use (&$count) {
            $count++;
        };

        $group->on('created', $listener);
        $group->on('updated', $listener);

        $dispatcher = new Dispatcher();
        $group->attach($dispatcher);

        $this->assertTrue($dispatcher->hasListeners('user.created'));

        $group->detach($dispatcher);

        $this->assertFalse($dispatcher->hasListeners('user.created'));
        $this->assertFalse($dispatcher->hasListeners('user.updated'));
    }

    public function testOnceListener(): void
    {
        $group = EventGroup::prefix('app.');
        $count = 0;

        $group->once('start', function () use (&$count) {
            $count++;
        });

        $dispatcher = new Dispatcher();
        $group->attach($dispatcher);

        $dispatcher->dispatch(new Event('app.start'));
        $dispatcher->dispatch(new Event('app.start'));
        $dispatcher->dispatch(new Event('app.start'));

        $this->assertSame(1, $count);
    }
}

class AbstractEventTest extends TestCase
{
    public function testCanExtendAbstractEvent(): void
    {
        $event = new class(['data' => 'value']) extends AbstractEvent {
            protected function getEventName(): string
            {
                return 'custom.event';
            }
        };

        $this->assertSame('custom.event', $event->getName());
        $this->assertSame('value', $event->get('data'));
    }

    public function testAbstractEventIsStringable(): void
    {
        $event = new class([]) extends AbstractEvent {
            protected function getEventName(): string
            {
                return 'test';
            }
        };

        $this->assertStringContainsString('test', (string) $event);
    }
}

class EventHelperTest extends TestCase
{
    public function testIsValidName(): void
    {
        $this->assertTrue(EventHelper::isValidName('user.created'));
        $this->assertTrue(EventHelper::isValidName('order.paid'));
        $this->assertTrue(EventHelper::isValidName('app_start'));
        $this->assertFalse(EventHelper::isValidName('123.invalid'));
        $this->assertFalse(EventHelper::isValidName(''));
    }

    public function testNormalizeName(): void
    {
        $this->assertSame('user.created', EventHelper::normalizeName(' User.Created '));
    }

    public function testParseName(): void
    {
        $parsed = EventHelper::parseName('user.profile.updated');

        $this->assertSame('user', $parsed['prefix']);
        $this->assertSame('profile', $parsed['name']);
        $this->assertSame('updated', $parsed['suffix']);
    }

    public function testMatchesPattern(): void
    {
        $this->assertTrue(EventHelper::matchesPattern('user.created', 'user.*'));
        $this->assertTrue(EventHelper::matchesPattern('user.created', '*.created'));
        $this->assertTrue(EventHelper::matchesPattern('user.created', '*.*'));
        $this->assertFalse(EventHelper::matchesPattern('user.created', 'order.*'));
    }

    public function testGetPhpFeatures(): void
    {
        $features = EventHelper::getPhpFeatures();

        $this->assertIsArray($features);
        $this->assertArrayHasKey('enum', $features);
        $this->assertArrayHasKey('union_types', $features);
        $this->assertArrayHasKey('readonly', $features);
    }
}
