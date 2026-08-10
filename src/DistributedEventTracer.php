<?php

declare(strict_types=1);

namespace Kode\Event;

use Kode\Context\Context as KodeContext;

/**
 * 基于 kode/context（W3C Trace Context 标准）的「事件分布式追踪器」
 *
 * 让事件在跨进程 / 跨节点（异步队列、RPC、消息总线）派发时仍携带统一的链路追踪上下文，
 * 与 OpenTelemetry 生态互通。事件被序列化进队列前注入 traceparent，消费端取出后
 * 用 {@see self::extractFromEvent()} 恢复同一条链路，从而把一次请求在多个服务间的调用
 * 串联成完整的调用链。
 *
 * 依赖 kode/context ^3.0 起提供的：startTrace / toW3CHeaders / fromTraceparent /
 * toTraceparent / getTraceInfo；其中组合键检查与事务作用域来自 ^3.1。
 */
class DistributedEventTracer
{
    public function __construct(
        protected ?EventTracer $localTracer = null
    ) {
    }

    /**
     * 开启一条新的链路追踪，返回 W3C traceparent 字符串。
     */
    public function startTrace(?string $traceId = null, ?string $nodeId = null): string
    {
        return KodeContext::startTrace($traceId, $nodeId);
    }

    /**
     * 将当前上下文的 W3C 追踪信息注入事件，便于随事件一起跨边界传递。
     *
     * @return array{traceparent:?string, tracestate:?string} 实际注入的头部
     */
    public function injectToEvent(Event $event): array
    {
        $headers = KodeContext::toW3CHeaders(); // ['traceparent'=>..., 'tracestate'=>...]

        if (isset($headers['traceparent'])) {
            $event->set('traceparent', $headers['traceparent']);
        }

        if (isset($headers['tracestate'])) {
            $event->set('tracestate', $headers['tracestate']);
        }

        return $headers;
    }

    /**
     * 从事件中提取 W3C 追踪信息并恢复为当前上下文（消费端调用）。
     *
     * @return bool 是否成功恢复（事件本身携带了合法 traceparent 才为 true）
     */
    public function extractFromEvent(Event $event): bool
    {
        $traceparent = $event->has('traceparent') ? $event->get('traceparent') : null;

        if (!is_string($traceparent)) {
            return false;
        }

        $tracestate = $event->has('tracestate') ? $event->get('tracestate') : null;

        return KodeContext::fromTraceparent(
            $traceparent,
            is_string($tracestate) ? $tracestate : null
        );
    }

    /**
     * 确保存在进行中的链路追踪，并将 W3C traceparent 注入事件，
     * 使事件在序列化 / 跨边界派发时自动携带链路上下文。
     *
     * 若当前尚无活动链路，会先 {@see self::startTrace()} 开启一条。
     *
     * @return string|null 注入后的 traceparent；若活动链路不可用且无法创建则为 null
     */
    public function propagate(Event $event): ?string
    {
        if ($this->getTraceparent() === null) {
            $this->startTrace();
        }

        // 复用 injectToEvent 返回的头部，避免再调一次 toTraceparent()，
        // 将每次派发的上下文调用从两次降到一次
        $headers = $this->injectToEvent($event);

        return $headers['traceparent'] ?? null;
    }

    /**
     * 返回当前 W3C traceparent；若尚未开启追踪则为 null。
     */
    public function getTraceparent(): ?string
    {
        return KodeContext::toTraceparent();
    }

    /**
     * 返回当前追踪信息（trace_id / span_id / flags 等）。
     *
     * @return array<string, mixed>
     */
    public function getTraceInfo(): array
    {
        return KodeContext::getTraceInfo();
    }

    /**
     * 在一条追踪跨度内派发事件：确保存在进行中的 trace，注入事件，再执行回调。
     *
     * 若构造时传入了本地 {@see EventTracer}，还会额外记录进程内的 span 明细。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function trace(Event $event, callable $callback): mixed
    {
        if ($this->getTraceparent() === null) {
            $this->startTrace();
        }

        $this->injectToEvent($event);

        if ($this->localTracer !== null) {
            return $this->localTracer->trace($event, $callback);
        }

        return $callback();
    }
}
