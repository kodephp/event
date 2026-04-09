# kode/event 事件编排库

轻量级、解耦的事件系统，支持事件派发、监听、订阅、异步事件、协程安全，可结合 `kode/aop` 做切面事件。

## 特性

- **事件派发** - 支持同步/异步事件派发
- **监听注册** - 支持优先级、通配符匹配
- **订阅者模式** - 通过订阅者集中管理监听器
- **属性声明** - PHP 8+ Attribute 声明式监听器
- **事件组** - 批量管理相关事件监听器
- **异步队列** - 支持 `kode/queue` 异步事件处理
- **协程安全** - 基于 `kode/context` 的协程上下文传递
- **AOP 切面** - 结合 `kode/aop` 实现切面事件
- **依赖注入** - 支持 `kode/di` 属性注入
- **PHP 8.5** - 支持新版本语言特性

## 环境要求

- PHP >= 8.1
- `kode/context` ^2.0（必须）

## 安装

```bash
composer require kode/event
```

## 目录

- [快速开始](#快速开始)
- [事件对象](#事件对象)
- [监听器](#监听器)
- [订阅者](#订阅者)
- [属性声明式监听](#属性声明式监听)
- [事件组](#事件组)
- [事件构建器](#事件构建器)
- [抽象事件类](#抽象事件类)
- [异常和验证](#异常和验证)
- [PHP 8.5 特性](#php-85-特性)
- [事件助手](#事件助手)
- [异步队列](#异步队列)
- [协程安全](#协程安全)
- [AOP 切面](#aop-切面)
- [依赖注入](#依赖注入)
- [门面模式](#门面模式)
- [并行处理](#并行处理)
- [API 参考](#api-参考)

## 快速开始

```php
use Kode\Event\Dispatcher;
use Kode\Event\Event;

$dispatcher = new Dispatcher();

// 监听事件
$dispatcher->listen('user.created', function (Event $event) {
    echo "用户创建: " . $event->get('name') . "\n";
});

// 派发事件
$dispatcher->dispatch(new Event('user.created', ['name' => '张三']));
```

## 事件对象

```php
use Kode\Event\Event;

$event = new Event('order.paid', [
    'order_id' => 12345,
    'amount' => 99.99,
    'user_id' => 100,
]);

// 获取数据
$orderId = $event->get('order_id');
$amount = $event->get('amount', 0.0);

// 设置数据
$event->set('status', 'completed');

// 批量设置
$event->fill(['paid_at' => time(), 'payment_method' => 'alipay']);

// 检查数据
$event->has('order_id'); // true

// 停止传播
$event->stopPropagation();
$event->isPropagationStopped(); // true

// 时间戳
$event->getTimestamp();  // 纳秒时间戳
$event->getElapsed();    // 经过的时间（纳秒）
```

## 监听器

### Callable 监听器

```php
$dispatcher->listen('user.created', function (Event $event) {
    echo "用户创建\n";
});

// 带优先级（数值越大越先执行）
$dispatcher->listen('user.created', function (Event $event) {
    echo "高优先级\n";
}, priority: 100);

$dispatcher->listen('user.created', function (Event $event) {
    echo "低优先级\n";
}, priority: -100);
```

### 监听器类

```php
use Kode\Event\AbstractListener;
use Kode\Event\EventPriority;

class UserEventListener extends AbstractListener
{
    public function __construct()
    {
        parent::__construct('user.*', EventPriority::NORMAL->value);
    }

    protected function handleEvent(Event $event): void
    {
        echo "处理事件: {$event->getName()}\n";
    }
}

$dispatcher->listen('user.created', new UserEventListener());
```

### 监听器特性

```php
use Kode\Event\EventListenerTrait;
use Kode\Event\EventPriority;

class UserListener
{
    use EventListenerTrait;

    public function __construct()
    {
        $this->setListenEvents(['user.*', 'order.*']);
        $this->setListenPriority(EventPriority::HIGH->value);
    }

    public function handle(Event $event): void
    {
        echo "处理事件: {$event->getName()}\n";
    }
}
```

### 事件派发 Trait

```php
use Kode\Event\EventDispatcherTrait;

class UserService
{
    use EventDispatcherTrait;

    public function createUser(array $data): void
    {
        // 业务逻辑
        $this->emit('user.created', $data);
    }
}

$service = new UserService();
$service->on('user.created', function ($event) {
    echo "用户创建\n";
});
$service->createUser(['name' => '张三']);

// 一次性监听
$service->once('user.created', function ($event) {
    echo "只触发一次\n";
});
```

## 订阅者

```php
use Kode\Event\Dispatcher;
use Kode\Event\SubscriberInterface;

class UserSubscriber implements SubscriberInterface
{
    public function subscribe(Dispatcher $dispatcher): void
    {
        $dispatcher->listen('user.created', [$this, 'onCreated']);
        $dispatcher->listen('user.updated', [$this, 'onUpdated']);
        $dispatcher->listen('user.deleted', [$this, 'onDeleted']);
    }

    public function onCreated(Event $event): void
    {
        echo "用户创建: {$event->get('name')}\n";
    }

    public function onUpdated(Event $event): void
    {
        echo "用户更新: {$event->get('name')}\n";
    }

    public function onDeleted(Event $event): void
    {
        echo "用户删除: {$event->get('id')}\n";
    }
}

// 注册订阅者
$dispatcher->subscribe(new UserSubscriber());
```

## 属性声明式监听

通过 PHP 8+ Attribute 声明式注册监听器。

### 基本用法

```php
use Kode\Event\Attribute\Listener;
use Kode\Event\Attribute\Priority;
use Kode\Event\Attribute\Subscriber;
use Kode\Event\AttributeListenerRegistry;

#[Subscriber]
class UserEventSubscriber
{
    #[Listener('user.created')]
    public function onUserCreated(Event $event): void
    {
        echo "用户创建: {$event->get('name')}\n";
    }

    #[Listener('user.updated')]
    public function onUserUpdated(Event $event): void
    {
        echo "用户更新: {$event->get('name')}\n";
    }

    #[Listener('user.deleted')]
    public function onUserDeleted(Event $event): void
    {
        echo "用户删除: {$event->get('id')}\n";
    }
}

// 创建注册器
$registry = new AttributeListenerRegistry($dispatcher);
$registry->register(new UserEventSubscriber());
```

### 多事件监听

```php
#[Subscriber]
class OrderEventSubscriber
{
    #[Listener(['order.created', 'order.paid', 'order.completed'])]
    public function onOrderChanges(Event $event): void
    {
        echo "订单事件: {$event->getName()}\n";
    }
}
```

### 优先级设置

```php
#[Subscriber]
class PrioritySubscriber
{
    #[Listener('app.start', priority: 100)]
    public function highPriority(): void
    {
        echo "高优先级\n";
    }

    #[Listener('app.start', priority: 0)]
    public function normalPriority(): void
    {
        echo "普通优先级\n";
    }

    #[Listener('app.start', priority: -100)]
    public function lowPriority(): void
    {
        echo "低优先级\n";
    }
}
```

### 批量注册

```php
$registry = new AttributeListenerRegistry($dispatcher);
$registry->registerMany([
    new UserEventSubscriber(),
    new OrderEventSubscriber(),
    new SystemSubscriber(),
]);
```

## 事件组

批量管理具有相同前缀/后缀的事件监听器。

### 基本用法

```php
use Kode\Event\EventGroup;

$group = EventGroup::prefix('user.');
$group->on('created', function (Event $e) { echo "创建\n"; });
$group->on('updated', function (Event $e) { echo "更新\n"; });
$group->on('deleted', function (Event $e) { echo "删除\n"; });

// 批量注册到调度器
$group->attach($dispatcher);

// 注销
$group->detach($dispatcher);
```

### 事件组工厂方法

```php
// 带前缀
$group = EventGroup::prefix('user.');

// 带后缀
$group = EventGroup::suffix('.event');

// 自定义前后缀
$group = EventGroup::create('order.', '.paid');

// 组合
$group = EventGroup::create('user.', '.admin');
$group->on('profile', $listener);  // 实际事件: user.profile.admin
```

### 一次性监听

```php
$group = EventGroup::prefix('app.');
$group->once('start', function () {
    echo "应用启动\n";
});

$group->attach($dispatcher);
$dispatcher->dispatch(new Event('app.start'));  // 触发
$dispatcher->dispatch(new Event('app.start'));  // 不触发
```

## 事件构建器

链式调用构建事件对象。

### 基本用法

```php
use Kode\Event\EventBuilder;

$event = EventBuilder::create('user.created')
    ->with('name', '张三')
    ->with('email', 'zhangsan@example.com')
    ->data(['age' => 25])  // 合并数据
    ->traceId('trace-123')
    ->meta('source', 'api')
    ->build();

$dispatcher->dispatch($event);
```

### 直接派发

```php
EventBuilder::create('user.created')
    ->with('name', '李四')
    ->with('email', 'lisi@example.com')
    ->dispatch($dispatcher);
```

## 抽象事件类

继承实现自定义事件类型。

### 基本用法

```php
use Kode\Event\AbstractEvent;

class UserCreatedEvent extends AbstractEvent
{
    protected function getEventName(): string
    {
        return 'user.created';
    }

    public function getUserId(): int
    {
        return $this->get('user_id');
    }

    public function getUserName(): string
    {
        return $this->get('name');
    }
}

$event = new UserCreatedEvent([
    'user_id' => 123,
    'name' => '张三',
]);

$dispatcher->dispatch($event);
```

### 抽象事件特性

```php
// 自动实现 Stringable 接口
$event = new UserCreatedEvent(['user_id' => 1]);
echo $event;  // 输出: UserCreatedEvent(user.created)

// 自动实现 StoppableEventInterface
$event->stopPropagation();

// 支持事件名称动态获取
class OrderPaidEvent extends AbstractEvent
{
    public function __construct(private int $orderId)
    {
        parent::__construct(['order_id' => $orderId]);
    }

    protected function getEventName(): string
    {
        return 'order.paid';
    }
}
```

## 异常和验证

### 内置异常

```php
use Kode\Event\Exception\InvalidEventException;
use Kode\Event\Exception\ListenerException;

throw InvalidEventException::emptyName();
// RuntimeException: 事件名称不能为空

throw InvalidEventException::invalidName('123.invalid');
// RuntimeException: 无效的事件名称: 123.invalid

throw ListenerException::notCallable('not callable');
// RuntimeException: 监听器必须可调用，当前类型: string
```

### 验证器

```php
use Kode\Event\Validator;

// 验证事件名称
Validator::validateEventName('user.created');  // 通过
Validator::validateEventName('');  // 抛出异常
Validator::validateEventName('123.invalid');  // 抛出异常

// 验证监听器
Validator::validateListener(function() {});  // 通过
Validator::validateListener('not callable');  // 抛出异常

// 验证优先级
Validator::validatePriority(100);  // 通过
```

### 安全执行

```php
use Kode\Event\Validator;

// 安全调用
$result = Validator::safeCall(function() {
    return $maybeFails();
}, 'default');

// 安全执行监听器
$error = Validator::safeExecuteListener($listener, $event, stopOnError: true);
if ($error) {
    echo "监听器执行出错: " . $error->getMessage();
}
```

## PHP 8.5 特性

### 版本检测

```php
use Kode\Event\Php85Features;

if (Php85Features::hasPipeOperator()) {
    // PHP 8.5+ 可以使用 |>
}

if (Php85Features::hasCloneWith()) {
    // PHP 8.5+ 可以使用 clone with 表达式
}
```

### Polyfill 方法

```php
// 管道操作 polyfill（兼容 PHP < 8.5）
$result = Php85Features::pipe($value, fn($v) => $v * 2);
$result = Php85Features::pipeMany($value, [
    fn($v) => $v + 1,
    fn($v) => $v * 2,
    fn($v) => $v - 3,
]);
```

## 事件助手

```php
use Kode\Event\EventHelper;

// 检查有效名称
EventHelper::isValidName('user.created');  // true
EventHelper::isValidName('123.invalid');    // false

// 规范化名称
EventHelper::normalizeName(' User.Created ');  // 'user.created'

// 解析事件名称
$parsed = EventHelper::parseName('user.profile.updated');
// ['prefix' => 'user', 'name' => 'profile', 'suffix' => 'updated']

// 匹配模式
EventHelper::matchesPattern('user.created', 'user.*');   // true
EventHelper::matchesPattern('user.created', '*.created'); // true

// 创建事件
$event = EventHelper::create(Event::class, ['data' => 'value']);

// 批量创建
$events = EventHelper::createMany([
    'event.a' => ['data' => 1],
    'event.b' => ['data' => 2],
]);

// 获取 PHP 特性支持
$features = EventHelper::getPhpFeatures();
$features['enum'];       // true (PHP 8.1+)
$features['readonly'];   // true (PHP 8.1+)
$features['pipe_operator'];  // false (PHP 8.5+)
```

## 事件名称常量

```php
use Kode\Event\EventNames;

EventNames::USER_CREATED;    // 'user.created'
EventNames::USER_UPDATED;    // 'user.updated'
EventNames::ORDER_PAID;      // 'order.paid'
EventNames::ORDER_COMPLETED; // 'order.completed'

$dispatcher->listen(EventNames::USER_CREATED, $listener);
```

## 事件优先级

```php
use Kode\Event\EventPriority;

EventPriority::CRITICAL->value;   // 200
EventPriority::HIGH->value;     // 100
EventPriority::ELEVATED->value; // 50
EventPriority::NORMAL->value;   // 0
EventPriority::LOW->value;     // -100
EventPriority::DEFERRED->value; // -200
```

## 通配符匹配

```php
$dispatcher->listen('user.*', function (Event $event) {
    echo "所有用户事件: {$event->getName()}\n";
});

$dispatcher->listen('*.created', function (Event $event) {
    echo "所有创建事件\n";
});

$dispatcher->listen('*.*', function (Event $event) {
    echo "所有事件\n";
});
```

## 异步队列

```bash
composer require kode/queue
```

```php
use Kode\Event\Queue\AsyncEvent;
use Kode\Event\Queue\QueueDispatcher;
use Kode\Event\Queue\Integration\KodeQueueDriver;
use Kode\Queue\Factory;

$queue = Factory::create([
    'default' => 'redis',
    'connections' => [
        'redis' => ['host' => '127.0.0.1', 'port' => 6379]
    ]
]);

$driver = new KodeQueueDriver($queue);
$dispatcher = new Dispatcher();
$queueDispatcher = new QueueDispatcher($driver, $dispatcher);

// 派发异步事件
$jobId = $queueDispatcher->enqueue('user.created', ['name' => '张三']);

// 延迟派发
$jobId = $queueDispatcher->enqueue('user.created', ['name' => '李四'], delay: 60);

// 消费队列
while ($queueDispatcher->process()) {
    // 处理事件
}
```

## 协程安全

```bash
composer require kode/runtime
```

```php
use Kode\Event\Coroutine\ContextStorage;
use Kode\Runtime\Runtime;

$context = new ContextStorage();
$context->setEventTraceId('trace-' . uniqid());

$dispatcher->listen('user.created', function (Event $event) {
    $traceId = Context::get('event.trace_id');
    echo "Trace: $traceId\n";
});

// 协程派发
Runtime::async(fn() => $dispatcher->dispatch(new Event('user.created', ['name' => '王五'])));
Runtime::wait();

// 上下文隔离
$result = $context->run(function () {
    Context::set('event.name', 'isolated');
    return Context::get('event.name');
});
```

## AOP 切面

```bash
composer require kode/aop
```

```php
use Kode\Event\Aop\AspectEventDispatcher;

$dispatcher = new AspectEventDispatcher();

$dispatcher->registerAspect('user.*', function (Event $event) {
    echo "前置: {$event->getName()}\n";
}, priority: 100);

$dispatcher->registerAspect('user.*', function (Event $event) {
    echo "后置: {$event->getName()}\n";
}, priority: -100);

$dispatcher->dispatch(new Event('user.created'));
```

## 依赖注入

```bash
composer require kode/di
```

```php
use Kode\DI\Attributes\Inject;
use Kode\DI\Attributes\Singleton;

#[Singleton]
class UserService
{
    #[Inject]
    private Dispatcher $dispatcher;

    public function createUser(array $data): void
    {
        $this->dispatcher->dispatch(new Event('user.created', $data));
    }
}
```

## 门面模式

```bash
composer require kode/facade
```

```php
use Kode\Facade\Facade;

abstract class EventFacade extends Facade
{
    protected static function id(): string
    {
        return 'event.dispatcher';
    }
}

EventFacade::listen('user.created', function ($event) {
    echo "用户创建\n";
});

EventFacade::dispatch(new Event('user.created', ['name' => '赵六']));
```

## 并行处理

```bash
composer require kode/parallel
```

```php
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;

$runtime = new Runtime();
$channel = Channel::make('events');
$dispatcher = new Dispatcher();

$runtime->run(fn() => $channel->send(new Event('user.created', ['name' => '用户1'])));
$runtime->run(fn() => $channel->send(new Event('user.created', ['name' => '用户2'])));

$runtime->run(function () use ($channel, $dispatcher) {
    while ($event = $channel->recv()) {
        $dispatcher->dispatch($event);
    }
});

$runtime->close();
```

## API 参考

### Event

| 方法 | 说明 |
|------|------|
| `getName()` | 获取事件名称 |
| `get(string $key, $default)` | 获取事件数据 |
| `set(string $key, $value)` | 设置事件数据 |
| `fill(array $data)` | 批量设置数据 |
| `has(string $key)` | 检查键是否存在 |
| `stopPropagation()` | 停止事件传播 |
| `isPropagationStopped()` | 检查是否已停止 |
| `getTimestamp()` | 获取创建时间戳 |
| `getElapsed()` | 获取经过的时间 |

### Dispatcher

| 方法 | 说明 |
|------|------|
| `listen(string $event, $listener, int $priority)` | 注册监听器 |
| `unlisten(string $event, $listener)` | 注销监听器 |
| `subscribe(SubscriberInterface $subscriber)` | 注册订阅者 |
| `dispatch(Event\|string $event, array $data)` | 派发事件 |
| `dispatchMany(Event ...$events)` | 批量派发 |
| `hasListeners(string $event)` | 检查是否有监听器 |
| `getListeners(string $event)` | 获取监听器列表 |
| `clear(?string $event)` | 清空监听器 |

### EventGroup

| 方法 | 说明 |
|------|------|
| `on(string $event, callable $listener, int $priority)` | 注册监听器 |
| `once(string $event, callable $listener)` | 注册一次性监听器 |
| `off(string $event)` | 注销监听器 |
| `attach(Dispatcher $dispatcher)` | 批量注册到调度器 |
| `detach(Dispatcher $dispatcher)` | 从调度器注销 |

### EventBuilder

| 方法 | 说明 |
|------|------|
| `create(string $name)` | 创建构建器 |
| `with(string $key, $value)` | 添加数据 |
| `data(array $data)` | 批量添加数据 |
| `traceId(string $id)` | 设置追踪ID |
| `meta(string $key, $value)` | 添加元数据 |
| `build()` | 构建事件对象 |
| `dispatch(Dispatcher $dispatcher)` | 直接派发 |

### AttributeListenerRegistry

| 方法 | 说明 |
|------|------|
| `register(object\|string $subscriber)` | 注册订阅者 |
| `registerMany(array $subscribers)` | 批量注册 |
| `getDispatcher()` | 获取调度器 |

### Validator

| 方法 | 说明 |
|------|------|
| `validateEventName(string $name)` | 验证事件名称 |
| `validateListener($listener)` | 验证监听器 |
| `validatePriority(int $priority)` | 验证优先级 |
| `safeCall(callable $callback, $default)` | 安全调用 |
| `safeExecuteListener($listener, Event $event)` | 安全执行监听器 |

## 项目结构

```
src/
├── Attribute/                        # PHP 8+ 属性
│   ├── Listener.php                # 监听器属性
│   ├── Priority.php                 # 优先级属性
│   └── Subscriber.php              # 订阅者属性
├── Exception/                       # 异常
│   └── EventException.php          # 事件异常
├── Event.php                        # 基础事件类
├── AbstractEvent.php               # 抽象事件类
├── AbstractListener.php            # 监听器抽象类
├── AttributeListenerRegistry.php   # 属性监听器注册器
├── Dispatcher.php                   # 事件调度器
├── EventBuilder.php                # 事件构建器
├── EventDispatcherTrait.php       # 事件调度特性
├── EventGroup.php                  # 事件组
├── EventHelper.php                 # 事件助手
├── EventInterceptorInterface.php   # 拦截器接口
├── EventListenerTrait.php         # 监听器特性
├── EventNames.php                 # 事件名称常量
├── EventPriority.php              # 事件优先级枚举
├── InterceptorRegistry.php        # 拦截器注册表
├── ListenerInterface.php          # 监听器接口
├── Php85Features.php              # PHP 8.5 特性
├── Queue/
│   ├── QueueDriverInterface.php
│   ├── AsyncEvent.php
│   ├── QueueDispatcher.php
│   └── Integration/
│       └── KodeQueueDriver.php
├── Coroutine/
│   ├── CoroutineContextInterface.php
│   └── ContextStorage.php
├── Aop/
│   └── AspectEventDispatcher.php
└── Validator.php                    # 验证器
```

## 许可证

Apache-2.0
