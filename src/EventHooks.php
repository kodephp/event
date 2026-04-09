<?php

declare(strict_types=1);

namespace Kode\Event;

interface EventLifecycleHookInterface
{
    public function beforeDispatch(Event $event): Event;

    public function afterDispatch(Event $event): void;

    public function onError(Event $event, \Throwable $error): void;
}

class EventHooks
{
    protected array $beforeDispatch = [];

    protected array $afterDispatch = [];

    protected array $onError = [];

    public function before(callable $hook, int $priority = 0): self
    {
        $this->beforeDispatch[] = [
            'hook' => $hook,
            'priority' => $priority,
        ];
        $this->sortHooks($this->beforeDispatch);
        return $this;
    }

    public function after(callable $hook, int $priority = 0): self
    {
        $this->afterDispatch[] = [
            'hook' => $hook,
            'priority' => $priority,
        ];
        $this->sortHooks($this->afterDispatch);
        return $this;
    }

    public function error(callable $hook, int $priority = 0): self
    {
        $this->onError[] = [
            'hook' => $hook,
            'priority' => $priority,
        ];
        $this->sortHooks($this->onError);
        return $this;
    }

    public function removeBefore(callable $hook): self
    {
        $this->beforeDispatch = array_values(
            array_filter(
                $this->beforeDispatch,
                fn($item) => $item['hook'] !== $hook
            )
        );
        return $this;
    }

    public function removeAfter(callable $hook): self
    {
        $this->afterDispatch = array_values(
            array_filter(
                $this->afterDispatch,
                fn($item) => $item['hook'] !== $hook
            )
        );
        return $this;
    }

    public function removeError(callable $hook): self
    {
        $this->onError = array_values(
            array_filter(
                $this->onError,
                fn($item) => $item['hook'] !== $hook
            )
        );
        return $this;
    }

    public function triggerBefore(Event $event): Event
    {
        foreach ($this->beforeDispatch as $item) {
            $result = $item['hook']($event);
            if ($result instanceof Event) {
                $event = $result;
            }
        }
        return $event;
    }

    public function triggerAfter(Event $event): void
    {
        foreach ($this->afterDispatch as $item) {
            $item['hook']($event);
        }
    }

    public function triggerError(Event $event, \Throwable $error): void
    {
        foreach ($this->onError as $item) {
            $item['hook']($event, $error);
        }
    }

    public function clear(?string $type = null): self
    {
        if ($type === null) {
            $this->beforeDispatch = [];
            $this->afterDispatch = [];
            $this->onError = [];
        } elseif ($type === 'before') {
            $this->beforeDispatch = [];
        } elseif ($type === 'after') {
            $this->afterDispatch = [];
        } elseif ($type === 'error') {
            $this->onError = [];
        }

        return $this;
    }

    protected function sortHooks(array &$hooks): void
    {
        usort($hooks, fn($a, $b) => $b['priority'] <=> $a['priority']);
    }
}