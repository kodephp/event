<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Aop\AspectEventDispatcher;
use Kode\Event\DeferredDispatcher;
use Kode\Event\Dispatcher;
use Kode\Event\ErrorStrategy;
use Kode\Event\Event;
use Kode\Event\EventBuilder;
use Kode\Event\ImmutableEvent;
use Kode\Event\EventPipeline;
use Kode\Event\EventReplay;
use Kode\Event\EventSchema;
use Kode\Event\EventSchemaRegistry;
use Kode\Event\Exception\EventDispatchException;
use Kode\Event\Exception\InvalidEventException;
use Kode\Event\Queue\AsyncEvent;
use Kode\Event\Queue\QueueDispatcher;
use Kode\Event\Queue\QueueDriverInterface;
use PHPUnit\Framework\TestCase;

/**
 * 健壮性回归测试：覆盖审计发现并修复的静默失效 / 崩溃 / 数据丢失类缺陷。
 */
class HardeningTest extends TestCase
{
    public function testNormalizeNameDoesNotEatValidCharacters(): void
    {
        // H1: 单引号转义失效会吃掉 r/t/n
        $this->assertSame('order', \Kode\Event\EventHelper::normalizeName('Order'));
        $this->assertSame('cart', \Kode\Event\EventHelper::normalizeName('cart'));
        $this->assertSame('user.created', \Kode\Event\EventHelper::normalizeName('USER.CREATED'));
    }

    public function testDeferredDispatcherDeferAtUsesMonotonicClock(): void
    {
        // H6: deferAt 把 Unix 时间戳误当单调时钟，导致任务永不触发
        $dispatcher = new Dispatcher();
        $called = 0;
        $dispatcher->listen('later.event', function () use (&$called): void {
            $called++;
        });

        $deferred = new DeferredDispatcher($dispatcher);
        $deferred->deferAt('later.event', [], time() - 10);

        $this->assertSame(1, $deferred->process());
        $this->assertSame(1, $called);
    }

    public function testAsyncEventJobIsStaticClassAndPayloadRoundTrip(): void
    {
        // H2/H3: getJob 应与子类一致；toPayload 的输出须可被 fromPayload 还原（含 delay）
        $event = AsyncEvent::create('q.send', ['k' => 1], 5, 'mail');

        $this->assertSame(AsyncEvent::class, $event->getJob());

        $restored = AsyncEvent::fromPayload($event->toPayload());

        $this->assertSame('q.send', $restored->getName());
        $this->assertSame(['k' => 1], $restored->getData());
        $this->assertSame(5, $restored->getDelay());
        $this->assertSame('mail', $restored->getQueue());
    }

    public function testEventBuilderWritesTraceAndMetaToDedicatedFields(): void
    {
        // H5: traceId / meta 不应污染业务 data
        $event = EventBuilder::create('x')
            ->traceId('T-1')
            ->meta('source', 'api')
            ->with('a', 1)
            ->build();

        $this->assertSame('T-1', $event->getTraceId());
        $this->assertSame('api', $event->getMeta('source'));
        $this->assertArrayNotHasKey('trace_id', $event->getData());
        $this->assertArrayNotHasKey('meta.source', $event->getData());
    }

    public function testFromArrayRejectsNonArrayData(): void
    {
        // H9: 反序列化对 data 字段零校验，会把 TypeError 泄漏给调用方
        $this->expectException(InvalidEventException::class);
        Event::fromArray(['name' => 'x', 'data' => 'oops']);
    }

    public function testToArrayIncludesStopReason(): void
    {
        // M1: toArray 与 jsonSerialize 字段不一致，导致 stop_reason 在数组通道上丢失
        $event = new Event('x');
        $event->stopPropagation('风控拦截');

        $this->assertSame('风控拦截', $event->toArray()['stop_reason'] ?? null);
        $this->assertSame($event->toArray(), $event->jsonSerialize());
    }

    public function testEventReplayResumesStoppedEvent(): void
    {
        // M7: 同一 Event 实例已 stopPropagation，重放应为静默空操作
        $dispatcher = new Dispatcher();
        $handled = false;
        $dispatcher->listen('r.test', function () use (&$handled): void {
            $handled = true;
        });

        $replay = new EventReplay($dispatcher);
        $event = new Event('r.test');
        $event->stopPropagation();
        $replay->record($event);

        $replay->replay();

        $this->assertTrue($handled);
    }

    public function testValidateDetailedKeepsAllFailuresForSameName(): void
    {
        // M11: 同名事件失败互相覆盖
        $registry = new EventSchemaRegistry();
        $registry->register(EventSchema::create('e1')->required('a'));

        $result = $registry->validateDetailed(new Event('e1'), new Event('e1'), new Event('e1'));

        $this->assertFalse($result->allValid);
        $this->assertSame(3, $result->failed);
        $this->assertCount(3, $result->failures['e1']);
    }

    public function testListenerRegistryResolvesInterfaceListenerRegisteredAfterDispatch(): void
    {
        // C4: 先派发（预热缓存）再注册接口监听器，接口监听器不应永久丢失
        $dispatcher = new Dispatcher();
        $concrete = 0;
        $iface = 0;

        $dispatcher->listen(HardeningObj::class, function () use (&$concrete): void {
            $concrete++;
        });

        $dispatcher->dispatch(new HardeningObj());

        $dispatcher->listen(HardeningIface::class, function () use (&$iface): void {
            $iface++;
        });

        $dispatcher->dispatch(new HardeningObj());

        $this->assertSame(2, $concrete);
        $this->assertSame(1, $iface);
    }

    public function testQueueDispatcherConsistentQueuePrefix(): void
    {
        // C5: 生产端加前缀、消费端不加前缀，导致事件永远消费不到
        $driver = new InMemoryDriver();
        $dispatcher = new Dispatcher();
        $handled = false;
        $dispatcher->listen('q.event', function () use (&$handled): void {
            $handled = true;
        });

        $queue = new QueueDispatcher($driver, $dispatcher);
        $queue->enqueue('q.event', [], 0, 'jobs');
        $queue->process('jobs');

        $this->assertTrue($handled);
    }

    public function testAspectDispatcherRunsWildcardAspectAndDelegatesDispatch(): void
    {
        // C1/C2/C3: 切面调度器重写丢弃了基类派发机制与通配符正则
        $dispatcher = new AspectEventDispatcher();
        $aspectRan = false;
        $listenerRan = false;

        $dispatcher->registerAspect('user.*', function (Event $event) use (&$aspectRan): void {
            $aspectRan = true;
        });
        $dispatcher->listen('user.created', function () use (&$listenerRan): void {
            $listenerRan = true;
        });

        $dispatcher->dispatch('user.created');

        $this->assertTrue($aspectRan, '通配符切面应命中 user.created');
        $this->assertTrue($listenerRan, '基类派发机制应被委派执行');
    }

    public function testImmutableEventHasAndGetParityWithEvent(): void
    {
        // M8/M9: null 值与 dot-path 语义应和 Event 一致
        $event = new ImmutableEvent('x', ['a' => ['b' => 1], 'n' => null]);

        $this->assertTrue($event->has('n'), 'null 值也应视为存在');
        $this->assertSame('d', $event->get('missing', 'd'));
        $this->assertNull($event->get('missing'));
        $this->assertSame(1, $event->get('a.b'));
    }

    public function testEventPipelineDispatchReturnsNullOnShortCircuit(): void
    {
        // H4: filter 短路返回 null 会触发 TypeError
        $pipeline = (new EventPipeline(new Event('x')))->filter(static fn(Event $e): bool => false);

        $this->assertNull($pipeline->dispatch(new Dispatcher()));
    }

    public function testUntilCollectsErrorsLikeDispatch(): void
    {
        // M5: until 吞掉异常，指标中也看不到
        $dispatcher = new Dispatcher();
        $dispatcher->setErrorStrategy(ErrorStrategy::COLLECT);
        $dispatcher->listen('u.event', function (): void {
            throw new \RuntimeException('boom');
        });

        $this->expectException(EventDispatchException::class);
        $dispatcher->until('u.event');
    }
}

interface HardeningIface
{
}

class HardeningObj implements HardeningIface
{
}

class InMemoryDriver implements QueueDriverInterface
{
    /** @var array<string, array<int, array>> */
    public array $queues = [];

    private int $seq = 0;

    public function push(string $job, array $data = [], ?string $queue = null): string
    {
        $id = (string) ++$this->seq;
        $this->queues[$queue ?? ''][] = ['id' => $id, 'job' => $job, 'data' => $data];
        return $id;
    }

    public function later(int $delay, string $job, array $data = [], ?string $queue = null): string
    {
        return $this->push($job, $data, $queue);
    }

    public function pop(?string $queue = null): ?array
    {
        $key = $queue ?? '';
        return $this->queues[$key] ? array_shift($this->queues[$key]) : null;
    }

    public function delete(string $jobId, ?string $queue = null): bool
    {
        return true;
    }

    public function size(?string $queue = null): int
    {
        return count($this->queues[$queue ?? ''] ?? []);
    }
}
