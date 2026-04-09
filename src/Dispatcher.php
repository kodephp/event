<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件调度器
 *
 * 核心事件调度器，负责事件的派发和监听器管理
 */
class Dispatcher
{
    /**
     * 监听器注册表
     */
    protected ListenerRegistry $registry;

    /**
     * 事件派发器列表
     *
     * @var callable[]
     */
    protected array $dispatchers = [];

    /**
     * 构造事件调度器
     *
     * @param ListenerRegistry|null $registry 监听器注册表
     */
    public function __construct(?ListenerRegistry $registry = null)
    {
        $this->registry = $registry ?? new ListenerRegistry();
    }

    /**
     * 注册监听器
     *
     * @param string $event 事件名称
     * @param callable|ListenerInterface $listener 监听器
     * @param int $priority 优先级
     * @return $this
     */
    public function listen(string $event, callable|ListenerInterface $listener, int $priority = 0): self
    {
        $this->registry->listen($event, $listener, $priority);
        return $this;
    }

    /**
     * 批量注册监听器
     *
     * @param array<string, callable|ListenerInterface> $listeners
     * @return $this
     */
    public function listens(array $listeners): self
    {
        $this->registry->listens($listeners);
        return $this;
    }

    /**
     * 注销监听器
     *
     * @param string $event 事件名称
     * @param callable|ListenerInterface $listener 监听器
     * @return $this
     */
    public function unlisten(string $event, callable|ListenerInterface $listener): self
    {
        $this->registry->unlisten($event, $listener);
        return $this;
    }

    /**
     * 注册订阅者
     *
     * @param SubscriberInterface $subscriber 订阅者
     * @return $this
     */
    public function subscribe(SubscriberInterface $subscriber): self
    {
        $subscriber->subscribe($this);
        return $this;
    }

    /**
     * 派发事件
     *
     * @param Event|string $event 事件对象或事件名称
     * @param array $data 事件数据（当 event 为字符串时使用）
     * @return Event
     */
    public function dispatch(Event|string $event, array $data = []): Event
    {
        if (is_string($event)) {
            $event = new Event($event, $data);
        }

        $event = $this->triggerPreDispatch($event);

        if ($event->isPropagationStopped()) {
            return $event;
        }

        foreach ($this->registry->getListeners($event->getName()) as $item) {
            $listener = $item['listener'];

            if ($listener instanceof ListenerInterface) {
                $listener->handle($event);
            } else {
                ($listener)($event);
            }

            if ($event->isPropagationStopped()) {
                break;
            }
        }

        $this->triggerPostDispatch($event);

        return $event;
    }

    /**
     * 同步派发多个事件
     *
     * @param Event ...$events
     * @return Event[]
     */
    public function dispatchMany(Event ...$events): array
    {
        $results = [];
        foreach ($events as $event) {
            $results[] = $this->dispatch($event);
        }
        return $results;
    }

    /**
     * 添加预分发钩子
     *
     * @param callable $dispatcher
     * @return $this
     */
    public function addPreDispatcher(callable $dispatcher): self
    {
        $this->dispatchers[] = $dispatcher;
        return $this;
    }

    /**
     * 获取监听器注册表
     */
    public function getRegistry(): ListenerRegistry
    {
        return $this->registry;
    }

    /**
     * 检查事件是否有监听器
     */
    public function hasListeners(string $event): bool
    {
        return $this->registry->hasListeners($event);
    }

    /**
     * 获取事件的所有监听器
     *
     * @return array
     */
    public function getListeners(string $event): array
    {
        return $this->registry->getListeners($event);
    }

    /**
     * 清空所有监听器
     *
     * @param string|null $event 事件名称，null 表示清空所有
     * @return $this
     */
    public function clear(?string $event = null): self
    {
        $this->registry->clear($event);
        return $this;
    }

    /**
     * 触发预分发钩子
     */
    protected function triggerPreDispatch(Event $event): Event
    {
        foreach ($this->dispatchers as $dispatcher) {
            $result = $dispatcher($event);
            if ($result instanceof Event) {
                $event = $result;
            }
        }
        return $event;
    }

    /**
     * 触发后分发钩子
     */
    protected function triggerPostDispatch(Event $event): void
    {
    }
}
