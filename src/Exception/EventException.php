<?php

declare(strict_types=1);

namespace Kode\Event\Exception;

/**
 * 事件异常基类
 */
class EventException extends \RuntimeException
{
}

/**
 * 无效事件异常
 */
class InvalidEventException extends EventException
{
    public static function invalidName(string $name): self
    {
        return new self("无效的事件名称: {$name}");
    }

    public static function emptyName(): self
    {
        return new self('事件名称不能为空');
    }
}

/**
 * 监听器异常
 */
class ListenerException extends EventException
{
    public static function notCallable(mixed $listener): self
    {
        return new self('监听器必须可调用，当前类型: ' . get_debug_type($listener));
    }

    public static function invalidPriority(int $priority): self
    {
        return new self("无效的优先级: {$priority}，优先级必须为整数");
    }
}

/**
 * 事件传播异常
 */
class PropagationException extends EventException
{
}
