<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件调度器特性
 *
 * 为任意类快速赋予「可监听 / 可派发」能力。
 * 监听相关方法（on/once/off）为公开方法，供外部注册回调；
 * emit 为受保护方法，表示事件应由对象自身在内部触发。
 */
trait EventDispatcherTrait
{
    /**
     * 事件调度器实例
     */
    protected ?DispatcherInterface $eventDispatcher = null;

    /**
     * 设置事件调度器
     */
    public function setEventDispatcher(DispatcherInterface $dispatcher): static
    {
        $this->eventDispatcher = $dispatcher;
        return $this;
    }

    /**
     * 获取事件调度器（惰性创建）
     */
    public function getEventDispatcher(): DispatcherInterface
    {
        return $this->eventDispatcher ??= new Dispatcher();
    }

    /**
     * 注册事件监听
     */
    public function on(string $event, callable|ListenerInterface $listener, int $priority = 0): static
    {
        $this->getEventDispatcher()->listen($event, $listener, $priority);
        return $this;
    }

    /**
     * 注册一次性监听
     */
    public function once(string $event, callable|ListenerInterface $listener, int $priority = 0): static
    {
        $this->getEventDispatcher()->once($event, $listener, $priority);
        return $this;
    }

    /**
     * 注销事件监听
     */
    public function off(string $event, callable|ListenerInterface $listener): static
    {
        $this->getEventDispatcher()->unlisten($event, $listener);
        return $this;
    }

    /**
     * 检查是否存在监听器
     */
    public function hasListeners(string $event): bool
    {
        return $this->getEventDispatcher()->hasListeners($event);
    }

    /**
     * 派发事件（供对象内部调用）
     *
     * @param array<string, mixed> $data
     */
    protected function emit(string $name, array $data = []): Event
    {
        $event = $this->getEventDispatcher()->dispatch(new Event($name, $data));

        /** @var Event $event */
        return $event;
    }

    /**
     * 派发事件并返回首个非 null 的监听器结果（供对象内部调用）
     *
     * @param array<string, mixed> $data
     */
    protected function emitUntil(string $name, array $data = []): mixed
    {
        $dispatcher = $this->getEventDispatcher();

        if ($dispatcher instanceof Dispatcher) {
            return $dispatcher->until(new Event($name, $data));
        }

        $dispatcher->dispatch(new Event($name, $data));

        return null;
    }
}
