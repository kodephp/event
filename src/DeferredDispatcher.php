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
            'dispatchAt' => hrtime(true) + max(0, $timestamp - time()) * 1_000_000_000,
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
        $ready = [];

        // 先收集到期任务，避免在遍历中对整张待处理表做 COW 复制；
        // 未到期任务保持不动，不会被无谓地复制。
        foreach ($this->deferred as $id => $job) {
            if ($job['dispatchAt'] <= $now) {
                $ready[$id] = $job['dispatchAt'];
            }
        }

        if ($ready === []) {
            return 0;
        }

        // 按调度时间升序派发，保证延迟语义正确（delay 小的先触发）；
        // 仅单条到期时无需排序，避免无谓开销
        if (count($ready) > 1) {
            asort($ready);
        }

        foreach (array_keys($ready) as $id) {
            $this->dispatcher->dispatch($this->deferred[$id]['event']);
            unset($this->deferred[$id]);
        }

        return count($ready);
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