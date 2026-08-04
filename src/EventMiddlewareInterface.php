<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件中间件接口
 *
 * 中间件以洋葱模型包裹事件派发过程，可在派发前后插入逻辑，
 * 也可短路后续流程（不调用 $next）。
 */
interface EventMiddlewareInterface
{
    /**
     * 处理事件
     *
     * @param Event $event 事件对象
     * @param callable $next 下一层处理器
     * @return mixed 处理结果
     */
    public function handle(Event $event, callable $next): mixed;
}
