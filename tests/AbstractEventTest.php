<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\AbstractEvent;
use PHPUnit\Framework\TestCase;

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
