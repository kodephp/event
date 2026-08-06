<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\ValidationMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * 覆盖 ValidationMiddleware 基于 array_filter / array_find_key 的匹配与诊断。
 */
class ValidationMiddlewareArrayTest extends TestCase
{
    public function testPassesWhenRuleSatisfied(): void
    {
        $mw = new ValidationMiddleware();
        $mw->addRule('user.created', static fn(Event $e): bool => $e->has('user_id'));

        $dispatched = false;
        $mw->handle(new Event('user.created', ['user_id' => 1]), static function () use (&$dispatched): void {
            $dispatched = true;
        });

        $this->assertTrue($dispatched);
    }

    public function testThrowsWithRuleIndexOnFailure(): void
    {
        $mw = new ValidationMiddleware();
        $mw->addRule('user.created', static fn(Event $e): bool => $e->has('user_id'));
        $mw->addRule('user.created', static fn(Event $e): bool => $e->has('email'));

        $this->expectException(\RuntimeException::class);

        $mw->handle(new Event('user.created', ['user_id' => 1]), static fn() => null);
    }

    public function testWildcardPatternMatches(): void
    {
        $mw = new ValidationMiddleware();
        $mw->addRule('user.*', static fn(Event $e): bool => $e->has('tenant_id'));

        $dispatched = false;
        $mw->handle(new Event('user.updated', ['tenant_id' => 9]), static function () use (&$dispatched): void {
            $dispatched = true;
        });

        $this->assertTrue($dispatched);
    }

    public function testUnrelatedPatternSkipped(): void
    {
        $mw = new ValidationMiddleware();
        $mw->addRule('order.*', static fn(Event $e): bool => $e->has('order_id'));

        // 事件名不匹配任何规则，应直接放行
        $dispatched = false;
        $mw->handle(new Event('user.created', []), static function () use (&$dispatched): void {
            $dispatched = true;
        });

        $this->assertTrue($dispatched);
    }

    public function testMultipleMatchingPatternsAllChecked(): void
    {
        $mw = new ValidationMiddleware();
        $mw->addRule('*.created', static fn(Event $e): bool => $e->has('id'));
        $mw->addRule('user.*', static fn(Event $e): bool => $e->has('actor'));

        $this->expectException(\RuntimeException::class);

        // 同时满足两个模式，但缺少 actor 字段 -> 第二个模式失败
        $mw->handle(new Event('user.created', ['id' => 1]), static fn() => null);
    }
}
