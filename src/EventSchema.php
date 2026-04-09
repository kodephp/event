<?php

declare(strict_types=1);

namespace Kode\Event;

class EventSchema
{
    protected string $eventName;

    protected array $required = [];

    protected array $optional = [];

    protected array $types = [];

    /** @var callable|null */
    protected $validator = null;

    public function __construct(string $eventName)
    {
        $this->eventName = $eventName;
    }

    public static function create(string $eventName): self
    {
        return new self($eventName);
    }

    public function required(string $field, ?string $type = null): self
    {
        $this->required[] = $field;
        if ($type !== null) {
            $this->types[$field] = $type;
        }
        return $this;
    }

    public function optional(string $field, ?string $type = null): self
    {
        $this->optional[] = $field;
        if ($type !== null) {
            $this->types[$field] = $type;
        }
        return $this;
    }

    public function field(string $field, ?string $type = null): self
    {
        return $this->optional($field, $type);
    }

    /**
     * @param callable(Event): bool $validator
     */
    public function validate(callable $validator): self
    {
        $this->validator = $validator;
        return $this;
    }

    public function validateEvent(Event $event): bool
    {
        if ($event->getName() !== $this->eventName) {
            return false;
        }

        foreach ($this->required as $field) {
            if (!$event->has($field)) {
                return false;
            }
        }

        foreach ($this->types as $field => $type) {
            if ($event->has($field)) {
                $value = $event->get($field);
                if (!$this->checkType($value, $type)) {
                    return false;
                }
            }
        }

        if ($this->validator !== null) {
            return ($this->validator)($event);
        }

        return true;
    }

    protected function checkType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'scalar' => is_scalar($value),
            'numeric' => is_numeric($value),
            default => true,
        };
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getRequired(): array
    {
        return $this->required;
    }

    public function getOptional(): array
    {
        return $this->optional;
    }
}

class EventSchemaRegistry
{
    /** @var EventSchema[] */
    protected array $schemas = [];

    public function register(EventSchema $schema): self
    {
        $this->schemas[$schema->getEventName()] = $schema;
        return $this;
    }

    public function get(string $eventName): ?EventSchema
    {
        return $this->schemas[$eventName] ?? null;
    }

    public function has(string $eventName): bool
    {
        return isset($this->schemas[$eventName]);
    }

    public function validate(Event $event): bool
    {
        $schema = $this->get($event->getName());

        if ($schema === null) {
            return true;
        }

        return $schema->validateEvent($event);
    }

    /**
     * @param Event[] $events
     * @return bool[]
     */
    public function validateMany(array $events): array
    {
        $results = [];

        foreach ($events as $event) {
            $results[] = $this->validate($event);
        }

        return $results;
    }

    public function clear(?string $eventName = null): self
    {
        if ($eventName === null) {
            $this->schemas = [];
        } else {
            unset($this->schemas[$eventName]);
        }

        return $this;
    }

    /**
     * @return EventSchema[]
     */
    public function all(): array
    {
        return $this->schemas;
    }
}