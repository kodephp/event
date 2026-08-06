<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Event;
use Kode\Event\EventPredicates;
use Kode\Event\EventSchema;
use PHPUnit\Framework\TestCase;

/**
 * 覆盖 EventSchema 多规则（array_all 判定）与 EventPredicates 组合谓词。
 */
class EventSchemaCompositeTest extends TestCase
{
    public function testMultipleRulesUseAndSemantics(): void
    {
        $schema = EventSchema::create('order.paid')
            ->required('order_id', 'int')
            ->addRule(static fn(Event $e): bool => ($e->get('amount') ?? 0) > 0)
            ->addRule(static fn(Event $e): bool => ($e->get('currency') ?? '') === 'CNY');

        $valid = new Event('order.paid', ['order_id' => 1, 'amount' => 10, 'currency' => 'CNY']);
        $missingCurrency = new Event('order.paid', ['order_id' => 1, 'amount' => 10]);

        $this->assertTrue($schema->validateEvent($valid));
        $this->assertFalse($schema->validateEvent($missingCurrency));
    }

    public function testValidateIsAliasForAddRule(): void
    {
        $schema = EventSchema::create('user.login')
            ->validate(static fn(Event $e): bool => $e->has('uid'))
            ->validate(static fn(Event $e): bool => $e->has('token'));

        $full = new Event('user.login', ['uid' => 1, 'token' => 'abc']);
        $partial = new Event('user.login', ['uid' => 1]);

        $this->assertTrue($schema->validateEvent($full));
        $this->assertFalse($schema->validateEvent($partial));
    }

    public function testExplainReturnsFirstFailureReason(): void
    {
        $schema = EventSchema::create('user.created')
            ->required('user_id', 'int')
            ->required('email', 'string')
            ->addRule(static fn(Event $e): bool => str_contains((string) $e->get('email'), '@'));

        $withoutEmail = new Event('user.created', ['user_id' => 1]);
        $badEmail = new Event('user.created', ['user_id' => 1, 'email' => 'not-an-email']);

        $this->assertSame('缺少必填字段 email', $schema->explain($withoutEmail));
        $this->assertSame('自定义规则 #0 未通过', $schema->explain($badEmail));
        $this->assertNull($schema->explain(new Event('user.created', ['user_id' => 1, 'email' => 'a@b.com'])));
    }

    public function testExplainDetectsNameMismatch(): void
    {
        $schema = EventSchema::create('a.b')->required('x');
        $this->assertNotNull($schema->explain(new Event('c.d', ['x' => 1])));
    }

    public function testPredicatesAll(): void
    {
        $adult = static fn(Event $e): bool => ($e->get('age') ?? 0) >= 18;
        $vip = static fn(Event $e): bool => ($e->get('vip') ?? false) === true;

        $gate = EventPredicates::all($adult, $vip);
        $ok = new Event('x', ['age' => 20, 'vip' => true]);
        $no = new Event('x', ['age' => 20, 'vip' => false]);

        $this->assertTrue($gate($ok));
        $this->assertFalse($gate($no));
    }

    public function testPredicatesAny(): void
    {
        $premium = static fn(Event $e): bool => ($e->get('tier') ?? '') === 'premium';
        $staff = static fn(Event $e): bool => ($e->get('staff') ?? false) === true;

        $gate = EventPredicates::any($premium, $staff);
        $this->assertTrue($gate(new Event('x', ['tier' => 'premium'])));
        $this->assertTrue($gate(new Event('x', ['staff' => true])));
        $this->assertFalse($gate(new Event('x', [])));
    }

    public function testPredicatesNone(): void
    {
        $banned = static fn(Event $e): bool => ($e->get('role') ?? '') === 'banned';
        $guest = static fn(Event $e): bool => ($e->get('role') ?? '') === 'guest';

        $gate = EventPredicates::none($banned, $guest);
        $this->assertTrue($gate(new Event('x', ['role' => 'user'])));
        $this->assertFalse($gate(new Event('x', ['role' => 'banned'])));
    }

    public function testAllSchemasComposesMultipleSchemas(): void
    {
        $login = EventSchema::create('session.start')->required('uid', 'int');
        $token = EventSchema::create('session.start')->required('token', 'string');

        $gate = EventPredicates::allSchemas($login, $token);
        $ok = new Event('session.start', ['uid' => 1, 'token' => 'abc']);
        $bad = new Event('session.start', ['uid' => 1]);

        $this->assertTrue($gate($ok));
        $this->assertFalse($gate($bad));
    }
}
