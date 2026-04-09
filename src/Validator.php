<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\Exception\InvalidEventException;
use Kode\Event\Exception\ListenerException;

/**
 * 验证器
 *
 * 提供事件和监听器的验证功能
 */
final class Validator
{
    private function __construct()
    {
    }

    /**
     * 验证事件名称
     *
     * @throws InvalidEventException
     */
    public static function validateEventName(string $name): void
    {
        if ($name === '') {
            throw InvalidEventException::emptyName();
        }

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9._-]*$/', $name)) {
            throw InvalidEventException::invalidName($name);
        }
    }

    /**
     * 验证监听器
     *
     * @throws ListenerException
     */
    public static function validateListener(callable|ListenerInterface $listener): void
    {
        if ($listener instanceof ListenerInterface) {
            return;
        }

        if (is_callable($listener)) {
            return;
        }

        throw ListenerException::notCallable($listener);
    }

    /**
     * 验证优先级
     *
     * @throws ListenerException
     */
    public static function validatePriority(int $priority): void
    {
        if (!is_int($priority)) {
            throw ListenerException::invalidPriority($priority);
        }
    }

    /**
     * 安全执行回调
     *
     * @template T
     * @param callable(): T $callback
     * @param T $default
     * @return T|null
     */
    public static function safeCall(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * 安全执行监听器
     *
     * @param callable|ListenerInterface $listener
     * @param Event $event
     * @param bool $stopOnError 是否在错误时停止
     * @return \Throwable|null
     */
    public static function safeExecuteListener(
        callable|ListenerInterface $listener,
        Event $event,
        bool $stopOnError = false
    ): ?\Throwable {
        try {
            if ($listener instanceof ListenerInterface) {
                $listener->handle($event);
            } else {
                ($listener)($event);
            }
            return null;
        } catch (\Throwable $e) {
            if ($stopOnError) {
                $event->stopPropagation();
            }
            return $e;
        }
    }
}
