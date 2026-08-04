<?php

declare(strict_types=1);

namespace Kode\Event\Attribute;

use Attribute;

/**
 * 事件订阅者属性
 *
 * 标记类为事件订阅者。该属性为可选项——
 * {@see \Kode\Event\AttributeListenerRegistry} 会扫描所有标注了
 * #[Listener] 的公开方法，无论类上是否有本属性。
 *
 * 标注本属性可为整个类声明统一的事件前缀与默认优先级。
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Subscriber
{
    /**
     * @param string $prefix 事件名统一前缀，如 'user.'
     * @param int $priority 类内监听器的默认优先级
     */
    public function __construct(
        public string $prefix = '',
        public int $priority = 0
    ) {
    }
}
