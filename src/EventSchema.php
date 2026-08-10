<?php

declare(strict_types=1);

namespace Kode\Event;

class EventSchema
{
    protected string $eventName;

    protected array $required = [];

    protected array $optional = [];

    protected array $types = [];

    /**
     * 自定义校验规则（多个规则之间为 AND 关系）
     *
     * @var array<int, callable(Event): bool>
     */
    protected array $rules = [];

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
     * 追加一条自定义校验规则（多条规则之间为 AND 关系）
     *
     * @param callable(Event): bool $rule
     */
    public function addRule(callable $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    /**
     * 设置自定义校验规则（兼容旧写法，等价于追加一条规则）
     *
     * @param callable(Event): bool $validator
     */
    public function validate(callable $validator): self
    {
        return $this->addRule($validator);
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

        // 多条自定义规则统一以「全部通过」判定，底层使用 PHP 8.4 array_all
        // （8.3 上由 Php84Functions 提供语义一致的 polyfill）
        if ($this->rules !== []) {
            return array_all($this->rules, static fn(callable $rule): bool => $rule($event));
        }

        return true;
    }

    /**
     * 返回首个未通过原因，全部通过时返回 null
     *
     * 便于在批量校验失败时给出可读的诊断信息。
     */
    public function explain(Event $event): ?string
    {
        if ($event->getName() !== $this->eventName) {
            return sprintf('事件名不匹配（期望 %s，实际 %s）', $this->eventName, $event->getName());
        }

        foreach ($this->required as $field) {
            if (!$event->has($field)) {
                return sprintf('缺少必填字段 %s', $field);
            }
        }

        foreach ($this->types as $field => $type) {
            if ($event->has($field) && !$this->checkType($event->get($field), $type)) {
                return sprintf('字段 %s 类型错误（期望 %s）', $field, $type);
            }
        }

        foreach ($this->rules as $index => $rule) {
            if (!$rule($event)) {
                return sprintf('自定义规则 #%d 未通过', $index);
            }
        }

        return null;
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