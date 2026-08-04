<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\ErrorStrategy;
use Kode\Event\Event;
use Kode\Event\Exception\EventDispatchException;
use Kode\Event\Exception\PropagationException;
use Kode\Event\ListenerRegistry;
use Kode\Event\NamedEventInterface;
use PHPUnit\Framework\TestCase;

// ----- fixtures for PSR-14 typed object resolution -----

interface DispatcherTestMarker
{
}

class DispatcherTestBase
{
}

class DispatcherTestChild extends DispatcherTestBase implements DispatcherTestMarker
{
}

class DispatcherTestNamed implements NamedEventInterface
{
    public function __construct(private string $name = 'named.event')
    {
    }

    public function getName(): string
    {
        return $this->name;
    }
}

class DispatcherEnhancementsTest extends TestCase
{
    // ----------------------------------------------------------------
    // until(): short-circuit dispatch returning first non-null
    // ----------------------------------------------------------------

    public function testUntilReturnsFirstNonNull(): void
    {
        $d = new Dispatcher();
        $d->listen('chain', static fn(): ?string => null);
        $d->listen('chain', static fn(): string => 'resolved');

        $this->assertSame('resolved', $d->until('chain'));
    }

    public function testUntilStopsAfterFirstResult(): void
    {
        $d = new Dispatcher();
        $secondRan = false;

        $d->listen('chain', static fn(): string => 'first');
        $d->listen('chain', function () use (&$secondRan): ?string {
            $secondRan = true;
            return null;
        });

        $this->assertSame('first', $d->until('chain'));
        $this->assertFalse($secondRan, 'listeners after a non-null result must not run');
    }

    public function testUntilReturnsNullWhenAllNull(): void
    {
        $d = new Dispatcher();
        $d->listen('chain', static fn(): ?string => null);

        $this->assertNull($d->until('chain'));
    }

    // ----------------------------------------------------------------
    // once(): one-time listeners auto-unregister
    // ----------------------------------------------------------------

    public function testOnceListenerFiresOnlyOnce(): void
    {
        $d = new Dispatcher();
        $count = 0;

        $d->once('boot', function () use (&$count): void {
            $count++;
        });

        $d->dispatch(new Event('boot'));
        $d->dispatch(new Event('boot'));
        $d->dispatch(new Event('boot'));

        $this->assertSame(1, $count);
        $this->assertFalse($d->hasListeners('boot'));
    }

    // ----------------------------------------------------------------
    // Error strategies
    // ----------------------------------------------------------------

    public function testThrowStrategyPropagatesListenerException(): void
    {
        $d = new Dispatcher();
        $d->listen('risky', static function (): void {
            throw new \RuntimeException('boom');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $d->dispatch(new Event('risky'));
    }

    public function testCollectStrategyAggregatesExceptions(): void
    {
        $d = new Dispatcher();
        $d->setErrorStrategy(ErrorStrategy::COLLECT);
        $d->listen('risky', static function (): void {
            throw new \RuntimeException('first');
        });
        $d->listen('risky', static function (): void {
            throw new \DomainException('second');
        });

        try {
            $d->dispatch(new Event('risky'));
            $this->fail('expected EventDispatchException');
        } catch (EventDispatchException $e) {
            $this->assertSame(2, $e->getErrorCount());
            $this->assertSame('risky', $e->getEventName());
            $this->assertInstanceOf(\RuntimeException::class, $e->getErrors()[0]);
            $this->assertInstanceOf(\DomainException::class, $e->getErrors()[1]);
        }
    }

    public function testIgnoreStrategySuppressesExceptions(): void
    {
        $d = new Dispatcher();
        $d->setErrorStrategy(ErrorStrategy::IGNORE);
        $captured = [];

        $d->onError(static function (object $event, \Throwable $e) use (&$captured): void {
            $captured[] = $e;
        });
        $d->listen('risky', static function (): void {
            throw new \RuntimeException('ignored');
        });
        $ranAfter = false;
        $d->listen('risky', static function () use (&$ranAfter): void {
            $ranAfter = true;
        });

        $result = $d->dispatch(new Event('risky'));

        $this->assertInstanceOf(Event::class, $result);
        $this->assertTrue($ranAfter, 'subsequent listener should still run under IGNORE');
        $this->assertCount(1, $captured);
        $this->assertInstanceOf(\RuntimeException::class, $captured[0]);
    }

    // ----------------------------------------------------------------
    // Recursive dispatch depth protection
    // ----------------------------------------------------------------

    public function testMaxDepthThrowsPropagationException(): void
    {
        $d = (new Dispatcher())->setMaxDepth(2);
        $d->listen('recursive', function (Event $e) use ($d): void {
            $d->dispatch(new Event('recursive'));
        });

        $this->expectException(PropagationException::class);

        $d->dispatch(new Event('recursive'));
    }

    public function testDefaultMaxDepthIsThirtyTwo(): void
    {
        $d = new Dispatcher();
        $this->assertSame(Dispatcher::DEFAULT_MAX_DEPTH, $d->getMaxDepth());
    }

    // ----------------------------------------------------------------
    // Pre/Post dispatcher hooks
    // ----------------------------------------------------------------

    public function testPreDispatcherCanReplaceEvent(): void
    {
        $d = new Dispatcher();
        $d->addPreDispatcher(static function (object $event): object {
            return new Event('rewritten', ['from' => $event::class]);
        });
        $seen = null;
        $d->listen('rewritten', static function (Event $e) use (&$seen): void {
            $seen = $e;
        });

        $d->dispatch(new Event('original'));

        $this->assertNotNull($seen);
        $this->assertSame('rewritten', $seen->getName());
    }

    public function testPostDispatcherReceivesFinalEvent(): void
    {
        $d = new Dispatcher();
        $captured = null;
        $d->addPostDispatcher(static function (object $event) use (&$captured): void {
            $captured = $event;
        });
        $d->listen('evt', static fn(): null => null);

        $d->dispatch(new Event('evt'));

        $this->assertInstanceOf(Event::class, $captured);
        $this->assertSame('evt', $captured->getName());
    }

    // ----------------------------------------------------------------
    // PSR-14 typed object resolution (class + parent + interface)
    // ----------------------------------------------------------------

    public function testListenerForKeyedByInterfaceReceivesChildInstance(): void
    {
        $d = new Dispatcher();
        $handled = false;
        $d->listen(DispatcherTestMarker::class, static function () use (&$handled): void {
            $handled = true;
        });

        $d->dispatch(new DispatcherTestChild());

        $this->assertTrue($handled);
    }

    public function testListenerForKeyedByBaseClassReceivesChildInstance(): void
    {
        $d = new Dispatcher();
        $handled = false;
        $d->listen(DispatcherTestBase::class, static function () use (&$handled): void {
            $handled = true;
        });

        $d->dispatch(new DispatcherTestChild());

        $this->assertTrue($handled);
    }

    public function testListenerForKeyedByExactClassOnly(): void
    {
        $d = new Dispatcher();
        $childHandled = false;
        $baseHandled = false;
        $d->listen(DispatcherTestChild::class, static function () use (&$childHandled): void {
            $childHandled = true;
        });
        $d->listen(DispatcherTestBase::class, static function () use (&$baseHandled): void {
            $baseHandled = true;
        });

        // dispatching base must NOT trigger child listener
        $d->dispatch(new DispatcherTestBase());

        $this->assertFalse($childHandled);
        $this->assertTrue($baseHandled);
    }

    // ----------------------------------------------------------------
    // NamedEventInterface routing
    // ----------------------------------------------------------------

    public function testNamedEventRoutedByName(): void
    {
        $d = new Dispatcher();
        $handled = false;
        $d->listen('named.event', static function () use (&$handled): void {
            $handled = true;
        });

        $d->dispatch(new DispatcherTestNamed());

        $this->assertTrue($handled);
    }

    // ----------------------------------------------------------------
    // DispatcherStats integration
    // ----------------------------------------------------------------

    public function testStatsCollectedWhenEnabled(): void
    {
        $d = (new Dispatcher())->enableStats();
        $d->listen('measured', static fn(): null => null);
        $d->listen('measured', static fn(): null => null);

        $d->dispatch(new Event('measured'));

        $stats = $d->getStats();
        $this->assertNotNull($stats);
        $this->assertSame(1, $stats->getTotalDispatches());
        $this->assertSame(1, $stats->getCount('measured'));
        $this->assertSame(2, $stats->getMetrics()['measured']['listeners']);
    }

    public function testStatsDisabledByDefault(): void
    {
        $d = new Dispatcher();
        $this->assertNull($d->getStats());
        $d->dispatch(new Event('noop'));
        $this->assertNull($d->getStats());
    }

    // ----------------------------------------------------------------
    // Wildcard registry behavior
    // ----------------------------------------------------------------

    public function testWildcardListenersAndPatterns(): void
    {
        $registry = new ListenerRegistry();
        $matched = [];
        $registry->listen('app.*', static function (Event $e) use (&$matched): void {
            $matched[] = $e->getName();
        });
        $registry->listen('app.user.?', static function (Event $e) use (&$matched): void {
            $matched[] = 'single:' . $e->getName();
        });

        $this->assertSame(['app.*', 'app.user.?'], $registry->getWildcardPatterns());

        $dispatcher = new Dispatcher($registry);
        $dispatcher->dispatch(new Event('app.created'));
        $dispatcher->dispatch(new Event('app.user.1'));
        $dispatcher->dispatch(new Event('other.ignored'));

        $this->assertContains('app.created', $matched);
        $this->assertContains('single:app.user.1', $matched);
        $this->assertNotContains('other.ignored', $matched);
        $this->assertTrue($registry->hasListeners('app.anything'));
        $this->assertFalse($registry->hasListeners('other.nope'));
    }

    public function testCompilePatternProducesAnchoredRegex(): void
    {
        $regex = ListenerRegistry::compilePattern('user.*.created');
        $this->assertStringStartsWith('/^', $regex);
        $this->assertStringEndsWith('$/', $regex);
        $this->assertMatchesRegularExpression($regex, 'user.42.created');
        $this->assertDoesNotMatchRegularExpression($regex, 'user.42.deleted');
    }
}
