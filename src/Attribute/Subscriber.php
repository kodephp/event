<?php

declare(strict_types=1);

namespace Kode\Event\Attribute;

use Attribute;

/**
 * 事件订阅者属性
 *
 * 标记类为事件订阅者，自动注册所有 #[Listener] 标记的方法
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Subscriber
{
}
