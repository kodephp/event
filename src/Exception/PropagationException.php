<?php

declare(strict_types=1);

namespace Kode\Event\Exception;

/**
 * 事件传播异常
 *
 * 事件传播链路出现不可恢复错误时抛出，例如检测到递归派发超限。
 */
class PropagationException extends EventException
{
    /**
     * 递归派发深度超限
     */
    public static function maxDepthExceeded(string $event, int $maxDepth): self
    {
        return new self("事件 {$event} 派发深度超过上限 {$maxDepth}，可能存在循环派发");
    }
}
