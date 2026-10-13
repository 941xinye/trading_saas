<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom;

use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Contracts\ExchangeOrder;

/**
 * 自定义 DSL 策略。
 *
 * 用户在 DSL 编辑器内编写策略脚本（params.dsl_script），
 * 由 DslParser 解析为可执行 AST，DslStrategy 执行 AST 产出开平仓信号。
 *
 * 触发器：on_bar / on_tick / on_fill
 * 动作：open_long / open_short / close_long / close_short / noop
 * 指标：ma / rsi / macd / bollinger / atr
 *
 * 骨架阶段不实现解析与执行逻辑，P6 扩展阶段落地。
 */
class DslStrategy extends AbstractStrategy
{
    public function onTick(ExchangeTicker $ticker): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P6 extension.');
    }

    public function onBar(ExchangeKline $bar): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P6 extension.');
    }

    public function onFill(ExchangeOrder $order): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P6 extension.');
    }

    public function shouldOpen(StrategyContext $ctx): ?array
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P6 extension.');
    }

    public function shouldClose(StrategyContext $ctx): ?array
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P6 extension.');
    }
}
