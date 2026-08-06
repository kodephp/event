<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 校验结果快照（只读值对象）
 *
 * 由 {@see EventSchemaRegistry::validateDetailed()} 返回，封装一批事件的
 * 整体校验结论与逐事件失败原因，便于上层直接消费或序列化。
 */
readonly class ValidationResult
{
    /**
     * @param bool $allValid 是否全部事件均通过校验
     * @param array<string, string> $failures 失败事件名 => 失败原因
     * @param int $total 参与校验的事件总数
     * @param int $passed 通过校验的事件数
     * @param int $failed 未通过校验的事件数
     */
    public function __construct(
        public bool $allValid,
        public array $failures,
        public int $total,
        public int $passed,
        public int $failed
    ) {
    }

    public function isAllValid(): bool
    {
        return $this->allValid;
    }

    /**
     * 导出为可序列化数组
     *
     * @return array{all_valid: bool, total: int, passed: int, failed: int, failures: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'all_valid' => $this->allValid,
            'total' => $this->total,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'failures' => $this->failures,
        ];
    }
}
