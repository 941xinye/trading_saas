<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exchange\ExchangeManager;
use App\Domain\Liquidation\LiquidationMapService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * P5 大户爆仓数据 API。
 *
 * 路由：GET /api/v1/liquidation/map, /api/v1/liquidation/recent
 * 走 auth:sanctum + tenant 中间件（routes/api.php），但数据本身市场级（无 tenant_id）。
 */
class LiquidationController extends Controller
{
    public function __construct(
        private readonly LiquidationMapService $mapService,
        private readonly ExchangeManager $exchangeManager,
    ) {
    }

    /**
     * GET /api/v1/liquidation/map?symbol=BTCUSDT&window=5m&top=10
     *
     * 返回 top N 密集区。currentPrice 由后端从 spot adapter 取，避免前端额外往返。
     */
    public function map(Request $request): JsonResponse
    {
        $validated = $this->validateMap($request);

        $currentPrice = $this->fetchCurrentPrice($validated['symbol']);

        $rows = $this->mapService->query(
            'binance',
            $validated['symbol'],
            $validated['window'],
            $validated['top'],
            $currentPrice,
        );

        return response()->json([
            'symbol' => $validated['symbol'],
            'window' => $validated['window'],
            'current_price' => $currentPrice,
            'clusters' => $rows,
        ]);
    }

    /**
     * GET /api/v1/liquidation/recent?symbol=BTCUSDT&limit=50
     *
     * 返回最近 N 条原始强平事件（按 event_ts desc）。
     */
    public function recent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $limit = (int) ($validated['limit'] ?? 50);

        $rows = DB::table('liquidation_events')
            ->where('symbol', $validated['symbol'])
            ->orderByDesc('event_ts')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'exchange_code' => $r->exchange_code,
                'symbol' => $r->symbol,
                'side' => $r->side,
                'price' => $r->price,
                'qty' => $r->qty,
                'value_usd' => $r->value_usd,
                'event_ts' => $r->event_ts,
            ])
            ->values()
            ->all();

        return response()->json([
            'symbol' => $validated['symbol'],
            'limit' => $limit,
            'events' => $rows,
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * @return array{symbol:string, window:string, top:int}
     */
    private function validateMap(Request $request): array
    {
        return $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'window' => ['sometimes', 'string', Rule::in(['5m', '15m', '60m'])],
            'top' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]) + ['window' => '5m', 'top' => 10];
    }

    /**
     * 通过 spot adapter 取最新价（公开行情，无需凭据）。
     * 失败时返回 null（distance_pct 不会计算）。
     */
    private function fetchCurrentPrice(string $symbol): ?string
    {
        try {
            $adapter = $this->exchangeManager->adapter('binance', 'spot', []);
            $ticker = $adapter->getTicker($symbol);

            return $ticker->lastPrice !== '0' ? $ticker->lastPrice : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
