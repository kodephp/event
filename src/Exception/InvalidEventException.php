<?php

declare(strict_types=1);

namespace Kode\Event\Exception;

/**
 * 无效事件异常
 *
 * 事件名称为空、格式非法或事件对象不符合契约时抛出。
 */
class InvalidEventException extends EventException
{
    /**
     * 事件名称格式非法
     */
    public static function invalidName(string $name): self
    {
        return new self("无效的事件名称: {$name}");
    }

    /**
     * 事件名称为空
     */
    public static function emptyName(): self
    {
        return new self('事件名称不能为空');
    }

    /**
     * 事件对象类型非法
     */
    public static function invalidEvent(mixed $event): self
    {
        return new self('事件必须为对象或字符串，当前类型: ' . get_debug_type($event));
    }

    /**
     * JSON 事件数据无效（PHP 8.3 json_validate 校验失败或结构非法）
     */
    public static function invalidJson(string $json): self
    {
        $preview = mb_strimwidth($json, 0, 80, '…');

        return new self("无效的 JSON 事件数据: {$preview}");
    }
}
