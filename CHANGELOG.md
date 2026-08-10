# 变更日志（随仓库发布）

> 本文件随版本发布进入仓库，记录每个正式版本的变更摘要。

## v1.14.0
- **性能优化（压测驱动）**：新增 `benchmarks/bench.php` 压测脚本与 `benchmarks/benchmark-baseline-v1.13.0.json`
  基线数据；在同机（PHP 8.3.33）对比验证。
- **`ListenerRegistry::getListeners` 避免冗余排序**：精确桶在注册时已排序，仅当命中通配符
  （需合并不同桶）才重新 `usort`；缓存未命中路径上对单桶结果不再做无谓排序。
  「大量不同事件名（缓存未命中密集）」场景吞吐 **+85.9%**（1019 → 1894 ops/sec）。
- **排序比较器提取为静态方法**：`sortBucket` 改用静态 `compareEntries()`，不再每次分配闭包。
- **`invalidateCache` 精准失效对象缓存**：新增独立 `$objectCacheKeys` 集合，注册 / 注销监听器时
  仅失效对象事件缓存条目，替代原全表扫描 `resolvedCache`；仍保留「任意注册都失效全部对象缓存」的
  正确性语义（见 v1.13.0 C4 修复），注册量越大收益越明显（批量注册 +3.6%、注册后派发 +4.0%）。
- **`Dispatcher::dispatch` / `until` 去重事件名计算**：移除前置钩子前的一次冗余 `describe()` 调用，
  热路径上每个事件仅计算一次可读标识（`until` 短路 **+9.5%**、通配符派发 **+5.8%**、基础派发 **+5.7%**）。
- **文档**：`README.md` 新增「性能压测」章节（含优化前后对比表与性能注意事项）；
  `benchmarks/README.md` 记录运行方式与基线数据；`.gitignore` 忽略易变的 `benchmark-latest.json`。
- **测试**：新增 `tests/PerfOptimizationTest.php`（3 项）锁定排序跳过 / 通配符合并排序 / 注册排序行为；
  全量套件 **240 测试 / 557 断言全绿**（原 237 → 240）。

## v1.13.0
- **`AspectEventDispatcher` 审计修复**：重写 `dispatch()`，把真正的派发委派给 `parent::dispatch`，
  完整保留深度/一次性/错误策略/钩子/提供者/统计/追踪器等全部能力；非 `Event` 对象现在也能被正确派发。
  修复切点（pointcut）通配符匹配（复用 `ListenerRegistry::compilePattern` 正则），并修复闭包切面被
  双重调用的问题（改用 `$aspect instanceof \Closure` 精确判断）。
- **`QueueDispatcher` 审计修复**：修复队列前缀不一致导致事件从未被消费的致命 Bug（push 用 `getQueueName`
  前缀，pop/delete 之前未用 → 事件永远卡在队列）。修复「毒丸」：无法解析的任务（job 类不存在/不可实例化）
  现在会被直接删除而不是永久阻塞；监听器抛出异常时通过 `try/finally` 确保任务被删除，避免重复消费。
  `resolveEvent()` 现在能正确剥开驱动层包裹的 `{job,data,queue,delay}` 信封（兼容 `data`/`body` 两种嵌套）。
- **`ListenerRegistry` 缓存失效修复**：注册接口/父类监听器（在已有具体类派发之后）时，现同时清除所有
  `"\0obj\0"` 前缀的对象事件缓存，避免新增的接口/父类监听器不生效。
- **`AsyncEvent` 序列化合身修复**：`getJob()` 此前硬编码返回字符串（导致所有异步事件被当成同一 job）；
  `fromPayload()` 此前用 `new self`、未恢复 `delay`、空 `jobId` 直接崩溃。现已改为 `static::class`、
  复用 `new static`、恢复 `delay`、并对 `jobId`/`queue` 做 `(string)` 兜底。
- **`EventBuilder` 数据污染修复**：`traceId` 与 `metadata` 不再泄漏进业务 `data`（改为通过 `setTraceId` /
  `setMeta` 写入事件元数据通道）。
- **`Event` / `AbstractEvent` 数据正确性修复**：`fromArray()` 新增 `data` 必须为数组的健壮校验，缺失或
  非数组直接抛 `InvalidEventException`；`toArray()` 补齐此前被丢弃的 `stop_reason`。
- **`ImmutableEvent` 语义对齐**：`has()` 改用 `array_key_exists`（修正 null 语义），`get()` 支持 `a.b.c`
  点路径（与 `Event` 一致）；`with` / `withData` / `withStopped` / `create` / `fromArray` 返回值由 `self`
  改为 `static`（协变，支持子类）；新增 `toArray()`。
- **`EventReplay` 静默空转修复**：重放一个已被 `stopPropagation` 的实例会变成无声 no-op；新增 `replayOne()`
  在派发前 `clone` + `resumePropagation()`，并对循环次数做上限保护；`import()` 校验 `name`/`data`。
- **`EventPipeline` 类型修复**：`dispatch()` 声明非空返回却在过滤短路时返回 null → `TypeError`；现改为
  `?Event`，与短路边际一致。
- **`EventSchema::validateDetailed` 修复**：同名事件的多个失败原因此前会互相覆盖，现按事件名聚合为
  `$failures[$name][] = $reason`。
- **`EventSchemaRegistry` 拆文件**：从 `EventSchema.php` 中拆分到独立 PSR-4 文件 `src/EventSchemaRegistry.php`，
  解决「该类需手动类加载才能 autoload」的 PSR-4 约束违规（现 `class_exists` 即自动加载，优化 autoloader 含 1500+
  类）。
- **依赖**：`composer.json` 新增 `ext-mbstring`（运行时 `InvalidEventException::invalidJson` 使用 `mb_strimwidth`）。
- **测试**：新增 `tests/HardeningTest.php`（14 项 / 32 断言）覆盖上述全部回归点；全量套件
  **237 测试 / 553 断言全绿**。
- 全程仅使用本机可完整测试的 PHP 8.3+ 特性；类型化类常量仍因本机构建残缺暂未加入。

## v1.12.0
- **`DistributedEventTracer` 可自动接线到 `Dispatcher`**：新增 `Dispatcher::setTracer()` /
  `getTracer()`，注入追踪器后每次派发的 `Event` 会自动携带 W3C `traceparent`
  （当前无活动链路时自动开启一条），无需在业务代码里手动注入；类型化事件对象（非 `Event`
  实例）不会被注入，保持原样。新增 `DistributedEventTracer::propagate()` 便捷方法
  （确保链路存在并注入事件，返回 traceparent）。
- **文档与版本一致性修正**：`README` 环境要求中 `kode/context` 依赖下限由错误的 `^2.0`
  更正为 `^3.1`；新增「自动接线到 Dispatcher」与 `DistributedEventTracer` API 表格；
  本 `CHANGELOG.md` 改为随仓库发布（此前仅本地维护）。
- 全程仅使用本机可完整测试的 PHP 8.3+ 特性；类型化类常量仍因本机构建残缺暂未加入。

## v1.11.0
- **依赖 `kode/context` 升级至 `^3.1`**（下限由 `^2.0` 提升）：采用 context 3.0 的
  W3C Trace Context 标准与 3.1 的组合键检查 / 事务作用域能力。
- **新增 `DistributedEventTracer`**：基于 `Kode\Context\Context` 的 W3C 链路追踪，
  支持 `startTrace()` / `injectToEvent()`（注入 `traceparent`/`tracestate`）/
  `extractFromEvent()`（跨进程重建链路）/`getTraceparent()` / `getTraceInfo()` /
  `trace()`，使事件在异步队列 / RPC 边界仍携带统一追踪上下文，可与 OpenTelemetry 互通。
- **`ContextStorage` 升级**：`getEventTimestamp()` 使用 `KodeContext::getInt()` 类型安全
  访问；`isEventContextPresent()` 使用 `KodeContext::hasAll()` 组合键检查；
  `withEventContext()` 使用 `KodeContext::transaction()` 事务作用域自动回滚。
- 全程仅使用本机可完整测试的 PHP 8.3+ 特性；类型化类常量仍因本机构建残缺暂未加入。

## v1.10.0
- **PHP 8.4 数组函数 polyfill + 集成**：新增 `src/Php84Functions.php`，在 PHP < 8.4 环境
  提供与官方语义一致的 `array_find` / `array_find_key` / `array_any` / `array_all`
  （经 `composer.json` `autoload.files` 随包分发，8.4+ 上自动让位给原生实现）。
- **`Php84Features` 特性探测**：与 `Php85Features` 互补，运行时探测 8.4 数组函数、
  属性钩子、非对称可见性、惰性对象等能力。
- **`EventPredicates` 谓词组合器**：基于 `array_all` / `array_any` 提供 `all()`（AND）/
  `any()`（OR）/`none()`（NOR）以及 `allSchemas()` / `anySchemas()`，便于表达复杂校验语义。
- **`EventSchema` 多规则 + 诊断**：`validate()` 升级为可追加多条规则（AND 关系，底层 `array_all`）；
  新增 `addRule()` 与 `explain()`（返回首个未通过原因）。
- **`EventSchemaRegistry` 智能搜索**：新增 `findFirstInvalid()` / `findFirstInvalidName()`
  （基于 `array_find` / `array_find_key`）与 `validateDetailed()`，返回只读 `ValidationResult` DTO
  （`allValid` / `failures` / `total` / `passed` / `failed`），适合批量派发前的快速校验。
- **`ValidationMiddleware` 重写**：匹配逻辑改用 `array_filter` + `array_find_key` + `array_all`，
  先筛出命中的精确/通配符规则模式，再精确报告首个未通过的校验器序号，行为等价且更健壮。
- 全程仅使用本机可完整测试的 PHP 8.3+ 特性；类型化类常量仍因本机构建残缺暂未加入。

## v1.9.0
- **外部 PSR-14 提供者聚合**：`ListenerRegistry::addProvider()` / `Dispatcher::addProvider()`
  支持把任意第三方 PSR-14 `ListenerProviderInterface` 接入调度器，实现跨系统事件互操作；
  命中的命名事件与类型化对象事件都会触发外部监听器，并提供 `getProviders()` /
  `hasProviders()` / `clearProviders()`。
- **`EventNames::all()`**：利用 PHP 8.3 动态类常量获取（`self::{$name}`）枚举全部内置事件名，
  新增常量后自动包含，无需手工维护列表。
- **`StatsSnapshot` 只读快照**：`DispatcherStats::snapshot()` 返回 `readonly` DTO，
  提供不可变的聚合指标视图，适合日志上报 / 链路追踪上下文传递。
- 全程基于「本机可完整测试」的 PHP 8.3 特性实现；类型化类常量（`const X: int`）因本机
  PHP 构建残缺无法解析，暂未加入（详见 v1.7.0 说明）。

## v1.8.0
- 事件 JSON 序列化（`json_validate` 校验），支撑跨进程传输与重放。

## v1.7.0
- 最低支持 PHP 8.3；方法重写统一标注 `#[\Override]`；修复 `AspectEventDispatcher` 致命 Bug。

## v1.6.0
- 健壮化调度内核：PSR-14 互操作、错误处理策略（THROW/COLLECT/IGNORE）、
  运行指标、一次性/短路派发、深度保护、派发钩子。
