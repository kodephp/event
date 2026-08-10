<?php

declare(strict_types=1);

namespace Kode\Event;

use Throwable;

/**
 * 死信条目
 *
 * 记录一条「最终处理失败、被移入死信队列」的事件：原始事件、失败异常、已尝试次数
 * 与移入时间戳，便于人工排查或延迟重新投递。
 */
final class DeadLetterEntry
{
    /**
     * @param Event $event 最终失败的事件
     * @param Throwable $error 最后一次尝试抛出的异常
     * @param int $attempts 实际尝试次数（含成功前的失败）
     * @param int $rejectedAt 移入死信队列的时间戳（微秒）
     */
    public function __construct(
        public readonly Event $event,
        public readonly Throwable $error,
        public readonly int $attempts,
        public readonly int $rejectedAt
    ) {
    }
}
