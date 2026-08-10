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

use Kode\Event\DeferredDispatcher;
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\ListenerRegistry;

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

echo "\n完成。\n";

if ($json) {
    file_put_contents(__DIR__ . '/benchmark-latest.json', json_encode([
        'php' => $php,
        'date' => date('c'),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "JSON 结果已写入 benchmarks/benchmark-latest.json\n";
}
