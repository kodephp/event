<?php

declare(strict_types=1);

namespace Kode\Event;

class DeferredDispatcher
{
    protected array $deferred = [];

    protected int $nextId = 1;

    public function __construct(
        protected Dispatcher $dispatcher
    ) {
    }

    public function defer(Event|string $event, array $data = [], int $delay = 0): int
    {
        $id = $this->nextId++;

        if (is_string($event)) {
            $event = new Event($event, $data);
        }

        $this->deferred[$id] = [
            'event' => $event,
            'dispatchAt' => hrtime(true) + ($delay * 1_000_000_000),
            'delay' => $delay,
        ];

        return $id;
    }

    public function deferAt(Event|string $event, array $data, int $timestamp): int
    {
        $id = $this->nextId++;

        if (is_string($event)) {
            $event = new Event($event, $data);
        }

        $this->deferred[$id] = [
            'event' => $event,
            'dispatchAt' => $timestamp * 1_000_000_000,
            'delay' => 0,
        ];

        return $id;
    }

    public function cancel(int $id): bool
    {
        if (isset($this->deferred[$id])) {
            unset($this->deferred[$id]);
            return true;
        }
        return false;
    }

    public function process(): int
    {
        $now = hrtime(true);
        $count = 0;

        foreach ($this->deferred as $id => $job) {
            if ($job['dispatchAt'] <= $now) {
                $this->dispatcher->dispatch($job['event']);
                unset($this->deferred[$id]);
                $count++;
            }
        }

        return $count;
    }

    public function processAll(): int
    {
        $count = 0;
        while (!empty($this->deferred)) {
            $processed = $this->process();
            $count += $processed;
            if ($processed === 0) {
                break;
            }
        }
        return $count;
    }

    public function pending(): array
    {
        return $this->deferred;
    }

    public function count(): int
    {
        return count($this->deferred);
    }

    public function clear(): self
    {
        $this->deferred = [];
        return $this;
    }

    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    public function getJob(int $id): ?array
    {
        return $this->deferred[$id] ?? null;
    }
}