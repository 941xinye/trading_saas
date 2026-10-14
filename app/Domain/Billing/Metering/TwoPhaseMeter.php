<?php

declare(strict_types=1);

namespace App\Domain\Billing\Metering;

use App\Models\MeterEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 两阶段计量器（架构 §4.7，沿用 AI-API 模式）。
 *
 * 热闸门：Redis key tenant:{tid}:meter:{event}:{UTC Ymd}，Lua 原子 check+INCR，
 *         杜绝「先查后增」并发竞争；TTL 2 天自动回收。
 * 持久审计：meter_events（frozen → settled / reversed）。
 *
 * 语义：
 *  - freeze()  原子占额 + frozen 行；同 ref_id 已有 frozen/settled 行 → 幂等返回，不双扣
 *  - settle()  动作真正发生（交易所受理/HTTP 已应答），占额保留，行置 settled
 *  - reverse() 动作未发生（前置拒绝/网络未达），DECR 还额，行置 reversed
 *  - reconcile() Redis 丢 key 时从 meter_events 重建当日计数
 *
 * 本类是平台基础设施：一律显式 tenant_id，查询全部 withoutGlobalScopes，
 * 不依赖 TenantContext（market:feed 协程内无上下文也必须可调用）。
 */
class TwoPhaseMeter
{
    /** 日桶保留 2 天 */
    private const TTL_SECONDS = 172800;

    /** 事件类型 → 套餐日配额 feature key */
    public const DAILY_LIMIT_KEYS = [
        'ai_call' => 'ai_calls_per_day',
        'order_placed' => 'orders_per_day',
    ];

    /**
     * 原子占额：未超限则 INCR 并写 frozen 行；超限返回 null（不落行）。
     */
    public function freeze(
        int $tenantId,
        string $eventType,
        string $refId,
        int $limit,
        string $quantity = '1',
    ): ?MeterEvent {
        if ($limit <= 0) {
            return null;
        }

        // 幂等：同 ref 已占额（frozen/settled）直接返回；reversed 的旧 ref 允许重新占额
        if ($refId !== '') {
            $existing = $this->findRef($tenantId, $eventType, $refId);
            if ($existing !== null && $existing->status !== 'reversed') {
                return $existing;
            }
        }

        [$allowed, $current] = $this->atomicIncrement($this->key($tenantId, $eventType), $limit);
        if (! $allowed) {
            return null;
        }

        $now = Carbon::now();

        /** @var MeterEvent $event */
        $event = MeterEvent::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'quantity' => $quantity,
            'frozen_amount' => $quantity,
            'settled_amount' => null,
            'status' => 'frozen',
            'ref_id' => $refId !== '' ? $refId : null,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $event;
    }

    /**
     * 结算：占额保留（计数不动），frozen → settled。幂等。
     */
    public function settle(MeterEvent $event): MeterEvent
    {
        if ($event->status === 'settled') {
            return $event;
        }

        DB::table('meter_events')
            ->where('id', $event->getKey())
            ->where('status', 'frozen')
            ->update([
                'status' => 'settled',
                'settled_amount' => (string) $event->quantity,
                'updated_at' => Carbon::now(),
            ]);

        return $this->refresh($event);
    }

    /**
     * 冲正：归还配额（DECR，不为负），frozen → reversed。幂等。
     */
    public function reverse(MeterEvent $event): MeterEvent
    {
        if ($event->status === 'reversed') {
            return $event;
        }

        // 仅当行仍是 frozen 时才还额（settled 不允许冲正，需走退款流程，POC 不涉及）
        $affected = DB::table('meter_events')
            ->where('id', $event->getKey())
            ->where('status', 'frozen')
            ->update(['status' => 'reversed', 'updated_at' => Carbon::now()]);

        if ($affected > 0) {
            Redis::connection('default')->eval(
                $this->luaDecrement(),
                1,
                $this->key((int) $event->tenant_id, (string) $event->event_type),
            );
        }

        return $this->refresh($event);
    }

    /**
     * 当日已占用配额（settled+frozen 合计，由 Redis 计数承载）。
     */
    public function usedToday(int $tenantId, string $eventType, ?Carbon $day = null): int
    {
        $value = Redis::connection('default')->get($this->key($tenantId, $eventType, $day));

        return (int) $value;
    }

    /**
     * 从 meter_events 重建当日 Redis 计数（Redis 丢 key/被清库后的自愈手段）。
     * 计数口径：status ∈ (frozen, settled)（reversed 已还额不计）。
     */
    public function reconcile(int $tenantId, string $eventType, ?Carbon $day = null): int
    {
        $day ??= Carbon::now('UTC');
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $count = (int) DB::table('meter_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', $eventType)
            ->whereIn('status', ['frozen', 'settled'])
            ->whereBetween('occurred_at', [$start, $end])
            ->count();

        $redis = Redis::connection('default');
        $key = $this->key($tenantId, $eventType, $day);
        if ($count > 0) {
            $redis->setex($key, self::TTL_SECONDS, (string) $count);
        } else {
            $redis->del($key);
        }

        return $count;
    }

    /**
     * 删除当日 Redis 计数（POC 验证/手工纠偏用）。
     */
    public function flushDayCounter(int $tenantId, string $eventType, ?Carbon $day = null): void
    {
        Redis::connection('default')->del($this->key($tenantId, $eventType, $day));
    }

    private function atomicIncrement(string $key, int $limit): array
    {
        // 返回 {是否允许, 占额后计数}；EXPIRE 只在首次 INCR 时设置
        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current ~= false and tonumber(current) >= tonumber(ARGV[1]) then
    return {0, tonumber(current)}
end
current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[2]))
end
return {1, current}
LUA;

        // Laravel 连接层签名：eval(script, numkeys, ...keysAndArgs)（phpredis/predis 通用）
        $result = Redis::connection('default')->eval($script, 1, $key, (string) $limit, (string) self::TTL_SECONDS);
        $result = is_array($result) ? array_values($result) : [(int) (bool) $result, 0];

        return [(int) $result[0] === 1, (int) ($result[1] ?? 0)];
    }

    private function luaDecrement(): string
    {
        return <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current == false then
    return 0
end
if tonumber(current) <= 0 then
    redis.call('SET', KEYS[1], 0)
    return 0
end
return redis.call('DECR', KEYS[1])
LUA;
    }

    private function findRef(int $tenantId, string $eventType, string $refId): ?MeterEvent
    {
        /** @var MeterEvent|null $row */
        $row = MeterEvent::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('event_type', $eventType)
            ->where('ref_id', $refId)
            ->orderByDesc('id')
            ->first();

        return $row;
    }

    private function refresh(MeterEvent $event): MeterEvent
    {
        /** @var MeterEvent|null $fresh */
        $fresh = $event->newQueryWithoutScopes()->find($event->getKey());

        return $fresh ?? $event;
    }

    private function key(int $tenantId, string $eventType, ?Carbon $day = null): string
    {
        $day ??= Carbon::now('UTC');

        return sprintf('tenant:%d:meter:%s:%s', $tenantId, $eventType, $day->format('Ymd'));
    }
}
