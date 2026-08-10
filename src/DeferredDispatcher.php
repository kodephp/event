<?php

declare(strict_types=1);

namespace Kode\Event;

/**
 * 延迟 / 定时事件调度器
 *
 * 维护一个按调度时间（dispatchAt）升序的待处理索引，process() 从队首取到期任务，
 * 遇到首个未到期任务即早停，从而在「待处理集很大、到期任务很少」的场景下避免
 * 对整张待处理表做 O(n) 全量扫描。
 *
 * 语义保证：
 * - 到期任务始终按 dispatchAt 升序派发（delay 小的先触发）；
 * - process() 仅派发并移除到期任务，未到期任务保持不动；
 * - cancel / clear / pending / count / getJob 行为与纯 Map 实现一致。
 */
class DeferredDispatcher
{
    /**
     * 待处理任务 [id => ['event'=>Event,'dispatchAt'=>int,'delay'=>int]]
     *
     * @var array<int, array{event: Event, dispatchAt: int, delay: int}>
     */
    protected array $deferred = [];

    /**
     * 任务 id 按 dispatchAt 升序排列的索引
     *
     * 维护该索引后，process() 无需遍历整个 $deferred，遇首个未到期即停止；
     * defer 时按序插入（新任务 dispatchAt 通常最大，平均 O(1)）。
     *
     * @var array<int, int>
     */
    protected array $order = [];

    protected int $nextId = 1;

    public function __construct(
        protected Dispatcher $dispatcher
    ) {
    }

    public function defer(Event|string $event, array $data = [], int $delay = 0): int
    {
        $id = $this->nextId++;

        if (is_string($event)) {
            $event = new Event($event, $data);
        }

        $at = hrtime(true) + ($delay * 1_000_000_000);

        $this->enqueue($id, [
            'event' => $event,
            'dispatchAt' => $at,
            'delay' => $delay,
        ], $at);

        return $id;
    }

    public function deferAt(Event|string $event, array $data, int $timestamp): int
    {
        $id = $this->nextId++;

        if (is_string($event)) {
            $event = new Event($event, $data);
        }

        $at = hrtime(true) + max(0, $timestamp - time()) * 1_000_000_000;

        $this->enqueue($id, [
            'event' => $event,
            'dispatchAt' => $at,
            'delay' => 0,
        ], $at);

        return $id;
    }

    /**
     * 批量回填历史定时任务（deferAt 语义的批量变体）
     *
     * 适用于「事件溯源重放 / 历史补调度 / 批量回填」等场景：一次性插入大量
     * dispatchAt 早于（或等于）现有待处理任务的定时任务。
     *
     * 逐个调用 deferAt() 时，每次前插都要从队尾向前定位插入点并执行 array_splice
     * （O(n) 搬移），批量回填 m 个任务会退化到 O(m·n)。本方法内部先按 dispatchAt
     * 升序排序，再与现有 order 索引做单次归并（O(m·log m + n + m)）：
     *
     * - 全部晚于现有任务 → 直接追加（O(m)）；
     * - 全部早于现有任务（历史回填最常见情形）→ 纯前插（O(m)）；
     * - 其余交错情形 → 两段均有序，单次归并（O(n + m)）。
     *
     * @param iterable<array{event: Event|string, data?: array, timestamp: int}> $entries
     *        每个条目至少含 `event` 与整数 `timestamp`（绝对 Unix 时间戳，语义同 deferAt）；
     *        `data` 缺省为空数组。
     * @return list<int> 按派发顺序返回的本次新插入任务 id 列表
     *
     * @throws \InvalidArgumentException 条目缺少 event 或整数 timestamp 时
     */
    public function deferBackfill(iterable $entries): array
    {
        $newIds = [];
        $newAts = [];

        foreach ($entries as $entry) {
            $event = $entry['event'] ?? null;
            if ($event === null) {
                throw new \InvalidArgumentException('deferBackfill 条目缺少 event');
            }
            $data = $entry['data'] ?? [];
            $timestamp = $entry['timestamp'] ?? null;
            if (!is_int($timestamp)) {
                throw new \InvalidArgumentException('deferBackfill 条目缺少整数 timestamp');
            }

            if (is_string($event)) {
                $event = new Event($event, $data);
            }

            $at = hrtime(true) + max(0, $timestamp - time()) * 1_000_000_000;
            $id = $this->nextId++;
            $this->deferred[$id] = [
                'event' => $event,
                'dispatchAt' => $at,
                'delay' => 0,
            ];
            $newIds[] = $id;
            $newAts[] = $at;
        }

        if ($newIds === []) {
            return [];
        }

        // 按 dispatchAt 升序稳定排序，使归并的两段都保持有序
        array_multisort($newAts, SORT_NUMERIC, $newIds);

        $this->mergeIntoOrder($newIds, $newAts);

        return $newIds;
    }

    public function cancel(int $id): bool
    {
        if (!isset($this->deferred[$id])) {
            return false;
        }

        // 仅移除任务本体（O(1)）。order 索引中的占位由 process() 遍历时跳过，
        // 避免大待处理集下每次 cancel 都 array_search + array_values 重建索引（退化 O(n²)）。
        // 累积的幽灵条目会在下一次 process() 中被一次性压缩回收。
        unset($this->deferred[$id]);

        return true;
    }

    public function process(): int
    {
        $now = hrtime(true);
        $count = 0;

        $n = count($this->order);
        $i = 0;

        while ($i < $n) {
            $id = $this->order[$i];

            // 已被 cancel 的任务在 order 中仍留有占位，跳过即可
            if (!isset($this->deferred[$id])) {
                unset($this->order[$i]);
                $i++;
                continue;
            }

            // order 按 dispatchAt 升序，首个未到期之后的任务都更晚，
            // 直接早停，避免扫描整张待处理表
            if ($this->deferred[$id]['dispatchAt'] > $now) {
                break;
            }

            $this->dispatcher->dispatch($this->deferred[$id]['event']);
            unset($this->deferred[$id]);
            unset($this->order[$i]);
            $i++;
            $count++;
        }

        // 仅在发生移除/跳过时压缩索引，避免空洞影响后续迭代与内存
        if ($count > 0 || $n !== count($this->order)) {
            $this->order = array_values($this->order);
        }

        return $count;
    }

    public function processAll(): int
    {
        $count = 0;
        while (!empty($this->deferred)) {
            $processed = $this->process();
            $count += $processed;
            if ($processed === 0) {
                break;
            }
        }
        return $count;
    }

    public function pending(): array
    {
        return $this->deferred;
    }

    public function count(): int
    {
        return count($this->deferred);
    }

    public function clear(): self
    {
        $this->deferred = [];
        $this->order = [];
        return $this;
    }

    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    public function getJob(int $id): ?array
    {
        return $this->deferred[$id] ?? null;
    }

    /**
     * 将任务按 dispatchAt 升序插入 order 索引
     *
     * 绝大多数场景下新任务的 dispatchAt 为当前最大（时间向前推进），直接尾部追加 O(1)；
     * 仅当插入到队首或中部（dispatchAt 小于已有最大任务，如 deferAt 指定更早时间）才
     * 从末尾向前定位插入点，属罕见场景。
     *
     * @param array{event: Event, dispatchAt: int, delay: int} $job
     */
    private function enqueue(int $id, array $job, int $at): void
    {
        $this->deferred[$id] = $job;

        if ($this->order === [] || $at >= $this->deferred[$this->order[count($this->order) - 1]]['dispatchAt']) {
            $this->order[] = $id;
            return;
        }

        $pos = 0;
        for ($j = count($this->order) - 1; $j >= 0; $j--) {
            if ($this->deferred[$this->order[$j]]['dispatchAt'] <= $at) {
                $pos = $j + 1;
                break;
            }
            $pos = $j;
        }

        array_splice($this->order, $pos, 0, [$id]);
    }

    /**
     * 将一批已按 dispatchAt 升序排列的任务 id 归并进 order 索引
     *
     * $newIds / $newAts 必须等长且均按 dispatchAt 升序。在「全部早于 / 全部晚于
     * 现有任务」两种常见情形下走 O(m) 快路径；其余交错情形做单次有序归并（O(n + m)），
     * 整体取代逐个 enqueue() 的 O(n) 前插，避免批量回填退化到 O(m·n)。
     *
     * @param list<int> $newIds 按 dispatchAt 升序的新任务 id
     * @param list<int> $newAts 与 $newIds 一一对应的 dispatchAt
     */
    private function mergeIntoOrder(array $newIds, array $newAts): void
    {
        if ($this->order === []) {
            $this->order = $newIds;
            return;
        }

        $lastExistingAt = $this->deferred[$this->order[count($this->order) - 1]]['dispatchAt'];
        $firstNewAt = $newAts[0];

        // 快路径 1：全部晚于现有任务 → 直接追加（与 enqueue 尾部 O(1) 一致）
        if ($firstNewAt >= $lastExistingAt) {
            foreach ($newIds as $nid) {
                $this->order[] = $nid;
            }
            return;
        }

        // 快路径 2：全部早于现有任务（历史回填最常见情形）→ 纯前插
        // 注意需用「最大新值」判定，而非最小新值，方能保证全部早于现有任务
        $firstExistingAt = $this->deferred[$this->order[0]]['dispatchAt'];
        $maxNewAt = $newAts[count($newAts) - 1];
        if ($maxNewAt <= $firstExistingAt) {
            $this->order = array_merge($newIds, $this->order);
            return;
        }

        // 一般情况：两段均有序，单次有序归并 O(n + m)
        // 相等时现有任务优先（与逐个 enqueue 的语义一致：晚注册者靠后）
        $merged = [];
        $i = 0;
        $j = 0;
        $nNew = count($newIds);
        $nOld = count($this->order);
        while ($i < $nNew && $j < $nOld) {
            if ($newAts[$i] < $this->deferred[$this->order[$j]]['dispatchAt']) {
                $merged[] = $newIds[$i];
                $i++;
            } else {
                $merged[] = $this->order[$j];
                $j++;
            }
        }
        while ($i < $nNew) {
            $merged[] = $newIds[$i++];
        }
        while ($j < $nOld) {
            $merged[] = $this->order[$j++];
        }
        $this->order = $merged;
    }
}
