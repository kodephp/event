<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\Exception\EventDispatchException;
use Kode\Event\Exception\PropagationException;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface as PsrStoppableEventInterface;
use Throwable;

/**
 * 事件调度器
 *
 * 核心事件调度器，负责事件派发与监听器管理。
 *
 * 能力概览：
 * - 同时实现 {@see DispatcherInterface} 与 PSR-14 {@see PsrEventDispatcherInterface}；
 * - 支持字符串事件名、Kode\Event\Event 以及任意 PSR-14 类型化事件对象；
 * - 可配置监听器异常处理策略（抛出 / 收集 / 忽略）并提供 onError 钩子；
 * - 内置递归派发深度保护，防止事件循环导致栈溢出；
 * - 支持一次性监听器、until 短路派发与可选的运行指标采集。
 */
class Dispatcher implements DispatcherInterface, PsrEventDispatcherInterface
{
    /**
     * 默认最大递归派发深度
     */
    public const DEFAULT_MAX_DEPTH = 32;

    /**
     * 监听器注册表
     */
    protected ListenerRegistry $registry;

    /**
     * 前置派发钩子
     *
     * @var array<callable(object): (object|null)>
     */
    protected array $preDispatchers = [];

    /**
     * 后置派发钩子
     *
     * @var array<callable(object): void>
     */
    protected array $postDispatchers = [];

    /**
     * 异常回调钩子
     *
     * @var array<callable(object, Throwable): void>
     */
    protected array $errorHandlers = [];

    /**
     * 监听器异常处理策略
     */
    protected ErrorStrategy $errorStrategy = ErrorStrategy::THROW;

    /**
     * 最大递归派发深度
     */
    protected int $maxDepth;

    /**
     * 当前递归派发深度
     */
    protected int $depth = 0;

    /**
     * 运行指标采集器
     */
    protected ?DispatcherStats $stats = null;

    /**
     * 构造事件调度器
     *
     * @param ListenerRegistry|null $registry 监听器注册表
     * @param int $maxDepth 最大递归派发深度
     */
    public function __construct(?ListenerRegistry $registry = null, int $maxDepth = self::DEFAULT_MAX_DEPTH)
    {
        $this->registry = $registry ?? new ListenerRegistry();
        $this->maxDepth = max(1, $maxDepth);
    }

    // ------------------------------------------------------------------
    // 监听器管理
    // ------------------------------------------------------------------

    /**
     * 注册监听器
     */
    public function listen(string $event, callable|ListenerInterface $listener, int $priority = 0): static
    {
        $this->registry->listen($event, $listener, $priority);
        return $this;
    }

    /**
     * 注册一次性监听器，触发一次后自动注销
     */
    public function once(string $event, callable|ListenerInterface $listener, int $priority = 0): static
    {
        $this->registry->listenOnce($event, $listener, $priority);
        return $this;
    }

    /**
     * 批量注册监听器
     *
     * @param array<string, callable|ListenerInterface> $listeners
     */
    public function listens(array $listeners): static
    {
        $this->registry->listens($listeners);
        return $this;
    }

    /**
     * 注销监听器
     */
    public function unlisten(string $event, callable|ListenerInterface $listener): static
    {
        $this->registry->unlisten($event, $listener);
        return $this;
    }

    /**
     * 注册订阅者
     */
    public function subscribe(SubscriberInterface $subscriber): static
    {
        $subscriber->subscribe($this);
        return $this;
    }

    /**
     * 批量注册订阅者
     *
     * @param SubscriberInterface[] $subscribers
     */
    public function subscribeMany(array $subscribers): static
    {
        foreach ($subscribers as $subscriber) {
            $this->subscribe($subscriber);
        }
        return $this;
    }

    // ------------------------------------------------------------------
    // 事件派发
    // ------------------------------------------------------------------

    /**
     * 派发事件
     *
     * @param object|string $event 事件对象或事件名称
     * @param array<string, mixed> $data 事件数据（$event 为字符串时生效）
     * @return object 派发后的事件对象
     *
     * @throws PropagationException 递归派发深度超限
     * @throws EventDispatchException COLLECT 策略下监听器抛出异常
     */
    public function dispatch(object|string $event, array $data = []): object
    {
        $event = is_string($event) ? new Event($event, $data) : $event;
        $name = $this->describe($event);

        if ($this->depth >= $this->maxDepth) {
            throw PropagationException::maxDepthExceeded($name, $this->maxDepth);
        }

        $this->depth++;
        $startedAt = hrtime(true);
        $errors = [];
        $invoked = 0;

        try {
            $event = $this->runPreDispatchers($event);

            if ($this->isStopped($event)) {
                return $event;
            }

            foreach ($this->registry->resolveEntriesForObject($event) as $entry) {
                if ($entry['once']) {
                    $this->registry->unlisten($entry['event'], $entry['listener']);
                }

                try {
                    $this->invoke($entry['listener'], $event);
                    $invoked++;
                } catch (Throwable $e) {
                    $this->runErrorHandlers($event, $e);

                    if ($this->errorStrategy === ErrorStrategy::THROW) {
                        throw $e;
                    }

                    $errors[] = $e;
                }

                if ($this->isStopped($event)) {
                    break;
                }
            }

            $this->runPostDispatchers($event);

            if ($errors !== [] && $this->errorStrategy === ErrorStrategy::COLLECT) {
                throw new EventDispatchException($name, $errors);
            }

            return $event;
        } finally {
            $this->depth--;
            $this->stats?->record($name, hrtime(true) - $startedAt, $invoked, count($errors));
        }
    }

    /**
     * 派发事件并返回强类型的 Event（便捷方法）
     *
     * @param array<string, mixed> $data
     */
    public function dispatchEvent(Event|string $event, array $data = []): Event
    {
        $result = $this->dispatch($event, $data);

        return $result instanceof Event ? $result : new Event($this->describe($result));
    }

    /**
     * 批量派发事件
     *
     * @return object[]
     */
    public function dispatchMany(object ...$events): array
    {
        $results = [];
        foreach ($events as $event) {
            $results[] = $this->dispatch($event);
        }
        return $results;
    }

    /**
     * 短路派发：返回首个非 null 的监听器返回值
     *
     * 常用于「责任链」场景——一旦某个监听器给出结果，
     * 立即停止后续监听器的执行。
     *
     * @param array<string, mixed> $data
     * @return mixed 首个非 null 返回值，全部为 null 时返回 null
     */
    public function until(object|string $event, array $data = []): mixed
    {
        $event = is_string($event) ? new Event($event, $data) : $event;
        $name = $this->describe($event);

        if ($this->depth >= $this->maxDepth) {
            throw PropagationException::maxDepthExceeded($name, $this->maxDepth);
        }

        $this->depth++;
        $startedAt = hrtime(true);
        $invoked = 0;

        try {
            $event = $this->runPreDispatchers($event);

            if ($this->isStopped($event)) {
                return null;
            }

            foreach ($this->registry->resolveEntriesForObject($event) as $entry) {
                if ($entry['once']) {
                    $this->registry->unlisten($entry['event'], $entry['listener']);
                }

                try {
                    $result = $this->invoke($entry['listener'], $event);
                    $invoked++;
                } catch (Throwable $e) {
                    $this->runErrorHandlers($event, $e);

                    if ($this->errorStrategy === ErrorStrategy::THROW) {
                        throw $e;
                    }

                    continue;
                }

                if ($result !== null) {
                    $this->runPostDispatchers($event);
                    return $result;
                }

                if ($this->isStopped($event)) {
                    break;
                }
            }

            $this->runPostDispatchers($event);

            return null;
        } finally {
            $this->depth--;
            $this->stats?->record($name, hrtime(true) - $startedAt, $invoked, 0);
        }
    }

    // ------------------------------------------------------------------
    // 钩子与策略
    // ------------------------------------------------------------------

    /**
     * 添加前置派发钩子
     *
     * 钩子可返回新的事件对象以替换原事件，返回 null 则保持原事件。
     *
     * @param callable(object): (object|null) $dispatcher
     */
    public function addPreDispatcher(callable $dispatcher): static
    {
        $this->preDispatchers[] = $dispatcher;
        return $this;
    }

    /**
     * 添加后置派发钩子
     *
     * @param callable(object): void $dispatcher
     */
    public function addPostDispatcher(callable $dispatcher): static
    {
        $this->postDispatchers[] = $dispatcher;
        return $this;
    }

    /**
     * 添加监听器异常回调
     *
     * @param callable(object, Throwable): void $handler
     */
    public function onError(callable $handler): static
    {
        $this->errorHandlers[] = $handler;
        return $this;
    }

    /**
     * 设置监听器异常处理策略
     */
    public function setErrorStrategy(ErrorStrategy $strategy): static
    {
        $this->errorStrategy = $strategy;
        return $this;
    }

    /**
     * 获取当前异常处理策略
     */
    public function getErrorStrategy(): ErrorStrategy
    {
        return $this->errorStrategy;
    }

    /**
     * 设置最大递归派发深度
     */
    public function setMaxDepth(int $maxDepth): static
    {
        $this->maxDepth = max(1, $maxDepth);
        return $this;
    }

    /**
     * 获取最大递归派发深度
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * 获取当前递归派发深度
     */
    public function getDepth(): int
    {
        return $this->depth;
    }

    // ------------------------------------------------------------------
    // 运行指标
    // ------------------------------------------------------------------

    /**
     * 启用运行指标采集
     *
     * @param float $slowThresholdMs 慢事件阈值（毫秒）
     */
    public function enableStats(float $slowThresholdMs = 100.0): static
    {
        $this->stats = new DispatcherStats($slowThresholdMs);
        return $this;
    }

    /**
     * 关闭运行指标采集
     */
    public function disableStats(): static
    {
        $this->stats = null;
        return $this;
    }

    /**
     * 获取运行指标采集器
     */
    public function getStats(): ?DispatcherStats
    {
        return $this->stats;
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    /**
     * 获取监听器注册表
     */
    public function getRegistry(): ListenerRegistry
    {
        return $this->registry;
    }

    /**
     * 检查事件是否存在监听器
     */
    public function hasListeners(string $event): bool
    {
        return $this->registry->hasListeners($event);
    }

    /**
     * 获取事件的所有监听器
     */
    public function getListeners(string $event): array
    {
        return $this->registry->getListeners($event);
    }

    /**
     * 统计监听器数量
     */
    public function countListeners(?string $event = null): int
    {
        return $this->registry->countListeners($event);
    }

    /**
     * 清空监听器
     */
    public function clear(?string $event = null): static
    {
        $this->registry->clear($event);
        return $this;
    }

    // ------------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------------

    /**
     * 执行单个监听器
     */
    protected function invoke(callable|ListenerInterface $listener, object $event): mixed
    {
        if ($listener instanceof ListenerInterface) {
            $listener->handle($event);
            return null;
        }

        return $listener($event);
    }

    /**
     * 判断事件是否已停止传播
     */
    protected function isStopped(object $event): bool
    {
        if ($event instanceof StoppableEventInterface || $event instanceof PsrStoppableEventInterface) {
            return $event->isPropagationStopped();
        }

        return false;
    }

    /**
     * 获取事件的可读标识
     */
    protected function describe(object $event): string
    {
        return $event instanceof NamedEventInterface ? $event->getName() : $event::class;
    }

    /**
     * 触发前置派发钩子
     */
    protected function runPreDispatchers(object $event): object
    {
        foreach ($this->preDispatchers as $hook) {
            $result = $hook($event);
            if (is_object($result)) {
                $event = $result;
            }
        }

        return $event;
    }

    /**
     * 触发后置派发钩子
     */
    protected function runPostDispatchers(object $event): void
    {
        foreach ($this->postDispatchers as $hook) {
            $hook($event);
        }
    }

    /**
     * 触发异常回调钩子
     */
    protected function runErrorHandlers(object $event, Throwable $error): void
    {
        foreach ($this->errorHandlers as $handler) {
            $handler($event, $error);
        }
    }
}
