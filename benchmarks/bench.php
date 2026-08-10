<?php

declare(strict_types=1);

/**
 * kode/event 性能压测脚本
 *
 * 用法：
 *   php benchmarks/bench.php            # 运行全部压测并输出表格
 *   php benchmarks/bench.php --json     # 额外以 JSON 输出机器可读结果
 *
 * 仅依赖 composer 自动加载，不依赖任何外部扩展。
 * 结果受本机 CPU / PHP 版本影响，仅用于「同一环境下优化前后」的横向对比。
 */

require __DIR__ . '/../vendor/autoload.php';

// 压测场景会生成大日志并全内存镜像（all()），放宽上限以容纳超大日志对比
ini_set('memory_limit', '512M');

use Kode\Event\DeferredDispatcher;
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\FileEventStore;
use Kode\Event\ListenerRegistry;
use Kode\Event\RetryListener;

/**
 * 压测单例
 *
 * @param callable():void $fn 单次被测操作
 * @return array{ops:int,ms:float,ops_per_sec:float}
 */
function bench(string $title, int $iterations, callable $fn, bool $warmup = true): array
{
    if ($warmup) {
        $fn();
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $elapsed = hrtime(true) - $start;

    $ms = $elapsed / 1e6;
    $opsPerSec = $elapsed > 0 ? $iterations / ($elapsed / 1e9) : 0.0;

    printf("  %-42s %12s ops  %10.3f ms  %15s ops/sec\n", $title, number_format($iterations), $ms, number_format($opsPerSec, 0));

    return ['title' => $title, 'ops' => $iterations, 'ms' => $ms, 'ops_per_sec' => $opsPerSec];
}

function section(string $name): void
{
    echo "\n\033[1m" . $name . "\033[0m\n";
}

$json = in_array('--json', $argv, true);
$results = [];
$php = PHP_VERSION;

echo "kode/event 性能压测  | PHP {$php} | " . date('Y-m-d H:i:s') . "\n";

// ------------------------------------------------------------------
// 1. Dispatcher 基础派发（无监听器）
// ------------------------------------------------------------------
section('1. Dispatcher 基础派发');
$results['dispatch_no_listener'] = bench('dispatch(name) 无监听器', 1_000_000, static function (): void {
    static $d = null;
    $d ??= new Dispatcher();
    $d->dispatch('app.tick');
});

$noOp = static function (Event $e): void {};
$results['dispatch_one_listener'] = bench('dispatch(name) +1 监听器', 1_000_000, static function () use ($noOp): void {
    static $d = null;
    if ($d === null) {
        $d = new Dispatcher();
        $d->listen('app.tick', $noOp);
    }
    $d->dispatch('app.tick');
});

$results['dispatch_ten_listeners'] = bench('dispatch(name) +10 监听器', 1_000_000, static function () use ($noOp): void {
    static $d = null;
    if ($d === null) {
        $d = new Dispatcher();
        for ($i = 0; $i < 10; $i++) {
            $d->listen('app.tick', $noOp);
        }
    }
    $d->dispatch('app.tick');
});

// ------------------------------------------------------------------
// 2. 通配符派发（缓存命中热路径）
// ------------------------------------------------------------------
section('2. 通配符派发');
$results['dispatch_wildcard_hit'] = bench('dispatch(name) 命中 user.* 通配符', 1_000_000, static function () use ($noOp): void {
    static $d = null;
    if ($d === null) {
        $d = new Dispatcher();
        $d->listen('user.*', $noOp);
    }
    $d->dispatch('user.created');
});

// ------------------------------------------------------------------
// 3. 大量不同事件名（getListeners 缓存未命中密集场景）
// ------------------------------------------------------------------
section('3. 大量不同事件名（缓存未命中密集）');
$results['dispatch_many_names'] = bench('1000 个不同事件名各派发一次', 1000, static function (): void {
    static $d = null;
    static $names = null;
    if ($d === null) {
        $d = new Dispatcher();
        $d->listen('evt.0', static function (Event $e): void {});
        $names = [];
        for ($i = 0; $i < 1000; $i++) {
            $names[] = 'evt.' . $i;
        }
    }
    foreach ($names as $n) {
        $d->dispatch($n);
    }
}, warmup: false);

// ------------------------------------------------------------------
// 4. 批量注册监听器（invalidateCache 开销）
// ------------------------------------------------------------------
section('4. 批量注册监听器（invalidateCache 开销）');
$noop2 = static function (Event $e): void {};
$results['register_many'] = bench('注册 2000 个监听器', 50, static function () use ($noop2): void {
    $d = new Dispatcher();
    for ($i = 0; $i < 2000; $i++) {
        $d->listen('reg.' . $i, $noop2);
    }
}, warmup: false);

// 注册后再派发（触发缓存重建）
$results['dispatch_after_register'] = bench('注册 2000 后派发 1 次', 200, static function () use ($noop2): void {
    $d = new Dispatcher();
    for ($i = 0; $i < 2000; $i++) {
        $d->listen('reg.' . $i, $noop2);
    }
    $d->dispatch('reg.1999');
}, warmup: false);

// ------------------------------------------------------------------
// 5. until 短路派发
// ------------------------------------------------------------------
section('5. until 短路派发');
$results['until_first_wins'] = bench('until() 首个监听器即返回', 1_000_000, static function (): void {
    static $d = null;
    if ($d === null) {
        $d = new Dispatcher();
        $d->listen('chain.run', static function (Event $e) {
            return 'result';
        });
        $d->listen('chain.run', static function (Event $e) {
            return 'ignored';
        });
    }
    $d->until('chain.run');
});

// ------------------------------------------------------------------
// 6. DeferredDispatcher
// ------------------------------------------------------------------
section('6. DeferredDispatcher');
$results['defer_and_process'] = bench('defer + process（delay=0）', 500_000, static function (): void {
    static $dd = null;
    $dd ??= new DeferredDispatcher(new Dispatcher());
    $dd->defer('def.tick');
    $dd->process();
});

// ------------------------------------------------------------------
// 6b. DeferredDispatcher 超大待处理集（扫描开销）
// ------------------------------------------------------------------
section('6b. DeferredDispatcher 超大待处理集扫描');
/**
 * 构造「N 个远未来任务 + M 个立即到期任务」的待处理集，
 * 仅计时单次 process() 的扫描开销（构造成本在计时区外）。
 * 优化前 process() 需全量扫描 N+M；优化后从队首取到期任务、遇首个未到期即早停，
 * 扫描量仅与 M 相关。
 *
 * @return array{ms:float, ops_per_sec:float}
 */
$measureLargePending = static function (int $total, int $due): array {
    $dd = new DeferredDispatcher(new Dispatcher());
    for ($i = 0; $i < $total; $i++) {
        $dd->deferAt('def.bulk', [], time() + 1_000_000); // 远未来，不会到期
    }
    for ($i = 0; $i < $due; $i++) {
        $dd->defer('def.bulk'); // 立即到期
    }

    $start = hrtime(true);
    $dd->process();
    $ms = (hrtime(true) - $start) / 1e6;

    return ['ms' => $ms, 'ops_per_sec' => $ms > 0 ? 1000.0 / $ms : 0.0];
};

$samples = [];
for ($i = 0; $i < 11; $i++) {
    $samples[] = $measureLargePending(50_000, 50)['ms'];
}
sort($samples);
$median = $samples[intdiv(count($samples), 2)];
printf("  %-42s 单次 process() 中位数 %10.3f ms\n", '50000 待处理 + 50 到期', $median);
$results['defer_large_pending'] = [
    'title' => '50000 待处理 + 50 到期 仅 process',
    'ops' => 1,
    'ms' => $median,
    'ops_per_sec' => $median > 0 ? 1000.0 / $median : 0.0,
];

// ------------------------------------------------------------------
// 6c. DeferredDispatcher 大待处理集 + 散布取消（cancel 退化场景）
// ------------------------------------------------------------------
section('6c. DeferredDispatcher 大待处理集 + 散布 cancel');
// 构造 20000 个立即到期任务，对其中 19900 个做散布 cancel，仅计时 cancel 调用本身。
// 优化前 cancel 每次 array_search + array_values 重建 O(n) 索引，累积退化 O(n²)；
// 优化后仅 unset 任务本体 O(1)，幽灵由 process() 下次遍历时跳过。
$results['defer_cancel_stress'] = bench('20000 待处理 + 散布 cancel 19900 次', 19_900, static function (): void {
    static $dd = null;
    static $ids = null;
    static $idx = 0;
    if ($dd === null) {
        $dd = new DeferredDispatcher(new Dispatcher());
        $ids = [];
        for ($i = 0; $i < 20_000; $i++) {
            $ids[] = $dd->defer('def.cancel.stress');
        }
    }
    $dd->cancel($ids[$idx]);
    $idx++;
}, warmup: false);

// ------------------------------------------------------------------
// 7. Event 对象构建与取数
// ------------------------------------------------------------------
section('7. Event 对象');
$results['event_construct'] = bench('new Event(name,data)', 2_000_000, static function (): void {
    new Event('app.tick', ['x' => 1]);
});
$results['event_get_dotted'] = bench('Event::get("a.b.c") 点路径', 2_000_000, static function (): void {
    static $e = null;
    $e ??= new Event('app.tick', ['a' => ['b' => ['c' => 42]]]);
    $e->get('a.b.c');
});

// ------------------------------------------------------------------
// 6d. DeferredDispatcher 历史回填批量前插
// ------------------------------------------------------------------
section('6d. DeferredDispatcher 历史回填批量前插');
// 构造 20000 个远未来任务作为现有待处理集，再回填 20000 个「历史」任务
// （dispatchAt 全部早于现有任务）。比较：
//  - 旧路径：循环调用 deferAt()（每次前插 O(n) → 整体 O(m·n)）
//  - 新路径：deferBackfill() 单次归并（O(m·log m + n + m)）
$existingFuture = 20_000;
$backfillCount = 20_000;

$buildBackfill = static function (int $n, int $baseTs): array {
    $entries = [];
    $step = 60; // 每个历史事件间隔 60s
    for ($i = 0; $i < $n; $i++) {
        $entries[] = [
            'event' => new Event('def.backfill', ['idx' => $i]),
            'data' => [],
            'timestamp' => $baseTs - ($n - $i) * $step,
        ];
    }
    return $entries;
};

$baseTs = time() - 10_000;
$backfillEntries = $buildBackfill($backfillCount, $baseTs);

$measureBackfillOld = static function () use ($existingFuture, $backfillEntries): float {
    $dd = new DeferredDispatcher(new Dispatcher());
    for ($i = 0; $i < $existingFuture; $i++) {
        $dd->deferAt(new Event('def.future'), [], time() + 1_000_000);
    }
    $start = hrtime(true);
    foreach ($backfillEntries as $e) {
        $dd->deferAt($e['event'], $e['data'], $e['timestamp']);
    }
    return (hrtime(true) - $start) / 1e6;
};

$measureBackfillNew = static function () use ($existingFuture, $backfillEntries): float {
    $dd = new DeferredDispatcher(new Dispatcher());
    for ($i = 0; $i < $existingFuture; $i++) {
        $dd->deferAt(new Event('def.future'), [], time() + 1_000_000);
    }
    $start = hrtime(true);
    $dd->deferBackfill($backfillEntries);
    return (hrtime(true) - $start) / 1e6;
};

$oldSamples = [];
$newSamples = [];
for ($s = 0; $s < 3; $s++) {
    $oldSamples[] = $measureBackfillOld();
    $newSamples[] = $measureBackfillNew();
}
sort($oldSamples);
sort($newSamples);
$medianOld = $oldSamples[intdiv(count($oldSamples), 2)];
$medianNew = $newSamples[intdiv(count($newSamples), 2)];
printf("  %-42s 中位数 %10.3f ms\n", "旧: 循环 deferAt ×{$backfillCount}", $medianOld);
printf("  %-42s 中位数 %10.3f ms\n", "新: deferBackfill ×{$backfillCount}", $medianNew);
$speedup = $medianOld > 0 ? $medianOld / $medianNew : 0.0;
printf("  %-42s %10.1f×\n", '回填提速', $speedup);
$results['defer_backfill_old'] = [
    'title' => "回填旧路径 deferAt×{$backfillCount}",
    'ops' => 1,
    'ms' => $medianOld,
    'ops_per_sec' => $medianOld > 0 ? 1000.0 / $medianOld : 0.0,
];
$results['defer_backfill_new'] = [
    'title' => "回填新路径 deferBackfill×{$backfillCount}",
    'ops' => 1,
    'ms' => $medianNew,
    'ops_per_sec' => $medianNew > 0 ? 1000.0 / $medianNew : 0.0,
];

// ------------------------------------------------------------------
// 8. 对象事件（深层级）解析复用 —— resolveEntriesForObject 复用 getListeners
// ------------------------------------------------------------------
section('8. 对象事件（深层级）解析复用');

// 构造一个具备多重解析键（自身类 + 父类 + 接口）的对象事件，并注册跨键 + 通配符监听器，
// 用于验证 v1.20.0 把「按 key 遍历通配符 + 排序」统一收敛到 getListeners() 后，
// 每次派发都能命中单键 resolvedCache，热路径不再重复 preg_match / 排序。
interface BenchOrderEvent {}
abstract class BenchOrderBase {}
class BenchConcreteOrder extends BenchOrderBase implements BenchOrderEvent {}

$results['object_dispatch_cold'] = bench('对象事件 冷启动 派发', 50_000, static function (): void {
    $d = new Dispatcher();
    $d->listen(BenchConcreteOrder::class, static fn() => null);
    $d->listen(BenchOrderEvent::class, static fn() => null);
    $d->listen(BenchOrderBase::class, static fn() => null);
    $d->listen('BenchConcrete*', static fn() => null); // 通配符命中对象键
    $d->dispatch(new BenchConcreteOrder());
}, warmup: false);

$results['object_dispatch_warm'] = bench('对象事件 热缓存 派发', 500_000, static function (): void {
    static $d = null;
    $d ??= (static function (): Dispatcher {
        $d = new Dispatcher();
        $d->listen(BenchConcreteOrder::class, static fn() => null);
        $d->listen(BenchOrderEvent::class, static fn() => null);
        $d->listen(BenchOrderBase::class, static fn() => null);
        $d->listen('BenchConcrete*', static fn() => null);
        return $d;
    })();
    $d->dispatch(new BenchConcreteOrder());
});

// ------------------------------------------------------------------
// 9. 事件溯源 FileEventStore 崩溃恢复（截断末行 + 重载校验）
// ------------------------------------------------------------------
section('9. FileEventStore 崩溃恢复');

$n = 50_000;
$tmpFile = sys_get_temp_dir() . '/kode_event_bench_store_' . getmypid() . '.jsonl';
@unlink($tmpFile);

// 9a. 批量写入 N 个事件（单次追加原子写）
$writeMs = (static function () use ($tmpFile, $n): float {
    $store = new FileEventStore($tmpFile);
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $store->append(new Event('evt.' . ($i % 50), ['i' => $i]));
    }
    return (hrtime(true) - $start) / 1e6;
})();
printf("  %-42s %10.3f ms (%d 条)\n", "写入 {$n} 事件", $writeMs, $n);

// 9b. 模拟崩溃：在文件末尾追加一条「被截断的半行 JSON」后进程中断
file_put_contents($tmpFile, "{\"seq\":{$n}, \"id\":\"evt-corrupt\", \"name\":\"evt.broken\"\n", FILE_APPEND);

// 9c. 全新实例重载：必须跳过损坏末行并重建出 N 条有效事件
$recoverMs = (static function () use ($tmpFile, $n): float {
    $start = hrtime(true);
    $reloaded = new FileEventStore($tmpFile);
    $count = $reloaded->count();
    $elapsed = (hrtime(true) - $start) / 1e6;
    // 断言：损坏末行被跳过，有效事件数仍为 N
    if ($count !== $n) {
        fwrite(STDERR, "  崩溃恢复校验失败：期望 {$n} 条，实际 {$count} 条\n");
    }
    return $elapsed;
})();
printf("  %-42s %10.3f ms (恢复 %d 条，跳过损坏末行)\n", "重载恢复（跳过截断行）", $recoverMs, $n);

// 9d. 干净文件重载吞吐（无损坏行，作为基线对照）
$cleanMs = (static function () use ($tmpFile, $n): float {
    // 去掉损坏末行，得到干净文件
    $lines = file($tmpFile, FILE_IGNORE_NEW_LINES);
    array_pop($lines); // 去掉截断行
    $cleanFile = $tmpFile . '.clean';
    file_put_contents($cleanFile, implode("\n", $lines) . "\n");
    $start = hrtime(true);
    $store = new FileEventStore($cleanFile);
    $c = $store->count();
    $elapsed = (hrtime(true) - $start) / 1e6;
    @unlink($cleanFile);
    if ($c !== $n) {
        fwrite(STDERR, "  干净重载校验失败：期望 {$n} 条，实际 {$c} 条\n");
    }
    return $elapsed;
})();
printf("  %-42s %10.3f ms (干净基线 %d 条)\n", "重载（无损坏行基线）", $cleanMs, $n);

$results['file_store_write'] = ['title' => "FileEventStore 写入×{$n}", 'ops' => $n, 'ms' => $writeMs, 'ops_per_sec' => $writeMs > 0 ? $n / ($writeMs / 1000) : 0];
$results['file_store_recover'] = ['title' => "FileEventStore 崩溃恢复×{$n}", 'ops' => $n, 'ms' => $recoverMs, 'ops_per_sec' => $recoverMs > 0 ? $n / ($recoverMs / 1000) : 0];
$results['file_store_clean'] = ['title' => "FileEventStore 干净重载×{$n}", 'ops' => $n, 'ms' => $cleanMs, 'ops_per_sec' => $cleanMs > 0 ? $n / ($cleanMs / 1000) : 0];

@unlink($tmpFile);

// ------------------------------------------------------------------
// 10. 事件溯源 FileEventStore 批量写入 + 流式加载（超大日志）
// ------------------------------------------------------------------
section('10. FileEventStore 批量写入 / 流式加载');

$big = 200_000;
$bigFile = sys_get_temp_dir() . '/kode_event_bench_big_' . getmypid() . '.jsonl';
@unlink($bigFile);

// 10a. 单条追加 N 次 vs appendBatch(N) 一次原子写
$singleMs = (static function () use ($bigFile, $big): float {
    $store = new FileEventStore($bigFile);
    $start = hrtime(true);
    for ($i = 0; $i < $big; $i++) {
        $store->append(new Event('evt.' . ($i % 50), ['i' => $i]));
    }
    return (hrtime(true) - $start) / 1e6;
})();
printf("  %-42s %10.3f ms\n", "单条 append ×{$big}", $singleMs);

@unlink($bigFile);
$batchMs = (static function () use ($bigFile, $big): float {
    $store = new FileEventStore($bigFile);
    $entries = [];
    for ($i = 0; $i < $big; $i++) {
        $entries[] = ['event' => new Event('evt.' . ($i % 50), ['i' => $i])];
    }
    $start = hrtime(true);
    $store->appendBatch($entries);
    return (hrtime(true) - $start) / 1e6;
})();
printf("  %-42s %10.3f ms\n", "appendBatch ×{$big}", $batchMs);
printf("  %-42s %10.1f×\n", '批量写入提速', $singleMs > 0 ? $singleMs / $batchMs : 0);

// 10b. 流式加载（O(1) 内存）vs 全量物化（all()）峰值内存对比
$allPeakKb = (static function () use ($bigFile): float {
    $store = new FileEventStore($bigFile);
    $before = memory_get_peak_usage(true);
    $store->all();
    return (memory_get_peak_usage(true) - $before) / 1024;
})();

$streamPeakKb = (static function () use ($bigFile, $big): float {
    $before = memory_get_peak_usage(true);
    $store = new FileEventStore($bigFile);
    $n = 0;
    foreach ($store->stream() as $_) {
        $n++;
    }
    $peak = (memory_get_peak_usage(true) - $before) / 1024;
    if ($n !== $big) {
        fwrite(STDERR, "  流式加载条数校验失败：期望 {$big}，实际 {$n}\n");
    }
    return $peak;
})();
printf("  %-42s %10.1f KB\n", "all() 全量物化峰值增量", $allPeakKb);
printf("  %-42s %10.1f KB\n", "stream() 流式峰值增量", $streamPeakKb);

$results['file_batch_single'] = ['title' => "FileEventStore 单条 append×{$big}", 'ops' => $big, 'ms' => $singleMs, 'ops_per_sec' => $singleMs > 0 ? $big / ($singleMs / 1000) : 0];
$results['file_batch_bulk'] = ['title' => "FileEventStore appendBatch×{$big}", 'ops' => $big, 'ms' => $batchMs, 'ops_per_sec' => $batchMs > 0 ? $big / ($batchMs / 1000) : 0];

@unlink($bigFile);

// 11. RetryListener 装饰开销 + 指数退避序列生成吞吐
$iter = 200000;
$retryOverhead = (static function () use ($iter): array {
    $bare = new Dispatcher();
    $bare->listen('ev', static function (Event $e): void {
    });

    $wrapped = new Dispatcher();
    $wrapped->listen('ev', new RetryListener(
        static function (Event $e): void {
        },
        'ev',
        backoff: RetryListener::exponentialBackoff(100, 2.0, 5000)
    ));

    $e = new Event('ev');
    $t = hrtime(true);
    for ($i = 0; $i < $iter; $i++) {
        $bare->dispatch($e);
    }
    $bareMs = (hrtime(true) - $t) / 1e6;

    $t = hrtime(true);
    for ($i = 0; $i < $iter; $i++) {
        $wrapped->dispatch($e);
    }
    $wrappedMs = (hrtime(true) - $t) / 1e6;

    return [$bareMs, $wrappedMs];
})();
printf("\n11. RetryListener 装饰开销（dispatch ×%d）\n", $iter);
printf("  %-42s %10.3f ms\n", '裸监听 dispatch', $retryOverhead[0]);
printf("  %-42s %10.3f ms\n", 'RetryListener 包裹（成功路径）', $retryOverhead[1]);
printf("  %-42s %10.2f%%\n", '装饰开销（相对裸监听）', $retryOverhead[0] > 0 ? ($retryOverhead[1] / $retryOverhead[0] - 1) * 100 : 0);

// 11b. 指数退避序列生成吞吐（含 cap 校验）
$expCap = (static function () use ($iter): array {
    $seq = RetryListener::exponentialBackoff(100, 2.0, 5000);
    $t = hrtime(true);
    $last = 0;
    for ($i = 1; $i <= 1000; $i++) {
        $last = $seq($i);
    }
    $genMs = (hrtime(true) - $t) / 1e6;
    return [$genMs, $last];
})();
printf("\n11b. 指数退避序列生成（×1000 次，cap=5000）\n");
printf("  %-42s %10.3f ms\n", 'exponentialBackoff 调用', $expCap[0]);
printf("  %-42s %10d ms\n", '第 1000 次（应被 cap 截断）', $expCap[1]);

$results['retry_overhead_bare'] = ['title' => "裸监听 dispatch×{$iter}", 'ops' => $iter, 'ms' => $retryOverhead[0], 'ops_per_sec' => $retryOverhead[0] > 0 ? $iter / ($retryOverhead[0] / 1000) : 0];
$results['retry_overhead_wrapped'] = ['title' => "RetryListener 包裹 dispatch×{$iter}", 'ops' => $iter, 'ms' => $retryOverhead[1], 'ops_per_sec' => $retryOverhead[1] > 0 ? $iter / ($retryOverhead[1] / 1000) : 0];

echo "\n完成。\n";

if ($json) {
    file_put_contents(__DIR__ . '/benchmark-latest.json', json_encode([
        'php' => $php,
        'date' => date('c'),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "JSON 结果已写入 benchmarks/benchmark-latest.json\n";
}
