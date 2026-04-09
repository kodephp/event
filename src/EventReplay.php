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
            $results[] = $this->dispatcher->dispatch($this->events[$i]['event']);
        }

        return $results;
    }

    public function replayReverse(?int $count = null): array
    {
        $results = [];
        $events = array_slice($this->events, -$count);

        foreach (array_reverse($events) as $item) {
            $results[] = $this->dispatcher->dispatch($item['event']);
        }

        return $results;
    }

    public function replayUntil(string $eventName, int $from = 0): array
    {
        $results = [];
        $start = max(0, $from);

        for ($i = $start; $i < count($this->events); $i++) {
            $event = $this->events[$i]['event'];
            $results[] = $this->dispatcher->dispatch($event);

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

        for ($i = $start; $i < count($this->events); $i++) {
            $event = $this->events[$i]['event'];

            if ($predicate($event)) {
                $results[] = $this->dispatcher->dispatch($event);
            }
        }

        return $results;
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
        return array_map(fn($item) => new Event($item['name'], $item['data']), $data);
    }
}