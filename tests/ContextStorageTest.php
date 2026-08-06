<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Context\Context as KodeContext;
use Kode\Event\Coroutine\ContextStorage;
use Kode\Event\Event;
use PHPUnit\Framework\TestCase;

/**
 * ContextStorage（基于 kode/context 的协程安全上下文存储）测试
 */
class ContextStorageTest extends TestCase
{
    protected function setUp(): void
    {
        KodeContext::reset();
    }

    protected function tearDown(): void
    {
        KodeContext::reset();
    }

    public function testBasicCrudDelegatesToContext(): void
    {
        $storage = new ContextStorage();

        $storage->set('foo', 'bar');
        $this->assertTrue($storage->has('foo'));
        $this->assertSame('bar', $storage->get('foo'));

        $storage->delete('foo');
        $this->assertFalse($storage->has('foo'));
        $this->assertNull($storage->get('foo'));
    }

    public function testCopyAndRestore(): void
    {
        $storage = new ContextStorage();
        $storage->set('a', 1);

        $snapshot = $storage->copy();
        $this->assertSame(['a' => 1], $snapshot);

        $storage->set('a', 2);
        $storage->restore($snapshot);
        $this->assertSame(1, $storage->get('a'));
    }

    public function testRunAndForkIsolateContext(): void
    {
        $storage = new ContextStorage();
        $storage->set('outer', 'keep');

        $runValue = $storage->run(function () use ($storage) {
            $storage->set('inner', 'temp');
            return $storage->get('inner');
        });
        $this->assertSame('temp', $runValue);
        // run() 隔离作用域，外部不受影响
        $this->assertFalse($storage->has('inner'));
        $this->assertSame('keep', $storage->get('outer'));

        $forkValue = $storage->fork(function () use ($storage) {
            return $storage->get('outer'); // 继承外部
        });
        $this->assertSame('keep', $forkValue);
    }

    public function testEventTraceIdRoundTrip(): void
    {
        $storage = new ContextStorage();
        $this->assertNull($storage->getEventTraceId());

        $storage->setEventTraceId('trace-abc');
        $this->assertSame('trace-abc', $storage->getEventTraceId());
    }

    public function testGetEventTimestampUsesTypedAccessor(): void
    {
        $storage = new ContextStorage();
        $storage->set('event.timestamp', 1717000000);

        $this->assertSame(1717000000, $storage->getEventTimestamp());
    }

    public function testIsEventContextPresentUsesHasAll(): void
    {
        $storage = new ContextStorage();
        $event = new Event('user.login', ['id' => 1]);

        $this->assertFalse($storage->isEventContextPresent());

        $storage->setEventContext($event);
        $this->assertTrue($storage->isEventContextPresent());
    }

    public function testWithEventContextRollsBackAfterCallback(): void
    {
        $storage = new ContextStorage();
        $event = new Event('user.login', ['id' => 1]);

        $inside = $storage->withEventContext($event, function () use ($storage) {
            return $storage->isEventContextPresent();
        });

        $this->assertTrue($inside);
        // 事务作用域结束后自动回滚
        $this->assertFalse($storage->isEventContextPresent());
    }
}
