<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件调度器契约
 *
 * 定义调度器的最小能力集合。库内所有依赖调度器的组件
 * （事件组、冒泡、延迟派发、追踪器等）均面向本接口编程，
 * 便于替换实现与单元测试打桩。
 */
interface DispatcherInterface
{
    /**
     * 注册监听器
     *
     * @param string $event 事件名称，支持 * 与 ? 通配符
     * @param callable|ListenerInterface $listener 监听器
     * @param int $priority 优先级，数值越大越先执行
     */
    public function listen(string $event, callable|ListenerInterface $listener, int $priority = 0): static;

    /**
     * 注册一次性监听器，触发一次后自动注销
     */
    public function once(string $event, callable|ListenerInterface $listener, int $priority = 0): static;

    /**
     * 注销监听器
     */
    public function unlisten(string $event, callable|ListenerInterface $listener): static;

    /**
     * 注册订阅者
     */
    public function subscribe(SubscriberInterface $subscriber): static;

    /**
     * 派发事件
     *
     * @param object|string $event 事件对象或事件名称
     * @param array<string, mixed> $data 事件数据（$event 为字符串时生效）
     * @return object 派发后的事件对象
     */
    public function dispatch(object|string $event, array $data = []): object;

    /**
     * 检查事件是否存在监听器
     */
    public function hasListeners(string $event): bool;

    /**
     * 获取事件的所有监听器
     *
     * @return array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}>
     */
    public function getListeners(string $event): array;

    /**
     * 清空监听器
     *
     * @param string|null $event 事件名称，null 表示清空全部
     */
    public function clear(?string $event = null): static;
}
