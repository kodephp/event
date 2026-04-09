<?php

declare(strict_types=1);

namespace Kode\Event;

class EventTracer
{
    protected array $traces = [];

    protected bool $enabled = true;

    public function __construct(
        protected Dispatcher $dispatcher
    ) {
    }

    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function trace(Event $event, callable $callback): mixed
    {
        if (!$this->enabled) {
            return $callback();
        }

        $traceId = bin2hex(random_bytes(4));
        $startTime = hrtime(true);

        $this->traces[$traceId] = [
            'id' => $traceId,
            'event' => $event->getName(),
            'startTime' => $startTime,
            'endTime' => null,
            'duration' => null,
            'listenerCount' => 0,
            'data' => $event->getData(),
            'stopped' => false,
        ];

        $event->set('trace_id', $traceId);

        try {
            $result = $callback();
            $this->traces[$traceId]['endTime'] = hrtime(true);
            $this->traces[$traceId]['duration'] = $this->traces[$traceId]['endTime'] - $startTime;
            $this->traces[$traceId]['listenerCount'] = count($this->dispatcher->getListeners($event->getName()));
            return $result;
        } catch (\Throwable $e) {
            $this->traces[$traceId]['endTime'] = hrtime(true);
            $this->traces[$traceId]['duration'] = $this->traces[$traceId]['endTime'] - $startTime;
            $this->traces[$traceId]['error'] = $e->getMessage();
            throw $e;
        }
    }

    public function markStopped(string $traceId): self
    {
        if (isset($this->traces[$traceId])) {
            $this->traces[$traceId]['stopped'] = true;
        }
        return $this;
    }

    public function getTrace(string $traceId): ?array
    {
        return $this->traces[$traceId] ?? null;
    }

    public function getAllTraces(): array
    {
        return $this->traces;
    }

    public function getRecentTraces(int $limit = 10): array
    {
        $traces = array_values($this->traces);
        usort($traces, fn($a, $b) => ($b['startTime'] ?? 0) <=> ($a['startTime'] ?? 0));
        return array_slice($traces, 0, $limit);
    }

    public function clear(?string $traceId = null): self
    {
        if ($traceId === null) {
            $this->traces = [];
        } else {
            unset($this->traces[$traceId]);
        }
        return $this;
    }

    public function count(): int
    {
        return count($this->traces);
    }
}