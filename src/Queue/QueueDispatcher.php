<?php

declare(strict_types=1);

namespace Kode\Event\Queue;

use Kode\Event\Dispatcher;
use Kode\Event\Event;

/**
 * 队列调度器
 *
 * 将事件异步投递到队列中
 */
class QueueDispatcher
{
    /**
     * 队列驱动器
     */
    protected QueueDriverInterface $driver;

    /**
     * 事件调度器
     */
    protected Dispatcher $dispatcher;

    /**
     * 队列名称前缀
     */
    protected string $prefix = 'event';

    /**
     * 构造队列调度器
     *
     * @param QueueDriverInterface $driver 队列驱动器
     * @param Dispatcher $dispatcher 事件调度器
     */
    public function __construct(
        QueueDriverInterface $driver,
        Dispatcher $dispatcher
    ) {
        $this->driver = $driver;
        $this->dispatcher = $dispatcher;
    }

    /**
     * 派发异步事件
     *
     * @param AsyncEvent $event 异步事件
     * @return string 任务ID
     */
    public function dispatch(AsyncEvent $event): string
    {
        $job = $event->getJob();
        $data = $event->toPayload();
        $queue = $this->getQueueName($event->getQueue());

        if ($event->getDelay() > 0) {
            return $this->driver->later(
                $event->getDelay(),
                $job,
                $data,
                $queue
            );
        }

        return $this->driver->push($job, $data, $queue);
    }

    /**
     * 派发事件到队列（快捷方法）
     *
     * @param string $name 事件名称
     * @param array $data 事件数据
     * @param int $delay 延迟秒数
     * @param string|null $queue 队列名称
     * @return string 任务ID
     */
    public function enqueue(
        string $name,
        array $data = [],
        int $delay = 0,
        ?string $queue = null
    ): string {
        $event = AsyncEvent::create($name, $data, $delay, $queue);
        return $this->dispatch($event);
    }

    /**
     * 处理队列中的事件
     *
     * @param string|null $queue 队列名称
     * @return bool 是否处理了事件
     */
    public function process(?string $queue = null): bool
    {
        $queueName = $this->getQueueName($queue);
        $job = $this->driver->pop($queueName);

        if ($job === null) {
            return false;
        }

        $event = $this->resolveEvent($job);

        // 无法反序列化的任务（毒丸）必须出队，否则会在 processMany 中无限重试
        if ($event === null) {
            if (isset($job['id'])) {
                $this->driver->delete($job['id'], $queueName);
            }
            return false;
        }

        try {
            $this->dispatcher->dispatch($event);
        } finally {
            // 无论监听器是否抛异常，均确保任务出队，避免毒丸任务无限重试
            if (isset($job['id'])) {
                $this->driver->delete($job['id'], $queueName);
            }
        }

        return true;
    }

    /**
     * 批量处理队列中的事件
     *
     * @param string|null $queue 队列名称
     * @param int $limit 最大处理数量
     * @return int 处理的事件数量
     */
    public function processMany(?string $queue = null, int $limit = 10): int
    {
        $count = 0;

        for ($i = 0; $i < $limit; $i++) {
            if (!$this->process($queue)) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * 获取队列大小
     *
     * @param string|null $queue 队列名称
     * @return int
     */
    public function size(?string $queue = null): int
    {
        return $this->driver->size($this->getQueueName($queue));
    }

    /**
     * 设置队列名称前缀
     *
     * @param string $prefix
     * @return $this
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * 获取队列名称前缀
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * 获取完整的队列名称
     *
     * @param string|null $queue
     * @return string
     */
    protected function getQueueName(?string $queue): string
    {
        if ($queue === null) {
            return $this->prefix;
        }

        return sprintf('%s.%s', $this->prefix, $queue);
    }

    /**
     * 从队列负载解析事件
     *
     * @param array $job
     * @return Event|null
     */
    protected function resolveEvent(array $job): ?Event
    {
        // toPayload() 自带 {job, data:{name,payload,context}, queue, delay} 信封。
        // 驱动通常会把它包在 data/body 字段里存储；若记录本身就是 toPayload 则直接使用。
        $payload = $job;

        if (isset($job['data']['data']['name']) && is_string($job['data']['data']['name'] ?? null)) {
            $payload = $job['data'];
        } elseif (isset($job['body']['data']['name']) && is_string($job['body']['data']['name'] ?? null)) {
            $payload = $job['body'];
        }

        if (!isset($payload['data']['name']) || !is_string($payload['data']['name'])) {
            return null;
        }

        $event = AsyncEvent::fromPayload($payload);

        // 任务 ID 由驱动在记录顶层写入，需回填到事件
        if (isset($job['id'])) {
            $event->setJobId((string) $job['id']);
        }

        return $event;
    }

    /**
     * 获取队列驱动器
     */
    public function getDriver(): QueueDriverInterface
    {
        return $this->driver;
    }

    /**
     * 获取事件调度器
     */
    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }
}
