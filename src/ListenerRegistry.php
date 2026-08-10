<?php

declare(strict_types=1);

namespace Kode\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * 监听器注册表
 *
 * 负责监听器的注册、注销与检索，同时实现 PSR-14 ListenerProviderInterface。
 *
 * 设计要点：
 * - 精确监听器与通配符监听器分桶存储，避免每次派发都遍历全部模式；
 * - 解析结果带缓存，注册/注销时按需失效，热路径接近 O(1)；
 * - 通配符正则编译一次后缓存复用；
 * - 同优先级下按注册顺序执行（稳定排序），语义可预测。
 */
class ListenerRegistry implements ListenerProviderInterface
{
    /**
     * 解析缓存条目上限，防止动态事件名导致内存无限增长
     */
    public const MAX_CACHE_ENTRIES = 512;

    /**
     * 精确匹配监听器 [eventName => [entry, ...]]
     *
     * @var array<string, array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}>>
     */
    protected array $listeners = [];

    /**
     * 通配符监听器 [pattern => [entry, ...]]
     *
     * @var array<string, array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}>>
     */
    protected array $wildcardListeners = [];

    /**
     * 解析结果缓存 [eventName => [entry, ...]]
     *
     * @var array<string, array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}>>
     */
    protected array $resolvedCache = [];

    /**
     * 通配符正则缓存 [pattern => regex]
     *
     * @var array<string, string>
     */
    protected static array $regexCache = [];

    /**
     * 全局自增序列号，保证同优先级监听器的稳定顺序
     */
    protected int $sequence = 0;

    /**
     * 外部 PSR-14 监听器提供者（聚合互操作）
     *
     * @var array<int, ListenerProviderInterface>
     */
    protected array $providers = [];

    /**
     * 注册监听器
     *
     * @param string $event 事件名称，支持 * （任意字符）与 ? （单个字符）通配符
     * @param callable|ListenerInterface $listener 监听器
     * @param int $priority 优先级，数值越大越先执行
     * @param bool $once 是否为一次性监听器
     * @return $this
     */
    public function listen(
        string $event,
        callable|ListenerInterface $listener,
        int $priority = 0,
        bool $once = false
    ): self {
        $isWildcard = $this->isWildcard($event);
        $bucket = $isWildcard ? $this->wildcardListeners[$event] ?? [] : $this->listeners[$event] ?? [];

        // 同一事件下重复注册同一监听器时直接忽略，避免重复触发
        foreach ($bucket as $entry) {
            if ($this->listenerEquals($entry['listener'], $listener)) {
                return $this;
            }
        }

        $entry = [
            'listener' => $listener,
            'priority' => $this->resolvePriority($listener, $priority),
            'seq' => $this->sequence++,
            'once' => $once,
            // 记录注册时使用的键（精确名或通配符模式），供一次性监听器自注销使用
            'event' => $event,
        ];

        if ($isWildcard) {
            $this->wildcardListeners[$event][] = $entry;
            $this->sortBucket($this->wildcardListeners[$event]);
        } else {
            $this->listeners[$event][] = $entry;
            $this->sortBucket($this->listeners[$event]);
        }

        $this->invalidateCache($isWildcard ? null : $event);

        return $this;
    }

    /**
     * 注册一次性监听器
     *
     * @return $this
     */
    public function listenOnce(string $event, callable|ListenerInterface $listener, int $priority = 0): self
    {
        return $this->listen($event, $listener, $priority, true);
    }

    /**
     * 批量注册监听器
     *
     * @param array<string, callable|ListenerInterface> $listeners
     * @return $this
     */
    public function listens(array $listeners): self
    {
        foreach ($listeners as $event => $listener) {
            $this->listen((string) $event, $listener);
        }
        return $this;
    }

    /**
     * 注销监听器
     *
     * @return $this
     */
    public function unlisten(string $event, callable|ListenerInterface $listener): self
    {
        $isWildcard = $this->isWildcard($event);
        $target = $isWildcard ? 'wildcardListeners' : 'listeners';

        if (!isset($this->{$target}[$event])) {
            return $this;
        }

        $this->{$target}[$event] = array_values(
            array_filter(
                $this->{$target}[$event],
                fn(array $entry): bool => !$this->listenerEquals($entry['listener'], $listener)
            )
        );

        if ($this->{$target}[$event] === []) {
            unset($this->{$target}[$event]);
        }

        $this->invalidateCache($isWildcard ? null : $event);

        return $this;
    }

    /**
     * 获取事件的所有监听器（按优先级降序、注册顺序升序）
     *
     * @return array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}>
     */
    public function getListeners(string $event): array
    {
        if (isset($this->resolvedCache[$event])) {
            return $this->resolvedCache[$event];
        }

        $resolved = $this->listeners[$event] ?? [];

        foreach ($this->wildcardListeners as $pattern => $entries) {
            if ($this->matchWildcard($event, $pattern)) {
                foreach ($entries as $entry) {
                    $resolved[] = $entry;
                }
            }
        }

        $this->sortBucket($resolved);

        return $this->cache($event, $resolved);
    }

    /**
     * PSR-14：获取事件对象对应的监听器
     *
     * 对实现 NamedEventInterface 的事件按事件名解析；
     * 对其他任意对象按「类名 + 父类 + 实现的接口」解析，
     * 从而无缝支持 PSR-14 风格的类型化事件对象。
     *
     * @param object $event
     * @return iterable<callable>
     */
    #[\Override]
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->resolveEntriesForObject($event) as $entry) {
            $listener = $entry['listener'];

            yield $listener instanceof ListenerInterface
                ? static fn(object $e) => $listener->handle($e)
                : $listener;
        }
    }

    /**
     * 解析事件对象对应的监听器条目
     *
     * @return array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}>
     */
    public function resolveEntriesForObject(object $event): array
    {
        if ($event instanceof NamedEventInterface) {
            // 无外部提供者时直接复用已缓存的内部结果，避免重复排序
            if ($this->providers === []) {
                return $this->getListeners($event->getName());
            }

            $resolved = $this->getListeners($event->getName());
        } else {
            $keys = $this->resolveObjectKeys($event);
            $cacheKey = "\0obj\0" . $keys[0];

            // 存在外部提供者时不走缓存，避免其动态增减导致结果过期
            if ($this->providers === [] && isset($this->resolvedCache[$cacheKey])) {
                return $this->resolvedCache[$cacheKey];
            }

            $resolved = [];
            $seen = [];

            foreach ($keys as $key) {
                foreach ($this->listeners[$key] ?? [] as $entry) {
                    if (!isset($seen[$entry['seq']])) {
                        $seen[$entry['seq']] = true;
                        $resolved[] = $entry;
                    }
                }
            }

            foreach ($this->wildcardListeners as $pattern => $entries) {
                foreach ($keys as $key) {
                    if (!$this->matchWildcard($key, $pattern)) {
                        continue;
                    }
                    foreach ($entries as $entry) {
                        if (!isset($seen[$entry['seq']])) {
                            $seen[$entry['seq']] = true;
                            $resolved[] = $entry;
                        }
                    }
                    break;
                }
            }
        }

        // 合并外部 PSR-14 提供者注册的监听器（无优先级信息，统一后置、不重复注销）
        foreach ($this->providers as $provider) {
            foreach ($provider->getListenersForEvent($event) as $listener) {
                $resolved[] = [
                    'listener' => $listener,
                    'priority' => $listener instanceof ListenerInterface ? $listener->priority() : 0,
                    'seq' => PHP_INT_MAX,
                    'once' => false,
                    'event' => null,
                ];
            }
        }

        $this->sortBucket($resolved);

        // 存在外部提供者时不缓存，避免其动态增减导致结果过期
        if ($this->providers === []) {
            $cacheKey = $event instanceof NamedEventInterface ? $event->getName() : "\0obj\0" . $this->resolveObjectKeys($event)[0];
            return $this->cache($cacheKey, $resolved);
        }

        return $resolved;
    }

    /**
     * 检查事件是否存在监听器
     */
    public function hasListeners(string $event): bool
    {
        if (!empty($this->listeners[$event])) {
            return true;
        }

        foreach ($this->wildcardListeners as $pattern => $entries) {
            if ($entries !== [] && $this->matchWildcard($event, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 统计事件的监听器数量
     */
    public function countListeners(?string $event = null): int
    {
        if ($event !== null) {
            return count($this->getListeners($event));
        }

        $total = 0;
        foreach ($this->listeners as $entries) {
            $total += count($entries);
        }
        foreach ($this->wildcardListeners as $entries) {
            $total += count($entries);
        }

        return $total;
    }

    /**
     * 获取所有已注册的精确事件名称
     *
     * @return string[]
     */
    public function getEventNames(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * 获取所有已注册的通配符模式
     *
     * @return string[]
     */
    public function getWildcardPatterns(): array
    {
        return array_keys($this->wildcardListeners);
    }

    /**
     * 清空监听器
     *
     * @param string|null $event 事件名称，null 表示清空全部
     * @return $this
     */
    public function clear(?string $event = null): self
    {
        if ($event === null) {
            $this->listeners = [];
            $this->wildcardListeners = [];
        } else {
            unset($this->listeners[$event], $this->wildcardListeners[$event]);
        }

        $this->resolvedCache = [];

        return $this;
    }

    /**
     * 注册订阅者
     *
     * @return $this
     */
    public function subscribe(SubscriberInterface $subscriber, DispatcherInterface $dispatcher): self
    {
        $subscriber->subscribe($dispatcher);
        return $this;
    }

    /**
     * 聚合一个外部 PSR-14 监听器提供者
     *
     * 允许将任意兼容 PSR-14 的提供者（如第三方框架的事件系统、Symfony Messenger 等）
     * 接入本调度器，实现跨系统的事件互操作：派发事件时会同时触发这些提供者注册的监听器。
     *
     * @return $this
     */
    public function addProvider(ListenerProviderInterface $provider): self
    {
        $this->providers[] = $provider;
        return $this;
    }

    /**
     * 获取所有已聚合的外部提供者
     *
     * @return array<int, ListenerProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * 是否存在已聚合的外部提供者
     */
    public function hasProviders(): bool
    {
        return $this->providers !== [];
    }

    /**
     * 移除全部外部提供者（不影响内部监听器）
     *
     * @return $this
     */
    public function clearProviders(): self
    {
        $this->providers = [];
        return $this;
    }

    /**
     * 判断事件名是否包含通配符
     */
    protected function isWildcard(string $event): bool
    {
        return str_contains($event, '*') || str_contains($event, '?');
    }

    /**
     * 解析监听器优先级
     */
    protected function resolvePriority(callable|ListenerInterface $listener, int $priority): int
    {
        if ($priority === 0 && $listener instanceof ListenerInterface) {
            return $listener->priority();
        }

        return $priority;
    }

    /**
     * 解析对象事件的查找键：自身类名 + 父类链 + 接口
     *
     * @return string[]
     */
    protected function resolveObjectKeys(object $event): array
    {
        $class = $event::class;

        return array_values(array_unique(array_merge(
            [$class],
            array_values(class_parents($event) ?: []),
            array_values(class_implements($event) ?: [])
        )));
    }

    /**
     * 稳定排序：优先级降序，同优先级按注册顺序
     *
     * @param array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}> $bucket
     */
    protected function sortBucket(array &$bucket): void
    {
        if (count($bucket) < 2) {
            return;
        }

        usort(
            $bucket,
            static fn(array $a, array $b): int => ($b['priority'] <=> $a['priority'])
                ?: ($a['seq'] <=> $b['seq'])
        );
    }

    /**
     * 写入解析缓存
     *
     * @param array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}> $resolved
     * @return array<array{listener: callable|ListenerInterface, priority: int, seq: int, once: bool}>
     */
    protected function cache(string $key, array $resolved): array
    {
        if (count($this->resolvedCache) >= self::MAX_CACHE_ENTRIES) {
            array_shift($this->resolvedCache);
        }

        return $this->resolvedCache[$key] = $resolved;
    }

    /**
     * 失效解析缓存
     *
     * @param string|null $event 为 null 时（通配符变更）全量失效，否则仅失效相关条目
     */
    protected function invalidateCache(?string $event): void
    {
        if ($event === null) {
            $this->resolvedCache = [];
            return;
        }

        unset($this->resolvedCache[$event]);

        // 对象事件缓存可能包含该键（类名注册），一并失效
        unset($this->resolvedCache["\0obj\0" . $event]);

        // 任意监听器注册都可能命中对象事件的「类名 / 父类 / 接口」解析路径，
        // 统一失效所有对象缓存条目，避免「先派发、后注册接口监听器」导致监听器永久丢失
        foreach ($this->resolvedCache as $key => $_) {
            if (str_starts_with($key, "\0obj\0")) {
                unset($this->resolvedCache[$key]);
            }
        }
    }

    /**
     * 比较两个监听器是否为同一个
     */
    protected function listenerEquals(callable|ListenerInterface $a, callable|ListenerInterface $b): bool
    {
        if (is_object($a) && is_object($b)) {
            return $a === $b;
        }

        if (is_array($a) && is_array($b)) {
            return count($a) === count($b)
                && ($a[0] ?? null) === ($b[0] ?? null)
                && ($a[1] ?? null) === ($b[1] ?? null);
        }

        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        return false;
    }

    /**
     * 通配符匹配
     *
     * `*` 匹配任意数量字符，`?` 匹配单个字符。
     */
    protected function matchWildcard(string $event, string $pattern): bool
    {
        return (bool) preg_match(self::compilePattern($pattern), $event);
    }

    /**
     * 编译通配符为正则（带静态缓存）
     */
    public static function compilePattern(string $pattern): string
    {
        if (isset(self::$regexCache[$pattern])) {
            return self::$regexCache[$pattern];
        }

        if (count(self::$regexCache) >= self::MAX_CACHE_ENTRIES) {
            self::$regexCache = [];
        }

        $regex = '/^' . str_replace(
            ['\*', '\?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/';

        return self::$regexCache[$pattern] = $regex;
    }
}
