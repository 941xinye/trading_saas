<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signal\Exceptions\SignalExecutionException;
use App\Domain\Signal\SignalExecutionService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\SignalResource;
use App\Models\Signal;
use App\Models\StrategyInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 信号 API Controller（index / show / execute）。
 *
 * execute 为人工确认的一键下单（信号 → 交易联动第一步）：
 * 复用 OrderExecutor 的权益/配额闸门与 client_order_id 幂等，不允许同一信号
 * 在同一实例上重复执行。
 */
class SignalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = Signal::query()->latest('triggered_at')->paginate();

        return SignalResource::collection($records);
    }

    public function show(Signal $signal): SignalResource
    {
        return new SignalResource($signal);
    }

    /**
     * 执行信号（body：instance_id 必填，notional 可选）。
     *
     * 422：方向非法 / 信号过期 / 交易对不匹配 / 资金或凭据问题；
     * 409：实例未运行 / 重复执行；404：跨租户信号或实例。
     */
    public function execute(Request $request, Signal $signal): JsonResponse
    {
        $tenantId = TenantContext::requireTenantId();

        if ((int) $signal->tenant_id !== $tenantId) {
            abort(404);
        }

        $data = $request->validate([
            'instance_id' => ['required', 'integer', 'min:1'],
            'notional' => ['sometimes', 'numeric', 'gt:0'],
        ]);

        $instance = StrategyInstance::query()
            ->where('tenant_id', $tenantId)
            ->find((int) $data['instance_id']);

        if ($instance === null) {
            abort(404);
        }

        $notional = (string) ($data['notional'] ?? config('signal.execution.default_notional', '100'));

        try {
            $result = app(SignalExecutionService::class)->execute($signal, $instance, $notional);
        } catch (SignalExecutionException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->conflict ? 409 : 422,
            );
        }

        $order = $result['order'];

        return response()->json([
            'data' => [
                'signal_id' => $signal->id,
                'instance_id' => $instance->id,
                'client_order_id' => $order->clientOrderId,
                'exchange_order_id' => $order->exchangeOrderId,
                'symbol' => $order->symbol,
                'side' => $order->side,
                'type' => $order->type,
                'quantity' => $order->quantity,
                'notional' => $result['notional'],
                'reference_price' => $result['reference_price'],
                'status' => $order->status,
                'filled_qty' => $order->filledQty,
                'avg_fill_price' => $order->avgFillPrice,
            ],
        ], 201);
    }
}
