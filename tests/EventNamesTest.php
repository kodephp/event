<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\EventNames;
use PHPUnit\Framework\TestCase;

/**
 * EventNames::all() 动态类常量获取测试（PHP 8.3 特性）
 */
class EventNamesTest extends TestCase
{
    public function testAllReturnsNonEmptyStringArray(): void
    {
        $all = EventNames::all();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);

        foreach ($all as $name) {
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }
    }

    public function testAllContainsKnownConstants(): void
    {
        $all = EventNames::all();

        $this->assertContains(EventNames::USER_CREATED, $all);
        $this->assertContains(EventNames::ORDER_PAID, $all);
        $this->assertContains(EventNames::APPLICATION_START, $all);
        $this->assertContains(EventNames::SYSTEM_WARNING, $all);
    }

    public function testAllValuesAreUnique(): void
    {
        $all = EventNames::all();
        $unique = array_values(array_unique($all));

        $this->assertSame(count($all), count($unique), '事件名称常量不应出现重复值');
    }

    public function testAllReflectsNewConstantsAutomatically(): void
    {
        // 通过反射确认 all() 覆盖全部 public 常量，无需手工维护列表
        $reflection = new \ReflectionClass(EventNames::class);
        $constantCount = count($reflection->getReflectionConstants());

        $this->assertCount($constantCount, EventNames::all());
    }
}
