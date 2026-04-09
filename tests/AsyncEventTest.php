<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Event;
use Kode\Event\Queue\AsyncEvent;
use PHPUnit\Framework\TestCase;

class AsyncEventTest extends TestCase
{
    public function testCanCreateAsyncEvent(): void
    {
        $event = new AsyncEvent('user.created', ['user_id' => 123], 60, 'high-priority');

        $this->assertSame('user.created', $event->getName());
        $this->assertSame(123, $event->get('user_id'));
        $this->assertSame(60, $event->getDelay());
        $this->assertSame('high-priority', $event->getQueue());
    }

    public function testCanSetAndGetJobId(): void
    {
        $event = new AsyncEvent('test');
        $this->assertNull($event->getJobId());

        $event->setJobId('job-123');
        $this->assertSame('job-123', $event->getJobId());
    }

    public function testCanSetQueue(): void
    {
        $event = new AsyncEvent('test');
        $event->setQueue('emails');

        $this->assertSame('emails', $event->getQueue());
    }

    public function testCanSetDelay(): void
    {
        $event = new AsyncEvent('test');
        $event->setDelay(120);

        $this->assertSame(120, $event->getDelay());
    }

    public function testCanSetContext(): void
    {
        $event = new AsyncEvent('test');
        $context = ['trace_id' => 'abc', 'span_id' => 'def'];
        $event->setContext($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testToPayload(): void
    {
        $event = new AsyncEvent('user.created', ['user_id' => 1], 0, 'default');
        $event->setJobId('job-1');

        $payload = $event->toPayload();

        $this->assertSame('Kode\Event\Queue\AsyncEvent', $payload['job']);
        $this->assertSame('user.created', $payload['data']['name']);
        $this->assertSame(1, $payload['data']['payload']['user_id']);
        $this->assertSame('default', $payload['queue']);
    }

    public function testFromPayload(): void
    {
        $payload = [
            'id' => 'job-999',
            'data' => [
                'name' => 'order.paid',
                'payload' => ['order_id' => 456],
                'context' => ['trace_id' => 'trace-abc'],
            ],
            'queue' => 'orders',
        ];

        $event = AsyncEvent::fromPayload($payload);

        $this->assertSame('order.paid', $event->getName());
        $this->assertSame(456, $event->get('order_id'));
        $this->assertSame('job-999', $event->getJobId());
        $this->assertSame('orders', $event->getQueue());
        $this->assertSame('trace-abc', $event->getContext()['trace_id']);
    }

    public function testGetJob(): void
    {
        $event = new AsyncEvent('test');
        $this->assertSame('Kode\Event\Queue\AsyncEvent', $event->getJob());
    }

    public function testStaticCreate(): void
    {
        $event = AsyncEvent::create('test', ['data' => 'value'], 30, 'queue-name');

        $this->assertInstanceOf(AsyncEvent::class, $event);
        $this->assertSame('test', $event->getName());
        $this->assertSame('value', $event->get('data'));
        $this->assertSame(30, $event->getDelay());
        $this->assertSame('queue-name', $event->getQueue());
    }

    public function testAsyncEventExtendsEvent(): void
    {
        $event = new AsyncEvent('test');

        $this->assertInstanceOf(Event::class, $event);
    }
}
