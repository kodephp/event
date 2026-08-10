# 性能压测

`kode/event` 的性能压测脚本与基线数据。

## 运行

```bash
# 运行全部压测场景，输出人类可读表格
php benchmarks/bench.php

# 额外导出机器可读结果到 benchmarks/benchmark-latest.json
php benchmarks/bench.php --json
```

脚本仅依赖 Composer 自动加载，不依赖任何外部扩展。每个场景先执行一次 warmup 再计时，
结果受本机 CPU / PHP 版本 / 系统负载影响，**仅用于同一环境下优化前后的横向对比**。

## 文件

| 文件 | 说明 |
| --- | --- |
| `bench.php` | 压测脚本（基准 + 通配符 + 大量不同事件名 + 批量注册 + until + 延迟 + 超大待处理集 + 对象构建） |
| `benchmark-baseline-v1.13.0.json` | v1.13.0 基线数据（仓库跟踪，作为优化对比锚点） |
| `benchmark-latest.json` | 最近一次运行结果（易变，已被 `.gitignore` 忽略） |

## v1.14.0 优化对比（PHP 8.3.33）

| 场景 | v1.13.0 | v1.14.0 | 提升 |
| --- | --- | --- | --- |
| 基础派发（无监听器） | 2,904,748 | 3,069,762 | +5.7% |
| 派发 +1 监听器 | 2,225,230 | 2,206,206 | ≈ 持平 |
| 派发 +10 监听器 | 2,172,683 | 2,188,390 | +0.7% |
| 通配符派发（命中 `user.*`） | 2,125,279 | 2,249,087 | +5.8% |
| 大量不同事件名（缓存未命中密集） | 1,019 | 1,894 | **+85.9%** |
| 批量注册 2000 监听器 | 1,301 | 1,348 | +3.6% |
| 注册后派发 | 1,286 | 1,337 | +4.0% |
| `until()` 短路派发 | 2,255,986 | 2,469,649 | +9.5% |
| `DeferredDispatcher` defer+process | 1,926,694 | 2,069,239 | +7.4% |

### 优化点

1. `ListenerRegistry::getListeners`：精确桶注册时已排序，仅命中通配符才重新 `usort`，
   缓存未命中路径避免冗余排序（「大量不同事件名」+86% 主因）。
2. `sortBucket` 比较器提取为静态方法 `compareEntries`，不再每次分配闭包。
3. `invalidateCache` 用独立 `$objectCacheKeys` 集合替代全表扫描 `resolvedCache`，
   注册 / 注销时仅失效对象事件缓存，保留正确性语义。
4. `Dispatcher::dispatch` / `until` 热路径上去重 `describe()` 调用。

## v1.15.0 优化对比（累计相对 v1.13.0 基线，PHP 8.3.33）

v1.15.0 在 v1.14.0 之上叠加了惰性排序、切面匹配缓存、类层级缓存、数据快照、延迟调度有序化、
追踪调用精简等优化，并修复了 5 处审计缺陷（详见 `../CHANGELOG.md`）。

| 场景 | v1.13.0 | v1.15.0 | 提升 |
| --- | --- | --- | --- |
| 基础派发（无监听器） | 2,904,748 | 3,141,963 | +8.2% |
| 派发 +1 监听器 | 2,225,230 | 2,487,768 | +11.8% |
| 派发 +10 监听器 | 2,172,683 | 2,434,419 | +12.0% |
| 通配符派发（命中 `user.*`） | 2,125,279 | 2,471,282 | +16.3% |
| 大量不同事件名（缓存未命中密集） | 1,019 | 1,969 | **+93.3%** |
| 批量注册 2000 监听器 | 1,301 | 1,571 | **+20.8%** |
| 注册后派发 | 1,286 | 1,560 | +21.3% |
| `until()` 短路派发 | 2,255,986 | 2,630,161 | +16.6% |
| `DeferredDispatcher` defer+process | 1,926,694 | 1,875,040 | ≈ 持平* |
| `new Event(name,data)` | 13,549,573 | 14,204,276 | +4.8% |
| `Event::get("a.b.c")` 点路径 | 5,718,864 | 5,952,977 | +4.1% |

\* 单元素 `defer+process` 在噪声范围内持平；待处理集大、到期项少时 `process()` 的「只收集到期项 +
按 `dispatchAt` 升序」策略相比旧版全表扫描 + 整表 COW 复制有明显优势，并修正延迟语义。

### v1.15.0 优化点

1. `ListenerRegistry` 注册惰性排序：注册仅追加 + 打 `dirty` 标记，排序延迟到首次读取时执行一次，
   消除同事件大量注册的 O(n²·log n) 排序开销（批量注册 +20.8%）。
2. `AspectEventDispatcher` 切面匹配缓存：按事件名缓存命中的切入点，派发从「全切面 `preg_match`」降为一次查表。
3. `ListenerRegistry` 类层级缓存（`$keysByClass`）+ 正则 FIFO 单条淘汰，免去重复 `class_parents` / `class_implements` 解析。
4. `EventSchema` 校验时 `getData()` 一次性快照，替代逐字段 `has()` + `get()` 双次查表。
5. `DeferredDispatcher::process` 收集到期项后按 `dispatchAt` 升序派发，避免整表 COW，修正延迟语义。
6. `DistributedEventTracer::propagate` 复用 `injectToEvent` 返回头部，去掉一次多余 `toTraceparent()` 调用。
7. `Dispatcher` 指标采集懒计时（`stats` 关闭时不调用 `hrtime`）+ 循环前提炼「可停止传播」布尔。

### v1.15.0 修复的审计缺陷

- BUG-1：`Dispatcher` 开启 `stats` 时，前置钩子抛异常不再被 `finally` 中的 `TypeError` 掩盖原始异常。
- BUG-2：`AspectEventDispatcher::until()` 此前绕过切面，现已同样触发前后置切面。
- BUG-3：`QueueDispatcher::processMany` 不再因队首毒丸任务（`process()` 返回 false）冻结整批消费。
- BUG-4：`EventReplay::replayReverse(0)` 不再误重放全部事件。
- BUG-5：`EventHelper::buildName` 不再把数字段名 `'0'` 当作空值丢弃。

## v1.16.0 优化对比（DeferredDispatcher 有序索引，PHP 8.3.33）

v1.16.0 将 `DeferredDispatcher` 的 `process()` 从「每次 O(n) 全量扫描待处理表」改为「按 `dispatchAt`
升序维护 `order` 索引 + 队首早停」。新增压测场景 `50000 待处理 + 50 到期`，计时单次 `process()` 中位数
（构造成本在计时区外），以隔离扫描开销：

| 实现 | 单次 process() 中位数 | 相对 |
| --- | --- | --- |
| v1.15.0（全量扫描 O(n)） | 3.930 ms | 1× |
| v1.16.0（有序索引早停） | 0.071 ms | **≈ 55×** |

小用例 `defer + process`（单元素）因索引维护开销有约 6% 微幅回落（1,780,372 → 1,669,831 ops/sec），
在噪声范围内，远小于大待处理集收益，属可接受权衡。

optimize 点：

1. `DeferredDispatcher` 新增按 `dispatchAt` 升序的 `order` 索引，`process()` 从队首取到期任务、遇首个
   未到期即 `break`，扫描量仅与到期项相关，与待处理集规模无关。
2. `enqueue()` 尾部追加 O(1) 快路径：绝大多数 `defer` 的 `dispatchAt` 为当前最大，直接追加；
   仅 `deferAt` 指定更早时间等罕见场景才从末尾向前定位插入点。
3. `cancel()` 同步从 `order` 索引移除对应 id，不影响其余任务顺序；`processAll` / `pending` / `count` /
   `getJob` 行为保持一致。

### v1.16.0 新增压测场景

- `50000 待处理 + 50 到期`（`6b` 节）：构造 50000 个远未来任务 + 50 个立即到期任务，仅计时单次 `process()`，
  用于量化「大待处理集、少到期」场景的扫描开销改善。
