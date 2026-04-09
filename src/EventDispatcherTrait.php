<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件调度器特性
 *
 * 提供事件调度的便捷方法
 */
trait EventDispatcherTrait
{
    /**
     * 事件调度器实例
     */
    protected Dispatcher $eventDispatcher;

    /**
     * 设置事件调度器
     */
    public function setEventDispatcher(Dispatcher $dispatcher): self
    {
        $this->eventDispatcher = $dispatcher;
        return $this;
    }

    /**
     * 获取事件调度器
     */
    public function getEventDispatcher(): Dispatcher
    {
        if (!isset($this->eventDispatcher)) {
            $this->eventDispatcher = new Dispatcher();
        }
        return $this->eventDispatcher;
    }

    /**
     * 派发事件
     */
    protected function emit(string $name, array $data = []): Event
    {
        return $this->getEventDispatcher()->dispatch(new Event($name, $data));
    }

    /**
     * 注册事件监听
     */
    protected function on(string $event, callable $listener, int $priority = 0): self
    {
        $this->getEventDispatcher()->listen($event, $listener, $priority);
        return $this;
    }

    /**
     * 注册一次性监听
     */
    protected function once(string $event, callable $listener): self
    {
        $dispatcher = $this->getEventDispatcher();

        $wrapper = function (Event $e) use (&$listener, $event, $dispatcher, &$wrapper) {
            $listener($e);
            $dispatcher->unlisten($event, $wrapper);
        };

        $dispatcher->listen($event, $wrapper);
        return $this;
    }
}
