<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Event;
use Kode\Event\Exception\InvalidEventException;
use Kode\Event\ImmutableEvent;
use Kode\Event\Queue\AsyncEvent;
use PHPUnit\Framework\TestCase;

/**
 * Event JSON 序列化（PHP 8.3 json_validate）测试
 */
class EventJsonTest extends TestCase
{
    public function testEventImplementsJsonSerializable(): void
    {
        $this->assertInstanceOf(\JsonSerializable::class, new Event('demo'));
    }

    public function testToJsonAndBackPreservesCoreFields(): void
    {
        $event = (new Event('user.created', ['id' => 7, 'name' => 'neo']))
            ->setMeta('source', 'api')
            ->setTraceId('trace-abc');

        $json = $event->toJson();

        $restored = Event::fromJson($json);

        $this->assertSame('user.created', $restored->getName());
        $this->assertSame(7, $restored->get('id'));
        $this->assertSame('neo', $restored->get('name'));
        $this->assertSame('api', $restored->getMeta('source'));
        $this->assertSame('trace-abc', $restored->getTraceId());
    }

    public function testToJsonPreservesPropagationState(): void
    {
        $event = new Event('order.paid', ['amount' => 100]);
        $event->stopPropagation('already handled');

        $restored = Event::fromJson($event->toJson());

        $this->assertTrue($restored->isPropagationStopped());
        $this->assertSame('already handled', $restored->getStopReason());
    }

    public function testFromJsonRejectsInvalidJsonViaJsonValidate(): void
    {
        $this->expectException(InvalidEventException::class);

        Event::fromJson('{this is not json');
    }

    public function testFromJsonRejectsNonArrayPayload(): void
    {
        $this->expectException(InvalidEventException::class);

        Event::fromJson('123');
    }

    public function testFromArrayRequiresNonEmptyName(): void
    {
        $this->expectException(InvalidEventException::class);

        Event::fromArray(['data' => []]);
    }

    public function testJsonEncodeUsesJsonSerializable(): void
    {
        $event = (new Event('sys.tick', ['n' => 1]))->setTraceId('t1');

        $decoded = json_decode(json_encode($event), true);

        $this->assertSame('sys.tick', $decoded['name']);
        $this->assertSame(['n' => 1], $decoded['data']);
        $this->assertSame('t1', $decoded['trace_id']);
        $this->assertArrayHasKey('timestamp', $decoded);
    }

    public function testAsyncEventJsonRoundTripIncludesQueueFields(): void
    {
        $event = (new AsyncEvent('mail.send', ['to' => 'a@b.com'], 30, 'emails'))
            ->setContext(['retry' => 2])
            ->setJobId('job-42');

        $restored = AsyncEvent::fromJson($event->toJson());

        $this->assertSame('mail.send', $restored->getName());
        $this->assertSame(30, $restored->getDelay());
        $this->assertSame('emails', $restored->getQueue());
        $this->assertSame('job-42', $restored->getJobId());
        $this->assertSame(['retry' => 2], $restored->getContext());
    }

    public function testAsyncEventJsonKeepsDataAndMetadata(): void
    {
        $event = (new AsyncEvent('report.gen', ['id' => 5]))
            ->setContext(['k' => 'v']);

        $restored = AsyncEvent::fromJson($event->toJson());

        $this->assertSame(5, $restored->get('id'));
    }

    public function testImmutableEventJsonRoundTrip(): void
    {
        $event = (new ImmutableEvent('cache.flush', ['keys' => 3]))->withStopped();

        $restored = ImmutableEvent::fromJson($event->toJson());

        $this->assertInstanceOf(ImmutableEvent::class, $restored);
        $this->assertSame('cache.flush', $restored->getName());
        $this->assertSame(3, $restored->get('keys'));
        $this->assertTrue($restored->isPropagationStopped());
    }

    public function testImmutableEventInvalidJsonThrows(): void
    {
        $this->expectException(InvalidEventException::class);

        ImmutableEvent::fromJson('not json at all');
    }

    public function testToJsonEmitsUtf8UnescapedWhenRequested(): void
    {
        $event = new Event('msg', ['text' => '中文']);

        $json = $event->toJson(JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('中文', $json);
        $this->assertStringContainsString('msg', $json);
    }
}
