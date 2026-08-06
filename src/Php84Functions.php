<?php

/**
 * PHP 8.4 数组函数 polyfill。
 *
 * 当运行环境为 PHP < 8.4 时，提供与官方语义一致的
 * {@see array_find}、{@see array_find_key}、{@see array_any}、{@see array_all}
 * 实现；在 PHP >= 8.4 上这些函数已由引擎原生提供，此处通过 function_exists 守卫跳过，
 * 因此可安全随包分发，既能让库在 8.3 运行，又能在 8.4+ 上享受原生性能。
 *
 * 回调签名与官方一致：callable(mixed $value, mixed $key): bool。
 */

if (!function_exists('array_find')) {
    /**
     * 返回数组中首个满足回调的元素，未找到时返回 null。
     *
     * @template T
     * @param array<mixed> $array
     * @param callable(mixed, mixed): bool $callback
     * @return mixed 首个匹配的元素，或 null
     */
    function array_find(array $array, callable $callback): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }
}

if (!function_exists('array_find_key')) {
    /**
     * 返回数组中首个满足回调的元素的键，未找到时返回 null。
     *
     * @param array<mixed> $array
     * @param callable(mixed, mixed): bool $callback
     * @return mixed 首个匹配的键，或 null
     */
    function array_find_key(array $array, callable $callback): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }

        return null;
    }
}

if (!function_exists('array_any')) {
    /**
     * 当数组中至少一个元素满足回调时返回 true。
     *
     * @param array<mixed> $array
     * @param callable(mixed, mixed): bool $callback
     */
    function array_any(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('array_all')) {
    /**
     * 当数组中所有元素都满足回调（或数组为空）时返回 true。
     *
     * @param array<mixed> $array
     * @param callable(mixed, mixed): bool $callback
     */
    function array_all(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }
}
