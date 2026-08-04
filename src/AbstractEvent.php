<?php

declare(strict_types=1);

namespace Kode\Event;

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
}
