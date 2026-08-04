<?php

declare(strict_types=1);

namespace Kode\Event\Aop;

use Kode\Event\Dispatcher;
use Kode\Event\Event;

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
        if (is_string($event)) {
            $event = new Event($event, $data);
        }

        // 切面与监听器循环仅面向 Event 对象；其它对象透传返回
        if ($event instanceof Event) {
            $this->triggerBeforeAspects($event);

            if ($event->isPropagationStopped()) {
                return $event;
            }

            foreach ($this->registry->getListeners($event->getName()) as $item) {
                $listener = $item['listener'];

                if ($listener instanceof \Kode\Event\ListenerInterface) {
                    $listener->handle($event);
                } else {
                    ($listener)($event);
                }

                if ($event->isPropagationStopped()) {
                    break;
                }
            }

            $this->triggerAfterAspects($event);
        }

        return $event;
    }

    /**
     * 触发前置通知切面
     *
     * @param Event $event
     * @return void
     */
    protected function triggerBeforeAspects(Event $event): void
    {
        if (!class_exists('Kode\Aop\Runtime\JoinPoint')) {
            return;
        }

        foreach ($this->aspects as $pointcut => $aspects) {
            if ($this->matchesPointcut($event->getName(), $pointcut)) {
                foreach ($aspects as $item) {
                    $this->invokeBeforeAspect($item['aspect'], $event);
                }
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
        if (!class_exists('Kode\Aop\Runtime\JoinPoint')) {
            return;
        }

        foreach ($this->aspects as $pointcut => $aspects) {
            if ($this->matchesPointcut($event->getName(), $pointcut)) {
                foreach ($aspects as $item) {
                    $this->invokeAfterAspect($item['aspect'], $event);
                }
            }
        }
    }

    /**
     * 调用前置通知
     *
     * @param callable|object $aspect
     * @param Event $event
     * @return void
     */
    protected function invokeBeforeAspect(callable|object $aspect, Event $event): void
    {
        if (is_callable($aspect)) {
            $aspect($event);
        } elseif (method_exists($aspect, 'before')) {
            $aspect->before($event);
        }
    }

    /**
     * 调用后置通知
     *
     * @param callable|object $aspect
     * @param Event $event
     * @return void
     */
    protected function invokeAfterAspect(callable|object $aspect, Event $event): void
    {
        if (is_callable($aspect)) {
            $aspect($event);
        } elseif (method_exists($aspect, 'after')) {
            $aspect->after($event);
        }
    }

    /**
     * 匹配切入点表达式
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

        if (str_contains($pointcut, '*')) {
            $pattern = '/^' . str_replace(
                '*',
                '.*',
                preg_quote($pointcut, '/')
            ) . '$/';
            return (bool) preg_match($pattern, $eventName);
        }

        return $eventName === $pointcut;
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
