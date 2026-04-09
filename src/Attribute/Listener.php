<?php

declare(strict_types=1);

namespace Kode\Event\Attribute;

use Attribute;

/**
 * 事件监听器属性
 *
 * 用于声明式注册事件监听器
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class Listener
{
    /**
     * 监听的事件名称
     */
    public string|array $events;

    /**
     * 监听器优先级
     */
    public int $priority;

    /**
     * 构造监听器属性
     *
     * @param string|array $events 事件名称或事件名称数组
     * @param int $priority 优先级
     */
    public function __construct(string|array $events, int $priority = 0)
    {
        $this->events = $events;
        $this->priority = $priority;
    }
}
