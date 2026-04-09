<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\EventPriority;
use PHPUnit\Framework\TestCase;

class EventPriorityTest extends TestCase
{
    public function testPriorityValues(): void
    {
        $this->assertSame(200, EventPriority::CRITICAL->value);
        $this->assertSame(100, EventPriority::HIGH->value);
        $this->assertSame(50, EventPriority::ELEVATED->value);
        $this->assertSame(0, EventPriority::NORMAL->value);
        $this->assertSame(-100, EventPriority::LOW->value);
        $this->assertSame(-200, EventPriority::DEFERRED->value);
    }

    public function testPriorityIsEnum(): void
    {
        $priority = EventPriority::HIGH;

        $this->assertSame('HIGH', $priority->name);
        $this->assertSame(100, $priority->value);
    }
}
