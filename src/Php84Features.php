<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * PHP 8.4 语言特性探测
 *
 * 提供对 PHP 8.4 新增能力的运行时探测，便于调用方依据能力选择最优实现路径。
 * 与 {@see Php85Features} 互补：本类聚焦 8.4 已稳定的特性（数组函数、
 * 属性钩子、非对称可见性、惰性对象等）。
 *
 * 所有方法均为纯只读探测，无任何副作用。
 */
final class Php84Features
{
    private function __construct()
    {
    }

    /**
     * 是否支持 PHP 8.4 数组函数（array_find / array_find_key / array_any / array_all）
     */
    public static function hasArrayFunctions(): bool
    {
        return version_compare(PHP_VERSION, '8.4', '>=');
    }

    public static function hasArrayFind(): bool
    {
        return function_exists('array_find');
    }

    public static function hasArrayFindKey(): bool
    {
        return function_exists('array_find_key');
    }

    public static function hasArrayAny(): bool
    {
        return function_exists('array_any');
    }

    public static function hasArrayAll(): bool
    {
        return function_exists('array_all');
    }

    /**
     * 是否支持属性钩子（property hooks）
     */
    public static function hasPropertyHooks(): bool
    {
        return version_compare(PHP_VERSION, '8.4', '>=');
    }

    /**
     * 是否支持非对称属性可见性（asymmetric visibility）
     */
    public static function hasAsymmetricVisibility(): bool
    {
        return version_compare(PHP_VERSION, '8.4', '>=');
    }

    /**
     * 是否支持 #[\Lazy] 惰性对象
     */
    public static function hasLazyObjects(): bool
    {
        return version_compare(PHP_VERSION, '8.4', '>=');
    }

    /**
     * 是否支持隐式可空类型（nullable 类型自动隐式化，如 ?T 写法废弃警告）
     */
    public static function hasDeprecatedNullableImplicit(): bool
    {
        return version_compare(PHP_VERSION, '8.4', '>=');
    }

    public static function getPhpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * 探测单一特性是否可用
     */
    public static function supportsFeature(string $feature): bool
    {
        return match ($feature) {
            'array_functions' => self::hasArrayFunctions(),
            'array_find' => self::hasArrayFind(),
            'array_find_key' => self::hasArrayFindKey(),
            'array_any' => self::hasArrayAny(),
            'array_all' => self::hasArrayAll(),
            'property_hooks' => self::hasPropertyHooks(),
            'asymmetric_visibility' => self::hasAsymmetricVisibility(),
            'lazy_objects' => self::hasLazyObjects(),
            'json_validate' => version_compare(PHP_VERSION, '8.3', '>='),
            'mb_str_pad' => version_compare(PHP_VERSION, '8.3', '>='),
            'readonly' => version_compare(PHP_VERSION, '8.1', '>='),
            'enum' => version_compare(PHP_VERSION, '8.1', '>='),
            default => false,
        };
    }

    /**
     * 汇总所有特性支持情况
     *
     * @return array<string, bool|string>
     */
    public static function getAllFeatures(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'array_functions' => self::hasArrayFunctions(),
            'array_find' => self::hasArrayFind(),
            'array_find_key' => self::hasArrayFindKey(),
            'array_any' => self::hasArrayAny(),
            'array_all' => self::hasArrayAll(),
            'property_hooks' => self::hasPropertyHooks(),
            'asymmetric_visibility' => self::hasAsymmetricVisibility(),
            'lazy_objects' => self::hasLazyObjects(),
            'json_validate' => version_compare(PHP_VERSION, '8.3', '>='),
            'mb_str_pad' => version_compare(PHP_VERSION, '8.3', '>='),
            'readonly' => version_compare(PHP_VERSION, '8.1', '>='),
            'enum' => version_compare(PHP_VERSION, '8.1', '>='),
        ];
    }
}
