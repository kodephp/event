<?php

declare(strict_types=1);

namespace Kode\Event\Queue;

if (class_exists('Kode\Queue\Factory')) {
    /**
     * kode/queue 驱动器实现
     *
     * 将事件派发到 kode/queue 队列中
     */
    class KodeQueueDriver implements QueueDriverInterface
    {
        /**
         * kode/queue 实例
         */
        protected object $queue;

        /**
         * 队列名称
         */
        protected string $queueName;

        /**
         * 构造 kode/queue 驱动器
         *
         * @param object $queue Kode\Queue\QueueInterface 实例
         * @param string $queueName 队列名称
         */
        public function __construct(object $queue, string $queueName = 'events')
        {
            $this->queue = $queue;
            $this->queueName = $queueName;
        }

        /**
         * 推送事件到队列
         */
        public function push(string $job, array $data = [], ?string $queue = null): string
        {
            return $this->queue->push($job, $data, $queue ?? $this->queueName);
        }

        /**
         * 延迟推送事件到队列
         */
        public function later(int $delay, string $job, array $data = [], ?string $queue = null): string
        {
            return $this->queue->later($delay, $job, $data, $queue ?? $this->queueName);
        }

        /**
         * 从队列中取出事件
         */
        public function pop(?string $queue = null): ?array
        {
            $job = $this->queue->pop($queue ?? $this->queueName);

            if ($job === null || $job === false) {
                return null;
            }

            return $job;
        }

        /**
         * 删除任务
         */
        public function delete(string $jobId, ?string $queue = null): bool
        {
            return $this->queue->delete($jobId, $queue ?? $this->queueName);
        }

        /**
         * 获取队列大小
         */
        public function size(?string $queue = null): int
        {
            return $this->queue->size($queue ?? $this->queueName);
        }
    }
} else {
    /**
     * 占位类 - 当 kode/queue 未安装时
     */
    class KodeQueueDriver implements QueueDriverInterface
    {
        public function __construct()
        {
            throw new \RuntimeException(
                'kode/queue is not installed. Run: composer require kode/queue'
            );
        }

        public function push(string $job, array $data = [], ?string $queue = null): string
        {
            return '';
        }

        public function later(int $delay, string $job, array $data = [], ?string $queue = null): string
        {
            return '';
        }

        public function pop(?string $queue = null): ?array
        {
            return null;
        }

        public function delete(string $jobId, ?string $queue = null): bool
        {
            return false;
        }

        public function size(?string $queue = null): int
        {
            return 0;
        }
    }
}
