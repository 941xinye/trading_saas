<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Custom\DslStrategy;
use App\Domain\Strategy\Futures\FuturesDcaStrategy;
use App\Domain\Strategy\Futures\FuturesGridStrategy;
use App\Domain\Strategy\Futures\SmartMartingaleStrategy;
use App\Domain\Strategy\Futures\TrendRollingStrategy;
use App\Domain\Strategy\Spot\SpotDcaStrategy;
use App\Domain\Strategy\Spot\SpotGridStrategy;
use RuntimeException;

/**
 * 策略工厂。
 *
 * 按 strategy.code 解析返回 StrategyInterface 实例（DI 注入）。
 * 不使用 static 缓存（Octane 长驻禁用，且策略实例之间状态独立）。
 *
 * 7 种内置策略：
 *   spot_grid / futures_grid / spot_dca / futures_dca /
 *   smart_martingale / trend_rolling / custom_dsl
 *
 * 用法：
 *   $strategy = $registry->make('spot_grid', $instanceId, $params, $context);
 *   $strategy->onBar($bar);
 */
class StrategyRegistry
{
    public function __construct()
    {
    }

    /**
     * @param string              $code       策略代码
     * @param int                 $instanceId 实例 ID
     * @param array<string,mixed> $params     已合并默认值的参数
     * @param StrategyContext     $context    运行时上下文
     */
    public function make(string $code, int $instanceId, array $params, StrategyContext $context): StrategyInterface
    {
        return match ($code) {
            'spot_grid' => new SpotGridStrategy($instanceId, $params, $context),
            'futures_grid' => new FuturesGridStrategy($instanceId, $params, $context),
            'spot_dca' => new SpotDcaStrategy($instanceId, $params, $context),
            'futures_dca' => new FuturesDcaStrategy($instanceId, $params, $context),
            'smart_martingale' => new SmartMartingaleStrategy($instanceId, $params, $context),
            'trend_rolling' => new TrendRollingStrategy($instanceId, $params, $context),
            'custom_dsl' => new DslStrategy($instanceId, $params, $context),
            default => throw new RuntimeException("Unsupported strategy code: {$code}"),
        };
    }
}
