<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件信封（Event Sourcing 持久化单元）
 *
 * 把「一次派发记录」封装为不可变信封：全局递增序号 seq、事件唯一 id、事件名、
 * 业务数据、附加元数据与记录时间戳。seq 构成事件流的有序游标，支持「从某序号
 * 起重放」以重建读模型或修复下游状态；信封与业务事件解耦，便于跨进程落盘与重放。
 */
final class EventEnvelope
{
    /**
     * @param int $seq 全局递增序号（事件流游标）
     * @param string $id 事件唯一 id
     * @param string $name 事件名
     * @param array<string, mixed> $data 事件业务数据快照
     * @param int $recordedAt 记录时间戳（微秒）
     * @param array<string, mixed> $metadata 附加元数据快照
     */
    public function __construct(
        public readonly int $seq,
        public readonly string $id,
        public readonly string $name,
        public readonly array $data,
        public readonly int $recordedAt,
        public readonly array $metadata = []
    ) {
    }

    /**
     * @return array{seq: int, id: string, name: string, data: array<string, mixed>, recorded_at: int, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'seq' => $this->seq,
            'id' => $this->id,
            'name' => $this->name,
            'data' => $this->data,
            'recorded_at' => $this->recordedAt,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (int) ($row['seq'] ?? 0),
            (string) ($row['id'] ?? ''),
            (string) ($row['name'] ?? ''),
            is_array($row['data'] ?? []) ? $row['data'] : [],
            (int) ($row['recorded_at'] ?? 0),
            is_array($row['metadata'] ?? []) ? $row['metadata'] : []
        );
    }
}
