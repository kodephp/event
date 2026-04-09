<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 订阅者接口
 *
 * 定义事件订阅者的标准契约
 * 订阅者可以通过一个方法注册多个监听器
 */
interface SubscriberInterface
{
    /**
     * 注册订阅者关心的事件监听器
     *
     * @param Dispatcher $dispatcher 事件调度器
     * @return void
     */
    public function subscribe(Dispatcher $dispatcher): void;
}
