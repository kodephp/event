<?php

declare(strict_types=1);

namespace Kode\Event\Exception;

use RuntimeException;

/**
 * 事件异常基类
 *
 * 本库抛出的所有异常均继承自该类，
 * 调用方可通过捕获 EventException 统一处理事件系统错误。
 */
class EventException extends RuntimeException
{
}
