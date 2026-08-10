<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Aop\AspectEventDispatcher;
use Kode\Event\DeferredDispatcher;
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\EventHelper;
use Kode\Event\EventReplay;
use Kode\Event\Queue\QueueDispatcher;
use Kode\Event\Queue\QueueDriverInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * v1.15.0 审计修复与性能优化的回归测试
 *
 * 覆盖：
 * - BUG-1 前置钩子异常时原始异常不应被 finally 中的 TypeError 吞掉
 * - BUG-2 AspectEventDispatcher::until 同样触发切面
 * - BUG-3 QueueDispatcher::processMany 不因毒丸任务冻结整批消费
 * - BUG-4 EventReplay::replayReverse(0) 不应重放全部
 * - BUG-5 EventHelper::buildName 保留 '0' 这类数字段名
 * - OPT-1 惰性排序后同事件监听器优先级顺序依旧正确
 * - OPT-2 切面匹配缓存下各类切面均正确触发
 * - OPT-8 DeferredDispatcher 未到期任务不被提前派发
 */
class AuditV115Test extends TestCase
{
    public function testPreDispatcherExceptionKeepsOriginalErrorWithStats(): void
    {
        // BUG-1：开启 stats 时，runPreDispatchers 抛异常若导致 finally 中 $name 未定义，
        // 会抛出 TypeError 掩盖原始异常。修复后 $name 始终有定义。
        $dispatcher = new Dispatcher();
        $dispatcher->enableStats();
        $dispatcher->addPreDispatcher(static function (object $event): ?object {
            throw new RuntimeException('pre boom');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pre boom');

        $dispatcher->dispatch('app.tick');
    }

    public function testAspectUntilTriggersAspects(): void
    {
        // BUG-2：until 此前绕过切面，命中数为 0
        $dispatcher = new AspectEventDispatcher();
        $before = 0;
        $after = 0;

        $dispatcher->registerAspect('order.*', new class($before, $after) {
            public function __construct(public int &$before, public int &$after)
            {
            }
            public function before(Event $e): void
            {
                $this->before++;
            }
            public function after(Event $e): void
            {
                $this->after++;
            }
        });

        $dispatcher->listen('order.created', static fn(Event $e) => 'ok');

        $result = $dispatcher->until('order.created');

        $this->assertSame('ok', $result);
        $this->assertSame(1, $before, 'until 应触发前置切面');
        $this->assertSame(1, $after, 'until 应触发后置切面');
    }

    public function testQueueProcessManySkipsPoisonPill(): void
    {
        // BUG-3：队首毒丸（不可反序列化）此前导致 processMany 直接 break，冻结后续有效任务
        $driver = new AuditInMemoryDriver();
        $dispatcher = new Dispatcher();
        $handled = [];
        $dispatcher->listen('good.a', static function (Event $e) use (&$handled): void {
            $handled[] = $e->getName();
        });
        $dispatcher->listen('good.b', static function (Event $e) use (&$handled): void {
            $handled[] = $e->getName();
        });

        $queue = new QueueDispatcher($driver, $dispatcher);
        $queue->enqueue('good.a');
        $queue->enqueue('good.b');
        // 塞入一条毒丸：缺少可解析的 data.name
        $driver->queues['event'][] = ['id' => 'poison', 'job' => 'x', 'data' => ['no_name' => 1]];

        $count = $queue->processMany(null, 10);

        $this->assertSame(2, $count, '两条有效任务都应被消费');
        $this->assertSame(['good.a', 'good.b'], $handled);
    }

    public function testReplayReverseWithZeroCountReplaysNothing(): void
    {
        // BUG-4：replayReverse(0) 此前因 -0 === 0 而重放全部
        $dispatcher = new Dispatcher();
        $replay = new EventReplay($dispatcher);
        for ($i = 0; $i < 3; $i++) {
            $replay->record(new Event('e.' . $i));
        }

        $results = $replay->replayReverse(0);

        $this->assertSame([], $results);
    }

    public function testBuildNameKeepsZeroSegment(): void
    {
        // BUG-5：裸 array_filter 把段名 '0' 当作 falsy 丢弃
        $this->assertSame('0.x', EventHelper::buildName('0', 'x'));
        $this->assertSame('a.0.b', EventHelper::buildName('a', '0', 'b'));
        $this->assertSame('x.y', EventHelper::buildName('x', 'y'));
    }

    public function testLazySortKeepsPriorityOrder(): void
    {
        // OPT-1：惰性排序后，同事件大量注册再派发，顺序仍应按优先级降序
        $dispatcher = new Dispatcher();
        $order = [];
        $priorities = [1 => 1, 3 => 3, 2 => 2, 5 => 5, 4 => 4];
        foreach ($priorities as $tag => $priority) {
            $dispatcher->listen('sort.evt', static function (Event $e) use (&$order, $tag): void {
                $order[] = $tag;
            }, $priority);
        }

        $dispatcher->dispatch('sort.evt');

        $this->assertSame([5, 4, 3, 2, 1], $order);
    }

    public function testAspectCacheTriggersAllMatched(): void
    {
        // OPT-2：切面匹配缓存下，精确 + 通配符切面都应正确触发
        $dispatcher = new AspectEventDispatcher();
        $exact = 0;
        $wild = 0;

        $dispatcher->registerAspect('cache.hit', new class($exact) {
            public function __construct(public int &$exact)
            {
            }
            public function before(Event $e): void
            {
                $this->exact++;
            }
        });
        $dispatcher->registerAspect('cache.*', new class($wild) {
            public function __construct(public int &$wild)
            {
            }
            public function before(Event $e): void
            {
                $this->wild++;
            }
        });

        $dispatcher->dispatch('cache.hit');
        $dispatcher->dispatch('cache.other');

        $this->assertSame(1, $exact, '精确切面应命中一次');
        $this->assertSame(2, $wild, '通配符切面应命中两次');
    }

    public function testDeferredDoesNotFireFutureTaskEarly(): void
    {
        // OPT-8：未到期任务不应被提前派发；到期任务按 dispatchAt 升序处理
        $dispatcher = new Dispatcher();
        $fired = [];
        $dispatcher->listen('d.evt', static function (Event $e) use (&$fired): void {
            $fired[] = $e->get('tag');
        });

        $dd = new DeferredDispatcher($dispatcher);
        $dd->defer(new Event('d.evt', ['tag' => 'late']), delay: 100);
        $dd->defer(new Event('d.evt', ['tag' => 'now']), delay: 0);

        $processed = $dd->process();

        $this->assertSame(1, $processed);
        $this->assertSame(['now'], $fired, '未到期任务不应被提前派发');
    }
}

/**
 * 内存队列驱动器（仅供本测试文件使用，避免依赖其它测试文件内定义的辅助类）
 */
class AuditInMemoryDriver implements QueueDriverInterface
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
