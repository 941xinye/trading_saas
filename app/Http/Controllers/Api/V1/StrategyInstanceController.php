<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\StrategyInstanceResource;
use App\Models\StrategyInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * 策略实例 API Controller。
 *
 * 状态机：stopped ↔ starting ↔ running ↔ paused_by_* ↔ error
 *  - start：置 starting，进程 B 的保活协程 5s 内热拉起并推进 running
 *  - stop：置 stopped，实例协程监听后自停并保留 Redis 状态
 *  - 运行中（LIVE_STATUSES）禁止改配或删除，必须先 stop
 */
class StrategyInstanceController extends Controller
{
    private const INTERVALS = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '12h', '1d'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $records = StrategyInstance::query()
            ->with(['strategy', 'exchangeAccount'])
            ->paginate();

        return StrategyInstanceResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::requireTenantId();

        $data = $request->validate([
            'strategy_id' => ['required', 'integer', 'exists:strategies,id'],
            'exchange_account_id' => [
                'required', 'integer',
                Rule::exists('exchange_accounts', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'symbol' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9]{2,20}$/',
                Rule::unique('strategy_instances', 'symbol')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('strategy_id', (int) $request->input('strategy_id'))),
            ],
            'interval' => ['required', Rule::in(self::INTERVALS)],
            'params' => ['sometimes', 'array'],
            'allocated_capital' => ['required', 'numeric', 'gt:0'],
            'leverage' => ['nullable', 'integer', 'min:1', 'max:125'],
        ]);

        $instance = StrategyInstance::query()->create([
            'tenant_id' => $tenantId,
            'strategy_id' => (int) $data['strategy_id'],
            'exchange_account_id' => (int) $data['exchange_account_id'],
            'symbol' => (string) $data['symbol'],
            'interval' => (string) $data['interval'],
            'params' => $data['params'] ?? [],
            'allocated_capital' => $data['allocated_capital'],
            'leverage' => $data['leverage'] ?? null,
            'status' => 'stopped',
        ]);

        return (new StrategyInstanceResource($instance->load(['strategy', 'exchangeAccount'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(StrategyInstance $instance): StrategyInstanceResource
    {
        $this->ensureTenantOwned($instance);

        return new StrategyInstanceResource($instance->load(['strategy', 'exchangeAccount']));
    }

    public function update(Request $request, StrategyInstance $instance): JsonResponse
    {
        $this->ensureTenantOwned($instance);

        if ($instance->isLive()) {
            return $this->conflict('实例运行中，请先 stop 再修改配置。');
        }

        $tenantId = TenantContext::requireTenantId();

        $data = $request->validate([
            'strategy_id' => ['sometimes', 'integer', 'exists:strategies,id'],
            'exchange_account_id' => [
                'sometimes', 'integer',
                Rule::exists('exchange_accounts', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'symbol' => [
                'sometimes', 'string', 'max:20', 'regex:/^[A-Z0-9]{2,20}$/',
                Rule::unique('strategy_instances', 'symbol')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('strategy_id', (int) $request->input('strategy_id', $instance->strategy_id)))
                    ->ignore($instance->getKey()),
            ],
            'interval' => ['sometimes', Rule::in(self::INTERVALS)],
            'params' => ['sometimes', 'array'],
            'allocated_capital' => ['sometimes', 'numeric', 'gt:0'],
            'leverage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:125'],
        ]);

        $instance->fill($data)->save();

        return (new StrategyInstanceResource($instance->refresh()->load(['strategy', 'exchangeAccount'])))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(StrategyInstance $instance): JsonResponse
    {
        $this->ensureTenantOwned($instance);

        if ($instance->isLive()) {
            return $this->conflict('实例运行中，请先 stop 再删除。');
        }

        $instance->delete();

        return response()->json(null, 204);
    }

    /**
     * 启动策略实例：置 starting，由进程 B 热拉起（无需重启 market:feed）。
     */
    public function start(StrategyInstance $instance): JsonResponse
    {
        $this->ensureTenantOwned($instance);

        if ($instance->isLive()) {
            return $this->conflict('实例已在运行或已处于启动流程中。');
        }

        $instance->forceFill([
            'status' => 'starting',
            'started_at' => now(),
            'stopped_at' => null,
        ])->save();

        return (new StrategyInstanceResource($instance->refresh()))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * 停止策略实例：置 stopped，实例协程 5s 内自停并保留恢复状态。
     */
    public function stop(StrategyInstance $instance): JsonResponse
    {
        $this->ensureTenantOwned($instance);

        if ($instance->status !== 'stopped') {
            $instance->forceFill([
                'status' => 'stopped',
                'stopped_at' => now(),
            ])->save();
        }

        return (new StrategyInstanceResource($instance->refresh()))
            ->response()
            ->setStatusCode(200);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }
}
