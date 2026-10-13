<?php

declare(strict_types=1);

namespace Kode\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * EventTracer 的 trace ID：128-bit 随机值。
 *
 * 回归背景：旧版 trace ID 为 32-bit（bin2hex(random_bytes(4))）——
 * 常驻进程内每秒上千条事件时生日碰撞约 6.5 万条即过半，
 * 一次碰撞就让进行中的链路记录被后来者覆盖。
 */
#[CoversClass(EventTracer::class)]
final class EventTracerIdTest extends TestCase
{
    public function testGeneratedTraceIdIs128BitHex(): void
    {
        $tracer = new EventTracer(new Dispatcher());
        $tracer->trace(new Event('probe'), static fn () => null);

        $traces = self::tracesOf($tracer);

        self::assertCount(1, $traces);
        $id = array_key_first($traces);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $id);
    }

    public function testConsecutiveTracesNeverCollide(): void
    {
        $tracer = new EventTracer(new Dispatcher());

        for ($i = 0; $i < 512; $i++) {
            $tracer->trace(new Event('probe'), static fn () => null);
        }

        // 512 条远小于 128-bit 空间的生日碰撞阈值，任何重复都视为实现退化
        self::assertCount(512, self::tracesOf($tracer));
    }

    /** @return array<string, array<string, mixed>> */
    private static function tracesOf(EventTracer $tracer): array
    {
        $prop = (new \ReflectionClass(EventTracer::class))->getProperty('traces');

        return (array) $prop->getValue($tracer);
    }
}
