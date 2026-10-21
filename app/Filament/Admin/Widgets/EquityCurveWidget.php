<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Tenancy\TenantContext;
use Filament\Widgets\ChartWidget;

/**
 * 净值曲线图（US5 / T044）。
 *
 * 数据源：backtest_reports.equity_curve（已完成报告的持久化快照，直接喂图，零重算）。
 * 兼容两种序列化形态：[[barTime_ms, equity_str], ...] 与 [{barTime, equity}, ...]。
 */
class EquityCurveWidget extends ChartWidget
{
    // 仅内嵌绩效页详情区，不自动挂 Dashboard
    protected static bool $isDiscovered = false;

    protected ?string $heading = '净值曲线';

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    /** 目标回测任务（由页面 @livewire 传入） */
    public ?int $runId = null;

    public function mount(?int $runId = null): void
    {
        $this->runId = $runId;
        $this->dataChecksum = $this->generateDataChecksum();
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $curve = $this->equityCurve();

        $labels = [];
        $values = [];
        foreach ($curve as $point) {
            [$ts, $equity] = $this->normalizePoint($point);
            $labels[] = $ts > 0 ? gmdate('m-d H:i', intdiv($ts, 1000)) : '';
            $values[] = (float) $equity;
        }

        return [
            'datasets' => [
                [
                    'label' => '净值 (USDT)',
                    'data' => $values,
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.12)',
                    'fill' => true,
                    'tension' => 0.2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function equityCurve(): array
    {
        if ($this->runId === null) {
            return [];
        }

        $tenantId = (int) TenantContext::getTenantId();

        // 双保险租户过滤（报告冗余 tenant_id，不 join runs 亦可隔离）
        $report = BacktestReport::query()
            ->where('tenant_id', $tenantId)
            ->where('backtest_run_id', $this->runId)
            ->first();

        if ($report === null || ! is_array($report->equity_curve)) {
            return [];
        }

        return $report->equity_curve;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function normalizePoint(mixed $point): array
    {
        if (is_array($point)) {
            return [
                (int) ($point[0] ?? $point['barTime'] ?? 0),
                (string) ($point[1] ?? $point['equity'] ?? '0'),
            ];
        }

        if (is_object($point)) {
            return [
                (int) ($point->barTime ?? 0),
                (string) ($point->equity ?? '0'),
            ];
        }

        return [0, '0'];
    }
}
