<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 监听器注册表
 *
 * 负责管理所有事件监听器的注册、存储和检索
 */
class ListenerRegistry
{
    /**
     * 监听器列表 [eventName => [[listener, priority], ...]]
     */
    protected array $listeners = [];

    /**
     * 通配符监听器
     */
    protected array $wildcardListeners = [];

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
        if ($this->contains($listener, $event)) {
            return $this;
        }

        $listenerData = $this->normalizeListener($listener, $priority);

        if (str_contains($event, '*')) {
            $this->wildcardListeners[$event][] = $listenerData;
            $this->sortWildcardListeners($event);
        } else {
            if (!isset($this->listeners[$event])) {
                $this->listeners[$event] = [];
            }
            $this->listeners[$event][] = $listenerData;
            $this->sortListeners($event);
        }

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
        foreach ($listeners as $event => $listener) {
            $this->listen($event, $listener);
        }
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
        if (str_contains($event, '*')) {
            if (isset($this->wildcardListeners[$event])) {
                $this->wildcardListeners[$event] = array_values(
                    array_filter(
                        $this->wildcardListeners[$event],
                        fn($item) => !$this->listenerEquals($item['listener'], $listener)
                    )
                );
            }
        } else {
            if (isset($this->listeners[$event])) {
                $this->listeners[$event] = array_values(
                    array_filter(
                        $this->listeners[$event],
                        fn($item) => !$this->listenerEquals($item['listener'], $listener)
                    )
                );
            }
        }

        return $this;
    }

    /**
     * 获取事件的所有监听器
     *
     * @param string $event 事件名称
     * @return array<array{listener: callable|ListenerInterface, priority: int}>
     */
    public function getListeners(string $event): array
    {
        $listeners = $this->listeners[$event] ?? [];

        foreach ($this->wildcardListeners as $pattern => $wildcardListenerList) {
            if ($this->matchWildcard($event, $pattern)) {
                $listeners = array_merge($listeners, $wildcardListenerList);
            }
        }

        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $listeners;
    }

    /**
     * 检查事件是否有监听器
     */
    public function hasListeners(string $event): bool
    {
        if (!empty($this->listeners[$event] ?? [])) {
            return true;
        }

        foreach ($this->wildcardListeners as $pattern => $listenerList) {
            if (!empty($listenerList) && $this->matchWildcard($event, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取所有已注册的事件名称
     *
     * @return string[]
     */
    public function getEventNames(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * 清空指定事件的监听器
     *
     * @param string|null $event 事件名称，null 表示清空所有
     * @return $this
     */
    public function clear(?string $event = null): self
    {
        if ($event === null) {
            $this->listeners = [];
            $this->wildcardListeners = [];
        } else {
            unset($this->listeners[$event], $this->wildcardListeners[$event]);
        }

        return $this;
    }

    /**
     * 注册订阅者
     *
     * @param SubscriberInterface $subscriber 订阅者
     * @param Dispatcher $dispatcher 调度器
     * @return $this
     */
    public function subscribe(SubscriberInterface $subscriber, Dispatcher $dispatcher): self
    {
        $subscriber->subscribe($dispatcher);
        return $this;
    }

    /**
     * 标准化监听器
     *
     * @param callable|ListenerInterface $listener
     * @param int $priority
     * @return array{listener: callable|ListenerInterface, priority: int}
     */
    protected function normalizeListener(callable|ListenerInterface $listener, int $priority): array
    {
        if ($listener instanceof ListenerInterface) {
            return [
                'listener' => $listener,
                'priority' => $priority !== 0 ? $priority : $listener->priority(),
            ];
        }

        return [
            'listener' => $listener,
            'priority' => $priority,
        ];
    }

    /**
     * 排序监听器列表
     */
    protected function sortListeners(string $event): void
    {
        if (isset($this->listeners[$event])) {
            usort(
                $this->listeners[$event],
                fn($a, $b) => $b['priority'] <=> $a['priority']
            );
        }
    }

    /**
     * 排序通配符监听器
     */
    protected function sortWildcardListeners(string $pattern): void
    {
        if (isset($this->wildcardListeners[$pattern])) {
            usort(
                $this->wildcardListeners[$pattern],
                fn($a, $b) => $b['priority'] <=> $a['priority']
            );
        }
    }

    /**
     * 检查监听器是否已存在
     */
    protected function contains(callable|ListenerInterface $listener, string $event): bool
    {
        foreach ($this->getListeners($event) as $item) {
            if ($this->listenerEquals($item['listener'], $listener)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 比较两个监听器是否相等
     */
    protected function listenerEquals(callable|ListenerInterface $a, callable|ListenerInterface $b): bool
    {
        if ($a instanceof ListenerInterface && $b instanceof ListenerInterface) {
            return $a === $b;
        }

        if (is_array($a) && is_array($b)) {
            return $a === $b;
        }

        if ($a instanceof \Closure && $b instanceof \Closure) {
            return $a === $b;
        }

        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        return false;
    }

    /**
     * 通配符匹配
     */
    protected function matchWildcard(string $event, string $pattern): bool
    {
        $regex = str_replace(
            ['\\*', '\\?'],
            ['.*', '.?'],
            preg_quote($pattern, '/')
        );

        return (bool) preg_match('/^' . $regex . '$/', $event);
    }
}
