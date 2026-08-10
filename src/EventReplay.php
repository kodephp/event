<?php

declare(strict_types=1);

namespace Kode\Event;

class EventReplay
{
    protected array $events = [];

    protected int $position = 0;

    public function __construct(
        protected Dispatcher $dispatcher
    ) {
    }

    public function record(Event $event): self
    {
        $this->events[] = [
            'event' => $event,
            'timestamp' => hrtime(true),
        ];
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