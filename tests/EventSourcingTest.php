<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\EventEnvelope;
use Kode\Event\EventReplay;
use Kode\Event\EventStoreInterface;
use Kode\Event\FileEventStore;
use Kode\Event\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * 事件溯源：EventStore（内存 / 文件）+ EventReplay 挂载存储 + 从存储重放
 */
final class EventSourcingTest extends TestCase
{
    public function testInMemoryStoreAssignsSequentialSeqAndId(): void
    {
        $store = new InMemoryEventStore();
        $e1 = $store->append(new Event('order.created', ['id' => 1]));
        $e2 = $store->append(new Event('order.paid', ['id' => 1]));

        $this->assertInstanceOf(EventEnvelope::class, $e1);
        $this->assertSame(1, $e1->seq);
        $this->assertSame(2, $e2->seq);
        $this->assertSame('evt-0000000001', $e1->id);
        $this->assertSame('order.created', $e1->name);
        $this->assertSame(['id' => 1], $e1->data);
        $this->assertSame(2, $store->count());
        $this->assertSame($e2, $store->last());
    }

    public function testStoreFromReturnsIncrementalSlice(): void
    {
        $store = new InMemoryEventStore();
        for ($i = 0; $i < 5; $i++) {
            $store->append(new Event('evt.' . $i));
        }

        $slice = $store->from(3);
        $this->assertCount(3, $slice);
        $this->assertSame(3, $slice[0]->seq);
        $this->assertSame(5, $slice[2]->seq);
    }

    public function testStoreRecordsMetadata(): void
    {
        $store = new InMemoryEventStore();
        $env = $store->append(new Event('x'), ['traceId' => 'abc', 'source' => 'svc']);
        $this->assertSame(['traceId' => 'abc', 'source' => 'svc'], $env->metadata);
    }

    public function testStoreClearResetsSeq(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new Event('a'));
        $store->append(new Event('b'));
        $store->clear();

        $this->assertSame(0, $store->count());
        $this->assertNull($store->last());
        $this->assertSame(1, $store->append(new Event('c'))->seq);
    }

    public function testFileStorePersistsAndReloads(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'evtstore_');
        $store = new FileEventStore($file);

        $store->append(new Event('order.created', ['id' => 1]));
        $store->append(new Event('order.paid', ['id' => 1]));

        // 全新实例从同一文件加载，验证持久化
        $reloaded = new FileEventStore($file);
        $this->assertSame(2, $reloaded->count());
        $this->assertSame('order.created', $reloaded->all()[0]->name);
        $this->assertSame(['id' => 1], $reloaded->all()[1]->data);

        unlink($file);
    }

    public function testFileStoreSkipsCorruptLines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'evtstore_');
        file_put_contents($file, "this-is-not-json\n" . json_encode([
            'seq' => 1, 'id' => 'evt-0000000001', 'name' => 'ok',
            'data' => [], 'recorded_at' => 1, 'metadata' => [],
        ]) . "\n");

        $store = new FileEventStore($file);
        $this->assertSame(1, $store->count());
        $this->assertSame('ok', $store->last()->name);

        unlink($file);
    }

    public function testReplayAttachesToDispatcherAndRecordsToStore(): void
    {
        $dispatcher = new Dispatcher();
        $store = new InMemoryEventStore();
        $replay = new EventReplay($dispatcher);
        $replay->setStore($store);
        $replay->attach($dispatcher);

        $fired = [];
        $dispatcher->listen('order.created', static function (Event $e) use (&$fired): void {
            $fired[] = $e->getName();
        });

        $dispatcher->dispatch('order.created', ['id' => 7]);

        // 派发被自动记入存储
        $this->assertSame(1, $store->count());
        $this->assertSame('order.created', $store->last()->name);

        // 从存储重放：应再次触发监听器
        $fired = [];
        $replay->replayFromStore();
        $this->assertSame(['order.created'], $fired);
    }

    public function testReplayFromStoreThrowsWithoutStore(): void
    {
        $replay = new EventReplay(new Dispatcher());
        $this->expectException(\RuntimeException::class);
        $replay->replayFromStore();
    }

    public function testReplayFromStoreHonorsFromAndCount(): void
    {
        $dispatcher = new Dispatcher();
        $store = new InMemoryEventStore();
        $replay = new EventReplay($dispatcher);
        $replay->setStore($store);

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $store->append(new Event($name));
        }

        $fired = [];
        $dispatcher->listen('*', static function (Event $e) use (&$fired): void {
            $fired[] = $e->getName();
        });

        $replay->replayFromStore(from: 2, count: 2);
        $this->assertSame(['b', 'c'], $fired);
    }

    public function testReplayFromStoreRestoresMetadata(): void
    {
        $dispatcher = new Dispatcher();
        $store = new InMemoryEventStore();
        $store->append(new Event('x'), ['traceId' => 'trace-xyz']);

        $captured = null;
        $dispatcher->listen('x', static function (Event $e) use (&$captured): void {
            $captured = $e;
        });

        (new EventReplay($dispatcher))->setStore($store)->replayFromStore();

        $this->assertNotNull($captured);
        $this->assertSame('trace-xyz', $captured->getMeta('traceId'));
    }

    public function testStoreTypeIsInterchangeable(): void
    {
        // 保证 FileEventStore 同样满足 EventStoreInterface 契约（上传真接口断言）
        $this->assertInstanceOf(EventStoreInterface::class, new InMemoryEventStore());
        $this->assertInstanceOf(EventStoreInterface::class, new FileEventStore(tempnam(sys_get_temp_dir(), 'x')));
    }
}
