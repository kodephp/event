<?php

declare(strict_types=1);

namespace Kode\Event\Coroutine;

/**
 * 协程上下文接口
 *
 * 定义协程上下文的标准契约，用于协程间安全传递事件上下文
 */
interface CoroutineContextInterface
{
    /**
     * 设置上下文值
     *
     * @param string $key 键名
     * @param mixed $value 键值
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * 获取上下文值
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 检查键是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * 删除键
     *
     * @param string $key 键名
     * @return void
     */
    public function delete(string $key): void;

    /**
     * 清空上下文
     *
     * @return void
     */
    public function clear(): void;

    /**
     * 复制当前上下文
     *
     * @return array
     */
    public function copy(): array;

    /**
     * 恢复上下文
     *
     * @param array $snapshot
     * @return void
     */
    public function restore(array $snapshot): void;

    /**
     * 在隔离作用域中执行
     *
     * @param callable $callable
     * @return mixed
     */
    public function run(callable $callable): mixed;

    /**
     * 在继承作用域中执行
     *
     * @param callable $callable
     * @return mixed
     */
    public function fork(callable $callable): mixed;
}
