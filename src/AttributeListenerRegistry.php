<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Event\Attribute\Listener;
use Kode\Event\Attribute\Priority;
use Kode\Event\Attribute\Subscriber;
use Kode\Event\Exception\ListenerException;
use ReflectionClass;
use ReflectionMethod;

/**
 * 属性监听器注册器
 *
 * 通过 PHP 8+ 属性自动注册事件监听器。
 *
 * 扫描规则：
 * - 任何标注了 #[Listener] 的公开方法都会被注册，无需类上标注 #[Subscriber]；
 * - 类上的 #[Subscriber] 可选，用于声明统一的事件前缀与默认优先级；
 * - 方法级 #[Priority] 提供默认优先级，#[Listener(priority:)] 优先级更高。
 */
class AttributeListenerRegistry
{
    /**
     * 调度器实例
     */
    protected DispatcherInterface $dispatcher;

    /**
     * 已注册的订阅者类名，避免重复注册
     *
     * @var array<string, true>
     */
    protected array $registered = [];

    /**
     * 反射结果缓存 [class => [[event, method, priority], ...]]
     *
     * @var array<string, array<array{event: string, method: string, priority: int}>>
     */
    protected static array $metadataCache = [];

    /**
     * 构造属性注册器
     */
    public function __construct(DispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * 注册订阅者类的所有监听器
     *
     * @param object|class-string $subscriber 订阅者实例或类名
     * @return $this
     * @throws ListenerException 类不存在或无法实例化时抛出
     */
    public function register(object|string $subscriber): self
    {
        $instance = $this->resolveInstance($subscriber);

        foreach (self::resolveMetadata($instance::class) as $binding) {
            $this->dispatcher->listen(
                $binding['event'],
                [$instance, $binding['method']],
                $binding['priority']
            );
        }

        $this->registered[$instance::class] = true;

        return $this;
    }

    /**
     * 批量注册订阅者
     *
     * @param array<object|class-string> $subscribers
     * @return $this
     */
    public function registerMany(array $subscribers): self
    {
        foreach ($subscribers as $subscriber) {
            $this->register($subscriber);
        }

        return $this;
    }

    /**
     * 判断类是否已注册过
     */
    public function isRegistered(string $class): bool
    {
        return isset($this->registered[$class]);
    }

    /**
     * 获取调度器
     */
    public function getDispatcher(): DispatcherInterface
    {
        return $this->dispatcher;
    }

    /**
     * 清空反射元数据缓存
     */
    public static function flushMetadataCache(): void
    {
        self::$metadataCache = [];
    }

    /**
     * 解析订阅者实例
     *
     * @throws ListenerException
     */
    protected function resolveInstance(object|string $subscriber): object
    {
        if (is_object($subscriber)) {
            return $subscriber;
        }

        if (!class_exists($subscriber)) {
            throw new ListenerException("订阅者类不存在: {$subscriber}");
        }

        $reflection = new ReflectionClass($subscriber);

        if (!$reflection->isInstantiable()) {
            throw new ListenerException("订阅者类无法实例化: {$subscriber}");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new ListenerException(
                "订阅者类 {$subscriber} 的构造函数存在必填参数，请传入已实例化的对象"
            );
        }

        return $reflection->newInstance();
    }

    /**
     * 解析类的监听器元数据（带缓存）
     *
     * @return array<array{event: string, method: string, priority: int}>
     */
    protected static function resolveMetadata(string $class): array
    {
        if (isset(self::$metadataCache[$class])) {
            return self::$metadataCache[$class];
        }

        $reflection = new ReflectionClass($class);
        $bindings = [];

        [$prefix, $defaultPriority] = self::resolveSubscriberOptions($reflection);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            $attributes = $method->getAttributes(Listener::class);

            if ($attributes === []) {
                continue;
            }

            $methodPriority = self::resolveMethodPriority($method, $defaultPriority);

            foreach ($attributes as $attribute) {
                /** @var Listener $listener */
                $listener = $attribute->newInstance();

                // 属性上显式声明的优先级优先于 #[Priority] 与订阅者默认值
                $priority = $listener->priority !== 0 ? $listener->priority : $methodPriority;

                foreach ((array) $listener->events as $event) {
                    $bindings[] = [
                        'event' => $prefix . (string) $event,
                        'method' => $method->getName(),
                        'priority' => $priority,
                    ];
                }
            }
        }

        return self::$metadataCache[$class] = $bindings;
    }

    /**
     * 解析类级 #[Subscriber] 配置
     *
     * @param ReflectionClass<object> $reflection
     * @return array{0: string, 1: int} [事件前缀, 默认优先级]
     */
    protected static function resolveSubscriberOptions(ReflectionClass $reflection): array
    {
        $attributes = $reflection->getAttributes(Subscriber::class);

        if ($attributes === []) {
            return ['', 0];
        }

        $subscriber = $attributes[0]->newInstance();

        return [
            property_exists($subscriber, 'prefix') ? (string) $subscriber->prefix : '',
            property_exists($subscriber, 'priority') ? (int) $subscriber->priority : 0,
        ];
    }

    /**
     * 解析方法级 #[Priority]
     */
    protected static function resolveMethodPriority(ReflectionMethod $method, int $default): int
    {
        $attributes = $method->getAttributes(Priority::class);

        if ($attributes === []) {
            return $default;
        }

        return (int) $attributes[0]->newInstance()->value;
    }
}
