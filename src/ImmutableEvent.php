<?php

declare(strict_types=1);

namespace Kode\Event;

use Stringable;

class ImmutableEvent implements Stringable
{
    public readonly string $name;

    public readonly array $data;

    public readonly bool $propagationStopped;

    public readonly float $timestamp;

    public function __construct(
        string $name,
        array $data = [],
        bool $propagationStopped = false,
        ?float $timestamp = null
    ) {
        $this->name = $name;
        $this->data = $data;
        $this->propagationStopped = $propagationStopped;
        $this->timestamp = $timestamp ?? hrtime(true);
    }

    public static function fromEvent(Event $event): self
    {
        return new self(
            $event->getName(),
            $event->getData(),
            $event->isPropagationStopped(),
            $event->getTimestamp()
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getElapsed(): float
    {
        return hrtime(true) - $this->timestamp;
    }

    public function with(string $key, mixed $value): self
    {
        $newData = $this->data;
        $newData[$key] = $value;
        return new self($this->name, $newData, $this->propagationStopped, $this->timestamp);
    }

    public function withData(array $data): self
    {
        return new self($this->name, array_merge($this->data, $data), $this->propagationStopped, $this->timestamp);
    }

    public function withStopped(): self
    {
        return new self($this->name, $this->data, true, $this->timestamp);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function __toString(): string
    {
        return sprintf('ImmutableEvent(%s)', $this->name);
    }

    public static function create(string $name, array $data = []): self
    {
        return new self($name, $data);
    }
}