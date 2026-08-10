<?php

declare(strict_types=1);

namespace Kode\Event\Aop;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\ListenerRegistry;

/**
 * AOP 切面事件调度器
 *
 * 结合 kode/aop 实现切面事件处理
 */
class AspectEventDispatcher extends Dispatcher
{
    /**
     * 切面列表
     *
     * @var array<string, array>
     */
    protected array $aspects = [];

    /**
     * 按事件名缓存命中的切入点表达式列表
     *
     * 避免每次派发都对全部切面逐一跑正则匹配（切面多时为热路径主要开销）。
     * 注册 / 注销切面时整体失效。
     *
     * @var array<string, string[]>
     */
    protected array $matchedPointcuts = [];

    /**
     * 是否已初始化 AOP
     */
    protected static bool $aopInitialized = false;

    /**
     * 注册切面
     *
     * @param string $pointcut 切入点表达式
     * @param callable|object $aspect 切面
     * @param int $priority 优先级
     * @return $this
     */
    public function registerAspect(string $pointcut, callable|object $aspect, int $priority = 0): self
    {
        if (!isset($this->aspects[$pointcut])) {
            $this->aspects[$pointcut] = [];
        }

        $this->aspects[$pointcut][] = [
            'aspect' => $aspect,
            'priority' => $priority,
        ];

        $this->sortAspects($pointcut);
        $this->matchedPointcuts = [];

        return $this;
    }

    /**
     * 注销切面
     *
     * @param string $pointcut 切入点表达式
     * @param callable|object $aspect 切面
     * @return $this
     */
    public function unregisterAspect(string $pointcut, callable|object $aspect): self
    {
        if (isset($this->aspects[$pointcut])) {
            $this->aspects[$pointcut] = array_values(
                array_filter(
                    $this->aspects[$pointcut],
                    fn($item) => $item['aspect'] !== $aspect
                )
            );
        }

        $this->matchedPointcuts = [];

        return $this;
    }

    /**
     * 派发事件（带 AOP 切面）
     *
     * @param object|string $event 事件对象或事件名称
     * @param array<string, mixed> $data 事件数据
     * @return object 派发后的事件对象
     */
    #[\Override]
    public function dispatch(object|string $event, array $data = []): object
    {
        $event = is_string($event) ? new Event($event, $data) : $event;

        // 事件对象（Event）先触发前置切面；若已被切面停止传播则直接返回
        if ($event instanceof Event) {
            $this->triggerBeforeAspects($event);

            if ($event->isPropagationStopped()) {
                return $event;
            }
        }

        // 真正的派发（递归深度保护 / 一次性监听器 / 异常策略 / 钩子 / 外部 PSR-14 提供者
        // / 指标统计 / 链路追踪）统一委托给基类，避免切面调度器丢失这些能力
        $event = parent::dispatch($event, $event instanceof Event ? $event->getData() : $data);

        if ($event instanceof Event) {
            $this->triggerAfterAspects($event);
        }

        return $event;
    }

    /**
     * 短路派发（带 AOP 切面）
     *
     * 基类 {@see Dispatcher::until()} 不走 dispatch，故需在此同样触发前后置切面，
     * 否则 until 场景下的切面会静默失效。
     *
     * @param object|string $event 事件对象或事件名称
     * @param array<string, mixed> $data 事件数据
     * @return mixed 首个非 null 返回值，全部为 null 时返回 null
     */
    #[\Override]
    public function until(object|string $event, array $data = []): mixed
    {
        $event = is_string($event) ? new Event($event, $data) : $event;

        if ($event instanceof Event) {
            $this->triggerBeforeAspects($event);

            if ($event->isPropagationStopped()) {
                return null;
            }
        }

        // 真正的短路派发统一委托给基类（深度保护 / 异常策略 / 钩子 / 外部 PSR-14 提供者
        // / 指标统计 / 链路追踪），避免切面调度器丢失这些能力
        $result = parent::until($event, $event instanceof Event ? $event->getData() : $data);

        if ($event instanceof Event) {
            $this->triggerAfterAspects($event);
        }

        return $result;
    }

    /**
     * 触发前置通知切面
     *
     * @param Event $event
     * @return void
     */
    protected function triggerBeforeAspects(Event $event): void
    {
        foreach ($this->getMatchedPointcuts($event->getName()) as $pointcut) {
            foreach ($this->aspects[$pointcut] as $item) {
                $this->invokeBeforeAspect($item['aspect'], $event);
            }
        }
    }

    /**
     * 触发后置通知切面
     *
     * @param Event $event
     * @return void
     */
    protected function triggerAfterAspects(Event $event): void
    {
        foreach ($this->getMatchedPointcuts($event->getName()) as $pointcut) {
            foreach ($this->aspects[$pointcut] as $item) {
                $this->invokeAfterAspect($item['aspect'], $event);
            }
        }
    }

    /**
     * 获取当前事件名命中的切入点表达式列表（带缓存）
     *
     * @return string[]
     */
    protected function getMatchedPointcuts(string $eventName): array
    {
        if (isset($this->matchedPointcuts[$eventName])) {
            return $this->matchedPointcuts[$eventName];
        }

        $matched = [];
        foreach ($this->aspects as $pointcut => $_) {
            if ($this->matchesPointcut($eventName, $pointcut)) {
                $matched[] = $pointcut;
            }
        }

        if (count($this->matchedPointcuts) >= 512) {
            array_shift($this->matchedPointcuts);
        }

        return $this->matchedPointcuts[$eventName] = $matched;
    }

    /**
     * 调用前置通知
     *
     * 纯闭包切面仅在前置阶段执行一次；带 before()/after() 方法的对象切面分别执行对应方法。
     *
     * @param callable|object $aspect
     * @param Event $event
     * @return void
     */
    protected function invokeBeforeAspect(callable|object $aspect, Event $event): void
    {
        if ($aspect instanceof \Closure) {
            $aspect($event);
            return;
        }

        if (method_exists($aspect, 'before')) {
            $aspect->before($event);
        }
    }

    /**
     * 调用后置通知
     *
     * 纯闭包切面已在前置阶段执行，此处不再重复；带 after() 方法的对象切面执行 after()。
     *
     * @param callable|object $aspect
     * @param Event $event
     * @return void
     */
    protected function invokeAfterAspect(callable|object $aspect, Event $event): void
    {
        if ($aspect instanceof \Closure) {
            return;
        }

        if (method_exists($aspect, 'after')) {
            $aspect->after($event);
        }
    }

    /**
     * 匹配切入点表达式
     *
     * `*` 匹配任意数量字符，`?` 匹配单个字符。
     * 复用 {@see ListenerRegistry::compilePattern()} 的已验证正则实现，
     * 避免自实现通配符（如 user.* 误编译为 /^user\.\.*$/）导致切面永不命中。
     *
     * @param string $eventName
     * @param string $pointcut
     * @return bool
     */
    protected function matchesPointcut(string $eventName, string $pointcut): bool
    {
        if ($pointcut === '*') {
            return true;
        }

        return (bool) preg_match(ListenerRegistry::compilePattern($pointcut), $eventName);
    }

    /**
     * 排序切面列表
     *
     * @param string $pointcut
     * @return void
     */
    protected function sortAspects(string $pointcut): void
    {
        if (isset($this->aspects[$pointcut])) {
            usort(
                $this->aspects[$pointcut],
                fn($a, $b) => $b['priority'] <=> $a['priority']
            );
        }
    }

    /**
     * 获取所有已注册的切面
     *
     * @return array
     */
    public function getAspects(): array
    {
        return $this->aspects;
    }

    /**
     * 检查 AOP 是否可用
     */
    public static function isAopAvailable(): bool
    {
        return class_exists('Kode\Aop\Runtime\AspectKernel');
    }
}
