<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\Exception\InvalidEventException;

/**
 * 验证中间件
 *
 * 在事件派发前按事件名执行注册的校验规则，任一规则不通过即中断派发。
 * 支持精确事件名与通配符模式（如 user.*）。
 */
class ValidationMiddleware implements EventMiddlewareInterface
{
    /**
     * 校验规则 [eventPattern => [rule, ...]]
     *
     * @var array<string, array<callable(Event): bool>>
     */
    protected array $rules = [];

    /**
     * 添加校验规则
     *
     * @param string $eventName 事件名称或通配符模式
     * @param callable(Event): bool $validator 校验回调，返回 false 表示不通过
     * @return $this
     */
    public function addRule(string $eventName, callable $validator): self
    {
        $this->rules[$eventName][] = $validator;
        return $this;
    }

    /**
     * 移除指定事件的全部规则
     *
     * @return $this
     */
    public function removeRules(string $eventName): self
    {
        unset($this->rules[$eventName]);
        return $this;
    }

    /**
     * 处理事件
     *
     * 先以 array_filter 筛出与当前事件名匹配的规则模式（精确名或通配符），
     * 再对每条规则的校验器集合用 array_all 判定「全部通过」，并用 array_find_key
     * 定位首个未通过的校验器序号，从而给出精确的诊断信息。
     *
     * @throws InvalidEventException 校验不通过时抛出
     */
    #[\Override]
    public function handle(Event $event, callable $next): mixed
    {
        $name = $event->getName();

        $matching = array_filter(
            $this->rules,
            static fn(array $validators, string $pattern): bool
                => $pattern === $name || EventHelper::matchesPattern($name, $pattern),
            ARRAY_FILTER_USE_BOTH
        );

        foreach ($matching as $pattern => $validators) {
            $failedIndex = array_find_key(
                $validators,
                static fn(callable $validator): bool => !$validator($event)
            );

            if ($failedIndex !== null) {
                throw new InvalidEventException(
                    sprintf('事件 %s 未通过校验规则 [%s#%d]', $name, $pattern, $failedIndex)
                );
            }
        }

        return $next($event);
    }

    /**
     * 获取所有规则
     *
     * @return array<string, array<callable(Event): bool>>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * 清空所有规则
     *
     * @return $this
     */
    public function clear(): self
    {
        $this->rules = [];
        return $this;
    }
}
