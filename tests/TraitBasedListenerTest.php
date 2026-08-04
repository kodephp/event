<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\EventBuilder;
use Kode\Event\EventDispatcherTrait;
use PHPUnit\Framework\TestCase;

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
