<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件优先级枚举
 */
enum EventPriority: int
{
    case HIGH = 100;
    case NORMAL = 0;
    case LOW = -100;

    case CRITICAL = 200;
    case ELEVATED = 50;
    case DEFERRED = -200;
}
