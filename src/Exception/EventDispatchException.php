<?php

declare(strict_types=1);

namespace Kode\Event\Exception;

use Throwable;

/**
 * 事件派发聚合异常
 *
 * 在 ErrorStrategy::COLLECT 策略下，若一次派发中有多个监听器抛出异常，
 * 全部异常会被收集并以本异常聚合抛出，避免"首个异常淹没后续异常"。
 */
class EventDispatchException extends EventException
{
    /**
     * 监听器异常列表
     *
     * @var Throwable[]
     */
    private array $errors;

    /**
     * 触发异常的事件名称
     */
    private string $eventName;

    /**
     * @param string $eventName 事件名称
     * @param Throwable[] $errors 监听器异常列表
     */
    public function __construct(string $eventName, array $errors)
    {
        $this->eventName = $eventName;
        $this->errors = array_values($errors);

        $count = count($this->errors);
        $first = $this->errors[0] ?? null;

        parent::__construct(
            sprintf(
                '事件 %s 派发过程中有 %d 个监听器抛出异常，首个异常: %s',
                $eventName,
                $count,
                $first instanceof Throwable ? $first->getMessage() : '未知'
            ),
            0,
            $first
        );
    }

    /**
     * 获取所有监听器异常
     *
     * @return Throwable[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 获取异常数量
     */
    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    /**
     * 获取触发异常的事件名称
     */
    public function getEventName(): string
    {
        return $this->eventName;
    }
}
