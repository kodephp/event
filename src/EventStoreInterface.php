<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件存储（Event Sourcing 持久化抽象）
 *
 * 仅追加（append-only）的事件日志契约：每次派发落盘为一条 {@see EventEnvelope}，
 * 消费端可据此重建状态或修复下游。实现可插拔（内存 / 文件 / 数据库 / 消息队列）。
 */
interface EventStoreInterface
{
    /**
     * 追加一次派发记录，返回封装好的信封（含全局序号）
     *
     * @param array<string, mixed> $metadata 附加元数据（如 traceId、来源服务等）
     */
    public function append(Event $event, array $metadata = []): EventEnvelope;

    /**
     * 返回全部信封（按 seq 升序）
     *
     * @return EventEnvelope[]
     */
    public function all(): array;

    /**
     * 返回 seq >= 给定序号的信封（用于增量重放）
     *
     * @return EventEnvelope[]
     */
    public function from(int $seq): array;

    /**
     * 返回最新一条信封（无记录时返回 null）
     */
    public function last(): ?EventEnvelope;

    /**
     * 已记录信封数量
     */
    public function count(): int;

    /**
     * 清空全部记录
     */
    public function clear(): void;
}
