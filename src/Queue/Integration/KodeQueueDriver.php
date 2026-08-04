<?php

declare(strict_types=1);

namespace Kode\Event\Queue\Integration;

use Kode\Event\Queue\QueueDriverInterface;
use RuntimeException;

/**
 * kode/queue 驱动器实现
 *
 * 将事件投递到 kode/queue 队列中。
 *
 * 说明：早期版本在文件内使用 if/else 条件声明两个同名类，
 * 既违反 PSR-4（命名空间与目录不符，会被 Composer 优化自动加载跳过），
 * 也让「依赖是否安装」的判断发生在加载期而非运行期。
 * 现改为单一类声明 + 构造期依赖校验，行为可预期。
 */
class KodeQueueDriver implements QueueDriverInterface
{
    /**
     * kode/queue 队列实例
     */
    protected object $queue;

    /**
     * 默认队列名称
     */
    protected string $queueName;

    /**
     * @param object $queue Kode\Queue\QueueInterface 实例
     * @param string $queueName 默认队列名称
     *
     * @throws RuntimeException 队列实例缺少必要方法时抛出
     */
    public function __construct(object $queue, string $queueName = 'events')
    {
        foreach (['push', 'later', 'pop', 'delete', 'size'] as $method) {
            if (!method_exists($queue, $method)) {
                throw new RuntimeException(sprintf(
                    '队列实例 %s 缺少方法 %s()，请确认已安装 kode/queue：composer require kode/queue',
                    $queue::class,
                    $method
                ));
            }
        }

        $this->queue = $queue;
        $this->queueName = $queueName;
    }

    /**
     * 推送事件到队列
     */
    public function push(string $job, array $data = [], ?string $queue = null): string
    {
        return (string) $this->queue->push($job, $data, $queue ?? $this->queueName);
    }

    /**
     * 延迟推送事件到队列
     */
    public function later(int $delay, string $job, array $data = [], ?string $queue = null): string
    {
        return (string) $this->queue->later($delay, $job, $data, $queue ?? $this->queueName);
    }

    /**
     * 从队列中取出事件
     */
    public function pop(?string $queue = null): ?array
    {
        $job = $this->queue->pop($queue ?? $this->queueName);

        return is_array($job) ? $job : null;
    }

    /**
     * 删除任务
     */
    public function delete(string $jobId, ?string $queue = null): bool
    {
        return (bool) $this->queue->delete($jobId, $queue ?? $this->queueName);
    }

    /**
     * 获取队列大小
     */
    public function size(?string $queue = null): int
    {
        return (int) $this->queue->size($queue ?? $this->queueName);
    }

    /**
     * 获取底层队列实例
     */
    public function getQueue(): object
    {
        return $this->queue;
    }
}
