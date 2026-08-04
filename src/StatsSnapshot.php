<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 调度器运行指标不可变快照
 *
 * 通过 {@see DispatcherStats::snapshot()} 获取，提供一次性的聚合视图。
 * 采用只读类（readonly），调用方持有后无法修改，避免指标在中途被篡改，
 * 适合用于日志上报、链路追踪上下文传递等需要「冻结」数据的场景。
 */
readonly class StatsSnapshot
{
    /**
     * @param int $totalDispatches 总派发次数
     * @param int $totalErrors 累计监听器异常数
     * @param int $slowEvents 慢事件记录数量
     * @param float $averageMs 平均单次派发耗时（毫秒）
     * @param float $totalMs 累计派发耗时（毫秒）
     * @param array<string, array{count: int, total_ns: float, max_ns: float, listeners: int, errors: int}> $metrics 各事件名聚合指标
     */
    public function __construct(
        public int $totalDispatches,
        public int $totalErrors,
        public int $slowEvents,
        public float $averageMs,
        public float $totalMs,
        public array $metrics
    ) {
    }
}
