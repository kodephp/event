<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 内存事件存储
 *
 * 进程内的仅追加事件日志，适合测试、单进程短期重放与演示。
 * 注意：进程退出即丢失，生产环境请使用 {@see FileEventStore} 或自有持久化实现。
 */
final class InMemoryEventStore implements EventStoreInterface
{
    /**
     * @var EventEnvelope[]
     */
    private array $envelopes = [];

    private int $seq = 0;

    #[\Override]
    public function append(Event $event, array $metadata = []): EventEnvelope
    {
        $this->seq++;
        $envelope = new EventEnvelope(
            $this->seq,
            sprintf('evt-%010d', $this->seq),
            $event->getName(),
            $event->getData(),
            (int) (hrtime(true) / 1000),
            $metadata
        );
        $this->envelopes[] = $envelope;

        return $envelope;
    }

    #[\Override]
    public function appendBatch(array $entries): array
    {
        $added = [];
        foreach ($entries as $entry) {
            $metadata = $entry['metadata'] ?? [];
            $added[] = $this->append($entry['event'], $metadata);
        }

        return $added;
    }

    #[\Override]
    public function stream(): \Generator
    {
        foreach ($this->envelopes as $envelope) {
            yield $envelope;
        }
    }

    #[\Override]
    public function all(): array
    {
        return $this->envelopes;
    }

    #[\Override]
    public function from(int $seq): array
    {
        return array_values(array_filter(
            $this->envelopes,
            static fn (EventEnvelope $e): bool => $e->seq >= $seq
        ));
    }

    #[\Override]
    public function last(): ?EventEnvelope
    {
        return $this->envelopes === [] ? null : end($this->envelopes);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->envelopes);
    }

    #[\Override]
    public function clear(): void
    {
        $this->envelopes = [];
        $this->seq = 0;
    }
}
