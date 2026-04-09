<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件管道
 *
 * 用于链式变换事件数据，支持 PHP 8.5 管道操作符
 */
class EventPipeline
{
    protected Event $event;

    protected array $pipes = [];

    protected bool $propagationStopped = false;

    public function __construct(Event $event)
    {
        $this->event = $event;
    }

    public static function create(Event $event): self
    {
        return new self($event);
    }

    public function pipe(callable $transform): self
    {
        $this->pipes[] = $transform;
        return $this;
    }

    public function filter(callable $predicate): self
    {
        $this->pipes[] = function (Event $event) use ($predicate): ?Event {
            return $predicate($event) ? $event : null;
        };
        return $this;
    }

    public function map(callable $mapper): self
    {
        $this->pipes[] = function (Event $event) use ($mapper): Event {
            $result = $mapper($event);
            return $result instanceof Event ? $result : $event;
        };
        return $this;
    }

    public function tap(callable $callback): self
    {
        $this->pipes[] = function (Event $event) use ($callback): Event {
            $callback($event);
            return $event;
        };
        return $this;
    }

    public function stop(): self
    {
        $this->propagationStopped = true;
        return $this;
    }

    public function then(callable $callback): mixed
    {
        $event = $this->execute();

        if ($event === null || $this->propagationStopped) {
            return null;
        }

        return $callback($event);
    }

    public function execute(): ?Event
    {
        if ($this->propagationStopped) {
            return null;
        }

        $event = $this->event;

        foreach ($this->pipes as $pipe) {
            if ($this->propagationStopped) {
                return null;
            }

            $result = $pipe($event);

            if ($result === null) {
                $this->propagationStopped = true;
                return null;
            }

            if ($result instanceof Event) {
                $event = $result;
            }
        }

        return $event;
    }

    public function dispatch(Dispatcher $dispatcher): Event
    {
        $event = $this->execute();
        return $event instanceof Event ? $dispatcher->dispatch($event) : $event;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function isStopped(): bool
    {
        return $this->propagationStopped;
    }

    public static function pipe85(mixed $value, callable $callback): mixed
    {
        return $callback($value);
    }
}