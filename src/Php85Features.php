<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * PHP 8.5 新特性支持
 *
 * 提供 PHP 8.5 新特性的条件使用支持
 */
final class Php85Features
{
    private function __construct()
    {
    }

    /**
     * 检查是否支持管道操作符 |>
     */
    public static function hasPipeOperator(): bool
    {
        return version_compare(PHP_VERSION, '8.5', '>=');
    }

    /**
     * 检查是否支持 clone with 表达式
     */
    public static function hasCloneWith(): bool
    {
        return version_compare(PHP_VERSION, '8.5', '>=');
    }

    /**
     * 管道操作符的 polyfill（兼容 PHP < 8.5）
     *
     * @template T
     * @param T $value
     * @param callable(T): mixed $callback
     * @return mixed
     */
    public static function pipe(mixed $value, callable $callback): mixed
    {
        return $callback($value);
    }

    /**
     * 批量管道操作
     *
     * @param mixed $value
     * @param callable[] $callbacks
     * @return mixed
     */
    public static function pipeMany(mixed $value, array $callbacks): mixed
    {
        foreach ($callbacks as $callback) {
            $value = $callback($value);
        }
        return $value;
    }
}
