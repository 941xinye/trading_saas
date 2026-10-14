<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Exchange\Contracts\LiquidationEvent;
use App\Domain\Liquidation\LiquidationClusterer;

/**
 * 记录 ingest 调用的 Clusterer 测试替身：不触碰 Redis。
 *
 * 用于 LiquidationFeedRunner 事件入库/窗口扇出的离线测试。
 */
class RecordingLiquidationClusterer extends LiquidationClusterer
{
    /**
     * @var array<int, array{symbol:string, side:string, window:string, qty:string}>
     */
    public array $ingestCalls = [];

    public function ingest(LiquidationEvent $e, string $window, ?string $eid = null): string
    {
        $this->ingestCalls[] = [
            'symbol' => $e->symbol,
            'side' => $e->side,
            'window' => $window,
            'qty' => $e->qty,
        ];

        return 'stub-eid-'.count($this->ingestCalls);
    }
}
