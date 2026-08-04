<?php

declare(strict_types=1);

namespace Kode\Event;

use Psr\EventDispatcher\StoppableEventInterface as PsrStoppableEventInterface;
use Stringable;

/**
 * 事件对象
 *
 * 基础事件类，用于承载事件数据与元信息。
 *
 * 同时实现库内 {@see StoppableEventInterface} 与 PSR-14
 * {@see PsrStoppableEventInterface}，可直接被任意 PSR-14 调度器消费。
 */
class Event implements NamedEventInterface, StoppableEventInterface, PsrStoppableEventInterface, Stringable
{
    /**
     * 事件名称
     */
    protected string $name;

    /**
     * 事件数据
     *
     * @var array<string, mixed>
     */
    protected array $data;

    /**
     * 事件元数据（不参与业务数据，用于链路追踪等横切信息）
     *
     * @var array<string, mixed>
     */
    protected array $metadata = [];

    /**
     * 链路追踪 ID
     */
    protected ?string $traceId = null;

    /**
     * 事件是否停止传播
     */
    protected bool $propagationStopped = false;

    /**
     * 停止传播的原因
     */
    protected ?string $stopReason = null;

    /**
     * 事件创建时间戳（纳秒，来自 hrtime）
     */
    protected float $timestamp;

    /**
     * 构造事件对象
     *
     * @param string $name 事件名称
     * @param array<string, mixed> $data 事件数据
     */
    public function __construct(string $name, array $data = [])
    {
        $this->name = $name;
        $this->data = $data;
        $this->timestamp = hrtime(true);
    }

    /**
     * 创建事件实例（支持子类）
     *
     * @param array<string, mixed> $data
     */
    public static function create(string $name, array $data = []): static
    {
        return new static($name, $data);
    }

    // ------------------------------------------------------------------
    // 基础属性
    // ------------------------------------------------------------------

    /**
     * 获取事件名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取全部事件数据
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 获取指定键的数据，支持 a.b.c 形式的点号路径
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }

        if (!str_contains($key, '.')) {
            return $default;
        }

        $value = $this->data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * 设置事件数据
     */
    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * 批量设置数据
     *
     * @param array<string, mixed> $data
     */
    public function fill(array $data): static
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * 移除指定键
     */
    public function remove(string $key): static
    {
        unset($this->data[$key]);
        return $this;
    }

    /**
     * 检查数据键是否存在（区别于 isset，null 值也视为存在）
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    // ------------------------------------------------------------------
    // 元数据与链路追踪
    // ------------------------------------------------------------------

    /**
     * 设置链路追踪 ID
     */
    public function setTraceId(?string $traceId): static
    {
        $this->traceId = $traceId;
        return $this;
    }

    /**
     * 获取链路追踪 ID
     */
    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    /**
     * 设置元数据
     */
    public function setMeta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * 获取元数据
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 获取全部元数据
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 批量设置元数据
     *
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        return $this;
    }

    // ------------------------------------------------------------------
    // 传播控制
    // ------------------------------------------------------------------

    /**
     * 停止事件传播
     *
     * @param string|null $reason 停止原因，便于排查「事件为何未被处理」
     */
    public function stopPropagation(?string $reason = null): void
    {
        $this->propagationStopped = true;
        $this->stopReason = $reason;
    }

    /**
     * 检查事件是否已停止传播
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * 获取停止传播的原因
     */
    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * 重置传播状态，便于事件对象复用与重放
     */
    public function resumePropagation(): static
    {
        $this->propagationStopped = false;
        $this->stopReason = null;
        return $this;
    }

    // ------------------------------------------------------------------
    // 时间
    // ------------------------------------------------------------------

    /**
     * 获取事件创建时间戳（纳秒）
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * 获取事件创建至今经过的时间（纳秒）
     */
    public function getElapsed(): float
    {
        return hrtime(true) - $this->timestamp;
    }

    /**
     * 获取事件创建至今经过的时间（毫秒）
     */
    public function getElapsedMs(): float
    {
        return $this->getElapsed() / 1_000_000;
    }

    // ------------------------------------------------------------------
    // 序列化
    // ------------------------------------------------------------------

    /**
     * 导出为数组
     *
     * @return array{name: string, data: array<string, mixed>, metadata: array<string, mixed>, trace_id: string|null, timestamp: float, propagation_stopped: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'data' => $this->data,
            'metadata' => $this->metadata,
            'trace_id' => $this->traceId,
            'timestamp' => $this->timestamp,
            'propagation_stopped' => $this->propagationStopped,
        ];
    }

    /**
     * Stringable 接口实现
     */
    public function __toString(): string
    {
        return sprintf('Event(%s)', $this->name);
    }
}
