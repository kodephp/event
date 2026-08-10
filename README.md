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
- **PSR-14 互操作** - 同时实现 `EventDispatcherInterface` 与 `ListenerProviderInterface`，无缝接入任意 PSR-14 生态
- **错误处理策略** - 监听器异常支持 抛出 / 收集 / 忽略 三种策略，并支持 `onError` 钩子
- **短路派发** - `until()` 责任链式派发，返回首个非 null 结果即停止
- **运行指标** - 内置 `DispatcherStats` 采集派发次数、耗时与慢事件
- **命名事件路由** - 通过 `NamedEventInterface` 按事件名路由任意对象
- **递归深度保护** - 防止事件循环导致栈溢出
- **派发钩子** - 支持前置 / 后置派发钩子
- **事件校验 Schema** - `EventSchema` / `EventSchemaRegistry` 声明必填字段、类型与自定义规则，支持 `explain()` 诊断
- **组合校验谓词** - `EventPredicates::all()` / `any()` / `none()` 基于 PHP 8.4 数组函数表达复杂语义
- **批量校验结果** - `EventSchemaRegistry::validateDetailed()` 返回只读 `ValidationResult` DTO
- **PHP 8.4 数组函数** - 内置 `array_find` / `array_find_key` / `array_any` / `array_all` polyfill（PHP < 8.4 自动启用，8.4+ 让位原生）
- **PHP 8.5** - 支持新版本语言特性
- **PHP 8.3+** - 最低运行版本要求，方法重写统一标注 `#[\Override]`

## 环境要求

- PHP >= 8.3
- `kode/context` ^3.1（必须）

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
- [事件冒泡](#事件冒泡)
- [事件过滤器](#事件过滤器)
- [延迟派发](#延迟派发)
- [事件追踪](#事件追踪)
- [批量事件](#批量事件)
- [事件管道](#事件管道)
- [生命周期钩子](#生命周期钩子)
- [不可变事件](#不可变事件)
- [事件重放](#事件重放)
- [事件中间件](#事件中间件)
- [事件验证](#事件验证)
- [PSR-14 互操作](#psr-14-互操作)
- [外部 PSR-14 提供者聚合](#外部-psr-14-提供者聚合)
- [调度策略（错误处理）](#调度策略错误处理)
- [短路派发 until](#短路派发-until)
- [运行指标 DispatcherStats](#运行指标-dispatcherstats)
- [命名事件 NamedEventInterface](#命名事件-namedeventinterface)
- [递归深度保护](#递归深度保护)
- [派发钩子](#派发钩子)
- [JSON 序列化](#json-序列化)
- [异步队列](#异步队列)
- [协程安全](#协程安全)
- [AOP 切面](#aop-切面)
- [依赖注入](#依赖注入)
- [门面模式](#门面模式)
- [并行处理](#并行处理)
- [性能压测](#性能压测)
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

## PHP 8.4 数组函数

库内置 `array_find` / `array_find_key` / `array_any` / `array_all` 的 polyfill
（`src/Php84Functions.php`，经 `composer.json` 的 `autoload.files` 自动加载）。
在 PHP < 8.4 时提供与官方语义一致的实现；在 PHP >= 8.4 时由引擎原生提供，
polyfill 通过 `function_exists` 守卫自动跳过，无需任何改动即可享受原生性能。

```php
use Kode\Event\Php84Features;

Php84Features::hasArrayFunctions(); // PHP 8.4+ 为 true
Php84Features::hasArrayFind();      // array_find 可用时为 true

// 在 PHP 8.3 上也会自动得到 polyfill 版本
$first = array_find([1, 2, 3], fn(int $v): bool => $v > 1); // 2
$allPositive = array_all([-1, 2, 3], fn(int $v): bool => $v > 0); // false
```

这些函数在事件校验路径中已被直接使用：`EventSchema::validateEvent()` 用 `array_all`
判定多规则全部通过，`EventSchemaRegistry::findFirstInvalid()` 用 `array_find` 定位首个非法事件，
`ValidationMiddleware` 用 `array_filter` + `array_find_key` 精确报告首个失败的校验器序号。

### 组合校验谓词

```php
use Kode\Event\Event;
use Kode\Event\EventPredicates;

$adult = fn(Event $e): bool => ($e->get('age') ?? 0) >= 18;
$vip   = fn(Event $e): bool => ($e->get('vip') ?? false) === true;

$gate = EventPredicates::all($adult, $vip); // 同时满足
$gate(new Event('x', ['age' => 20, 'vip' => true])); // true
```

### 详细校验结果

```php
use Kode\Event\EventSchema;
use Kode\Event\EventSchemaRegistry;

$registry = new EventSchemaRegistry();
$registry->register(
    EventSchema::create('user.created')->required('user_id', 'int')
);

$result = $registry->validateDetailed(
    new Event('user.created', ['user_id' => 1]),
    new Event('user.created', []), // 缺少 user_id
);

$result->allValid;  // false
$result->failures;  // ['user.created' => '缺少必填字段 user_id']
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

## 事件冒泡

子事件自动冒泡到父事件。

```php
use Kode\Event\EventBubbles;

$dispatcher = new Dispatcher();
$bubbles = new EventBubbles($dispatcher);

// 注册父子关系：user.created -> user.activity
$bubbles->registerParent('user.created', 'user.activity');
$bubbles->registerParent('user.updated', 'user.activity');

$dispatcher->listen('user.activity', function (Event $e) {
    echo "用户活动事件: {$e->getName()}\n";
});

$bubbles->bubble(new Event('user.created'));
// 输出: 用户活动事件: user.activity
```

### 批量注册

```php
$bubbles->registerParents([
    'user.created' => 'user.activity',
    'user.updated' => 'user.activity',
    'user.deleted' => 'user.activity',
]);
```

### 启用/禁用

```php
$bubbles->disable();
$bubbles->enable();
$bubbles->isEnabled(); // false
```

## 事件过滤器

在事件派发前修改事件数据。

```php
use Kode\Event\EventFilter;

$filter = new EventFilter();

$filter->add('user.created', function (Event $event) {
    $event->set('name', strtoupper($event->get('name')));
    $event->set('filtered', true);
    return $event;
}, priority: 10);

$event = new Event('user.created', ['name' => 'test']);
$filtered = $filter->filter($event);

echo $filtered->get('name'); // TEST
echo $filtered->get('filtered'); // true
```

### 移除过滤器

```php
$myFilter = function (Event $event) { return $event; };
$filter->add('test', $myFilter);
$filter->remove('test', $myFilter);
```

## 延迟派发

延迟一段时间后派发事件。

```php
use Kode\Event\DeferredDispatcher;

$dispatcher = new Dispatcher();
$deferred = new DeferredDispatcher($dispatcher);

// 延迟 5 秒派发
$jobId = $deferred->defer('user.created', ['name' => '张三'], delay: 5);

// 取消延迟事件
$deferred->cancel($jobId);

// 处理到期的延迟事件
$deferred->process();

// 处理所有延迟事件
$deferred->processAll();

// 检查待处理数量
$deferred->count();

// 批量回填历史定时任务（事件溯源重放 / 历史补调度）
// 一次性插入大量 dispatchAt 早于（或等于）现有任务的定时任务，内部排序后单次归并，
// 远快于逐个 deferAt()（详见 v1.18.0 优化说明）
$deferred->deferBackfill([
    ['event' => 'order.created', 'timestamp' => time() - 3600],
    ['event' => 'order.paid',    'timestamp' => time() - 1800],
    ['event' => 'order.shipped', 'timestamp' => time() - 600],
]);
```

## 事件溯源（Event Sourcing）

把每一次派发的事件作为不可变记录持久化到「仅追加日志」，需要时可以重放事件流来重建读模型或修复下游。

```php
use Kode\Event\EventReplay;
use Kode\Event\FileEventStore;

$dispatcher = new Dispatcher();
$store = new FileEventStore(__DIR__ . '/events.log'); // JSON Lines，整行原子追加
$replay = new EventReplay($dispatcher);
$replay->setStore($store);

// 挂载后，每次派发的 Event 自动入账（内存 + 文件），无需手动 record()
$replay->attach($dispatcher);

$dispatcher->listen('order.created', fn (Event $e) => /* 更新读模型 */ null);
$dispatcher->dispatch('order.created', ['id' => 7]);

// 故障恢复 / 读模型重建：从持久化日志重放（from=起始序号，count=条数上限）
$replay->replayFromStore(from: 1);
```

`EventEnvelope` 是不可变信封（全局序号 `seq` + 事件唯一 `id` + name/data/metadata/记录时间戳），
`seq` 即事件流游标，支持「从某序号起重放」。`EventStoreInterface` 实现可插拔：
`InMemoryEventStore`（测试 / 单进程）与 `FileEventStore`（文件持久化，单写入者）。

超大日志（快照回放、批量导入）请用批量写入与流式加载：

```php
// 批量写入：一次性整块原子追加，减少 syscall（20 万事件约 18.9× 提速）
$store->appendBatch([
    ['event' => new Event('order.created', ['id' => 1])],
    ['event' => new Event('order.paid', ['id' => 1])],
]);

// 流式重放：O(1) 内存，逐行产出，避免把整份日志物化进内存（损坏行自动跳过）
foreach ($store->stream() as $envelope) {
    // 增量重建读模型 / 修复下游
}
```



## 重试与死信策略（Retry / Dead-Letter）

用 `RetryListener` 包裹真实监听器，失败时按次数重试并退避；重试耗尽后投递到死信接收器，
避免单次失败中断整条监听链：

```php
use Kode\Event\CallbackDeadLetterSink;
use Kode\Event\InMemoryDeadLetterSink;
use Kode\Event\RetryListener;

$sink = new InMemoryDeadLetterSink(); // 生产中可换 CallbackDeadLetterSink 转发到队列/库

// 退避：$backoff 可为固定毫秒数，或 callable(int $attempt): int（按第几次尝试计算）
$dispatcher->listen('order.paid', new RetryListener(
    static function (Event $e): void {
        // 幂等的业务处理，可能抛异常
    },
    'order.paid',
    maxAttempts: 5,
    backoff: static fn (int $attempt): int => 2 ** $attempt * 100, // 100 / 200 / 400 ...
    deadLetter: $sink
));

// 重试耗尽：事件被投递到 $sink 并「吞掉」异常；未配置 deadLetter 则重抛，交调度器 ErrorStrategy 裁决

// 退避抖动（jitter，0~1）：在基础退避上叠加 ±jitter 的随机扰动，缓解大量失败时的「重试惊群」
// 实际退避 = 基础退避 × (1 ± jitter 随机扰动)；可用 setRng() 注入确定性随机源以便单元测试
$retry = new RetryListener(
    static fn (Event $e) => null,
    'order.paid',
    maxAttempts: 5,
    backoff: static fn (int $attempt): int => 2 ** $attempt * 100,
    jitter: 0.3, // ±30%
    deadLetter: $sink
);
```

除了手写 `backoff` callable，也提供两个静态工厂，直接作为 `backoff` 参数使用：

```php
use Kode\Event\RetryListener;

// 指数退避：100ms、200ms、400ms … 上限 5s；超大 attempt 幂溢出时安全回落到上限
$dispatcher->listen('order.paid', new RetryListener(
    $handler,
    'order.paid',
    maxAttempts: 8,
    backoff: RetryListener::exponentialBackoff(100, 2.0, 5000),
    deadLetter: $sink
));

// 去相关抖动退避（AWS 风格）：sleep = random(base, prev×3)，更快错峰，进一步缓解惊群
$dispatcher->listen('order.shipped', new RetryListener(
    $handler,
    'order.shipped',
    backoff: RetryListener::decorrelatedJitterBackoff(100, 10000),
    deadLetter: $sink
));
```

退避策略可与 `jitter` 叠加：工厂给出「基础退避」，jitter 在其上再做 ±扰动。


`DeadLetterSinkInterface` 提供 `InMemoryDeadLetterSink`（进程内暂存，便于排查）与
`CallbackDeadLetterSink`（转发到任意回调，便于接入消息队列 / 数据库 / 监控），
死信条目由 `DeadLetterEntry`（事件 + 异常 + 尝试次数 + 移入时间戳）承载。

## 事件追踪

追踪事件派发的性能和时间。

```php
use Kode\Event\EventTracer;

$tracer = new EventTracer($dispatcher);
$tracer->enable();

$event = new Event('user.created', ['data' => 'value']);

$tracer->trace($event, function () use ($event, $dispatcher) {
    $dispatcher->dispatch($event);
});

// 获取追踪信息
$trace = $tracer->getRecentTraces(1)[0];
$trace['event'];        // 'user.created'
$trace['duration'];     // 纳秒
$trace['listenerCount']; // 监听器数量
$trace['data'];         // ['data' => 'value']

// 获取所有追踪
$tracer->getAllTraces();

// 清空追踪记录
$tracer->clear();
```

### 分布式追踪（跨进程 / 跨节点）

借助 `kode/context`（^3.0 起提供 W3C Trace Context 标准），`DistributedEventTracer`
让事件在异步队列、RPC、消息总线等边界传递时仍携带统一链路上下文，与 OpenTelemetry 互通。

```php
use Kode\Event\DistributedEventTracer;
use Kode\Event\Event;

$tracer = new DistributedEventTracer();

// 生产端：开启链路并把 traceparent 注入事件
$tracer->startTrace();
$event = new Event('order.paid', ['amount' => 100]);
$tracer->injectToEvent($event);   // 事件 now 携带 traceparent / tracestate

// 经 JSON 序列化跨进程边界
$json = $event->toJson();

// 消费端：从 JSON 重建事件并恢复同一条链路
$restored = Event::fromJson($json);
$tracer->extractFromEvent($restored);   // 返回 true，trace_id 与生产者一致

// 任意时刻读取当前链路
$tracer->getTraceparent();  // W3C traceparent 字符串
$tracer->getTraceInfo();    // ['trace_id' => ..., 'span_id' => ..., ...]
```

`ContextStorage`（协程安全上下文存储）也已升级以使用 context 的最新能力：
`KodeContext::getInt()` 类型安全读取、`KodeContext::hasAll()` 组合键检查、
`KodeContext::transaction()` 事务作用域自动回滚。

> 要求 `kode/context: ^3.1`（已随本版本将依赖下限由 `^2.0` 提升至 `^3.1`）。

#### 自动接线到 Dispatcher

将 `DistributedEventTracer` 注入 {@see Dispatcher} 后，每次派发的 `Event` 会**自动携带**
W3C `traceparent`（当前无活动链路时自动开启一条），无需在业务代码里手动 `injectToEvent`。
类型化事件对象（非 `Event` 实例）不会被注入，保持原样。

```php
use Kode\Event\Dispatcher;
use Kode\Event\DistributedEventTracer;

$dispatcher = new Dispatcher();
$dispatcher->setTracer(new DistributedEventTracer());

$dispatcher->listen('order.created', function (\Kode\Event\Event $event) {
    // 事件已自动携带 traceparent，可直接 toJson() 入队
});

$dispatcher->dispatch('order.created', ['id' => 42]);
```

#### `DistributedEventTracer` API

| 方法 | 说明 |
| --- | --- |
| `startTrace(?string $traceId, ?string $nodeId): string` | 开启新链路，返回 32 位 trace_id |
| `injectToEvent(Event $event): array` | 将当前 W3C 头部注入事件（`traceparent` / `tracestate`） |
| `extractFromEvent(Event $event): bool` | 从事件恢复链路（消费端），成功返回 true |
| `propagate(Event $event): ?string` | 确保链路存在并注入事件，返回 traceparent |
| `getTraceparent(): ?string` | 当前 W3C traceparent |
| `getTraceInfo(): array` | 当前链路信息（`trace_id` / `span_id` / `flags` 等） |
| `trace(Event $event, callable $cb): mixed` | 在一条跨度内派发事件并执行回调 |

## 批量事件

批量构建和派发事件。

```php
use Kode\Event\BatchEventBuilder;

$dispatcher = new Dispatcher();
$batch = BatchEventBuilder::batch($dispatcher);

// 批量派发
$batch->create('user.created')
      ->create('user.updated')
      ->create('order.created')
      ->dispatch();

// 带前缀后缀
$batch->prefix('app.')
      ->suffix('.event')
      ->create('start')
      ->create('stop')
      ->dispatch();

// 带默认数据
$batch->defaults(['source' => 'batch', 'timestamp' => time()])
      ->with('user.created', ['name' => 'test'])
      ->dispatch();

// 仅构建不派发
$events = $batch->create('user.created')
                ->create('user.updated')
                ->build();
```

## 事件管道

链式变换事件数据，支持 PHP 8.5 管道操作符。

```php
use Kode\Event\EventPipeline;

$event = new Event('user.created', ['name' => 'test']);
$pipeline = EventPipeline::create($event);

$result = $pipeline
    ->pipe(fn($e) => $e->set('piped', true))
    ->pipe(fn($e) => $e->set('step', 1))
    ->execute();
```

### 过滤器

```php
$pipeline->filter(fn($e) => $e->get('value') > 0)
        ->pipe(fn($e) => $e->set('passed', true));
```

### 变换映射

```php
$pipeline->map(fn($e) => $e->set('mapped', true));
```

### 调试

```php
$pipeline->tap(fn($e) => var_dump($e->getData()));
```

### 停止管道

```php
$pipeline->stop();
```

### 链式执行回调

```php
$result = $pipeline
    ->pipe(fn($e) => $e->set('done', true))
    ->then(fn($e) => $e->get('value') * 2);
```

### 直接派发

```php
$pipeline->pipe(fn($e) => $e->set('enhanced', true))
         ->dispatch($dispatcher);
```

## 生命周期钩子

在事件派发的各个阶段插入自定义逻辑。

```php
use Kode\Event\EventHooks;

$hooks = new EventHooks();

$hooks->before(function (Event $event) {
    $event->set('before_dispatch', time());
    return $event;
}, priority: 100);

$hooks->after(function (Event $event) {
    echo "事件已派发: {$event->getName()}\n";
});

$hooks->error(function (Event $event, \Throwable $e) {
    echo "派发错误: {$e->getMessage()}\n";
});

// 触发钩子
$event = new Event('test');
$event = $hooks->triggerBefore($event);
$dispatcher->dispatch($event);
$hooks->triggerAfter($event);
```

### 移除钩子

```php
$myHook = function (Event $event) { return $event; };
$hooks->before($myHook);
$hooks->removeBefore($myHook);
```

### 清空钩子

```php
$hooks->clear('before'); // 仅清空 before
$hooks->clear();         // 清空所有
```

## 不可变事件

使用 PHP 8.1 readonly 属性的不可变事件对象。

```php
use Kode\Event\ImmutableEvent;

$event = ImmutableEvent::create('user.created', ['name' => 'test']);

// 只读属性
$event->name;        // 'user.created'
$event->data;        // ['name' => 'test']
$event->propagationStopped; // false

// 创建新实例（不修改原对象）
$newEvent = $event->with('age', 25);
$newEvent = $event->withData(['extra' => 'data']);
$newEvent = $event->withStopped();

// 从普通事件转换
$immutable = ImmutableEvent::fromEvent($event);
```

> **语义对齐**：`ImmutableEvent` 与 `Event` 行为一致 —— `has()` 用 `array_key_exists`
> 精确判断键是否存在（区分「键不存在」与「值为 null」）；`get()` 支持 `a.b.c` 点路径取值；
> `with` / `withData` / `withStopped` / `create` / `fromArray` / `fromEvent` 均返回 `static`
> （支持子类协变）。

## 事件重放

记录和重放事件序列。

```php
use Kode\Event\EventReplay;

$dispatcher = new Dispatcher();
$replay = new EventReplay($dispatcher);

// 记录事件
$replay->record(new Event('user.created', ['id' => 1]));
$replay->record(new Event('user.updated', ['id' => 1]));

// 重放所有
$replay->replay();

// 重放指定范围
$replay->replay(from: 0, count: 5);

// 反向重放
$replay->replayReverse();

// 重放直到特定事件
$replay->replayUntil('user.deleted');

// 条件重放
$replay->replayIf(fn($e) => $e->has('id'));

// 导出/导入
$exported = $replay->export();
$imported = EventReplay::import($exported);
```

## 事件中间件

在事件派发过程中插入中间件处理。

```php
use Kode\Event\EventMiddleware;
use Kode\Event\LoggingMiddleware;
use Kode\Event\ValidationMiddleware;

$middleware = new EventMiddleware();

// 添加中间件
$middleware->add(function ($event, $next) {
    echo "前置处理\n";
    $result = $next($event);
    echo "后置处理\n";
    return $result;
}, priority: 10);

// 处理事件
$middleware->process($event, fn($e) => $dispatcher->dispatch($e));
```

### 日志中间件

```php
$logging = new LoggingMiddleware();
$logging->handle($event, fn($e) => $dispatcher->dispatch($e));
```

### 验证中间件

```php
$validation = new ValidationMiddleware();

$validation->addRule('user.created', fn($e) => $e->has('user_id'));
$validation->addRule('user.created', fn($e) => is_int($e->get('user_id')));

$validation->handle($event, fn($e) => $dispatcher->dispatch($e));
```

## 事件验证

使用 Schema 定义和验证事件结构。

```php
use Kode\Event\EventSchema;
use Kode\Event\EventSchemaRegistry;

$schema = EventSchema::create('user.created')
    ->required('user_id', 'int')
    ->required('name', 'string')
    ->optional('email', 'string')
    ->validate(fn($e) => $e->get('user_id') > 0);

$event = new Event('user.created', [
    'user_id' => 123,
    'name' => '张三',
]);

if ($schema->validateEvent($event)) {
    $dispatcher->dispatch($event);
}
```

### Schema 注册表

```php
$registry = new EventSchemaRegistry();

$registry->register(EventSchema::create('user.created')
    ->required('user_id', 'int'));

$registry->register(EventSchema::create('order.paid')
    ->required('order_id', 'int')
    ->required('amount', 'numeric'));

// 验证事件
$registry->validate($event);

// 批量验证
$registry->validateMany([$event1, $event2]);
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

`EventNames::all()` 利用 PHP 8.3 动态类常量获取枚举全部内置事件名，
新增常量后自动包含，无需手工维护列表：

```php
EventNames::all(); // ['app.start', 'user.created', ...]
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

## PSR-14 互操作

`Dispatcher` 同时实现 `Psr\EventDispatcher\EventDispatcherInterface` 与 `ListenerProviderInterface`，
因此可直接接入任何遵循 PSR-14 的框架或库；`ListenerRegistry` 也实现了 `ListenerProviderInterface`。

除了按字符串事件名派发，还支持派发**任意对象**：

- 对象实现 `Kode\Event\NamedEventInterface`（提供 `getName()`）时，按事件名路由；
- 其余对象按「类名 + 父类链 + 实现的接口」解析，从而命中针对其父类型或接口注册的监听器。

```php
interface UserEvent {}
class UserRegistered implements UserEvent {}

$dispatcher->listen(UserEvent::class, function () {
    echo "任意用户事件\n";
});

$dispatcher->dispatch(new UserRegistered()); // 命中上面的监听器
```

## 外部 PSR-14 提供者聚合

除了内置注册表，还能把**任意第三方 PSR-14 `ListenerProviderInterface`** 聚合进调度器，
实现跨系统的事件互操作。派发时，命中的命名事件与类型化对象事件都会一并触发这些外部监听器。

```php
use Psr\EventDispatcher\ListenerProviderInterface;

$provider = new class implements ListenerProviderInterface {
    public function getListenersForEvent(object $event): iterable
    {
        if ($event instanceof \Kode\Event\Event && $event->getName() === 'user.created') {
            yield fn(\Kode\Event\Event $e) => $e->setMeta('via_provider', true);
        }
    }
};

$dispatcher->addProvider($provider);          // 返回 $this，可链式
$dispatcher->getProviders();                  // 已聚合的提供者列表
$dispatcher->getRegistry()->clearProviders(); // 移除全部外部提供者（不影响内置监听器）
```

> 外部提供者无优先级信息，统一以最低优先级（`seq = PHP_INT_MAX`）在内部监听器之后执行；
> 因其可能动态增减，聚合状态下解析结果不进入缓存，保证即时生效。

## 调度策略（错误处理）

监听器抛出的异常可通过 `ErrorStrategy` 枚举控制后续行为：

| 策略 | 行为 |
|------|------|
| `THROW`   | 立即向上抛出，中断监听链（默认，兼容旧版本） |
| `COLLECT` | 收集所有异常并继续执行，派发结束后以 `EventDispatchException` 聚合抛出 |
| `IGNORE`  | 忽略异常并继续执行，仅通过 `onError` 回调通知 |

```php
use Kode\Event\ErrorStrategy;
use Kode\Event\Exception\EventDispatchException;

$dispatcher->setErrorStrategy(ErrorStrategy::COLLECT);

$dispatcher->listen('risky', fn() => throw new \RuntimeException('a'));
$dispatcher->listen('risky', fn() => throw new \DomainException('b'));

try {
    $dispatcher->dispatch(new Event('risky'));
} catch (EventDispatchException $e) {
    $e->getErrorCount(); // 2
    $e->getEventName();  // 'risky'
    $e->getErrors();     // [RuntimeException, DomainException]
}
```

```php
// 无论采用哪种策略，都可通过 onError 接收异常
$dispatcher->onError(function (object $event, \Throwable $e) {
    logger()->error($e->getMessage(), ['event' => $event::class]);
});
```

## 短路派发 until

`until()` 沿责任链派发，一旦某个监听器返回非 `null` 值立即返回该值并停止后续监听器，
常用于「首个命中即返回」的场景。

```php
$dispatcher->listen('cache.resolve', fn() => null);
$dispatcher->listen('cache.resolve', fn() => 'hit');

$value = $dispatcher->until('cache.resolve'); // 'hit'，后续监听器不会执行
```

## 运行指标 DispatcherStats

通过 `enableStats()` 开启后，调度器会采集每次派发的次数、耗时、监听器调用数与异常数，
并记录超过阈值的慢事件，便于线上可观测与性能排查。

```php
$dispatcher = (new Dispatcher())->enableStats(thresholdMs: 100.0);
$dispatcher->listen('measured', fn() => null);
$dispatcher->dispatch(new Event('measured'));

$stats = $dispatcher->getStats();
$stats->getTotalDispatches(); // 1
$stats->getCount('measured'); // 1
$stats->getMetrics();         // 按事件名聚合
$stats->getSlowEvents();      // 超过阈值的慢事件
$stats->getTopByTotalTime();  // 按总耗时 TopN
$stats->toArray();            // 可序列化摘要
```

### 不可变指标快照

`snapshot()` 返回 `readonly` 的 `StatsSnapshot` 对象，提供一次性的聚合视图，
适合在异步 / 并发上下文中传递冻结数据（如日志上报、链路追踪），调用方无法中途篡改。

```php
$snapshot = $dispatcher->getStats()->snapshot();
$snapshot->totalDispatches; // 总派发次数
$snapshot->totalErrors;     // 累计异常数
$snapshot->averageMs;       // 平均耗时（毫秒）
$snapshot->metrics;         // 各事件名聚合指标
```

## 命名事件 NamedEventInterface

实现 `NamedEventInterface` 的任意对象会按其 `getName()` 返回的事件名路由，
无需继承 `Event` 即可参与事件系统。

```php
use Kode\Event\NamedEventInterface;

class OrderShipped implements NamedEventInterface
{
    public function getName(): string
    {
        return 'order.shipped';
    }
}

$dispatcher->listen('order.shipped', fn() => /* ... */);
$dispatcher->dispatch(new OrderShipped()); // 命中
```

## 递归深度保护

为防止事件在监听器内递归派发导致的栈溢出，调度器内置最大递归深度（默认 `32`）。
超过上限会抛出 `Kode\Event\Exception\PropagationException`。

```php
$dispatcher = (new Dispatcher())->setMaxDepth(16);
$dispatcher->getMaxDepth(); // 16

$dispatcher->listen('recursive', fn($e) => $dispatcher->dispatch(new Event('recursive')));
// 超过深度后抛出 PropagationException
```

## 派发钩子

可在派发前后插入钩子：前置钩子可返回新的事件对象以替换原事件，后置钩子用于善后或观测。

```php
$dispatcher->addPreDispatcher(function (object $event): object {
    // 返回新对象以替换，返回原对象或 null 则保持
    return $event;
});

$dispatcher->addPostDispatcher(function (object $event): void {
    // 派发完成后的统一处理
});
```

## JSON 序列化

事件天然实现 `JsonSerializable`，可一键序列化为 JSON，便于跨进程传输、入队与重放。
反序列化使用 **PHP 8.3 的 `json_validate()`** 在解析前做轻量校验，无效载荷会抛出
`Kode\Event\Exception\InvalidEventException`，避免把脏数据交给 `json_decode`。

```php
use Kode\Event\Event;

$event = (new Event('user.created', ['id' => 7]))
    ->setTraceId('trace-123')
    ->setMeta('source', 'api');
// 支持 JSON_UNESCAPED_UNICODE 等 json_encode 标志位
$json = $event->toJson(JSON_UNESCAPED_UNICODE);

// 从 JSON 还原（校验失败抛 InvalidEventException）
$restored = Event::fromJson($json);
$restored->getName();   // 'user.created'
$restored->get('id');   // 7
$restored->getTraceId(); // 'trace-123'

// 也可从关联数组重建
$same = Event::fromArray(['name' => 'user.created', 'data' => ['id' => 7]]);
```

`AsyncEvent` 与 `ImmutableEvent` 同样支持，且 `AsyncEvent` 会一并携带 `delay` / `queue` /
`context` / `jobId` 等异步专属字段：

```php
use Kode\Event\Queue\AsyncEvent;

$event = (new AsyncEvent('mail.send', ['to' => 'a@b.com'], 30, 'emails'))
    ->setContext(['retry' => 2]);
$json = $event->toJson();

$restored = AsyncEvent::fromJson($json);
$restored->getDelay();  // 30
$restored->getQueue();  // 'emails'
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

> **健壮性说明**：`QueueDispatcher` 内部 push / pop / delete 统一使用 `getQueueName()` 前缀，
> 确保入队与消费指向同一队列；消费时通过 `try/finally` 保证任务被删除，监听器抛异常也不会导致
> 任务残留重复消费；无法解析的 job（类不存在 / 不可实例化）会被直接丢弃（毒丸隔离），
> 不会永久阻塞队列。`AsyncEvent::getJob()` 返回 `static::class`，不同异步事件类对应不同 job。

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

> **行为说明**：`AspectEventDispatcher` 把真正的派发委派给底层 `Dispatcher`，完整保留深度控制、
> 一次性派发、错误策略（THROW/COLLECT/IGNORE）、钩子、外部 PSR-14 提供者、运行指标与链路追踪等
> 全部能力；非 `Event` 对象也能被正确派发。切面 pointcut 支持通配符（`user.*`、`*` 等），
> 闭包切面仅被调用一次（不会重复触发）。

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

## 性能压测

本库内置压测脚本 `benchmarks/bench.php`，用于在同一环境下对比优化前后的派发吞吐：

```bash
php benchmarks/bench.php            # 运行全部压测并输出表格
php benchmarks/bench.php --json     # 额外导出 benchmarks/benchmark-latest.json
```

> 结果受本机 CPU / PHP 版本 / 系统负载影响，仅用于「同一环境下优化前后」的横向对比，
> 不代表绝对性能基准。基线数据见 `benchmarks/benchmark-baseline-v1.13.0.json`。

### v1.14.0 优化（相对 v1.13.0 基线，PHP 8.3.33）

| 场景 | v1.13.0 (ops/sec) | v1.14.0 (ops/sec) | 提升 |
| --- | --- | --- | --- |
| 基础派发（无监听器） | 2,904,748 | 3,069,762 | +5.7% |
| 派发 +1 监听器 | 2,225,230 | 2,206,206 | ≈ 持平* |
| 派发 +10 监听器 | 2,172,683 | 2,188,390 | +0.7% |
| 通配符派发（命中 `user.*`） | 2,125,279 | 2,249,087 | +5.8% |
| **大量不同事件名（缓存未命中密集）** | **1,019** | **1,894** | **+85.9%** |
| 批量注册 2000 监听器 | 1,301 | 1,348 | +3.6% |
| 注册后派发 | 1,286 | 1,337 | +4.0% |
| `until()` 短路派发 | 2,255,986 | 2,469,649 | +9.5% |
| `DeferredDispatcher` defer+process | 1,926,694 | 2,069,239 | +7.4% |

\* 微基准噪声范围内的正常波动，无回归。

### v1.15.0 优化（累计相对 v1.13.0 基线，PHP 8.3.33）

v1.15.0 在 v1.14.0 基础上叠加了「惰性排序 / 切面匹配缓存 / 类层级缓存 / 数据快照 / 延迟调度有序化 /
追踪调用精简」等优化，并修复了 5 处审计发现的缺陷（见 [CHANGELOG](CHANGELOG.md)）：

| 场景 | v1.13.0 (ops/sec) | v1.15.0 (ops/sec) | 提升 |
| --- | --- | --- | --- |
| 基础派发（无监听器） | 2,904,748 | 3,141,963 | +8.2% |
| 派发 +1 监听器 | 2,225,230 | 2,487,768 | +11.8% |
| 派发 +10 监听器 | 2,172,683 | 2,434,419 | +12.0% |
| 通配符派发（命中 `user.*`） | 2,125,279 | 2,471,282 | +16.3% |
| **大量不同事件名（缓存未命中密集）** | **1,019** | **1,969** | **+93.3%** |
| **批量注册 2000 监听器** | **1,301** | **1,571** | **+20.8%** |
| 注册后派发 | 1,286 | 1,560 | +21.3% |
| `until()` 短路派发 | 2,255,986 | 2,630,161 | +16.6% |
| `DeferredDispatcher` defer+process | 1,926,694 | 1,875,040 | ≈ 持平* |
| `new Event(name,data)` | 13,549,573 | 14,204,276 | +4.8% |
| `Event::get("a.b.c")` 点路径 | 5,718,864 | 5,952,977 | +4.1% |

\* 单元素 `defer+process` 在噪声范围内持平；当待处理集较大、到期项较少时，`process()` 的「只收集到期项 +
按 `dispatchAt` 升序」策略相比旧版全表扫描 + 整表 COW 复制有显著优势，且修正了延迟语义（delay 小的先触发）。

### 本轮优化点

- **`ListenerRegistry::getListeners` 避免冗余排序**：精确桶在注册时已排序，仅当命中通配符
  （需合并不同桶）才重新 `usort`；缓存未命中路径上对单桶结果不再做无谓排序，
  这是「大量不同事件名」场景提升约 86% 的主因。
- **排序比较器提取为静态方法**：`sortBucket` 不再每次分配闭包，配合上条进一步降低排序开销。
- **`invalidateCache` 精准失效对象缓存**：用独立的 `$objectCacheKeys` 集合替代全表扫描
  `resolvedCache`，注册 / 注销监听器时仅失效对象事件条目（仍保留「任意注册都失效全部对象缓存」
  的正确性语义，见 v1.13.0 C4 修复），注册量越大收益越明显。
- **`Dispatcher::dispatch` / `until` 去重事件名计算**：移除了前置钩子前的一次冗余
  `describe()` 调用，热路径上每个事件仅计算一次可读标识。

#### v1.15.0 新增优化点

- **`ListenerRegistry` 监听器注册惰性排序（OPT-1）**：注册阶段仅追加条目并打 `dirty` 标记，
  排序延迟到首次 `getListeners()` 读取时执行一次（结果会进入解析缓存）。彻底消除「同事件大量注册」
  场景下每次 `listen()` 都 `usort` 的 O(n²·log n) 开销，使「批量注册 2000 监听器」提升约 20%。
- **`AspectEventDispatcher` 切面匹配缓存（OPT-2）**：按事件名缓存命中的切入点表达式列表，
  每次派发从「对全部切面逐一 `preg_match`」降为一次数组查表；切面数量越多收益越大。
- **`ListenerRegistry` 类层级缓存 + 正则 FIFO 淘汰（OPT-5/6）**：`resolveObjectKeys()` 按类名缓存
  （类层级不可变，命中即免一次 `class_parents` / `class_implements` 全量解析）；`compilePattern()` 正则缓存
  从「填满即整表清空」改为 FIFO 单条淘汰，消除周期性重编译抖动。
- **`EventSchema` 数据快照（OPT-4）**：`validateEvent()` / `explain()` 开头一次性 `getData()` 快照，
  后续用 `array_key_exists` / 直接索引替代逐字段 `has()` + `get()` 的双次方法调用与查表。
- **`DeferredDispatcher` 有序延迟调度（OPT-8）**：`process()` 先收集到期任务、按 `dispatchAt` 升序派发，
  未到期任务保持不动，避免遍历中对整张待处理表做 COW 复制，并修正延迟语义（delay 小的先触发）。
- **`DistributedEventTracer::propagate` 追踪调用精简（OPT-3）**：复用 `injectToEvent()` 返回的头部，
  去掉一次多余的 `toTraceparent()` 调用，每次派发的上下文调用由两次降到一次。
- **`Dispatcher` 指标采集懒计时（OPT-7）**：`stats` 未启用时不调用 `hrtime()`，并将「是否可停止传播」
  的判断在循环前提炼为局部布尔，减少热路径上的重复 `instanceof`。

#### v1.16.0 新增优化点

- **`DeferredDispatcher` 有序索引 + 早停（堆结构思路）**：`process()` 此前每次对整张待处理表做
  O(n) 全量扫描以找出到期任务。v1.16.0 改为按 `dispatchAt` 升序维护 `order` 索引，`process()` 从队首
  取到期任务、**遇到首个未到期任务即早停**，扫描量仅与到期项数量相关，与待处理集规模无关。
  - 绝大多数 `defer` 的 `dispatchAt` 为当前最大（时间向前推进），走尾部追加 O(1) 快路径；
    仅 `deferAt` 指定更早时间等罕见场景才从末尾向前定位插入点。
  - `cancel()` 同步从索引中移除对应 id，不影响其余任务顺序。

  效果（PHP 8.3.33，单次 `process()` 中位数，50000 远未来任务 + 50 到期）：

  | 实现 | 单次 process() 中位数 | 相对 |
  | --- | --- | --- |
  | v1.15.0 全量扫描 | 3.930 ms | 1× |
  | v1.16.0 有序索引早停 | 0.071 ms | **≈ 55×** |

  小用例（`defer + process` 单元素）因索引维护开销有约 6% 的微幅回落（1.78M → 1.67M ops/sec），
  在噪声范围内，远小于大待处理集的收益，属可接受的权衡。

#### v1.17.0 新增优化点

- **`DeferredDispatcher::cancel` 降为 O(1)**：v1.16.0 的 cancel 每次都 `array_search` + `array_values`
  重建 `order` 索引（O(n)），在「大待处理集 + 频繁取消」场景下累积退化到 O(n²)。v1.17.0 改为
  **仅 `unset` 任务本体（O(1)）**，被取消的 id 在 `order` 中仅留占位，`process()` 遍历时跳过，
  累积的幽灵条目在下次 `process()` 中一次性压缩回收。`pending()` / `count()` / `getJob()` / `process()`
  语义与纯 Map 实现完全一致。

  效果（PHP 8.3.33，20000 待处理任务中散布取消 19900 次，每轮计时 cancel 调用本身）：

  | 实现 | 取消吞吐 | 相对 |
  | --- | --- | --- |
  | v1.16.0（每次重建索引） | 113,811 ops/sec | 1× |
  | v1.17.0（仅 unset 本体） | 3,939,326 ops/sec | **≈ 34.6×** |

- **审计后主动放弃的通配符匹配缓存**：评估过为 `ListenerRegistry::matchWildcard` 增加 `(pattern,event)=>bool`
  结果缓存，以规避高频事件名下的重复 `preg_match`。压测（30 通配符 + 1800 名 ×5 轮 `getListeners`）显示
  **反而略慢**（7,305 → 6,455 ops/sec）——因为 `resolvedCache` 已拦截重复事件名，仅当 `resolvedCache`
  被容量淘汰重算时才触及 `matchWildcard`，而缓存查询的额外开销抵消了 `preg_match` 的节省；且 pattern 内的
  event 数组若无上限会导致内存膨胀。遵循「压测驱动、不为优化而优化」的原则，已撤销该改动。

#### v1.18.0 新增优化点

- **`DeferredDispatcher::deferBackfill()` 历史回填批量前插**：针对「事件溯源重放 / 历史补调度 / 批量回填」
  等一次性插入大量 `dispatchAt` 早于现有任务的场景。逐个 `deferAt()` 每次前插都要 `array_splice` 搬移 O(n)，
  批量回填 m 个任务退化到 O(m·n)。v1.18.0 的 `deferBackfill()` 改为先按 `dispatchAt` 升序排序，再与现有
  `order` 索引做单次归并（O(m·log m + n + m)）：
  - 全部晚于现有任务 → 直接追加（O(m)）；
  - 全部早于现有任务（历史回填最常见情形）→ 纯前插（O(m)）；
  - 交错情形 → 两段均有序，单次有序归并（O(n + m)）。
  相等 `dispatchAt` 以现有任务优先，与逐个 `enqueue` 语义一致。

  效果（PHP 8.3.33，20000 远未来任务 + 回填 20000 个历史事件）：

  | 实现 | 回填 20000 任务耗时（中位数） | 相对 |
  | --- | --- | --- |
  | 循环 deferAt()（每次前插 O(n)，整体 O(m·n)） | 7,284 ms | 1× |
  | deferBackfill()（排序 + 单次归并 O(m·log m + n + m)） | 5.354 ms | **≈ 1360×** |

### v1.20.0 优化点：resolveEntriesForObject 解析源收敛

对象事件 / NamedEvent 的监听器解析统一收敛到 `getListeners()`，消除对象分支中手写的「按 key 遍历通配符 + 排序」重复逻辑（方向 ③）：

- 每个 key（类名 / 父类 / 接口，或 NamedEvent 的事件名）直接复用 `getListeners($key)`，其内置的通配符正则缓存、单键排序与 `resolvedCache` 单键缓存一并生效，跨派发复用；
- 跨 key 用 `seq` 去重（同一 entry 经多个 key 命中只触发一次），外部 PSR-14 提供者在合并结果后置；
- **providers 存在时仍不缓存**合并结果，保留其动态增减的语义；
- 首要收益是「解析逻辑单一来源」——后续对 `getListeners` 的修复与优化自动覆盖对象事件路径。

效果（PHP 8.3.33，对象事件深层级重复派发）：热缓存路径 ≈ 1.59M ops/sec，与重构前持平略优；冷启动因基准每次重建 registry 不具生产代表性。

### v1.21.0 增强：退避抖动 + 事件溯源崩溃恢复

- **退避抖动（jitter）**：`RetryListener` 新增 `jitter`（0~1 比例）参数，实际退避 = 基础退避 × (1 ± jitter 随机扰动)，缓解大量失败时的「重试惊群」；提供 `setRng()` 注入确定性随机源以便测试，并校验 jitter∈[0,1]。纯计算增强，不引入额外 I/O 或阻塞（`computeDelay()` 可单测）。
- **事件溯源崩溃恢复压测**：`FileEventStore` 单写入者场景下，写入 5 万事件后模拟崩溃（截断末行半行 JSON），全新实例重载跳过损坏行并重建出全部 5 万条有效事件；恢复耗时 41.4 ms vs 干净基线 41.9 ms，损坏行跳过几乎零开销。

### v1.22.0 增强：FileEventStore 批量写入 + 流式加载

- `EventStoreInterface` 新增 `appendBatch()`（批量原子写入）与 `stream()`（生成器流式遍历，O(1) 内存），`FileEventStore` / `InMemoryEventStore` 双实现。
- `appendBatch` 压测 20 万事件 **4190ms → 221ms（≈18.9×）**；`stream()` 峰值内存增量 **0 KB**（对照 `all()` 全量物化 ≈ 40 MB），适合超大日志重放 / 批量导入。

### v1.23.0 增强：RetryListener 退避策略工厂

新增两个静态工厂，直接作为 `RetryListener` 构造器的 `backoff` 参数（返回 `callable(int $attempt): int`），与固定毫秒 / 自定义 callable / jitter 无缝组合：

- `RetryListener::exponentialBackoff($baseMs, $factor=2.0, $capMs=PHP_INT_MAX)`：指数退避 `base × factor^(attempt−1)`，截断到上限；**超大 attempt 下幂溢出为 INF 时安全回落到 `$capMs`**（而非被 `max(0,…)` 误算为 0）。
- `RetryListener::decorrelatedJitterBackoff($baseMs=100, $capMs=10000)`：AWS 风格去相关抖动，`sleep = random(base, prev×3)`，让重试者更快错峰，进一步缓解重试惊群；退避同样截断到上限。

压测（PHP 8.3.33，dispatch ×200,000）：`RetryListener` 成功路径装饰开销约 **11.4%**（裸监听 66.4ms → 包裹 73.9ms）；`exponentialBackoff` 生成 1000 次序列仅 0.06ms。

### 性能注意事项

- 同一事件名重复派发命中解析缓存（默认上限 `ListenerRegistry::MAX_CACHE_ENTRIES = 512`），
  热路径接近 O(1)；**动态生成大量不同事件名**会触发缓存未命中与重复排序/通配符匹配，
  建议复用有限的事件名或控制动态事件名规模。
- 通配符监听器（`user.*` 等）在缓存未命中时需逐个 `preg_match` 匹配，监听器注册阶段即完成
  正则编译并缓存（`ListenerRegistry::compilePattern`）；通配符模式过多会拖慢首次派发。
- 对象事件（PSR-14 类型化对象）按「类名 + 父类 + 接口」解析，结果同样带缓存；
  解析键通过 PHP 内部的 `class_parents` / `class_implements` 计算，二者均有内部缓存。
- `DeferredDispatcher` 的 `order` 索引按 `dispatchAt` 升序维护，`process()` 早停，因此
  **待处理集越大、到期项越少，单次 `process()` 的相对收益越高**；`defer` 的追加为 O(1)，
  仅指定早于已有任务的 `dispatchAt` 且为零散单条 `deferAt` 时才需 O(n) 前插（罕见）。
  **批量历史回填**请改用 `deferBackfill()`（v1.18.0）：一次排序 + 单次归并，复杂度由 O(m·n) 降为
  O(m·log m + n + m)，「全部早于现有任务」的常见回填情形更可走 O(m) 纯前插（详见 v1.18.0 优化点）。

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
| `once(string $event, $listener, int $priority)` | 注册一次性监听器（触发后自动注销） |
| `unlisten(string $event, $listener)` | 注销监听器 |
| `subscribe(SubscriberInterface $subscriber)` | 注册订阅者 |
| `subscribeMany(array $subscribers)` | 批量注册订阅者 |
| `dispatch(Event\|string $event, array $data)` | 派发事件 |
| `dispatchEvent(Event\|string $event, array $data)` | 派发并返回强类型 Event |
| `dispatchMany(Event ...$events)` | 批量派发 |
| `until(Event\|string $event, array $data)` | 短路派发，返回首个非 null 结果 |
| `setErrorStrategy(ErrorStrategy $s)` | 设置监听器异常处理策略 |
| `getErrorStrategy()` | 获取当前异常处理策略 |
| `onError(callable $handler)` | 注册异常回调钩子 |
| `addPreDispatcher(callable $hook)` | 添加前置派发钩子 |
| `addPostDispatcher(callable $hook)` | 添加后置派发钩子 |
| `setMaxDepth(int $depth)` | 设置最大递归派发深度 |
| `getMaxDepth()` / `getDepth()` | 获取最大/当前递归深度 |
| `enableStats(float $slowThresholdMs)` | 开启运行指标采集 |
| `disableStats()` / `getStats()` | 关闭 / 获取运行指标 |
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

### EventPipeline

| 方法 | 说明 |
|------|------|
| `create(Event $event)` | 创建管道 |
| `pipe(callable $transform)` | 添加变换步骤 |
| `filter(callable $predicate)` | 添加过滤条件 |
| `map(callable $mapper)` | 添加映射变换 |
| `tap(callable $callback)` | 添加调试回调 |
| `stop()` | 停止管道 |
| `execute()` | 执行管道 |
| `then(callable $callback)` | 执行并回调 |
| `dispatch(Dispatcher $dispatcher)` | 执行并派发 |

### EventHooks

| 方法 | 说明 |
|------|------|
| `before(callable $hook, int $priority)` | 添加前置钩子 |
| `after(callable $hook, int $priority)` | 添加后置钩子 |
| `error(callable $hook, int $priority)` | 添加错误钩子 |
| `removeBefore(callable $hook)` | 移除前置钩子 |
| `removeAfter(callable $hook)` | 移除后置钩子 |
| `removeError(callable $hook)` | 移除错误钩子 |
| `triggerBefore(Event $event)` | 触发前置钩子 |
| `triggerAfter(Event $event)` | 触发后置钩子 |
| `triggerError(Event $event, Throwable $e)` | 触发错误钩子 |
| `clear(?string $type)` | 清空钩子 |

## 项目结构

```
src/
├── Attribute/                        # PHP 8+ 属性
│   ├── Listener.php                # 监听器属性
│   ├── Priority.php                 # 优先级属性
│   └── Subscriber.php              # 订阅者属性
├── Exception/                       # 异常
│   ├── EventException.php          # 基础异常
│   ├── EventDispatchException.php  # 派发聚合异常（COLLECT 策略）
│   ├── InvalidEventException.php   # 事件名 / 事件对象非法
│   ├── ListenerException.php       # 监听器非法
│   └── PropagationException.php    # 递归深度超限等传播异常
├── Event.php                        # 基础事件类（实现 NamedEventInterface / StoppableEventInterface）
├── NamedEventInterface.php          # 命名事件接口（按 getName() 路由）
├── StoppableEventInterface.php     # 可停止传播接口
├── DispatcherInterface.php          # 调度器契约
├── Dispatcher.php                   # 事件调度器（实现 DispatcherInterface + PSR-14）
├── DispatcherStats.php             # 运行指标采集
├── ErrorStrategy.php               # 监听器异常处理策略枚举
├── ListenerRegistry.php            # 监听器注册表（实现 PSR-14 ListenerProviderInterface）
├── AbstractEvent.php               # 抽象事件类
├── AbstractListener.php            # 监听器抽象类
├── AttributeListenerRegistry.php   # 属性监听器注册器
├── BatchEventBuilder.php           # 批量事件构建器
├── DeferredDispatcher.php          # 延迟派发调度器
├── Dispatcher.php                   # 事件调度器
├── EventBubbles.php                # 事件冒泡
├── EventBuilder.php                # 事件构建器
├── EventDispatcherTrait.php       # 事件调度特性
├── EventFilter.php                 # 事件过滤器
├── EventGroup.php                  # 事件组
├── EventHelper.php                 # 事件助手
├── EventHooks.php                  # 生命周期钩子
├── EventInterceptorInterface.php   # 拦截器接口
├── EventListenerTrait.php         # 监听器特性
├── EventMiddleware.php            # 事件中间件
├── EventMiddlewareInterface.php   # 中间件契约
├── LoggingMiddleware.php          # 日志中间件（写入内部缓冲，可导出）
├── ValidationMiddleware.php       # 验证中间件（基于通配符规则）
├── EventNames.php                 # 事件名称常量
├── EventPipeline.php              # 事件管道
├── EventPriority.php              # 事件优先级枚举
├── EventReplay.php               # 事件重放
├── EventSchema.php               # 事件验证
├── EventSchemaRegistry.php        # 事件 Schema 注册表
├── EventTracer.php                # 事件追踪器
├── ImmutableEvent.php            # 不可变事件
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
