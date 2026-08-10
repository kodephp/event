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
     * 批量追加多条派发记录，返回本次追加的信封列表。
     *
     * 持久化实现应尽量一次性原子落盘（减少 syscall / 锁竞争），
     * 适合「事件溯源快照回放」「批量导入」等超大日志场景。
     *
     * @param array<int, array{event: Event, metadata?: array<string, mixed>}> $entries
     * @return EventEnvelope[]
     */
    public function appendBatch(array $entries): array;

    /**
     * 以生成器形式流式遍历全部信封（O(1) 内存，适合超大日志），
     * 不要求把整份日志物化进内存。实现应跳过损坏行。
     *
     * @return \Generator<int, EventEnvelope, void, void>
     */
    public function stream(): \Generator;

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
