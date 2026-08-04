<?php

declare(strict_types=1);

namespace Kode\Event\Queue;

use Kode\Event\Event;

/**
 * 异步事件
 *
 * 用于异步队列的事件封装
 */
class AsyncEvent extends Event
{
    /**
     * 任务ID
     */
    protected ?string $jobId = null;

    /**
     * 队列名称
     */
    protected ?string $queue = null;

    /**
     * 延迟秒数
     */
    protected int $delay = 0;

    /**
     * 上下文数据
     */
    protected array $context = [];

    /**
     * 构造异步事件
     *
     * @param string $name 事件名称
     * @param array $data 事件数据
     * @param int $delay 延迟秒数
     * @param string|null $queue 队列名称
     */
    public function __construct(
        string $name,
        array $data = [],
        int $delay = 0,
        ?string $queue = null
    ) {
        parent::__construct($name, $data);
        $this->delay = $delay;
        $this->queue = $queue;
    }

    /**
     * 设置任务ID
     *
     * @param string $jobId
     * @return $this
     */
    public function setJobId(string $jobId): self
    {
        $this->jobId = $jobId;
        return $this;
    }

    /**
     * 获取任务ID
     */
    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    /**
     * 设置队列名称
     *
     * @param string $queue
     * @return $this
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * 获取队列名称
     */
    public function getQueue(): ?string
    {
        return $this->queue;
    }

    /**
     * 获取任务名称（用于队列）
     */
    public function getJob(): string
    {
        return 'Kode\Event\Queue\AsyncEvent';
    }

    /**
     * 设置延迟秒数
     *
     * @param int $delay
     * @return $this
     */
    public function setDelay(int $delay): self
    {
        $this->delay = $delay;
        return $this;
    }

    /**
     * 获取延迟秒数
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * 设置上下文数据
     *
     * @param array $context
     * @return $this
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * 获取上下文数据
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * 创建异步事件
     *
     * @param string $name 事件名称
     * @param array $data 事件数据
     * @param int $delay 延迟秒数
     * @param string|null $queue 队列名称
     */
    #[\Override]
    public static function create(
        string $name,
        array $data = [],
        int $delay = 0,
        ?string $queue = null
    ): static {
        return new static($name, $data, $delay, $queue);
    }

    /**
     * 转换为队列负载
     */
    public function toPayload(): array
    {
        return [
            'job' => static::class,
            'data' => [
                'name' => $this->getName(),
                'payload' => $this->getData(),
                'context' => $this->context,
            ],
            'queue' => $this->queue,
            'delay' => $this->delay,
        ];
    }

    /**
     * 导出可移植快照（含异步专属字段）
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
            'job_id' => $this->jobId,
            'queue' => $this->queue,
            'delay' => $this->delay,
            'context' => $this->context,
        ];
    }

    /**
     * 从关联数组重建异步事件
     *
     * @param array<string, mixed> $payload
     * @throws \Kode\Event\Exception\InvalidEventException
     */
    #[\Override]
    public static function fromArray(array $payload): static
    {
        $event = parent::fromArray($payload);

        $event->jobId = $payload['job_id'] ?? $payload['jobId'] ?? null;
        $event->queue = $payload['queue'] ?? null;
        $event->delay = (int) ($payload['delay'] ?? 0);
        $event->context = $payload['context'] ?? [];

        return $event;
    }

    /**
     * 从队列负载创建异步事件
     *
     * @param array $payload
     * @return static
     */
    public static function fromPayload(array $payload): static
    {
        $data = $payload['data'] ?? [];
        $event = new self(
            $data['name'] ?? 'unknown',
            $data['payload'] ?? [],
        );
        $event->setJobId($payload['id'] ?? null);
        $event->setQueue($payload['queue'] ?? null);
        $event->setContext($data['context'] ?? []);
        return $event;
    }
}
