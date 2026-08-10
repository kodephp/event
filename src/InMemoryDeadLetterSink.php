<?php

declare(strict_types=1);

namespace Kode\Event;

use Throwable;

/**
 * 内存死信接收器
 *
 * 把失败事件暂存在进程内，便于测试断言或单进程运维排查。生产环境建议替换为
 * 持久化 / 外部队列实现（如 {@see CallbackDeadLetterSink} 转发到消息队列）。
 */
final class InMemoryDeadLetterSink implements DeadLetterSinkInterface
{
    /**
     * @var DeadLetterEntry[]
     */
    private array $entries = [];

    #[\Override]
    public function reject(Event $event, Throwable $error, int $attempts): void
    {
        $this->entries[] = new DeadLetterEntry(
            $event,
            $error,
            $attempts,
            (int) (hrtime(true) / 1000)
        );
    }

    /**
     * @return DeadLetterEntry[]
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function latest(): ?DeadLetterEntry
    {
        return $this->entries === [] ? null : end($this->entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
