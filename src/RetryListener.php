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
 * - 提供 {@see exponentialBackoff()}（指数退避 + 上限）与 {@see decorrelatedJitterBackoff()}
 *   （AWS 风格去相关抖动）两个静态工厂，直接作为 $backoff 参数使用；
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
     * 指数退避工厂：返回 `callable(int $attempt): int`
     *
     * 第 $attempt 次失败后的退避为 `base × factor ^ (attempt−1)`，并截断到 $capMs。
     * 与 {@see RetryListener} 构造器的 $backoff 参数直接兼容：
     *
     * ```php
     * new RetryListener($h, backoff: RetryListener::exponentialBackoff(100, 2.0, 5000));
     * ```
     *
     * @param int $baseMs 基准退避（毫秒，第 1 次失败时）
     * @param float $factor 增长因子（默认 2.0，即翻倍）
     * @param int $capMs 退避上限（毫秒），用于防止退避无限膨胀
     * @return callable(int):int
     */
    public static function exponentialBackoff(int $baseMs, float $factor = 2.0, int $capMs = PHP_INT_MAX): callable
    {
        if ($baseMs < 0) {
            throw new \InvalidArgumentException('baseMs 必须 >= 0');
        }
        if ($factor <= 0.0) {
            throw new \InvalidArgumentException('factor 必须 > 0');
        }
        if ($capMs < 0) {
            throw new \InvalidArgumentException('capMs 必须 >= 0');
        }

        return static function (int $attempt) use ($baseMs, $factor, $capMs): int {
            $raw = $baseMs * ($factor ** max(0, $attempt - 1));
            // 大 attempt 下 $factor ** (attempt−1) 可能溢出为 INF，此时应截断到上限
            if (!is_finite($raw) || $raw >= $capMs) {
                return $capMs;
            }
            return max(0, (int) round($raw));
        };
    }

    /**
     * 去相关抖动退避工厂（AWS 风格 decorrelated jitter）：返回 `callable(int $attempt): int`
     *
     * - 第 1 次失败退避 $baseMs；
     * - 后续每次退避 = `random($baseMs, $prev × 3)`，其中 $prev 为上一次实际退避。
     *
     * 相比纯指数退避，去相关抖动能让各重试者更快「错峰」，进一步缓解重试风暴的惊群效应；
     * 退避同样截断到 $capMs。注意该策略内部使用随机源，行为不可由 {@see setRng()} 注入。
     *
     * @param int $baseMs 基准退避（毫秒）
     * @param int $capMs 退避上限（毫秒）
     * @return callable(int):int
     */
    public static function decorrelatedJitterBackoff(int $baseMs = 100, int $capMs = 10000): callable
    {
        if ($baseMs < 0) {
            throw new \InvalidArgumentException('baseMs 必须 >= 0');
        }
        if ($capMs < 0) {
            throw new \InvalidArgumentException('capMs 必须 >= 0');
        }

        $prev = $baseMs;
        return static function (int $attempt) use ($baseMs, $capMs, &$prev): int {
            $sleep = $attempt <= 1 ? $baseMs : random_int($baseMs, $prev * 3);
            $prev = $sleep;
            return min($capMs, max(0, $sleep));
        };
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
