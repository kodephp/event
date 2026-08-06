<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 验证 PHP 8.4 数组函数 polyfill 在 8.3 上的语义与原生 8.4 一致。
 */
class Php84FunctionsTest extends TestCase
{
    public function testArrayFindReturnsFirstMatch(): void
    {
        $data = [1, 2, 3, 4, 5];
        $result = array_find($data, static fn(int $v): bool => $v > 3);

        $this->assertSame(4, $result);
    }

    public function testArrayFindReturnsNullWhenNoMatch(): void
    {
        $result = array_find([1, 2, 3], static fn(int $v): bool => $v > 10);

        $this->assertNull($result);
    }

    public function testArrayFindKeyReturnsKey(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => 3];
        $key = array_find_key($data, static fn(int $v): bool => $v === 2);

        $this->assertSame('b', $key);
    }

    public function testArrayFindKeyReturnsNullWhenNoMatch(): void
    {
        $key = array_find_key(['a' => 1], static fn(int $v): bool => $v === 99);

        $this->assertNull($key);
    }

    public function testArrayAnyTrue(): void
    {
        $this->assertTrue(array_any([1, 2, 3], static fn(int $v): bool => $v === 2));
    }

    public function testArrayAnyFalse(): void
    {
        $this->assertFalse(array_any([1, 2, 3], static fn(int $v): bool => $v === 99));
    }

    public function testArrayAllTrue(): void
    {
        $this->assertTrue(array_all([2, 4, 6], static fn(int $v): bool => $v % 2 === 0));
    }

    public function testArrayAllFalse(): void
    {
        $this->assertFalse(array_all([2, 4, 5], static fn(int $v): bool => $v % 2 === 0));
    }

    public function testArrayAllOnEmptyIsTrue(): void
    {
        $this->assertTrue(array_all([], static fn($v): bool => false));
    }

    public function testCallbackReceivesKey(): void
    {
        $seenKeys = [];
        array_find(['x' => 10, 'y' => 20], static function (int $v, $k) use (&$seenKeys): bool {
            $seenKeys[] = $k;
            return false;
        });

        $this->assertSame(['x', 'y'], $seenKeys);
    }

    public function testFunctionsAreAvailable(): void
    {
        $this->assertTrue(function_exists('array_find'));
        $this->assertTrue(function_exists('array_find_key'));
        $this->assertTrue(function_exists('array_any'));
        $this->assertTrue(function_exists('array_all'));
    }
}
