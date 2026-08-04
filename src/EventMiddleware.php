<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 事件中间件管道
 *
 * 以洋葱模型组织中间件，按优先级从高到低依次包裹核心处理器。
 * 优先级相同的中间件按添加顺序执行（稳定排序）。
 */
class EventMiddleware
{
    /**
     * 中间件列表
     *
     * @var array<array{middleware: callable|EventMiddlewareInterface, priority: int, seq: int}>
     */
    protected array $middlewares = [];

    /**
     * 自增序列号，用于保证同优先级下的稳定排序
     */
    protected int $sequence = 0;

    /**
     * 添加中间件
     *
     * @param callable|EventMiddlewareInterface $middleware 中间件
     * @param int $priority 优先级，数值越大越靠外层
     * @return $this
     */
    public function add(callable|EventMiddlewareInterface $middleware, int $priority = 0): self
    {
        $this->middlewares[] = [
            'middleware' => $middleware,
            'priority' => $priority,
            'seq' => $this->sequence++,
        ];
        $this->sort();
        return $this;
    }

    /**
     * 移除中间件
     *
     * @param callable|EventMiddlewareInterface $middleware 中间件
     * @return $this
     */
    public function remove(callable|EventMiddlewareInterface $middleware): self
    {
        $this->middlewares = array_values(
            array_filter(
                $this->middlewares,
                fn(array $item): bool => $item['middleware'] !== $middleware
            )
        );
        return $this;
    }

    /**
     * 执行中间件管道
     *
     * @param Event $event 事件对象
     * @param callable $handler 核心处理器
     * @return mixed 处理结果
     */
    public function process(Event $event, callable $handler): mixed
    {
        if ($this->middlewares === []) {
            return $handler($event);
        }

        return ($this->buildStack($handler))($event);
    }

    /**
     * 构建中间件调用栈
     */
    protected function buildStack(callable $handler): callable
    {
        $stack = $handler;

        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i]['middleware'];
            $next = $stack;

            $stack = static function (Event $event) use ($middleware, $next): mixed {
                if ($middleware instanceof EventMiddlewareInterface) {
                    return $middleware->handle($event, $next);
                }

                return $middleware($event, $next);
            };
        }

        return $stack;
    }

    /**
     * 按优先级稳定排序（优先级降序，同级按添加顺序）
     */
    protected function sort(): void
    {
        usort(
            $this->middlewares,
            static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']
                ?: $a['seq'] <=> $b['seq']
        );
    }

    /**
     * 清空所有中间件
     *
     * @return $this
     */
    public function clear(): self
    {
        $this->middlewares = [];
        return $this;
    }

    /**
     * 获取中间件数量
     */
    public function count(): int
    {
        return count($this->middlewares);
    }

    /**
     * 获取所有中间件
     *
     * @return array<array{middleware: callable|EventMiddlewareInterface, priority: int, seq: int}>
     */
    public function all(): array
    {
        return $this->middlewares;
    }
}
