<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件组
 *
 * 用于批量管理共享前缀 / 后缀的一组事件监听器，
 * 可一次性挂载到调度器或从调度器摘除。
 *
 * 相比早期版本的改进：
 * - 同一事件名支持注册多个监听器（此前会互相覆盖）；
 * - once() 在挂载后能真正从调度器自注销（此前仅从组内移除，不生效）；
 * - 记录每个调度器的实际绑定，detach() 精确摘除，支持同时挂载多个调度器。
 */
class EventGroup
{
    /**
     * 事件名称前缀
     */
    protected string $prefix;

    /**
     * 事件名称后缀
     */
    protected string $suffix;

    /**
     * 监听器定义 [fullEvent => [['listener' => ..., 'priority' => ..., 'once' => ...], ...]]
     *
     * @var array<string, array<array{listener: callable, priority: int, once: bool}>>
     */
    protected array $listeners = [];

    /**
     * 已挂载的调度器绑定 [objectId => ['dispatcher' => ..., 'bindings' => [[event, callable], ...]]]
     *
     * @var array<int, array{dispatcher: DispatcherInterface, bindings: array<array{0: string, 1: callable}>}>
     */
    protected array $attached = [];

    /**
     * 构造事件组
     */
    public function __construct(string $prefix = '', string $suffix = '')
    {
        $this->prefix = $prefix;
        $this->suffix = $suffix;
    }

    /**
     * 创建事件组
     */
    public static function create(string $prefix = '', string $suffix = ''): static
    {
        return new static($prefix, $suffix);
    }

    /**
     * 创建带前缀的组
     */
    public static function prefix(string $prefix): static
    {
        return new static($prefix, '');
    }

    /**
     * 创建带后缀的组
     */
    public static function suffix(string $suffix): static
    {
        return new static('', $suffix);
    }

    /**
     * 注册监听器
     *
     * @param string $event 事件名称（不含前缀后缀）
     */
    public function on(string $event, callable $listener, int $priority = 0): static
    {
        $full = $this->resolveName($event);

        $this->listeners[$full][] = [
            'listener' => $listener,
            'priority' => $priority,
            'once' => false,
        ];

        $this->syncNewEntry($full, $listener, $priority, false);

        return $this;
    }

    /**
     * 注册一次性监听器
     */
    public function once(string $event, callable $listener, int $priority = 0): static
    {
        $full = $this->resolveName($event);

        $this->listeners[$full][] = [
            'listener' => $listener,
            'priority' => $priority,
            'once' => true,
        ];

        $this->syncNewEntry($full, $listener, $priority, true);

        return $this;
    }

    /**
     * 注销监听器
     *
     * @param callable|null $listener 为 null 时移除该事件的全部监听器
     */
    public function off(string $event, ?callable $listener = null): static
    {
        $full = $this->resolveName($event);

        if (!isset($this->listeners[$full])) {
            return $this;
        }

        if ($listener === null) {
            unset($this->listeners[$full]);
        } else {
            $this->listeners[$full] = array_values(
                array_filter(
                    $this->listeners[$full],
                    static fn(array $entry): bool => $entry['listener'] !== $listener
                )
            );

            if ($this->listeners[$full] === []) {
                unset($this->listeners[$full]);
            }
        }

        // 同步摘除已挂载调度器上的对应绑定
        foreach ($this->attached as $id => $record) {
            foreach ($record['bindings'] as $index => [$boundEvent, $boundListener]) {
                if ($boundEvent !== $full) {
                    continue;
                }

                if ($listener !== null && $boundListener !== $listener) {
                    continue;
                }

                $record['dispatcher']->unlisten($boundEvent, $boundListener);
                unset($this->attached[$id]['bindings'][$index]);
            }

            $this->attached[$id]['bindings'] = array_values($this->attached[$id]['bindings']);
        }

        return $this;
    }

    /**
     * 挂载到调度器
     */
    public function attach(DispatcherInterface $dispatcher): static
    {
        $id = spl_object_id($dispatcher);

        if (isset($this->attached[$id])) {
            return $this;
        }

        $this->attached[$id] = ['dispatcher' => $dispatcher, 'bindings' => []];

        foreach ($this->listeners as $event => $entries) {
            foreach ($entries as $entry) {
                $this->bind($dispatcher, $event, $entry['listener'], $entry['priority'], $entry['once']);
            }
        }

        return $this;
    }

    /**
     * 从调度器摘除
     */
    public function detach(DispatcherInterface $dispatcher): static
    {
        $id = spl_object_id($dispatcher);

        if (!isset($this->attached[$id])) {
            // 未通过本组挂载时，按定义尽力摘除
            foreach ($this->listeners as $event => $entries) {
                foreach ($entries as $entry) {
                    $dispatcher->unlisten($event, $entry['listener']);
                }
            }
            return $this;
        }

        foreach ($this->attached[$id]['bindings'] as [$event, $listener]) {
            $dispatcher->unlisten($event, $listener);
        }

        unset($this->attached[$id]);

        return $this;
    }

    /**
     * 获取前缀
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * 获取后缀
     */
    public function getSuffix(): string
    {
        return $this->suffix;
    }

    /**
     * 获取所有监听器定义
     *
     * @return array<string, array<array{listener: callable, priority: int, once: bool}>>
     */
    public function all(): array
    {
        return $this->listeners;
    }

    /**
     * 获取组内已注册的完整事件名列表
     *
     * @return string[]
     */
    public function getEventNames(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * 统计监听器总数
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->listeners as $entries) {
            $total += count($entries);
        }
        return $total;
    }

    /**
     * 清空所有监听器（同时摘除已挂载的绑定）
     */
    public function clear(): static
    {
        foreach ($this->attached as $id => $record) {
            foreach ($record['bindings'] as [$event, $listener]) {
                $record['dispatcher']->unlisten($event, $listener);
            }
            unset($this->attached[$id]);
        }

        $this->listeners = [];

        return $this;
    }

    /**
     * 拼接完整事件名
     */
    protected function resolveName(string $event): string
    {
        return $this->prefix . $event . $this->suffix;
    }

    /**
     * 将新增的监听器同步到已挂载的调度器
     */
    protected function syncNewEntry(string $event, callable $listener, int $priority, bool $once): void
    {
        foreach ($this->attached as $record) {
            $this->bind($record['dispatcher'], $event, $listener, $priority, $once);
        }
    }

    /**
     * 在指定调度器上绑定监听器并记录绑定关系
     */
    protected function bind(
        DispatcherInterface $dispatcher,
        string $event,
        callable $listener,
        int $priority,
        bool $once
    ): void {
        $id = spl_object_id($dispatcher);

        if ($once) {
            $dispatcher->once($event, $listener, $priority);
        } else {
            $dispatcher->listen($event, $listener, $priority);
        }

        $this->attached[$id]['bindings'][] = [$event, $listener];
    }
}
