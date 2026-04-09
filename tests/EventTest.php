<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Event;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testCanCreateEvent(): void
    {
        $event = new Event('test.event', ['key' => 'value']);

        $this->assertSame('test.event', $event->getName());
        $this->assertSame(['key' => 'value'], $event->getData());
    }

    public function testCanGetEventData(): void
    {
        $event = new Event('test', ['name' => '张三', 'age' => 25]);

        $this->assertSame('张三', $event->get('name'));
        $this->assertSame(25, $event->get('age'));
        $this->assertNull($event->get('not-exist'));
        $this->assertSame('default', $event->get('not-exist', 'default'));
    }

    public function testCanSetEventData(): void
    {
        $event = new Event('test');
        $event->set('key', 'value');

        $this->assertSame('value', $event->get('key'));
    }

    public function testCanFillEventData(): void
    {
        $event = new Event('test', ['a' => 1]);
        $event->fill(['b' => 2, 'c' => 3]);

        $this->assertSame(1, $event->get('a'));
        $this->assertSame(2, $event->get('b'));
        $this->assertSame(3, $event->get('c'));
    }

    public function testCanCheckDataExists(): void
    {
        $event = new Event('test', ['key' => 'value']);

        $this->assertTrue($event->has('key'));
        $this->assertFalse($event->has('not-exist'));
    }

    public function testCanStopPropagation(): void
    {
        $event = new Event('test');

        $this->assertFalse($event->isPropagationStopped());

        $event->stopPropagation();

        $this->assertTrue($event->isPropagationStopped());
    }

    public function testCanGetTimestamp(): void
    {
        $event = new Event('test');
        $this->assertIsFloat($event->getTimestamp());
    }

    public function testCanGetElapsed(): void
    {
        $event = new Event('test');
        usleep(1000);
        $elapsed = $event->getElapsed();

        $this->assertGreaterThan(0, $elapsed);
    }

    public function testEventIsStringable(): void
    {
        $event = new Event('user.created');
        $this->assertSame('Event(user.created)', (string) $event);
    }

    public function testStaticCreate(): void
    {
        $event = Event::create('test', ['data' => 123]);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('test', $event->getName());
        $this->assertSame(123, $event->get('data'));
    }
}
