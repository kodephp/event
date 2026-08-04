<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 具名事件契约
 *
 * 实现本接口的事件对象将按「事件名称」路由到监听器；
 * 未实现本接口的普通对象则按「类名 + 父类 + 接口」路由（PSR-14 风格）。
 *
 * 这使得两种事件风格可以在同一个调度器中共存。
 */
interface NamedEventInterface
{
    /**
     * 获取事件名称
     */
    public function getName(): string;
}
