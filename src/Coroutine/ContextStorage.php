<?php

declare(strict_types=1);

namespace Kode\Event\Coroutine;

use Kode\Context\Context as KodeContext;
use Kode\Event\Event;

/**
 * 上下文存储
 *
 * 基于 kode/context 实现协程安全的事件上下文存储
 */
class ContextStorage implements CoroutineContextInterface
{
    /**
     * 存储容器（备用）
     */
    protected array $storage = [];

    /**
     * 构造上下文存储
     */
    public function __construct()
    {
    }

    /**
     * 设置上下文值
     */
    #[\Override]
    public function set(string $key, mixed $value): void
    {
        KodeContext::set($key, $value);
    }

    /**
     * 获取上下文值
     */
    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return KodeContext::get($key, $default);
    }

    /**
     * 检查键是否存在
     */
    #[\Override]
    public function has(string $key): bool
    {
        return KodeContext::has($key);
    }

    /**
     * 删除键
     */
    #[\Override]
    public function delete(string $key): void
    {
        KodeContext::delete($key);
    }

    /**
     * 清空上下文
     */
    #[\Override]
    public function clear(): void
    {
        KodeContext::clear();
    }

    /**
     * 复制当前上下文
     */
    #[\Override]
    public function copy(): array
    {
        return KodeContext::copy();
    }

    /**
     * 恢复上下文
     */
    #[\Override]
    public function restore(array $snapshot): void
    {
        KodeContext::restore($snapshot);
    }

    /**
     * 在隔离作用域中执行
     */
    #[\Override]
    public function run(callable $callable): mixed
    {
        return KodeContext::run($callable);
    }

    /**
     * 在继承作用域中执行
     */
    #[\Override]
    public function fork(callable $callable): mixed
    {
        return KodeContext::fork($callable);
    }

    /**
     * 为事件设置追踪上下文
     */
    public function setEventContext(Event $event): void
    {
        $this->set('event.name', $event->getName());
        $this->set('event.timestamp', $event->getTimestamp());
    }

    /**
     * 获取事件追踪ID
     */
    public function getEventTraceId(): ?string
    {
        return $this->get('event.trace_id');
    }

    /**
     * 设置事件追踪ID
     */
    public function setEventTraceId(string $traceId): void
    {
        $this->set('event.trace_id', $traceId);
    }

    /**
     * 以类型安全方式读取事件时间戳（使用 kode/context ^3.0 的 getInt 访问器）
     */
    public function getEventTimestamp(): ?int
    {
        return KodeContext::getInt('event.timestamp');
    }

    /**
     * 判断事件上下文是否齐备（使用 kode/context ^3.1 的 hasAll 组合键检查）
     */
    public function isEventContextPresent(): bool
    {
        return KodeContext::hasAll(['event.name', 'event.timestamp']);
    }

    /**
     * 在「事务作用域」内临时写入事件上下文并执行回调，结束后自动回滚，
     * 避免污染外部上下文（使用 kode/context ^3.1 的 transaction）。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withEventContext(Event $event, callable $callback): mixed
    {
        return KodeContext::transaction(function () use ($event, $callback) {
            $this->setEventContext($event);
            return $callback();
        });
    }
}
