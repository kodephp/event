<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件名称常量类
 *
 * 提供常用事件名称常量，避免硬编码
 */
final class EventNames
{
    private function __construct()
    {
    }

    public const APPLICATION_START = 'app.start';
    public const APPLICATION_STOP = 'app.stop';
    public const APPLICATION_ERROR = 'app.error';

    public const REQUEST_START = 'request.start';
    public const REQUEST_END = 'request.end';

    public const USER_CREATED = 'user.created';
    public const USER_UPDATED = 'user.updated';
    public const USER_DELETED = 'user.deleted';
    public const USER_LOGIN = 'user.login';
    public const USER_LOGOUT = 'user.logout';

    public const ORDER_CREATED = 'order.created';
    public const ORDER_PAID = 'order.paid';
    public const ORDER_COMPLETED = 'order.completed';
    public const ORDER_CANCELLED = 'order.cancelled';

    public const SYSTEM_ERROR = 'system.error';
    public const SYSTEM_WARNING = 'system.warning';
}
