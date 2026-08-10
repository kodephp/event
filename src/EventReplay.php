<?php

declare(strict_types=1);

namespace Kode\Event;

class EventReplay
{
    protected array $events = [];

    protected int $position = 0;

    /**
     * 可选的持久化事件存储（Event Sourcing）
     *
     * 设置后：record() 在内存记录的同时也会 append 到存储；attach() 可把调度器
     * 每次派发的 Event 自动入账；replayFromStore() 直接从存储重建并重放事件流。
     */
    protected ?EventStoreInterface $store = null;

    public function __construct(
        protected Dispatcher $dispatcher
    ) {
    }

    /**
     * 挂载持久化事件存储
     */
    public function setStore(?EventStoreInterface $store): self
    {
        $this->store = $store;
        return $this;
    }

    /**
     * 获取已挂载的事件存储（未挂载返回 null）
     */
    public function getStore(): ?EventStoreInterface
    {
        return $this->store;
    }

    /**
     * 绑定到调度器：每次派发的 Event 自动记入（内存 + 存储），无需手动 record()
     */
    public function attach(Dispatcher $dispatcher): self
    {
        $dispatcher->addPostDispatcher(function (object $event): void {
            if ($event instanceof Event) {
                $this->record($event);
            }
        });
        return $this;
    }

    public function record(Event $event): self
    {
        $this->events[] = [
            'event' => $event,
            'timestamp' => hrtime(true),
        ];

        if ($this->store !== null) {
            $this->store->append($event);
        }

        return $this;
    }

    public function replay(int $from = 0, ?int $count = null): array
    {
        $results = [];
        $start = max(0, $from);
        $end = $count === null ? count($this->events) : min($start + $count, count($this->events));

        for ($i = $start; $i < $end; $i++) {
            $results[] = $this->replayOne($this->events[$i]['event']);
        }

        return $results;
    }

    public function replayReverse(?int $count = null): array
    {
        $results = [];
        $events = $count === null
            ? $this->events
            : ($count <= 0 ? [] : array_slice($this->events, -$count));

        foreach (array_reverse($events) as $item) {
            $results[] = $this->replayOne($item['event']);
        }

        return $results;
    }

    public function replayUntil(string $eventName, int $from = 0): array
    {
        $results = [];
        $start = max(0, $from);
        $total = count($this->events);

        for ($i = $start; $i < $total; $i++) {
            $event = $this->events[$i]['event'];
            $results[] = $this->replayOne($event);

            if ($event->getName() === $eventName) {
                break;
            }
        }

        return $results;
    }

    public function replayIf(callable $predicate, int $from = 0): array
    {
        $results = [];
        $start = max(0, $from);
        $total = count($this->events);

        for ($i = $start; $i < $total; $i++) {
            $event = $this->events[$i]['event'];

            if ($predicate($event)) {
                $results[] = $this->replayOne($event);
            }
        }

        return $results;
    }

    /**
     * 重放单个事件
     *
     * 重放前对事件做「克隆 + 重置传播状态」，确保即使该事件此前已被 stopPropagation()
     * 也能被真实派发（修复「同一 Event 实例重放为静默空操作」的问题）。
     */
    protected function replayOne(Event $event): Event
    {
        $cloned = clone $event;
        $cloned->resumePropagation();

        return $this->dispatcher->dispatch($cloned);
    }

    public function getRecorded(): array
    {
        return array_map(fn($item) => $item['event'], $this->events);
    }

    /**
     * 从挂载的事件存储重建并重放事件流
     *
     * 将存储中的每条信封还原为 Event（恢复 name/data/metadata/时间戳），以
     * 「克隆 + 重置传播状态」的语义重新派发，用于读模型重建或下游修复。
     *
     * @param int $from 起始序号（默认 1，即从最早一条开始）
     * @param int|null $count 重放条数上限（null 表示全部）
     * @return Event[] 重放后的事件对象
     *
     * @throws \RuntimeException 未挂载 EventStore 时
     */
    public function replayFromStore(int $from = 1, ?int $count = null): array
    {
        if ($this->store === null) {
            throw new \RuntimeException('EventReplay 未挂载 EventStore，无法从存储重放');
        }

        $envelopes = $this->store->from($from);
        if ($count !== null) {
            $envelopes = array_slice($envelopes, 0, $count);
        }

        $results = [];
        foreach ($envelopes as $envelope) {
            $event = new Event($envelope->name, $envelope->data);
            foreach ($envelope->metadata as $key => $value) {
                $event->setMeta($key, $value);
            }

            $results[] = $this->replayOne($event);
        }

        return $results;
    }

    public function getEventNames(): array
    {
        return array_map(fn($item) => $item['event']->getName(), $this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function clear(): self
    {
        $this->events = [];
        $this->position = 0;
        return $this;
    }

    public function seek(int $position): self
    {
        $this->position = max(0, min($position, count($this->events)));
        return $this;
    }

    public function current(): ?Event
    {
        return $this->events[$this->position]['event'] ?? null;
    }

    public function next(): self
    {
        if ($this->position < count($this->events)) {
            $this->position++;
        }
        return $this;
    }

    public function rewind(): self
    {
        $this->position = 0;
        return $this;
    }

    public function valid(): bool
    {
        return $this->position < count($this->events);
    }

    public function export(): array
    {
        return array_map(fn($item) => [
            'name' => $item['event']->getName(),
            'data' => $item['event']->getData(),
            'timestamp' => $item['timestamp'],
        ], $this->events);
    }

    public static function import(array $data): array
    {
        return array_map(
            fn($item) => new Event(
                is_string($item['name'] ?? null) ? $item['name'] : '',
                is_array($item['data'] ?? []) ? $item['data'] : []
            ),
            $data
        );
    }
}