<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\Exception\InvalidEventException;
use Psr\EventDispatcher\StoppableEventInterface as PsrStoppableEventInterface;
use Stringable;

/**
 * 抽象事件类
 *
 * 供业务定义强类型事件时继承：子类只需实现 getEventName()
 * 即可获得完整的数据访问、元数据、传播控制与时间统计能力。
 *
 * 继承自 {@see Event}，因此天然实现 {@see NamedEventInterface}，
 * 派发时按事件名称路由，可与字符串事件名的监听器互通。
 */
abstract class AbstractEvent extends Event implements PsrStoppableEventInterface, Stringable
{
    /**
     * 构造抽象事件
     *
     * @param array<string, mixed> $data 事件数据
     */
    public function __construct(array $data = [])
    {
        parent::__construct($this->getEventName(), $data);
    }

    /**
     * 获取事件名称（子类实现）
     */
    abstract protected function getEventName(): string;

    /**
     * 创建事件实例
     *
     * 抽象事件的名称由 getEventName() 决定，$name 参数会被忽略，
     * 保留该参数仅为与父类签名保持兼容。
     *
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function create(string $name = '', array $data = []): static
    {
        return new static($data);
    }

    /**
     * Stringable 接口实现
     */
    #[\Override]
    public function __toString(): string
    {
        return sprintf('%s(%s)', static::class, $this->name);
    }

    /**
     * 从关联数组重建抽象事件
     *
     * 抽象事件的构造签名仅接受数据数组，故此处单独实现，
     * 其余元数据/传播状态复用与父类一致的恢复逻辑。
     *
     * @param array<string, mixed> $payload
     * @throws InvalidEventException
     */
    #[\Override]
    public static function fromArray(array $payload): static
    {
        $name = $payload['name'] ?? null;

        if (!is_string($name) || $name === '') {
            throw InvalidEventException::emptyName();
        }

        $data = $payload['data'] ?? [];

        if (!is_array($data)) {
            throw InvalidEventException::invalidJson('事件 data 字段必须为数组');
        }

        $event = new static($data);

        if (!empty($payload['metadata']) && is_array($payload['metadata'])) {
            $event->metadata = $payload['metadata'];
        }

        if (isset($payload['trace_id']) && is_string($payload['trace_id'])) {
            $event->traceId = $payload['trace_id'];
        }

        if (!empty($payload['propagation_stopped'])) {
            $event->propagationStopped = true;

            if (!empty($payload['stop_reason']) && is_string($payload['stop_reason'])) {
                $event->stopReason = $payload['stop_reason'];
            }
        }

        if (isset($payload['timestamp']) && is_numeric($payload['timestamp'])) {
            $event->timestamp = (float) $payload['timestamp'];
        }

        return $event;
    }
}
