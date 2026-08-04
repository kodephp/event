<?php

declare(strict_types=1);

namespace Kode\Event\Exception;

/**
 * 监听器异常
 *
 * 监听器不可调用、优先级非法或监听器注册冲突时抛出。
 */
class ListenerException extends EventException
{
    /**
     * 监听器不可调用
     */
    public static function notCallable(mixed $listener): self
    {
        return new self('监听器必须可调用，当前类型: ' . get_debug_type($listener));
    }

    /**
     * 优先级非法
     */
    public static function invalidPriority(mixed $priority): self
    {
        return new self('无效的优先级: ' . get_debug_type($priority) . '，优先级必须为整数');
    }

    /**
     * 监听器数量超过上限
     */
    public static function tooManyListeners(string $event, int $max): self
    {
        return new self("事件 {$event} 的监听器数量已达上限 {$max}");
    }
}
