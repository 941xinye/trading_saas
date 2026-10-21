<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\SignalSubscriptionResource;
use App\Models\SignalSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 信号订阅 API Controller。
 *
 * 读接口由 TenantScope 自动隔离；写接口在路由绑定阶段 TenantContext 尚未
 * 初始化，因此 show/update/destroy 均用 ensureTenantOwned() 做二道防线，
 * 跨租户一律 404。
 *
 * 自动跟单配置与 Filament 表单语义一致：
 *  - auto_execute=1 时必须绑定本租户策略实例
 *  - execution_notional 留空表示使用 signal.execution.default_notional
 *  - execution_min_confidence 0 = 不过滤；execution_daily_limit 0 = 不限
 */
class SignalSubscriptionController extends Controller
{
    private const INTERVALS = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '12h', '1d'];

    private const AI_PROMPTERS = ['deepseek', 'openai', 'qwen', 'local'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $records = SignalSubscription::query()->paginate();

        return SignalSubscriptionResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::requireTenantId();

        $data = $request->validate($this->rules($request, $tenantId));

        $subscription = SignalSubscription::query()->create([
            ...$data,
            'tenant_id' => $tenantId,
        ]);

        // 刷新以回显数据库默认值（active / rule_fallback_enabled / 日上限等）
        return (new SignalSubscriptionResource($subscription->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SignalSubscription $subscription): SignalSubscriptionResource
    {
        $this->ensureTenantOwned($subscription);

        return new SignalSubscriptionResource($subscription);
    }

    public function update(Request $request, SignalSubscription $subscription): JsonResponse
    {
        $this->ensureTenantOwned($subscription);

        $tenantId = TenantContext::requireTenantId();

        $data = $request->validate($this->rules($request, $tenantId, $subscription));

        $autoExecute = $request->has('auto_execute')
            ? $request->boolean('auto_execute')
            : (bool) $subscription->auto_execute;

        $instanceId = $request->has('execution_instance_id')
            ? (int) $request->input('execution_instance_id')
            : (int) $subscription->execution_instance_id;

        if ($autoExecute && $instanceId <= 0) {
            throw ValidationException::withMessages([
                'execution_instance_id' => '开启自动跟单时必须绑定执行实例。',
            ]);
        }

        $subscription->fill($data)->save();

        return (new SignalSubscriptionResource($subscription->refresh()))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(SignalSubscription $subscription): JsonResponse
    {
        $this->ensureTenantOwned($subscription);

        $subscription->delete();

        return response()->json(null, 204);
    }

    /**
     * 请求校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, int $tenantId, ?SignalSubscription $existing = null): array
    {
        $creating = $existing === null;
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:60'],
            'symbols' => [$presence, 'array', 'min:1'],
            'symbols.*' => ['string', 'max:20', 'regex:/^[A-Z0-9]{2,20}$/', 'distinct'],
            'intervals' => [$presence, 'array', 'min:1'],
            'intervals.*' => ['string', 'distinct', Rule::in(self::INTERVALS)],
            'ai_prompter' => [$presence, Rule::in(self::AI_PROMPTERS)],
            'ai_model' => ['nullable', 'string', 'max:40'],
            'rule_fallback_enabled' => ['sometimes', 'boolean'],
            'channels' => [$presence, 'array', 'min:1'],
            'channels.*' => ['string', 'max:40', 'distinct'],
            'active' => ['sometimes', 'boolean'],
            'auto_execute' => ['sometimes', 'boolean'],
            'execution_instance_id' => [
                $creating && $request->boolean('auto_execute') ? 'required' : 'nullable',
                'integer',
                Rule::exists('strategy_instances', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'execution_notional' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'execution_min_confidence' => ['sometimes', 'integer', 'between:0,100'],
            'execution_daily_limit' => ['sometimes', 'integer', 'between:0,65535'],
        ];
    }
}
