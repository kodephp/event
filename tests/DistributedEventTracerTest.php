<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Context\Context as KodeContext;
use Kode\Event\DistributedEventTracer;
use Kode\Event\Event;
use PHPUnit\Framework\TestCase;

/**
 * DistributedEventTracer（基于 kode/context W3C Trace Context 的事件分布式追踪）测试
 */
class DistributedEventTracerTest extends TestCase
{
    protected function setUp(): void
    {
        KodeContext::reset();
    }

    protected function tearDown(): void
    {
        KodeContext::reset();
    }

    public function testStartTraceProducesTraceparent(): void
    {
        $tracer = new DistributedEventTracer();

        $traceId = $tracer->startTrace();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);

        $traceparent = $tracer->getTraceparent();
        $this->assertIsString($traceparent);
        $this->assertStringContainsString($traceId, $traceparent);
    }

    public function testInjectAndExtractRoundTripWithinSameProcess(): void
    {
        $tracer = new DistributedEventTracer();
        $tracer->startTrace();

        $event = new Event('order.paid', ['amount' => 100]);
        $tracer->injectToEvent($event);

        $this->assertTrue($event->has('traceparent'));
        $this->assertIsString($event->get('traceparent'));

        // 模拟消费端：重置上下文后从事件恢复
        $producerTraceId = $tracer->getTraceInfo()['trace_id'] ?? null;

        KodeContext::reset();
        $this->assertNull($tracer->getTraceparent());

        $ok = $tracer->extractFromEvent($event);
        $this->assertTrue($ok);
        $this->assertSame($producerTraceId, $tracer->getTraceInfo()['trace_id'] ?? null);
    }

    public function testExtractFailsWhenEventHasNoTraceparent(): void
    {
        $tracer = new DistributedEventTracer();
        $event = new Event('order.paid', ['amount' => 100]);

        $this->assertFalse($tracer->extractFromEvent($event));
    }

    public function testTraceCrossesSerializeBoundaryWithSameTraceId(): void
    {
        $tracer = new DistributedEventTracer();
        $tracer->startTrace();

        $event = new Event('order.paid', ['amount' => 100]);
        $tracer->injectToEvent($event);

        $producerTraceId = $tracer->getTraceInfo()['trace_id'] ?? null;

        // 事件经 JSON 序列化跨进程边界
        $json = $event->toJson();

        // 消费端：全新上下文，从 JSON 重建事件并恢复链路
        KodeContext::reset();
        $restored = Event::fromJson($json);

        $ok = $tracer->extractFromEvent($restored);
        $this->assertTrue($ok);
        $this->assertSame($producerTraceId, $tracer->getTraceInfo()['trace_id'] ?? null);
    }

    public function testGetTraceInfoReturnsArray(): void
    {
        $tracer = new DistributedEventTracer();
        $tracer->startTrace();

        $info = $tracer->getTraceInfo();
        $this->assertIsArray($info);
        $this->assertArrayHasKey('trace_id', $info);
    }

    public function testTraceCallbackRunsAndInjects(): void
    {
        $tracer = new DistributedEventTracer();
        $event = new Event('user.login', ['id' => 1]);

        $ran = false;
        $result = $tracer->trace($event, function () use (&$ran) {
            $ran = true;
            return 'done';
        });

        $this->assertTrue($ran);
        $this->assertSame('done', $result);
        $this->assertTrue($event->has('traceparent'));
    }
}
