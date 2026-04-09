<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\Queue\QueueDriverInterface;

/**
 * 事件拦截器注册表
 *
 * 管理事件拦截器的注册和执行
 */
class InterceptorRegistry
{
    /**
     * 拦截器列表
     *
     * @var EventInterceptorInterface[]
     */
    protected array $interceptors = [];

    /**
     * 注册拦截器
     *
     * @param EventInterceptorInterface $interceptor
     * @return $this
     */
    public function add(EventInterceptorInterface $interceptor): self
    {
        $this->interceptors[] = $interceptor;
        $this->sort();
        return $this;
    }

    /**
     * 注销拦截器
     *
     * @param string $name 拦截器名称
     * @return $this
     */
    public function remove(string $name): self
    {
        $this->interceptors = array_values(
            array_filter(
                $this->interceptors,
                fn($i) => $i->getName() !== $name
            )
        );
        return $this;
    }

    /**
     * 执行所有拦截器
     *
     * @param Event $event
     * @return Event|null
     */
    public function intercept(Event $event): ?Event
    {
        foreach ($this->interceptors as $interceptor) {
            $result = $interceptor->intercept($event);
            if ($result === null) {
                return null;
            }
            $event = $result;
        }
        return $event;
    }

    /**
     * 清空所有拦截器
     *
     * @return $this
     */
    public function clear(): self
    {
        $this->interceptors = [];
        return $this;
    }

    /**
     * 获取所有拦截器
     *
     * @return EventInterceptorInterface[]
     */
    public function all(): array
    {
        return $this->interceptors;
    }

    /**
     * 排序拦截器
     */
    protected function sort(): void
    {
        usort(
            $this->interceptors,
            fn($a, $b) => $b->getPriority() <=> $a->getPriority()
        );
    }
}
