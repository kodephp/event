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
| `bench.php` | 压测脚本（基准 + 通配符 + 大量不同事件名 + 批量注册 + until + 延迟 + 对象构建） |
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
