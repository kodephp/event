<?php

declare(strict_types=1);

namespace Kode\Event;

use Throwable;

/**
 * 死信接收器（Dead-Letter Sink）
 *
 * 监听器在重试耗尽后仍失败时，由 {@see RetryListener} 把事件投递到此接收器，
 * 而非向上抛异常中断整条监听链。实现可插拔（内存、回调、外部队列、数据库等）。
 */
interface DeadLetterSinkInterface
{
    /**
     * 接收一条最终失败的事件
     *
     * @param Event $event 失败的事件
     * @param Throwable $error 最后一次尝试抛出的异常
     * @param int $attempts 实际尝试次数（含成功前的失败）
     */
    public function reject(Event $event, Throwable $error, int $attempts): void;
}
