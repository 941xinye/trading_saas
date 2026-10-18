<?php

declare(strict_types=1);

namespace App\Domain\Archive\Contracts;

/**
 * 归档数据类型（spec 004 FR-001）。
 *
 * 值与 config/archive.php types 键、archive_batches.data_type 字符串一致。
 * 每类型绑定一个 ArchiveType 策略实现（时间轴/CH 表名/visibility/行映射）。
 */
enum ArchiveDataType: string
{
    /** 平台公共行情 K 线（kline_bars / ch_kline_bars），visibility=public */
    case KlineBar = 'kline_bar';

    /** 租户 AI/规则信号（signals / ch_signals），visibility=tenant */
    case Signal = 'signal';

    /** 租户策略订单（strategy_orders / ch_strategy_orders），visibility=tenant */
    case StrategyOrder = 'strategy_order';

    /** 平台公共爆仓事件（liquidation_events / ch_liquidation_events），visibility=public */
    case LiquidationEvent = 'liquidation_event';

    /**
     * 绑定的策略实现类（一文件一类，constitution VII）。
     *
     * @return class-string<ArchiveType>
     */
    public function typeClass(): string
    {
        return match ($this) {
            self::KlineBar => \App\Domain\Archive\Types\KlineBarArchiveType::class,
            self::Signal => \App\Domain\Archive\Types\SignalArchiveType::class,
            self::StrategyOrder => \App\Domain\Archive\Types\StrategyOrderArchiveType::class,
            self::LiquidationEvent => \App\Domain\Archive\Types\LiquidationEventArchiveType::class,
        };
    }

    /**
     * 从字符串构造（非法值抛 InvalidArgumentException）。
     */
    public static function fromString(string $value): self
    {
        $case = self::tryFrom($value);
        if ($case === null) {
            throw new \InvalidArgumentException(sprintf('未知归档数据类型 "%s"（合法值：kline_bar/signal/strategy_order/liquidation_event）', $value));
        }

        return $case;
    }
}
