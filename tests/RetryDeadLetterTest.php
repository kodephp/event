<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\CallbackDeadLetterSink;
use Kode\Event\DeadLetterSinkInterface;
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\InMemoryDeadLetterSink;
use Kode\Event\ListenerInterface;
use Kode\Event\RetryListener;
use PHPUnit\Framework\TestCase;

/**
 * 重试 / 死信策略：RetryListener + DeadLetterSink
 */
final class RetryDeadLetterTest extends TestCase
{
    public function testSucceedsOnFirstAttempt(): void
    {
        $dispatcher = new Dispatcher();
        $calls = 0;
        $dispatcher->listen('order.paid', new RetryListener(
            static function (Event $e) use (&$calls): void {
                $calls++;
            },
            'order.paid'
        ));

        $dispatcher->dispatch('order.paid');
        $this->assertSame(1, $calls);
    }

    public function testRetriesUntilSuccess(): void
    {
        $dispatcher = new Dispatcher();
        $calls = 0;
        $retryListener = new RetryListener(
            static function (Event $e) use (&$calls): void {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException('transient #' . $calls);
                }
            },
            'order.paid',
            maxAttempts: 5
        );
        $dispatcher->listen('order.paid', $retryListener);

        $dispatcher->dispatch('order.paid');
        // 第 1、2 次失败，第 3 次成功
        $this->assertSame(3, $calls);
    }

    public function testExhaustionWithoutDeadLetterRethrows(): void
    {
        $dispatcher = new Dispatcher();
        $retryListener = new RetryListener(
            static function (Event $e): void {
                throw new \RuntimeException('always fail');
            },
            'order.paid',
            maxAttempts: 2
        );
        $dispatcher->listen('order.paid', $retryListener);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('always fail');
        $dispatcher->dispatch('order.paid');
    }

    public function testExhaustionRoutesToDeadLetterAndSwallows(): void
    {
        $dispatcher = new Dispatcher();
        $sink = new InMemoryDeadLetterSink();
        $retryListener = new RetryListener(
            static function (Event $e): void {
                throw new \RuntimeException('always fail');
            },
            'order.paid',
            maxAttempts: 3,
            deadLetter: $sink
        );
        $dispatcher->listen('order.paid', $retryListener);

        // 不抛异常，事件被静默移入死信
        $dispatcher->dispatch('order.paid');

        $this->assertSame(1, $sink->count());
        $entry = $sink->latest();
        $this->assertNotNull($entry);
        $this->assertSame('order.paid', $entry->event->getName());
        $this->assertSame('always fail', $entry->error->getMessage());
        $this->assertSame(3, $entry->attempts);
    }

    public function testBackoffCallableReceivesAttemptNumber(): void
    {
        $dispatcher = new Dispatcher();
        $sink = new InMemoryDeadLetterSink();
        $attemptsSeen = [];
        $retryListener = new RetryListener(
            static function (Event $e): void {
                throw new \RuntimeException('boom');
            },
            'order.paid',
            maxAttempts: 3,
            backoff: static function (int $attempt) use (&$attemptsSeen): int {
                $attemptsSeen[] = $attempt;
                return 0; // 不实际休眠
            },
            deadLetter: $sink
        );
        $dispatcher->listen('order.paid', $retryListener);

        $dispatcher->dispatch('order.paid');

        // 仅在失败且非最后一次时退避：第 1、2 次失败触发，第 3 次（最后）不触发
        $this->assertSame([1, 2], $attemptsSeen);
    }

    public function testWrapsListenerInterfaceAndDelegatesEvents(): void
    {
        $dispatcher = new Dispatcher();
        $real = new class implements ListenerInterface {
            public int $calls = 0;
            public function handle(Event $event): void
            {
                $this->calls++;
            }
            public function events(): string
            {
                return 'order.created';
            }
            public function priority(): int
            {
                return 10;
            }
        };

        $retry = new RetryListener($real, priority: 0);
        $this->assertSame('order.created', $retry->events());
        $this->assertSame(10, $retry->priority());

        $dispatcher->listen('order.created', $retry);
        $dispatcher->dispatch('order.created');
        $this->assertSame(1, $real->calls);
    }

    public function testCallableRequiresEvents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetryListener(static fn (Event $e) => null);
    }

    public function testMaxAttemptsMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetryListener(static fn (Event $e) => null, 'x', maxAttempts: 0);
    }

    public function testJitterZeroYieldsExactBackoff(): void
    {
        $retry = new RetryListener(
            static fn (Event $e) => null,
            'order.paid',
            backoff: 100,
            jitter: 0.0
        );

        $this->assertSame(100, $retry->computeDelay(1));
        $this->assertSame(100, $retry->computeDelay(2));
    }

    public function testJitterStaysWithinBounds(): void
    {
        // 注入确定性随机数源：[0,1) 两端极值覆盖 ±jitter 边界
        $retry = new RetryListener(
            static fn (Event $e) => null,
            'order.paid',
            backoff: 100,
            jitter: 0.5
        );
        $retry->setRng(static fn (): float => 0.0);   // factor = 1 - 0.5 = 0.5 → 50ms
        $this->assertSame(50, $retry->computeDelay(1));

        $retry->setRng(static fn (): float => 0.999999); // factor ≈ 1 + 0.499999 → 150ms
        $this->assertSame(150, $retry->computeDelay(1));

        $retry->setRng(static fn (): float => 0.5);   // factor = 1.0 → 100ms
        $this->assertSame(100, $retry->computeDelay(1));
    }

    public function testJitterAppliesToCallableBackoff(): void
    {
        $retry = new RetryListener(
            static fn (Event $e) => null,
            'order.paid',
            backoff: static fn (int $attempt): int => $attempt * 200, // attempt1=200, attempt2=400
            jitter: 0.25
        );
        $retry->setRng(static fn (): float => 0.0); // factor = 0.75

        $this->assertSame(150, $retry->computeDelay(1)); // 200 * 0.75
        $this->assertSame(300, $retry->computeDelay(2)); // 400 * 0.75
    }

    public function testJitterRejectsOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetryListener(static fn (Event $e) => null, 'order.paid', jitter: 1.5);
    }

    public function testCallbackDeadLetterSinkInvoked(): void
    {
        $captured = null;
        $sink = new CallbackDeadLetterSink(
            static function (Event $e, \Throwable $err, int $attempts) use (&$captured): void {
                $captured = [$e->getName(), $err->getMessage(), $attempts];
            }
        );
        $this->assertInstanceOf(DeadLetterSinkInterface::class, $sink);

        $sink->reject(new Event('x'), new \RuntimeException('err'), 4);
        $this->assertSame(['x', 'err', 4], $captured);
    }
}
