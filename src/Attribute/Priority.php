<?php

declare(strict_types=1);

namespace Kode\Event\Attribute;

use Attribute;

/**
 * 事件优先级属性
 *
 * 用于设置监听器的优先级
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Priority
{
    public function __construct(public int $value = 0)
    {
    }
}
