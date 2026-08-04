<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 监听器异常处理策略
 *
 * 决定某个监听器抛出异常时，调度器的后续行为。
 */
enum ErrorStrategy: string
{
    /**
     * 立即向上抛出，中断整条监听链（默认，保持与旧版本一致的行为）
     */
    case THROW = 'throw';

    /**
     * 收集异常并继续执行后续监听器，全部执行完毕后
     * 以 EventDispatchException 聚合抛出
     */
    case COLLECT = 'collect';

    /**
     * 忽略异常并继续执行后续监听器，异常仅传递给 onError 钩子
     */
    case IGNORE = 'ignore';
}
