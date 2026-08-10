<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件构建器
 *
 * 用于链式构建事件对象
 */
class EventBuilder
{
    protected string $name;
    protected array $data = [];
    protected ?string $traceId = null;
    protected array $metadata = [];

    /**
     * 构造事件构建器
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * 创建构建器
     */
    public static function create(string $name): self
    {
        return new self($name);
    }

    /**
     * 设置数据
     */
    public function data(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * 添加数据
     */
    public function with(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * 设置追踪ID
     */
    public function traceId(string $traceId): self
    {
        $this->traceId = $traceId;
        return $this;
    }

    /**
     * 添加元数据
     */
    public function meta(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * 构建事件对象
     */
    public function build(): Event
    {
        $event = new Event($this->name, $this->data);

        if ($this->traceId !== null) {
            $event->setTraceId($this->traceId);
        }

        foreach ($this->metadata as $key => $value) {
            $event->setMeta($key, $value);
        }

        return $event;
    }

    /**
     * 直接派发
     */
    public function dispatch(Dispatcher $dispatcher): Event
    {
        return $dispatcher->dispatch($this->build());
    }
}
