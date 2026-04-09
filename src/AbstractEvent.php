<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 抽象事件类
 *
 * 提供事件的基础实现，子类可继承扩展
 */
abstract class AbstractEvent implements StoppableEventInterface
{
    /**
     * 事件名称
     */
    protected string $name;

    /**
     * 事件数据
     */
    protected array $data;

    /**
     * 是否停止传播
     */
    protected bool $propagationStopped = false;

    /**
     * 事件创建时间戳
     */
    protected float $timestamp;

    /**
     * 构造抽象事件
     *
     * @param array $data 事件数据
     */
    public function __construct(array $data = [])
    {
        $this->name = $this->getEventName();
        $this->data = $data;
        $this->timestamp = hrtime(true);
    }

    /**
     * 获取事件名称（子类实现）
     */
    abstract protected function getEventName(): string;

    /**
     * 获取事件名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取事件数据
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 获取指定键的数据
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 设置事件数据
     *
     * @param string $key 键名
     * @param mixed $value 键值
     * @return $this
     */
    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * 批量设置数据
     *
     * @param array $data 数据
     * @return $this
     */
    public function fill(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * 检查数据键是否存在
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * 停止事件传播
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    /**
     * 检查事件是否已停止传播
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * 获取事件创建时间戳
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * 获取事件经过的时间（纳秒）
     */
    public function getElapsed(): float
    {
        return hrtime(true) - $this->timestamp;
    }

    /**
     * Stringable 接口实现
     */
    public function __toString(): string
    {
        return sprintf('%s(%s)', static::class, $this->name);
    }
}
