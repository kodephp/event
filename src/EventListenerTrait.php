<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\ListenerInterface;

/**
 * 监听器特性
 *
 * 提供监听器接口的便捷实现
 */
trait EventListenerTrait
{
    /**
     * 监听的事件名称
     */
    protected string|array $listenEvents = [];

    /**
     * 监听器优先级
     */
    protected int $listenPriority = EventPriority::NORMAL->value;

    /**
     * 获取监听的事件名称
     */
    public function events(): string|array
    {
        return $this->listenEvents;
    }

    /**
     * 获取监听器优先级
     */
    public function priority(): int
    {
        return $this->listenPriority;
    }

    /**
     * 设置监听的事件名称
     *
     * @param string|array $events
     * @return $this
     */
    public function setListenEvents(string|array $events): self
    {
        $this->listenEvents = $events;
        return $this;
    }

    /**
     * 设置监听器优先级
     *
     * @param int $priority
     * @return $this
     */
    public function setListenPriority(int $priority): self
    {
        $this->listenPriority = $priority;
        return $this;
    }
}
