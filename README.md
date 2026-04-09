# kode/event 事件编排库

轻量级、解耦的事件系统，支持事件派发、监听、订阅、异步事件、协程安全，可结合 `kode/aop` 做切面事件。

## 特性

- **事件派发** - 支持同步/异步事件派发
- **监听注册** - 支持优先级、通配符匹配
- **订阅者模式** - 通过订阅者集中管理监听器
- **异步队列** - 支持 `kode/queue` 异步事件处理
- **协程安全** - 基于 `kode/context` 的协程上下文传递
- **AOP 切面** - 结合 `kode/aop` 实现切面事件
- **依赖注入** - 支持 `kode/di` 属性注入

## 环境要求

- PHP >= 8.1
- `kode/context` ^2.0（必须）

## 安装

```bash
composer require kode/event
```

## 快速开始

### 基本用法

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

### 事件对象

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

// 停止传播
$event->stopPropagation();

// 检查是否停止
if ($event->isPropagationStopped()) {
    echo "事件已停止传播";
}
```

### 监听器

#### Callable 监听器

```php
$dispatcher->listen('user.created', function (Event $event) {
    echo "用户创建\n";
});

// 带优先级
$dispatcher->listen('user.created', function (Event $event) {
    echo "高优先级\n";
}, priority: 100);
```

#### 监听器类

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

#### 订阅者

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

### 事件名称常量

```php
use Kode\Event\EventNames;
use Kode\Event\Dispatcher;
use Kode\Event\Event;

$dispatcher = new Dispatcher();

$dispatcher->listen(EventNames::USER_CREATED, function (Event $e) {
    echo "用户创建\n";
});

$dispatcher->listen(EventNames::ORDER_PAID, function (Event $e) {
    echo "订单支付\n";
});

$dispatcher->dispatch(new Event(EventNames::USER_CREATED));
```

### 通配符匹配

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

### 批量派发

```php
$events = [
    new Event('event.a', ['data' => 1]),
    new Event('event.b', ['data' => 2]),
    new Event('event.c', ['data' => 3]),
];

$results = $dispatcher->dispatchMany(...$events);
```

## 异步队列

### 安装 kode/queue

```bash
composer require kode/queue
```

### 基本用法

```php
use Kode\Event\Dispatcher;
use Kode\Event\Queue\AsyncEvent;
use Kode\Event\Queue\QueueDispatcher;
use Kode\Event\Queue\QueueDriverInterface;
use Kode\Queue\Factory;

// 创建队列
$queue = Factory::create([
    'default' => 'redis',
    'connections' => [
        'redis' => ['host' => '127.0.0.1', 'port' => 6379]
    ]
]);

// 创建队列调度器
$driver = new \Kode\Event\Queue\Integration\KodeQueueDriver($queue);
$dispatcher = new Dispatcher();
$queueDispatcher = new QueueDispatcher($driver, $dispatcher);

// 派发异步事件
$jobId = $queueDispatcher->enqueue('user.created', ['name' => '张三']);

// 延迟派发
$jobId = $queueDispatcher->enqueue('user.created', ['name' => '李四'], delay: 60);

// 消费队列
while (true) {
    $queueDispatcher->process();
}
```

## 协程安全

### 安装依赖

```bash
composer require kode/runtime
```

### 基本用法

```php
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\Coroutine\ContextStorage;
use Kode\Runtime\Runtime;

$dispatcher = new Dispatcher();
$context = new ContextStorage();

// 设置事件追踪上下文
$context->setEventTraceId('trace-' . uniqid());

$dispatcher->listen('user.created', function (Event $event) use ($context) {
    // 在监听器中获取追踪ID
    $traceId = $context->getEventTraceId();
    echo "Trace: $traceId\n";
});

// 协程方式派发
Runtime::async(fn() => $dispatcher->dispatch(new Event('user.created', ['name' => '王五'])));

Runtime::wait();
```

### 上下文隔离

```php
use Kode\Event\Coroutine\ContextStorage;

$context = new ContextStorage();

// 隔离作用域
$result = $context->run(function () {
    Context::set('event.name', 'isolated');
    return Context::get('event.name');
});

// 继承作用域
$result = $context->fork(function () {
    return Context::get('event.name');
});
```

## AOP 切面

### 安装依赖

```bash
composer require kode/aop
```

### 基本用法

```php
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\Aop\AspectEventDispatcher;

$dispatcher = new AspectEventDispatcher();

// 注册前置切面
$dispatcher->registerAspect('user.*', function (Event $event) {
    echo "前置: {$event->getName()}\n";
}, priority: 100);

// 注册后置切面
$dispatcher->registerAspect('user.*', function (Event $event) {
    echo "后置: {$event->getName()}\n";
}, priority: -100);

$dispatcher->listen('user.created', function (Event $event) {
    echo "处理事件\n";
});

$dispatcher->dispatch(new Event('user.created'));
```

## 依赖注入

### 安装依赖

```bash
composer require kode/di
```

### 基本用法

```php
use Kode\Event\Dispatcher;
use Kode\DI\Attributes\Inject;
use Kode\DI\Attributes\Singleton;

#[Singleton]
class UserService
{
    #[Inject]
    private Dispatcher $dispatcher;

    public function createUser(array $data): void
    {
        // 业务逻辑
        $this->dispatcher->dispatch(new \Kode\Event\Event('user.created', $data));
    }
}
```

## 门面模式

### 安装依赖

```bash
composer require kode/facade
```

### 基本用法

```php
use Kode\Event\Facades\EventFacade;

abstract class EventFacade extends \Kode\Facade\Facade
{
    protected static function id(): string
    {
        return 'event.dispatcher';
    }
}

// 静态调用
EventFacade::listen('user.created', function ($event) {
    echo "用户创建\n";
});

EventFacade::dispatch(new \Kode\Event\Event('user.created', ['name' => '赵六']));
```

## 并行处理

### 安装依赖

```bash
composer require kode/parallel
```

### 基本用法

```php
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;

$runtime = new Runtime();
$channel = Channel::make('events');
$dispatcher = new Dispatcher();

// 生产者
$runtime->run(fn() => $channel->send(new Event('user.created', ['name' => '用户1'])));
$runtime->run(fn() => $channel->send(new Event('user.created', ['name' => '用户2'])));

// 消费者
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
| `stopPropagation()` | 停止事件传播 |
| `isPropagationStopped()` | 检查是否已停止 |
| `getTimestamp()` | 获取创建时间戳 |

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

### EventPriority

```php
use Kode\Event\EventPriority;

EventPriority::CRITICAL->value;   // 200
EventPriority::HIGH->value;       // 100
EventPriority::ELEVATED->value;  // 50
EventPriority::NORMAL->value;     // 0
EventPriority::LOW->value;        // -100
EventPriority::DEFERRED->value;   // -200
```

## 项目结构

```
src/
├── Event.php                         # 基础事件类
├── Dispatcher.php                    # 事件调度器
├── ListenerRegistry.php              # 监听器注册表
├── ListenerInterface.php             # 监听器接口
├── SubscriberInterface.php           # 订阅者接口
├── AbstractListener.php              # 监听器抽象类
├── EventListenerTrait.php            # 监听器特性
├── EventPriority.php                 # 事件优先级枚举
├── EventNames.php                    # 常用事件名称常量
├── Queue/
│   ├── QueueDriverInterface.php      # 队列驱动接口
│   ├── AsyncEvent.php                # 异步事件
│   ├── QueueDispatcher.php           # 队列调度器
│   └── Integration/
│       └── KodeQueueDriver.php       # kode/queue 集成
├── Coroutine/
│   ├── CoroutineContextInterface.php # 协程上下文接口
│   └── ContextStorage.php            # 上下文存储
└── Aop/
    └── AspectEventDispatcher.php     # AOP 切面调度器
```

## 许可证

Apache-2.0
