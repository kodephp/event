<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件组
 *
 * 用于批量管理相关事件的监听器
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
     * 监听器映射
     *
     * @var array<string, array{listener: callable, priority: int}>
     */
    protected array $listeners = [];

    /**
     * 构造事件组
     *
     * @param string $prefix 前缀
     * @param string $suffix 后缀
     */
    public function __construct(string $prefix = '', string $suffix = '')
    {
        $this->prefix = $prefix;
        $this->suffix = $suffix;
    }

    /**
     * 创建事件组
     */
    public static function create(string $prefix = '', string $suffix = ''): self
    {
        return new self($prefix, $suffix);
    }

    /**
     * 创建带前缀的组
     */
    public static function prefix(string $prefix): self
    {
        return new self($prefix, '');
    }

    /**
     * 创建带后缀的组
     */
    public static function suffix(string $suffix): self
    {
        return new self('', $suffix);
    }

    /**
     * 注册监听器
     *
     * @param string $event 事件名称（不含前缀后缀）
     * @param callable $listener 监听器
     * @param int $priority 优先级
     * @return $this
     */
    public function on(string $event, callable $listener, int $priority = 0): self
    {
        $fullEvent = $this->prefix . $event . $this->suffix;
        $this->listeners[$fullEvent] = [
            'listener' => $listener,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * 注册一次性监听器
     *
     * @param string $event
     * @param callable $listener
     * @param int $priority
     * @return $this
     */
    public function once(string $event, callable $listener, int $priority = 0): self
    {
        $wrapper = function (Event $e) use (&$wrapper, &$listener, $event) {
            $listener($e);
            $this->off($event, $wrapper);
        };

        return $this->on($event, $wrapper, $priority);
    }

    /**
     * 注销监听器
     *
     * @param string $event
     * @param callable|null $listener
     * @return $this
     */
    public function off(string $event, ?callable $listener = null): self
    {
        $fullEvent = $this->prefix . $event . $this->suffix;

        if ($listener === null) {
            unset($this->listeners[$fullEvent]);
        } else {
            if (isset($this->listeners[$fullEvent]) &&
                $this->listeners[$fullEvent]['listener'] === $listener) {
                unset($this->listeners[$fullEvent]);
            }
        }

        return $this;
    }

    /**
     * 注册到调度器
     *
     * @param Dispatcher $dispatcher
     * @return $this
     */
    public function attach(Dispatcher $dispatcher): self
    {
        foreach ($this->listeners as $event => $data) {
            $dispatcher->listen($event, $data['listener'], $data['priority']);
        }

        return $this;
    }

    /**
     * 从调度器注销
     *
     * @param Dispatcher $dispatcher
     * @return $this
     */
    public function detach(Dispatcher $dispatcher): self
    {
        foreach ($this->listeners as $event => $data) {
            $dispatcher->unlisten($event, $data['listener']);
        }

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
     * 获取所有监听器
     */
    public function all(): array
    {
        return $this->listeners;
    }

    /**
     * 清空所有监听器
     *
     * @return $this
     */
    public function clear(): self
    {
        $this->listeners = [];
        return $this;
    }
}
