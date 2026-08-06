<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件谓词组合器
 *
 * 基于 PHP 8.4 数组函数（{@see array_all} / {@see array_any}，在 8.3 上由
 * {@see Php84Functions} 提供 polyfill）将多个断言组合为单一可调用谓词，
 * 便于在 {@see EventSchema}、{@see ValidationMiddleware} 等场景中表达
 * 复杂的「全部满足 / 任一满足 / 全不满足」语义。
 *
 * 示例：
 * ```php
 * $adult = fn(Event $e) => ($e->get('age') ?? 0) >= 18;
 * $vip   = fn(Event $e) => ($e->get('vip') ?? false) === true;
 * $gate  = EventPredicates::all($adult, $vip);
 * $gate($event); // true 表示同时满足成年且 VIP
 * ```
 */
final class EventPredicates
{
    private function __construct()
    {
    }

    /**
     * 全部谓词均满足（AND 语义）
     *
     * @param callable(mixed): bool ...$predicates
     * @return callable(mixed): bool
     */
    public static function all(callable ...$predicates): callable
    {
        return static fn(mixed $value): bool => array_all(
            $predicates,
            static fn(callable $predicate): bool => $predicate($value)
        );
    }

    /**
     * 任一谓词满足（OR 语义）
     *
     * @param callable(mixed): bool ...$predicates
     * @return callable(mixed): bool
     */
    public static function any(callable ...$predicates): callable
    {
        return static fn(mixed $value): bool => array_any(
            $predicates,
            static fn(callable $predicate): bool => $predicate($value)
        );
    }

    /**
     * 全部谓词均不满足（NOR 语义）
     *
     * @param callable(mixed): bool ...$predicates
     * @return callable(mixed): bool
     */
    public static function none(callable ...$predicates): callable
    {
        return static fn(mixed $value): bool => !array_any(
            $predicates,
            static fn(callable $predicate): bool => $predicate($value)
        );
    }

    /**
     * 将多个 {@see EventSchema} 组合为单一谓词（全部通过才算通过）
     *
     * @param EventSchema ...$schemas
     * @return callable(Event): bool
     */
    public static function allSchemas(EventSchema ...$schemas): callable
    {
        return self::all(...array_map(
            static fn(EventSchema $schema): callable => static fn(Event $event): bool => $schema->validateEvent($event),
            $schemas
        ));
    }

    /**
     * 将多个 {@see EventSchema} 组合为单一谓词（任一通过即通过）
     *
     * @param EventSchema ...$schemas
     * @return callable(Event): bool
     */
    public static function anySchemas(EventSchema ...$schemas): callable
    {
        return self::any(...array_map(
            static fn(EventSchema $schema): callable => static fn(Event $event): bool => $schema->validateEvent($event),
            $schemas
        ));
    }
}
