<?php

declare(strict_types=1);

namespace Kode\Event;

class EventFilter
{
    protected array $filters = [];

    public function add(string $event, callable $filter, int $priority = 0): self
    {
        if (!isset($this->filters[$event])) {
            $this->filters[$event] = [];
        }
        $this->filters[$event][] = [
            'filter' => $filter,
            'priority' => $priority,
        ];
        $this->sortFilters($event);
        return $this;
    }

    public function remove(string $event, callable $filter): self
    {
        if (!isset($this->filters[$event])) {
            return $this;
        }

        $this->filters[$event] = array_values(
            array_filter(
                $this->filters[$event],
                fn($item) => $item['filter'] !== $filter
            )
        );

        return $this;
    }

    public function filter(Event $event): Event
    {
        $eventName = $event->getName();
        $filters = $this->filters[$eventName] ?? [];

        foreach ($filters as $item) {
            $result = ($item['filter'])($event);
            if ($result instanceof Event) {
                $event = $result;
            }
        }

        return $event;
    }

    public function hasFilters(string $event): bool
    {
        return !empty($this->filters[$event] ?? []);
    }

    public function clear(?string $event = null): self
    {
        if ($event === null) {
            $this->filters = [];
        } else {
            unset($this->filters[$event]);
        }

        return $this;
    }

    public function getFilters(string $event): array
    {
        return $this->filters[$event] ?? [];
    }

    protected function sortFilters(string $event): void
    {
        if (isset($this->filters[$event])) {
            usort(
                $this->filters[$event],
                fn($a, $b) => $b['priority'] <=> $a['priority']
            );
        }
    }
}