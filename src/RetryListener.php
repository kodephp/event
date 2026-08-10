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
 * - 退避抖动 $jitter（0~1 的比例）在基础退避上叠加 ±jitter 的随机扰动，用于避免重试风暴中的「惊群效应」；
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
     * 退避抖动比例（0~1）：实际退避 = 基础退避 × (1 ± jitter 随机扰动)
     */
    private float $jitter;

    /**
     * 随机数源（返回 [0,1) 浮点），可注入以便测试；默认使用 {@see random_int}
     *
     * @var (callable():float)|null
     */
    private $rng = null;

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
     * @param float $jitter 退避抖动比例（0~1），在基础退避上叠加 ±jitter 的随机扰动
     * @param DeadLetterSinkInterface|null $deadLetter 死信接收器（可选）
     */
    public function __construct(
        callable|ListenerInterface $listener,
        string|array|null $events = null,
        int $priority = 0,
        int $maxAttempts = 3,
        int|callable $backoff = 0,
        float $jitter = 0.0,
        ?DeadLetterSinkInterface $deadLetter = null
    ) {
        $this->listener = $listener;
        $this->maxAttempts = $maxAttempts;
        $this->backoff = $backoff;
        $this->jitter = $jitter;
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
        if ($this->jitter < 0.0 || $this->jitter > 1.0) {
            throw new \InvalidArgumentException('jitter 必须在 0~1 之间');
        }
    }

    /**
     * 注入确定性随机数源（[0,1)），主要用于测试；不传则使用 {@see random_int}
     *
     * @param callable():float $rng
     * @return $this
     */
    public function setRng(callable $rng): self
    {
        $this->rng = $rng;
        return $this;
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
     * 计算第 $attempt 次失败后、下一次重试前的退避毫秒数（含抖动）。
     *
     * 基础退避来自 $backoff（固定毫秒或 callable）；若配置了 $jitter（0~1），
     * 实际退避 = 基础退避 × (1 + (r·2−1)·jitter)，其中 r∈[0,1) 来自随机数源，
     * 从而落在 [基础×(1−jitter), 基础×(1+jitter)] 区间，缓解重试惊群。
     */
    public function computeDelay(int $attempt): int
    {
        $base = is_callable($this->backoff)
            ? (int) ($this->backoff)($attempt)
            : $this->backoff;

        if ($base <= 0 || $this->jitter <= 0.0) {
            return max(0, $base);
        }

        $r = $this->rng !== null ? ($this->rng)() : (random_int(0, 1_000_000) / 1_000_000);
        $delay = (int) round($base * (1.0 + ($r * 2.0 - 1.0) * $this->jitter));

        return max(0, $delay);
    }

    /**
     * 按退避策略休眠（computeDelay <= 0 时不休眠）
     */
    private function sleep(int $attempt): void
    {
        $ms = $this->computeDelay($attempt);

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
