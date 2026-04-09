<?php

declare(strict_types=1);

namespace Kode\Event;

interface EventMiddlewareInterface
{
    public function handle(Event $event, callable $next): mixed;
}

class EventMiddleware
{
    protected array $middlewares = [];

    public function add(callable|EventMiddlewareInterface $middleware, int $priority = 0): self
    {
        $this->middlewares[] = [
            'middleware' => $middleware,
            'priority' => $priority,
        ];
        $this->sort();
        return $this;
    }

    public function remove(callable|EventMiddlewareInterface $middleware): self
    {
        $this->middlewares = array_values(
            array_filter(
                $this->middlewares,
                fn($item) => $item['middleware'] !== $middleware
            )
        );
        return $this;
    }

    public function process(Event $event, callable $handler): mixed
    {
        if (empty($this->middlewares)) {
            return $handler($event);
        }

        $middlewareStack = $this->buildStack($handler);
        return $middlewareStack($event);
    }

    protected function buildStack(callable $handler): callable
    {
        $stack = $handler;

        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i]['middleware'];
            $currentStack = $stack;

            $stack = function (Event $event) use ($middleware, $currentStack) {
                if ($middleware instanceof EventMiddlewareInterface) {
                    return $middleware->handle($event, $currentStack);
                }

                if (is_callable($middleware)) {
                    return $middleware($event, $currentStack);
                }

                return $currentStack($event);
            };
        }

        return $stack;
    }

    protected function sort(): void
    {
        usort($this->middlewares, fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    public function clear(): self
    {
        $this->middlewares = [];
        return $this;
    }

    public function count(): int
    {
        return count($this->middlewares);
    }

    public function all(): array
    {
        return $this->middlewares;
    }
}

class LoggingMiddleware implements EventMiddlewareInterface
{
    public function handle(Event $event, callable $next): mixed
    {
        $start = hrtime(true);
        $name = $event->getName();

        echo "📤 派发事件: {$name}\n";

        $result = $next($event);

        $elapsed = hrtime(true) - $start;
        echo "✅ 事件完成: {$name} (耗时: {$elapsed}ns)\n";

        return $result;
    }
}

class ValidationMiddleware implements EventMiddlewareInterface
{
    protected array $rules = [];

    public function addRule(string $eventName, callable $validator): self
    {
        $this->rules[$eventName][] = $validator;
        return $this;
    }

    public function handle(Event $event, callable $next): mixed
    {
        $name = $event->getName();

        if (isset($this->rules[$name])) {
            foreach ($this->rules[$name] as $validator) {
                if (!$validator($event)) {
                    throw new \RuntimeException("事件 {$name} 验证失败");
                }
            }
        }

        return $next($event);
    }
}