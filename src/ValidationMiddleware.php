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
     * @throws InvalidEventException 校验不通过时抛出
     */
    public function handle(Event $event, callable $next): mixed
    {
        $name = $event->getName();

        foreach ($this->rules as $pattern => $validators) {
            if ($pattern !== $name && !EventHelper::matchesPattern($name, $pattern)) {
                continue;
            }

            foreach ($validators as $index => $validator) {
                if (!$validator($event)) {
                    throw new InvalidEventException(
                        sprintf('事件 %s 未通过校验规则 [%s#%d]', $name, $pattern, $index)
                    );
                }
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
