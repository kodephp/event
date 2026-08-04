<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件助手类
 *
 * 提供事件相关的静态工具方法
 */
final class EventHelper
{
    private function __construct()
    {
    }

    /**
     * 创建事件
     *
     * @template T of Event
     * @param class-string<T> $class
     * @param array $data
     * @return T
     */
    public static function create(string $class, array $data = []): Event
    {
        return new $class($data);
    }

    /**
     * 批量创建事件
     *
     * @param array<string, array> $events [eventName => data]
     * @return Event[]
     */
    public static function createMany(array $events): array
    {
        $result = [];
        foreach ($events as $name => $data) {
            $result[] = new Event((string) $name, $data);
        }
        return $result;
    }

    /**
     * 检查是否为有效事件名称
     */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9._-]*$/', $name) === 1;
    }

    /**
     * 规范化事件名称
     */
    public static function normalizeName(string $name): string
    {
        return strtolower(trim($name, ' \t\n\r\0\x0B.'));
    }

    /**
     * 解析事件名称为组件
     *
     * @return array{prefix: string, name: string, suffix: string}
     */
    public static function parseName(string $name): array
    {
        $parts = explode('.', $name);
        $prefix = $parts[0] ?? '';
        $suffix = $parts[count($parts) - 1] ?? '';
        $middleParts = array_slice($parts, 1, -1);
        $middle = implode('.', $middleParts);

        return [
            'prefix' => $prefix,
            'name' => $middle ?: $suffix,
            'suffix' => $middle ? $suffix : '',
        ];
    }

    /**
     * 构建事件名称
     *
     * @param string $prefix
     * @param string $name
     * @param string $suffix
     */
    public static function buildName(string $prefix, string $name, string $suffix = ''): string
    {
        $parts = array_filter([$prefix, $name, $suffix]);
        return implode('.', $parts);
    }

    /**
     * 检查是否匹配通配符模式
     *
     * `*` 匹配任意数量字符，`?` 匹配单个字符。
     * 正则编译结果由 {@see ListenerRegistry::compilePattern()} 统一缓存复用。
     *
     * @param string $name 事件名称
     * @param string $pattern 通配符模式（如 user.* 或 *.created）
     */
    public static function matchesPattern(string $name, string $pattern): bool
    {
        return (bool) preg_match(ListenerRegistry::compilePattern($pattern), $name);
    }

    /**
     * 获取 PHP 版本特性支持信息
     *
     * @return array<string, bool>
     */
    public static function getPhpFeatures(): array
    {
        return [
            'enum' => version_compare(PHP_VERSION, '8.1', '>='),
            'union_types' => version_compare(PHP_VERSION, '8.1', '>='),
            'never_return' => version_compare(PHP_VERSION, '8.1', '>='),
            'readonly' => version_compare(PHP_VERSION, '8.1', '>='),
            'true_type' => version_compare(PHP_VERSION, '8.2', '>='),
            'dnf_types' => version_compare(PHP_VERSION, '8.2', '>='),
            'constants_null' => version_compare(PHP_VERSION, '8.2', '>='),
            'pipe_operator' => version_compare(PHP_VERSION, '8.5', '>='),
            'clone_with' => version_compare(PHP_VERSION, '8.5', '>='),
            'json_validate' => version_compare(PHP_VERSION, '8.3', '>='),
        ];
    }
}
