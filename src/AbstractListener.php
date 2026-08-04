<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 便捷监听器抽象类
 *
 * 提供监听器接口的便捷基类实现
 */
abstract class AbstractListener implements ListenerInterface
{
    use EventListenerTrait;

    /**
     * 构造监听器
     *
     * @param string|array $events 监听的事件名称
     * @param int $priority 优先级
     */
    public function __construct(string|array $events, int $priority = EventPriority::NORMAL->value)
    {
        $this->listenEvents = $events;
        $this->listenPriority = $priority;
    }

    /**
     * 处理事件（子类实现）
     *
     * @param Event $event
     * @return void
     */
    abstract protected function handleEvent(Event $event): void;

    /**
     * 实现 ListenerInterface
     */
    #[\Override]
    public function handle(Event $event): void
    {
        $this->handleEvent($event);
    }
}
