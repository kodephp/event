<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 可停止事件接口
 *
 * 用于需要支持停止传播的事件
 */
interface StoppableEventInterface
{
    /**
     * 停止事件传播
     */
    public function stopPropagation(): void;

    /**
     * 检查事件是否已停止传播
     */
    public function isPropagationStopped(): bool;
}
