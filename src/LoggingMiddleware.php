<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 日志中间件
 *
 * 记录事件派发的开始与结束，并统计耗时。
 * 默认不产生任何输出（日志写入内部缓冲区，可通过 getRecords() 读取），
 * 也可注入自定义 logger 回调对接 PSR-3 等日志组件。
 */
class LoggingMiddleware implements EventMiddlewareInterface
{
    /**
     * 日志输出回调
     *
     * @var (callable(string, array<string, mixed>): void)|null
     */
    protected $logger;

    /**
     * 内部日志缓冲区
     *
     * @var array<array{message: string, context: array<string, mixed>}>
     */
    protected array $records = [];

    /**
     * 缓冲区最大记录数，防止长时间运行时内存无限增长
     */
    protected int $maxRecords;

    /**
     * @param (callable(string, array<string, mixed>): void)|null $logger 日志回调，为 null 时写入内部缓冲区
     * @param int $maxRecords 内部缓冲区上限
     */
    public function __construct(?callable $logger = null, int $maxRecords = 1000)
    {
        $this->logger = $logger;
        $this->maxRecords = max(1, $maxRecords);
    }

    /**
     * 处理事件
     */
    #[\Override]
    public function handle(Event $event, callable $next): mixed
    {
        $name = $event->getName();
        $start = hrtime(true);

        $this->log('事件派发开始', ['event' => $name]);

        try {
            $result = $next($event);
        } catch (\Throwable $e) {
            $this->log('事件派发失败', [
                'event' => $name,
                'elapsed_ns' => hrtime(true) - $start,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->log('事件派发完成', [
            'event' => $name,
            'elapsed_ns' => hrtime(true) - $start,
        ]);

        return $result;
    }

    /**
     * 写入一条日志
     *
     * @param array<string, mixed> $context
     */
    protected function log(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, $context);
            return;
        }

        if (count($this->records) >= $this->maxRecords) {
            array_shift($this->records);
        }

        $this->records[] = ['message' => $message, 'context' => $context];
    }

    /**
     * 获取内部缓冲的日志记录
     *
     * @return array<array{message: string, context: array<string, mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * 清空内部日志缓冲区
     *
     * @return $this
     */
    public function clearRecords(): self
    {
        $this->records = [];
        return $this;
    }
}
