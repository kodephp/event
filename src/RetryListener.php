<?php

declare(strict_types=1);

namespace Kode\Event;

use Throwable;

/**
 * 重试监听器（Retry / Dead-Letter 装饰器）
 *
 * 包裹一个真实监听器（callable 或 {@see ListenerInterface}），在其抛异常时按
 * 配置的重试次数与退避策略自动重试。重试耗尽后：
 *
 * - 若注入了 {@see DeadLetterSinkInterface}，则把事件投递到死信接收器并「吞掉」
 *   异常（不让失败扩散到整条监听链）；
 * - 否则原样重抛最后一次异常，交由调度器的异常处理策略（{@see ErrorStrategy}）裁决。
 *
 * 典型用法：
 * ```php
 * $dispatcher->listen('order.paid',
 *     new RetryListener($handler, deadLetter: $sink, maxAttempts: 5));
 * ```
 *
 * 设计要点：
 * - 每次重试都把同一个 $event 交给被包裹监听器，要求监听器具备幂等性；
 * - 退避 $backoff 可为固定毫秒数，或 `callable(int $attempt): int`（按第几次尝试计算）；
 * - 自身实现 {@see ListenerInterface}，因此可直接用 `$dispatcher->listen()` 注册，
 *   并与一次性监听器、优先级、通配符等既有能力无缝协作。
 */
final class RetryListener implements ListenerInterface
{
    private string|array $events;

    private int $priority;

    /**
     * 被包裹的真实监听器（callable 或 ListenerInterface）
     *
     * @var callable|ListenerInterface
     */
    private $listener;

    /**
     * 最大尝试次数（含首次）
     */
    private int $maxAttempts;

    /**
     * 退避策略：固定毫秒数或 `callable(int $attempt): int`
     *
     * @var int|callable
     */
    private $backoff;

    /**
     * 死信接收器（可选）
     */
    private ?DeadLetterSinkInterface $deadLetter;

    /**
     * @param callable|ListenerInterface $listener 被包裹的真实监听器
     * @param string|array|null $events 事件名（callable 时必填；ListenerInterface 时从其实例派生）
     * @param int $priority 优先级（callable 时生效；ListenerInterface 时从其派生）
     * @param int $maxAttempts 最大尝试次数（含首次），至少 1
     * @param int|callable $backoff 退避：固定毫秒数或 `callable(int $attempt): int`
     * @param DeadLetterSinkInterface|null $deadLetter 死信接收器（可选）
     */
    public function __construct(
        callable|ListenerInterface $listener,
        string|array|null $events = null,
        int $priority = 0,
        int $maxAttempts = 3,
        int|callable $backoff = 0,
        ?DeadLetterSinkInterface $deadLetter = null
    ) {
        $this->listener = $listener;
        $this->maxAttempts = $maxAttempts;
        $this->backoff = $backoff;
        $this->deadLetter = $deadLetter;
        if ($this->listener instanceof ListenerInterface) {
            $this->events = $this->listener->events();
            $this->priority = $this->listener->priority();
        } else {
            if ($events === null) {
                throw new \InvalidArgumentException('用 callable 包裹 RetryListener 时必须提供事件名');
            }
            $this->events = $events;
            $this->priority = $priority;
        }

        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts 至少为 1');
        }
    }

    #[\Override]
    public function handle(Event $event): void
    {
        $attempt = 0;
        $last = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;
            try {
                $this->invoke($event);
                return;
            } catch (Throwable $e) {
                $last = $e;
                // 非最后一次失败才退避，最后一次失败直接走死信/重抛
                if ($attempt < $this->maxAttempts) {
                    $this->sleep($attempt);
                }
            }
        }

        if ($this->deadLetter !== null) {
            $this->deadLetter->reject($event, $last, $attempt);
            return;
        }

        throw $last;
    }

    #[\Override]
    public function events(): string|array
    {
        return $this->events;
    }

    #[\Override]
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * 调用被包裹的监听器
     */
    private function invoke(Event $event): void
    {
        if ($this->listener instanceof ListenerInterface) {
            $this->listener->handle($event);
            return;
        }

        ($this->listener)($event);
    }

    /**
     * 按退避策略休眠（backoff <= 0 时不休眠）
     */
    private function sleep(int $attempt): void
    {
        $ms = is_callable($this->backoff)
            ? (int) ($this->backoff)($attempt)
            : $this->backoff;

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
