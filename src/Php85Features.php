<?php

declare(strict_types=1);

namespace Kode\Event;

final class Php85Features
{
    private function __construct()
    {
    }

    public static function hasPipeOperator(): bool
    {
        return version_compare(PHP_VERSION, '8.5', '>=');
    }

    public static function hasCloneWith(): bool
    {
        return version_compare(PHP_VERSION, '8.5', '>=');
    }

    public static function hasAsymmetricVisibility(): bool
    {
        return version_compare(PHP_VERSION, '8.5', '>=');
    }

    public static function hasTrueType(): bool
    {
        return version_compare(PHP_VERSION, '8.5', '>=');
    }

    public static function hasJsonError(): bool
    {
        return version_compare(PHP_VERSION, '8.5', '>=');
    }

    public static function pipe(mixed $value, callable $callback): mixed
    {
        return $callback($value);
    }

    public static function pipeMany(mixed $value, array $callbacks): mixed
    {
        foreach ($callbacks as $callback) {
            $value = $callback($value);
        }
        return $value;
    }

    public static function pipeEvent(Event $event, callable $callback): Event
    {
        $result = $callback($event);
        return $result instanceof Event ? $result : $event;
    }

    public static function getPhpVersion(): string
    {
        return PHP_VERSION;
    }

    public static function getMajorVersion(): int
    {
        return (int) explode('.', PHP_VERSION)[0];
    }

    public static function getMinorVersion(): int
    {
        return (int) explode('.', PHP_VERSION)[1];
    }

    public static function getReleaseVersion(): int
    {
        $parts = explode('.', PHP_VERSION);
        return (int) ($parts[2] ?? 0);
    }

    public static function supportsFeature(string $feature): bool
    {
        return match ($feature) {
            'pipe_operator' => self::hasPipeOperator(),
            'clone_with' => self::hasCloneWith(),
            'asymmetric_visibility' => self::hasAsymmetricVisibility(),
            'true_type' => self::hasTrueType(),
            'json_error' => self::hasJsonError(),
            'enum' => version_compare(PHP_VERSION, '8.1', '>='),
            'union_types' => version_compare(PHP_VERSION, '8.0', '>='),
            'readonly' => version_compare(PHP_VERSION, '8.1', '>='),
            'never_type' => version_compare(PHP_VERSION, '8.1', '>='),
            'first_class_callable' => version_compare(PHP_VERSION, '8.1', '>='),
            'constants' => version_compare(PHP_VERSION, '8.3', '>='),
            'typed_class_constants' => version_compare(PHP_VERSION, '8.3', '>='),
            'impure' => version_compare(PHP_VERSION, '8.4', '>='),
            default => false,
        };
    }

    public static function getAllFeatures(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'major' => self::getMajorVersion(),
            'minor' => self::getMinorVersion(),
            'release' => self::getReleaseVersion(),
            'pipe_operator' => self::hasPipeOperator(),
            'clone_with' => self::hasCloneWith(),
            'asymmetric_visibility' => self::hasAsymmetricVisibility(),
            'true_type' => self::hasTrueType(),
            'json_error' => self::hasJsonError(),
            'enum' => version_compare(PHP_VERSION, '8.1', '>='),
            'union_types' => version_compare(PHP_VERSION, '8.0', '>='),
            'readonly' => version_compare(PHP_VERSION, '8.1', '>='),
            'never_type' => version_compare(PHP_VERSION, '8.1', '>='),
            'first_class_callable' => version_compare(PHP_VERSION, '8.1', '>='),
            'constants' => version_compare(PHP_VERSION, '8.3', '>='),
            'typed_class_constants' => version_compare(PHP_VERSION, '8.3', '>='),
            'impure' => version_compare(PHP_VERSION, '8.4', '>='),
        ];
    }
}