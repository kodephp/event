<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 调度器运行指标
 *
 * 采集事件派发的次数、耗时、监听器调用数与异常数，
 * 并记录超过阈值的慢事件，用于线上可观测与性能排查。
 */
class DispatcherStats
{
    /**
     * 慢事件记录条数上限
     */
    public const MAX_SLOW_RECORDS = 100;

    /**
     * 慢事件阈值（纳秒）
     */
    protected float $slowThresholdNs;

    /**
     * 按事件名聚合的指标
     *
     * @var array<string, array{count: int, total_ns: float, max_ns: float, listeners: int, errors: int}>
     */
    protected array $metrics = [];

    /**
     * 慢事件记录
     *
     * @var array<array{event: string, elapsed_ns: float, listeners: int}>
     */
    protected array $slowEvents = [];

    /**
     * 总派发次数
     */
    protected int $totalDispatches = 0;

    /**
     * 总异常数
     */
    protected int $totalErrors = 0;

    /**
     * @param float $slowThresholdMs 慢事件阈值（毫秒）
     */
    public function __construct(float $slowThresholdMs = 100.0)
    {
        $this->slowThresholdNs = max(0.0, $slowThresholdMs) * 1_000_000;
    }

    /**
     * 记录一次派发
     *
     * @param string $event 事件标识
     * @param float $elapsedNs 耗时（纳秒）
     * @param int $listeners 实际执行的监听器数量
     * @param int $errors 本次派发中的异常数量
     */
    public function record(string $event, float $elapsedNs, int $listeners = 0, int $errors = 0): void
    {
        $this->totalDispatches++;
        $this->totalErrors += $errors;

        if (!isset($this->metrics[$event])) {
            $this->metrics[$event] = [
                'count' => 0,
                'total_ns' => 0.0,
                'max_ns' => 0.0,
                'listeners' => 0,
                'errors' => 0,
            ];
        }

        $metric = &$this->metrics[$event];
        $metric['count']++;
        $metric['total_ns'] += $elapsedNs;
        $metric['max_ns'] = max($metric['max_ns'], $elapsedNs);
        $metric['listeners'] += $listeners;
        $metric['errors'] += $errors;
        unset($metric);

        if ($this->slowThresholdNs > 0 && $elapsedNs >= $this->slowThresholdNs) {
            if (count($this->slowEvents) >= self::MAX_SLOW_RECORDS) {
                array_shift($this->slowEvents);
            }

            $this->slowEvents[] = [
                'event' => $event,
                'elapsed_ns' => $elapsedNs,
                'listeners' => $listeners,
            ];
        }
    }

    /**
     * 获取总派发次数
     */
    public function getTotalDispatches(): int
    {
        return $this->totalDispatches;
    }

    /**
     * 获取总异常数
     */
    public function getTotalErrors(): int
    {
        return $this->totalErrors;
    }

    /**
     * 获取指定事件的派发次数
     */
    public function getCount(string $event): int
    {
        return $this->metrics[$event]['count'] ?? 0;
    }

    /**
     * 获取指定事件的平均耗时（纳秒）
     */
    public function getAverageNs(string $event): float
    {
        $metric = $this->metrics[$event] ?? null;

        if ($metric === null || $metric['count'] === 0) {
            return 0.0;
        }

        return $metric['total_ns'] / $metric['count'];
    }

    /**
     * 获取全部聚合指标
     *
     * @return array<string, array{count: int, total_ns: float, max_ns: float, listeners: int, errors: int}>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * 获取慢事件记录
     *
     * @return array<array{event: string, elapsed_ns: float, listeners: int}>
     */
    public function getSlowEvents(): array
    {
        return $this->slowEvents;
    }

    /**
     * 按总耗时降序获取 TopN 事件
     *
     * @return array<array{event: string, count: int, total_ns: float, avg_ns: float, max_ns: float}>
     */
    public function getTopByTotalTime(int $limit = 10): array
    {
        $rows = [];

        foreach ($this->metrics as $event => $metric) {
            $rows[] = [
                'event' => $event,
                'count' => $metric['count'],
                'total_ns' => $metric['total_ns'],
                'avg_ns' => $metric['count'] > 0 ? $metric['total_ns'] / $metric['count'] : 0.0,
                'max_ns' => $metric['max_ns'],
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $b['total_ns'] <=> $a['total_ns']);

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * 导出为可序列化摘要
     *
     * @return array{total_dispatches: int, total_errors: int, events: int, metrics: array<string, array<string, float|int>>, slow_events: array<array<string, float|int|string>>}
     */
    public function toArray(): array
    {
        return [
            'total_dispatches' => $this->totalDispatches,
            'total_errors' => $this->totalErrors,
            'events' => count($this->metrics),
            'metrics' => $this->metrics,
            'slow_events' => $this->slowEvents,
        ];
    }

    /**
     * 重置全部指标
     */
    public function reset(): void
    {
        $this->metrics = [];
        $this->slowEvents = [];
        $this->totalDispatches = 0;
        $this->totalErrors = 0;
    }
}
