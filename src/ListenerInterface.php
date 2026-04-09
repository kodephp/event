<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 监听器接口
 *
 * 定义事件监听器的标准契约
 */
interface ListenerInterface
{
    /**
     * 处理事件
     *
     * @param Event $event 事件对象
     * @return void|callable 返回 callable 表示暂停，返回 void 继续传播
     */
    public function handle(Event $event): void;

    /**
     * 获取监听的事件名称
     *
     * @return string|string[] 事件名称或事件名称数组
     */
    public function events(): string|array;

    /**
     * 获取监听器优先级
     *
     * @return int 优先级，数值越大越先执行
     */
    public function priority(): int;
}
