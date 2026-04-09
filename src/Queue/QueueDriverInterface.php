<?php

declare(strict_types=1);

namespace Kode\Event\Queue;

/**
 * 队列驱动器接口
 *
 * 定义异步事件队列的标准契约
 */
interface QueueDriverInterface
{
    /**
     * 推送事件到队列
     *
     * @param string $job 任务名称
     * @param array $data 任务数据
     * @param string|null $queue 队列名称
     * @return string 任务ID
     */
    public function push(string $job, array $data = [], ?string $queue = null): string;

    /**
     * 延迟推送事件到队列
     *
     * @param int $delay 延迟秒数
     * @param string $job 任务名称
     * @param array $data 任务数据
     * @param string|null $queue 队列名称
     * @return string 任务ID
     */
    public function later(int $delay, string $job, array $data = [], ?string $queue = null): string;

    /**
     * 从队列中取出事件
     *
     * @param string|null $queue 队列名称
     * @return array|null 任务数据
     */
    public function pop(?string $queue = null): ?array;

    /**
     * 删除任务
     *
     * @param string $jobId 任务ID
     * @param string|null $queue 队列名称
     * @return bool
     */
    public function delete(string $jobId, ?string $queue = null): bool;

    /**
     * 获取队列大小
     *
     * @param string|null $queue 队列名称
     * @return int
     */
    public function size(?string $queue = null): int;
}
