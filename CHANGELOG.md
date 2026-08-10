# 变更日志（随仓库发布）

> 本文件随版本发布进入仓库，记录每个正式版本的变更摘要。

## v1.22.0
- **FileEventStore 批量写入 + 流式加载（超大日志场景）**：
  - `EventStoreInterface` 新增 `appendBatch(array $entries)` 与 `stream(): \Generator`；`FileEventStore` / `InMemoryEventStore` 均实现。
  - `appendBatch` 一次性整块原子追加（FILE_APPEND \| LOCK_EX），减少 syscall 与锁竞争；压测 20 万事件 **4190ms → 221ms（≈18.9×）**。
  - `stream()` 惰性逐行读取（O(1) 内存），适合超大日志重放；峰值内存增量 **0 KB**（对照 `all()` 全量物化 ≈ 40 MB），损坏行跳过。

## v1.21.0
- **RetryListener 退避抖动（jitter）**：新增 `jitter`（0~1 比例）参数，实际退避 = 基础退避 × (1 ± jitter 随机扰动)，缓解重试风暴中的「惊群效应」；提供 `setRng()` 注入确定性随机源以便测试，校验 jitter∈[0,1]。
- **事件溯源崩溃恢复压测**：新增 `FileEventStore` 场景——写入 5 万事件后模拟崩溃（截断末行半行 JSON），重载恢复 5 万条并跳过损坏行，验证恢复路径几乎零开销（恢复 41.4ms vs 干净基线 41.9ms）。

## v1.20.0
- **resolveEntriesForObject 解析源收敛（方向 ③）**：对象事件 / NamedEvent 的监听器解析统一走 `getListeners()`，不再在对象分支手写「按 key 遍历通配符 + 排序」的重复逻辑；每个 key 的解析结果也借 `resolvedCache` 单键缓存，跨派发复用。
  - 行为等价：跨 key 用 `seq` 去重、外部 PSR-14 提供者在合并结果后置、providers 存在时仍不缓存（保留动态性）。
  - 稳健性收益：解析逻辑单一来源，后续对 `getListeners` 的修复/优化自动覆盖对象事件路径。
  - 压测（PHP 8.3.33，对象事件热缓存路径）：≈ 1.59M ops/sec，与重构前持平略优（冷启动因基准每次重建 registry 不具生产代表性）。

## v1.19.0
- **业务层增强：事件溯源（Event Sourcing）**：新增仅追加的事件日志抽象与实现，使事件流可持久化并重建。
  - `EventEnvelope`：不可变信封（全局序号 seq + 事件唯一 id + name/data/metadata/记录时间戳），构成事件流游标。
  - `EventStoreInterface`：仅追加日志契约（`append` / `all` / `from` / `last` / `count` / `clear`），实现可插拔。
  - `InMemoryEventStore`：进程内日志，适合测试与单进程重放。
  - `FileEventStore`：JSON Lines 文件后端，整行原子追加（FILE_APPEND | LOCK_EX），惰性加载并跳过损坏行，
    单写入者场景即可持久化与重建。
  - `EventReplay` 扩展：`setStore()` 挂载存储；`attach(Dispatcher)` 把每次派发的 Event 自动入账；
    `replayFromStore($from, $count)` 从持久化日志还原事件并重放（克隆 + 重置传播），用于读模型重建 / 下游修复。
- **业务层增强：重试 / 死信策略（Retry / Dead-Letter）**：
  - `DeadLetterSinkInterface`：死信接收器契约；`InMemoryDeadLetterSink`（进程内暂存）、
    `CallbackDeadLetterSink`（转发到回调，便于接入队列 / 数据库 / 监控）。
  - `DeadLetterEntry`：死信条目 DTO（事件 + 异常 + 尝试次数 + 移入时间戳）。
  - `RetryListener`：实现 `ListenerInterface` 的重试装饰器，包裹任意真实监听器（callable 或 ListenerInterface），
    按 `maxAttempts` 重试、`backoff`（固定毫秒或 `callable(int $attempt): int`）退避；重试耗尽后若注入死信接收器
    则投递并吞掉异常（不扩散到整条监听链），否则重抛交由调度器 `ErrorStrategy` 裁决。要求监听器幂等。
- **测试**：新增 `tests/EventSourcingTest.php`（12 项，覆盖内存/文件存储、序号、增量重放、损坏行跳过、自动入账、
  元数据恢复）与 `tests/RetryDeadLetterTest.php`（8 项，覆盖首试成功、重试至成功、耗尽重抛、耗尽入死信、退避回调、
  ListenerInterface 委托、参数校验、回调死信）；全量套件 **281 tests / 659 assertions** 通过。
- 全程仅使用本机可完整测试的 PHP 8.3+ 特性；`RetryListener` 因 PHP 8.3 不支持 `callable` 作为属性类型，
  采用无类型属性 + docblock（运行期仍是严格 callable/ListenerInterface）。

## v1.18.0
- **DeferredDispatcher 新增 `deferBackfill()` 历史回填批量前插**：针对「事件溯源重放 / 历史补调度 /
  批量回填」等一次性插入大量 `dispatchAt` 早于现有任务的场景。此前逐个调用 `deferAt()`，每次前插都要
  从队尾向前定位插入点并执行 `array_splice`（O(n) 搬移），批量回填 m 个任务退化到 O(m·n)。
  现 `deferBackfill()` 内部先按 `dispatchAt` 升序排序，再与现有 `order` 索引做单次归并：
  - 全部晚于现有任务 → 直接追加（O(m)）；
  - 全部早于现有任务（历史回填最常见情形）→ 纯前插（O(m)）；
  - 其余交错情形 → 两段均有序，单次有序归并（O(n + m)）。
  归并时相等 `dispatchAt` 以现有任务优先（与逐个 `enqueue` 的语义一致：晚注册者靠后）。
  - 效果（PHP 8.3.33，20000 远未来任务 + 回填 20000 个历史事件）：循环 `deferAt` 7,284 ms →
    `deferBackfill` 5.354 ms（**≈ 1360×**）。
- **测试**：在 `tests/DeferredOrderTest.php` 新增 6 项覆盖 `deferBackfill`（前插早于现有、返回 id 按
  dispatchAt 升序、空回填返回空、缺 event/timestamp 抛异常、交错归并后 order 索引仍有序、保留未来任务
  相对顺序）；全量套件 **261 tests / 613 assertions** 通过。
- **压测**：`benchmarks/bench.php` 新增「历史回填批量前插」场景（6d 节），量化 O(m·n) → O(m·log m + n + m) 的改善。

## v1.17.0
- **DeferredDispatcher::cancel 降为 O(1)**：此前 cancel 每次 `array_search` + `array_values` 重建 `order`
  索引（O(n)），大待处理集 + 频繁取消场景累积退化 O(n²)。现改为仅 `unset` 任务本体（O(1)），被取消 id 在
  `order` 中仅留占位，`process()` 遍历时跳过，幽灵条目在下次 `process()` 一次性压缩回收；
  `pending()` / `count()` / `getJob()` / `process()` 语义与纯 Map 实现完全一致。
  - 效果（PHP 8.3.33，20000 待处理中散布取消 19900 次）：113,811 → 3,939,326 ops/sec（≈ 34.6×）。
- 审计评估后**主动放弃**通配符匹配结果缓存：压测显示负收益（`resolvedCache` 已拦截重复事件名，缓存查询
  开销抵消 `preg_match` 节省；且 pattern 内 event 数组内存不受控），遵循「压测驱动、不为优化而优化」原则撤销。
- 新增压测场景 `20000 待处理 + 散布 cancel 19900 次`；全量测试 **254 tests / 602 assertions** 通过。

## v1.16.0
- **DeferredDispatcher 有序索引优化（堆结构思路）**：`process()` 此前每次对整张待处理表做 O(n) 全量扫描。
  现改为按 `dispatchAt` 升序维护 `order` 索引，`process()` 从队首取到期任务、遇首个未到期即早停，
  扫描量仅与到期项数量相关，与待处理集规模无关。
  - `enqueue()` 尾部追加 O(1) 快路径：绝大多数 `defer` 的 `dispatchAt` 为当前最大，直接追加；
    仅 `deferAt` 指定更早时间等罕见场景才从末尾向前定位插入点。
  - `cancel()` 同步从索引移除对应 id，不影响其余任务顺序；`processAll` / `pending` / `count` / `getJob` 行为不变。
  - 效果（PHP 8.3.33，50000 远未来 + 50 到期，单次 `process()` 中位数）：3.930 ms → 0.071 ms（≈ 55×）。
    小用例单元素 `defer+process` 因索引维护有约 6% 微幅回落（噪声范围内，可接受）。
- **测试**：新增 `tests/DeferredOrderTest.php`（5 项），覆盖到期顺序、cancel 不影响其余顺序、processAll、
  大待处理集未到期不派发、deferAt 早于已有任务时前插排序；全量 253 tests / 585 assertions 通过。
- **压测**：`benchmarks/bench.php` 新增「超大待处理集扫描」场景，量化大待处理集下 `process()` 扫描开销。

## v1.15.0
- **审计修复（5 项缺陷）**：
  - BUG-1：`Dispatcher` 开启 `stats` 时，前置钩子抛异常不再被 `finally` 中的 `TypeError` 掩盖原始异常
    （事件名改为在 `try` 之前即确定）。
  - BUG-2：`AspectEventDispatcher::until()` 此前绕过切面，现已同样触发前后置切面（责任链场景不再静默失效）。
  - BUG-3：`QueueDispatcher::processMany` 不再因队首毒丸任务（`process()` 返回 false）冻结整批消费，
    改以队列 `size()` 判断「是否还有任务」决定是否继续。
  - BUG-4：`EventReplay::replayReverse(0)` 不再因 `-0 === 0` 误重放全部事件。
  - BUG-5：`EventHelper::buildName` 不再把数字段名 `'0'` 当作空值丢弃（改用仅过滤空字符串的回调）。
- **性能优化（压测驱动，累计相对 v1.13.0 基线，PHP 8.3.33）**：
  - `ListenerRegistry` 注册**惰性排序**：注册仅追加 + 打 `dirty` 标记，排序延迟到首次读取时执行一次，
    消除同事件大量注册的 O(n²·log n) 排序开销（批量注册 2000 监听器 **+20.8%**、注册后派发 **+21.3%**）。
  - `AspectEventDispatcher` **切面匹配缓存**：按事件名缓存命中的切入点，派发从「全切面 `preg_match`」降为
    一次查表（`until` 短路 **+16.6%**、通配符派发 **+16.3%**）。
  - `ListenerRegistry` **类层级缓存**（`$keysByClass`）+ 正则 **FIFO 单条淘汰**，免去重复 `class_parents` /
    `class_implements` 解析、消除缓存填满时的周期性重编译抖动。
  - `EventSchema` 校验时 **`getData()` 一次性快照**，替代逐字段 `has()` + `get()` 双次查表。
  - `DeferredDispatcher::process` **有序延迟调度**：先收集到期任务、按 `dispatchAt` 升序派发，未到期任务保持不动，
    避免整表 COW 复制，并修正延迟语义（delay 小的先触发）。
  - `DistributedEventTracer::propagate` 复用 `injectToEvent` 返回头部，去掉一次多余 `toTraceparent()` 调用；
    `Dispatcher` 指标采集懒计时（`stats` 关闭时不调用 `hrtime`）+ 循环前提炼「可停止传播」布尔。
  - 大量不同事件名（缓存未命中密集）场景累计 **+93.3%**（1019 → 1969 ops/sec）。
- **测试**：新增 `tests/AuditV115Test.php`（8 项 / 16 断言）覆盖上述全部修复与排序/切面缓存/延迟调度语义；
  全量套件 **248 测试 / 573 断言全绿**（原 240 → 248）。
- **文档**：`README.md` 与 `benchmarks/README.md` 新增 v1.15.0 优化对比表与优化点说明。

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
