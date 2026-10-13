<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom;

/**
 * DSL 解析器（占位）。
 *
 * 将用户编写的策略脚本解析为可执行 AST，供 DslStrategy 执行。
 *
 * 支持的语法（见 config/strategy.php dsl_schema）：
 *  - 触发器：on_bar / on_tick / on_fill
 *  - 动作：open_long / open_short / close_long / close_short / noop
 *  - 指标：ma / rsi / macd / bollinger / atr
 *
 * 骨架阶段不实现词法/语法分析，P6 扩展阶段落地。
 */
class DslParser
{
    public function __construct()
    {
    }

    /**
     * 解析 DSL 脚本为可执行 AST。
     *
     * @param string $script DSL 脚本源码
     *
     * @return array<string,mixed> AST 节点树
     */
    public function parse(string $script): array
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P6 extension.');
    }
}
