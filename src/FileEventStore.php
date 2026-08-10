<?php

declare(strict_types=1);

namespace Kode\Event;

use JsonException;

/**
 * 文件事件存储（JSON Lines）
 *
 * 以「每行一个 JSON 对象」的追加方式落盘，单次 append 为整行原子写入
 * （FILE_APPEND | LOCK_EX），无需把整份日志读入内存即可增量追加。
 * 读取时惰性加载并跳过损坏行（单条坏行不影响整体重放），适合中小规模事件溯源。
 *
 * 设计为「单写入者」后端：并发多进程写入需自行加分布式锁或改用数据库后端。
 */
final class FileEventStore implements EventStoreInterface
{
    /**
     * @var EventEnvelope[]
     */
    private array $envelopes = [];

    private int $seq = 0;

    private bool $loaded = false;

    public function __construct(
        private string $file
    ) {
    }

    #[\Override]
    public function append(Event $event, array $metadata = []): EventEnvelope
    {
        $this->load();

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
        $this->persist($envelope);

        return $envelope;
    }

    #[\Override]
    public function appendBatch(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $this->load();

        $lines = '';
        $added = [];
        foreach ($entries as $entry) {
            $metadata = $entry['metadata'] ?? [];
            $this->seq++;
            $envelope = new EventEnvelope(
                $this->seq,
                sprintf('evt-%010d', $this->seq),
                $entry['event']->getName(),
                $entry['event']->getData(),
                (int) (hrtime(true) / 1000),
                $metadata
            );
            $this->envelopes[] = $envelope;
            $added[] = $envelope;
            $lines .= json_encode(
                $envelope->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . "\n";
        }

        // 一次性整块原子追加，减少 syscall 与 LOCK_EX 竞争
        file_put_contents($this->file, $lines, FILE_APPEND | LOCK_EX);

        return $added;
    }

    #[\Override]
    public function stream(): \Generator
    {
        // 已加载（或已发生过 append）：直接产出内存索引，行为等价
        if ($this->loaded) {
            foreach ($this->envelopes as $envelope) {
                yield $envelope;
            }
            return;
        }

        // 惰性逐行读取：O(1) 内存，适合超大日志；损坏行跳过
        if (!is_file($this->file)) {
            return;
        }

        $fh = fopen($this->file, 'rb');
        if ($fh === false) {
            return;
        }

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $envelope = $this->parseLine($line);
            if ($envelope !== null) {
                yield $envelope;
            }
        }
        fclose($fh);
    }

    #[\Override]
    public function all(): array
    {
        $this->load();
        return $this->envelopes;
    }

    #[\Override]
    public function from(int $seq): array
    {
        $this->load();
        return array_values(array_filter(
            $this->envelopes,
            static fn (EventEnvelope $e): bool => $e->seq >= $seq
        ));
    }

    #[\Override]
    public function last(): ?EventEnvelope
    {
        $this->load();
        return $this->envelopes === [] ? null : end($this->envelopes);
    }

    #[\Override]
    public function count(): int
    {
        $this->load();
        return count($this->envelopes);
    }

    #[\Override]
    public function clear(): void
    {
        $this->envelopes = [];
        $this->seq = 0;
        $this->loaded = true;
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * 惰性加载日志文件，逐行解析并跳过损坏行
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->envelopes = [];
        $this->seq = 0;

        if (is_file($this->file)) {
            $content = file_get_contents($this->file);
            if (is_string($content) && $content !== '') {
                foreach (explode("\n", $content) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $envelope = $this->parseLine($line);
                    if ($envelope === null) {
                        continue;
                    }
                    $this->envelopes[] = $envelope;
                    if ($envelope->seq > $this->seq) {
                        $this->seq = $envelope->seq;
                    }
                }
            }
        }

        $this->loaded = true;
    }

    /**
     * 解析单行 JSON 为信封；损坏行返回 null（跳过）
     */
    private function parseLine(string $line): ?EventEnvelope
    {
        try {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // 损坏行：跳过，避免单条坏数据阻断整份日志重放
            return null;
        }
        if (!is_array($row)) {
            return null;
        }

        return EventEnvelope::fromArray($row);
    }

    /**
     * 整行原子追加写入
     */
    private function persist(EventEnvelope $envelope): void
    {
        $line = json_encode(
            $envelope->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";

        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
