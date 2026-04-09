<?php

declare(strict_types=1);

namespace Kode\Event;

class BatchEventBuilder
{
    protected array $events = [];

    protected ?string $prefix = null;

    protected ?string $suffix = null;

    protected array $defaultData = [];

    protected ?string $defaultTraceId = null;

    protected array $metadata = [];

    public function __construct(
        protected Dispatcher $dispatcher
    ) {
    }

    public function create(string $name): self
    {
        $this->events[] = $name;
        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function suffix(string $suffix): self
    {
        $this->suffix = $suffix;
        return $this;
    }

    public function defaults(array $data): self
    {
        $this->defaultData = $data;
        return $this;
    }

    public function traceId(string $traceId): self
    {
        $this->defaultTraceId = $traceId;
        return $this;
    }

    public function meta(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function with(string $name, array $data = []): self
    {
        $this->events[] = [
            'name' => $name,
            'data' => $data,
        ];
        return $this;
    }

    public function dispatch(): array
    {
        $results = [];

        foreach ($this->events as $event) {
            if (is_string($event)) {
                $name = $this->prefix . $event . $this->suffix;
                $event = EventBuilder::create($name)
                    ->data($this->defaultData);
            } else {
                $name = $this->prefix . $event['name'] . $this->suffix;
                $event = EventBuilder::create($name)
                    ->data(array_merge($this->defaultData, $event['data']));
            }

            if ($this->defaultTraceId !== null) {
                $event->traceId($this->defaultTraceId);
            }

            foreach ($this->metadata as $key => $value) {
                $event->meta($key, $value);
            }

            $results[] = $this->dispatcher->dispatch($event->build());
        }

        $this->reset();

        return $results;
    }

    public function build(): array
    {
        $events = [];

        foreach ($this->events as $event) {
            if (is_string($event)) {
                $name = $this->prefix . $event . $this->suffix;
                $events[] = EventBuilder::create($name)
                    ->data($this->defaultData)
                    ->build();
            } else {
                $name = $this->prefix . $event['name'] . $this->suffix;
                $events[] = EventBuilder::create($name)
                    ->data(array_merge($this->defaultData, $event['data']))
                    ->build();
            }
        }

        return $events;
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function clear(): self
    {
        $this->events = [];
        return $this;
    }

    protected function reset(): void
    {
        $this->events = [];
        $this->prefix = null;
        $this->suffix = null;
        $this->defaultData = [];
        $this->defaultTraceId = null;
        $this->metadata = [];
    }

    public static function batch(Dispatcher $dispatcher): self
    {
        return new self($dispatcher);
    }
}