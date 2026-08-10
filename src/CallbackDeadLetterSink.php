<?php

declare(strict_types=1);

namespace Kode\Event;

use Throwable;

/**
 * 回调死信接收器
 *
 * 把失败事件转发给一个回调（如写入数据库、推送到消息队列、上报监控系统），
 * 便于把死信策略接入任意后端。
 *
 * @param callable(Event, Throwable, int): void $callback 签名：(事件, 异常, 尝试次数)
 */
final class CallbackDeadLetterSink implements DeadLetterSinkInterface
{
    /**
     * @param callable(Event, Throwable, int): void $callback
     */
    public function __construct(
        private $callback
    ) {
    }

    #[\Override]
    public function reject(Event $event, Throwable $error, int $attempts): void
    {
        ($this->callback)($event, $error, $attempts);
    }
}
