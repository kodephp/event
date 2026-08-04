<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\EventGroup;
use PHPUnit\Framework\TestCase;

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
