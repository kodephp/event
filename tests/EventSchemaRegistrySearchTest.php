<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Event;
use Kode\Event\EventSchema;
use Kode\Event\EventSchemaRegistry;
use Kode\Event\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * 覆盖 EventSchemaRegistry 的智能搜索（array_find / array_find_key）与详细校验结果。
 */
class EventSchemaRegistrySearchTest extends TestCase
{
    private function registry(): EventSchemaRegistry
    {
        $registry = new EventSchemaRegistry();
        $registry->register(
            EventSchema::create('user.created')
                ->required('user_id', 'int')
        );
        $registry->register(
            EventSchema::create('order.paid')
                ->required('order_id', 'int')
                ->addRule(static fn(Event $e): bool => ($e->get('amount') ?? 0) > 0)
        );

        return $registry;
    }

    public function testFindFirstInvalidReturnsNullWhenAllValid(): void
    {
        $registry = $this->registry();

        $result = $registry->findFirstInvalid(
            new Event('user.created', ['user_id' => 1]),
            new Event('order.paid', ['order_id' => 2, 'amount' => 10])
        );

        $this->assertNull($result);
    }

    public function testFindFirstInvalidReturnsFirstFailingEvent(): void
    {
        $registry = $this->registry();

        $first = $registry->findFirstInvalid(
            new Event('order.paid', ['order_id' => 2, 'amount' => -5]),
            new Event('user.created', ['user_id' => 1])
        );

        $this->assertInstanceOf(Event::class, $first);
        $this->assertSame('order.paid', $first->getName());
    }

    public function testFindFirstInvalidName(): void
    {
        $registry = $this->registry();

        $name = $registry->findFirstInvalidName(
            new Event('user.created', ['user_id' => 1]),
            new Event('order.paid', ['order_id' => 2, 'amount' => -5])
        );

        $this->assertSame('order.paid', $name);
    }

    public function testFindFirstInvalidNameNullWhenValid(): void
    {
        $registry = $this->registry();

        $this->assertNull($registry->findFirstInvalidName(
            new Event('user.created', ['user_id' => 1])
        ));
    }

    public function testValidateDetailedAllValid(): void
    {
        $registry = $this->registry();

        $result = $registry->validateDetailed(
            new Event('user.created', ['user_id' => 1]),
            new Event('order.paid', ['order_id' => 2, 'amount' => 10])
        );

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->allValid);
        $this->assertSame(2, $result->total);
        $this->assertSame(2, $result->passed);
        $this->assertSame(0, $result->failed);
        $this->assertSame([], $result->failures);
    }

    public function testValidateDetailedReportsFailures(): void
    {
        $registry = $this->registry();

        $result = $registry->validateDetailed(
            new Event('user.created', ['name' => 'no id']),
            new Event('order.paid', ['order_id' => 2, 'amount' => -5])
        );

        $this->assertFalse($result->allValid);
        $this->assertSame(2, $result->total);
        $this->assertSame(0, $result->passed);
        $this->assertSame(2, $result->failed);
        $this->assertArrayHasKey('user.created', $result->failures);
        $this->assertArrayHasKey('order.paid', $result->failures);
        $this->assertNotEmpty($result->failures['order.paid']);
    }

    public function testValidateDetailedUnregisteredEventIsValid(): void
    {
        $registry = $this->registry();

        $result = $registry->validateDetailed(new Event('unknown.event', []));

        $this->assertTrue($result->allValid);
        $this->assertSame(1, $result->passed);
    }

    public function testValidationResultToArray(): void
    {
        $registry = $this->registry();
        $result = $registry->validateDetailed(new Event('user.created', ['name' => 'x']));

        $array = $result->toArray();

        $this->assertArrayHasKey('all_valid', $array);
        $this->assertArrayHasKey('failures', $array);
        $this->assertArrayHasKey('total', $array);
        $this->assertFalse($array['all_valid']);
    }
}
