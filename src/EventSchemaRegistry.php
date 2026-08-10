<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件结构注册表
 *
 * 管理一批 {@see EventSchema}，提供按事件名校验、批量详细校验、
 * 以及基于 PHP 8.4 数组函数（array_find / array_find_key，8.3 上由
 * Php84Functions 提供 polyfill）的智能搜索能力。
 */
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
     * 返回首个未通过校验的事件（无失败则返回 null）
     *
     * 基于 PHP 8.4 array_find（8.3 上由 Php84Functions 提供 polyfill）实现，
     * 适合在批量派发前快速定位首个非法事件。
     */
    public function findFirstInvalid(Event ...$events): ?Event
    {
        return array_find(
            $events,
            fn(Event $event): bool => !$this->validate($event)
        );
    }

    /**
     * 返回首个未通过校验的事件名称（无失败则返回 null）
     *
     * 基于 PHP 8.4 array_find_key。
     */
    public function findFirstInvalidName(Event ...$events): ?string
    {
        $index = array_find_key(
            $events,
            fn(Event $event): bool => !$this->validate($event)
        );

        return $index === null ? null : $events[$index]->getName();
    }

    /**
     * 对一批事件做详细校验，返回结构化结果
     *
     * 对每个未通过校验的事件，尝试给出可读的失败原因（由对应 {@see EventSchema::explain()}
     * 提供）；未注册 schema 的事件视为通过。
     */
    public function validateDetailed(Event ...$events): ValidationResult
    {
        $failures = [];
        $passed = 0;

        foreach ($events as $event) {
            if ($this->validate($event)) {
                $passed++;
                continue;
            }

            $schema = $this->get($event->getName());
            $reason = $schema !== null ? $schema->explain($event) : '未通过校验';

            // 同名事件可能存在多条失败，存为数组避免相互覆盖
            $failures[$event->getName()][] = $reason ?? '未通过校验';
        }

        $total = count($events);
        $failed = $total - $passed;

        return new ValidationResult($failed === 0, $failures, $total, $passed, $failed);
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
