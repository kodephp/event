<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\ListenerInterface;

class EventBubbles
{
    protected bool $enabled = true;

    protected array $parentEvents = [];

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

    public function registerParent(string $child, string $parent): self
    {
        if (!isset($this->parentEvents[$child])) {
            $this->parentEvents[$child] = [];
        }
        $this->parentEvents[$child][] = $parent;
        return $this;
    }

    public function registerParents(array $mapping): self
    {
        foreach ($mapping as $child => $parent) {
            $this->registerParent($child, $parent);
        }
        return $this;
    }

    public function bubble(Event $event): Event
    {
        if (!$this->enabled) {
            return $event;
        }

        $eventName = $event->getName();
        $parents = $this->parentEvents[$eventName] ?? [];

        foreach ($parents as $parent) {
            if ($event->isPropagationStopped()) {
                break;
            }
            $parentEvent = Event::create($parent, $event->getData());
            if ($event->isPropagationStopped()) {
                $parentEvent->stopPropagation();
            }
            $this->dispatcher->dispatch($parentEvent);
        }

        return $event;
    }

    public function getParents(string $event): array
    {
        return $this->parentEvents[$event] ?? [];
    }

    public function hasParent(string $event): bool
    {
        return isset($this->parentEvents[$event]) && !empty($this->parentEvents[$event]);
    }

    public function clear(): self
    {
        $this->parentEvents = [];
        return $this;
    }
}