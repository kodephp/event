# 变更日志（随仓库发布）

> 本文件随版本发布进入仓库，记录每个正式版本的变更摘要。

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
