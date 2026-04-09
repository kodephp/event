<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\Attribute\Listener;
use Kode\Event\Attribute\Priority;
use Kode\Event\Attribute\Subscriber;
use ReflectionMethod;

/**
 * 属性监听器注册器
 *
 * 通过 PHP 8+ 属性自动注册事件监听器
 */
class AttributeListenerRegistry
{
    /**
     * 调度器实例
     */
    protected Dispatcher $dispatcher;

    /**
     * 构造属性注册器
     */
    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * 注册订阅者类的所有监听器
     *
     * @param object|string $subscriber 订阅者实例或类名
     * @return $this
     */
    public function register(object|string $subscriber): self
    {
        $class = is_object($subscriber) ? $subscriber::class : $subscriber;

        if (!is_object($subscriber)) {
            $subscriber = new $subscriber();
        }

        $reflection = new \ReflectionClass($subscriber);

        if (!$this->hasSubscriberAttribute($reflection)) {
            return $this;
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->registerMethod($subscriber, $method);
        }

        return $this;
    }

    /**
     * 注册单个方法
     */
    protected function registerMethod(object $instance, ReflectionMethod $method): void
    {
        $listenerAttributes = $method->getAttributes(Listener::class);

        if (empty($listenerAttributes)) {
            return;
        }

        $priority = $this->getMethodPriority($method);

        foreach ($listenerAttributes as $attr) {
            $listener = $attr->newInstance();
            $events = $listener->events;
            $priority = $listener->priority !== 0 ? $listener->priority : $priority;

            $events = is_array($events) ? $events : [$events];

            foreach ($events as $event) {
                $this->dispatcher->listen(
                    $event,
                    [$instance, $method->getName()],
                    $priority
                );
            }
        }
    }

    /**
     * 获取方法优先级
     */
    protected function getMethodPriority(ReflectionMethod $method): int
    {
        $priorityAttributes = $method->getAttributes(Priority::class);

        if (!empty($priorityAttributes)) {
            return $priorityAttributes[0]->newInstance()->value;
        }

        return 0;
    }

    /**
     * 检查类是否有订阅者属性
     */
    protected function hasSubscriberAttribute(\ReflectionClass $reflection): bool
    {
        $attributes = $reflection->getAttributes(Subscriber::class);
        return !empty($attributes);
    }

    /**
     * 获取调度器
     */
    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * 批量注册订阅者
     *
     * @param object|string[] $subscribers
     * @return $this
     */
    public function registerMany(array $subscribers): self
    {
        foreach ($subscribers as $subscriber) {
            $this->register($subscriber);
        }

        return $this;
    }
}
