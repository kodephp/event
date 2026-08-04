<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\ErrorStrategy;
use PHPUnit\Framework\TestCase;

class ErrorStrategyTest extends TestCase
{
    public function testEnumHasExpectedCases(): void
    {
        $this->assertSame(
            ['THROW', 'COLLECT', 'IGNORE'],
            array_map(static fn(ErrorStrategy $s): string => $s->name, ErrorStrategy::cases())
        );
    }

    public function testEnumHasExpectedValues(): void
    {
        $this->assertSame('throw', ErrorStrategy::THROW->value);
        $this->assertSame('collect', ErrorStrategy::COLLECT->value);
        $this->assertSame('ignore', ErrorStrategy::IGNORE->value);
    }

    public function testEnumIsBackedByString(): void
    {
        $this->assertInstanceOf(ErrorStrategy::class, ErrorStrategy::from('collect'));
        $this->assertSame(ErrorStrategy::IGNORE, ErrorStrategy::from('ignore'));
    }
}
