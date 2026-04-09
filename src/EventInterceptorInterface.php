<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件拦截器接口
 *
 * 用于在事件派发前后执行拦截逻辑
 */
interface EventInterceptorInterface
{
    /**
     * 前置拦截
     *
     * @param Event $event 事件对象
     * @return Event|null 返回修改后的事件，或 null 表示不阻止
     */
    public function intercept(Event $event): ?Event;

    /**
     * 获取拦截器名称
     */
    public function getName(): string;

    /**
     * 获取拦截优先级
     */
    public function getPriority(): int;
}
