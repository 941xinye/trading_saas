<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyState;
use App\Domain\Strategy\Custom\Dsl\DslExecutionException;
use App\Domain\Strategy\Custom\Dsl\DslPreviewService;
use App\Domain\Strategy\Custom\Dsl\DslSignal;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use App\Domain\Strategy\Custom\Dsl\WhitelistInterpreter;
use App\Domain\Strategy\Custom\DslParser;
use App\Domain\Strategy\Custom\DslStrategy;
use App\Domain\Strategy\Custom\Dsl\Preview\HistoricalPreviewService;
use App\Domain\Strategy\Spot\SpotDcaStrategy;
use App\Domain\Strategy\Spot\SpotGridStrategy;
use Illuminate\Console\Command;
use Throwable;

/**
 * spec 006 自定义 DSL 离线全链路断言（T035 / SC-001~010）。
 *
 * 覆盖：解析/编译 → 沙箱红线 → 生命周期闭环 → 语义等价 → 崩溃恢复幂等 →
 * 预览一致 → 白名单门控 → 求值步预算上界 → bcmath 精度。
 *
 * SC-007（计费/熔断）依赖 Redis，由 DslBillingBreakerTest 覆盖，本命令不重复执行。
 *
 * 预期输出 `ALL CHECKS PASSED`，断言数 ≥ 20。
 */
class PocVerifyDslCommand extends Command
{
    protected $signature = 'poc:verify-dsl';

    protected $description = 'POC: offline assertions for custom DSL strategy engine (spec 006 SC-001~010)';

    private int $pass = 0;

    private int $fail = 0;

    /** @var string[] */
    private array $failures = [];

    public function handle(): int
    {
        $this->info('==== spec 006 自定义 DSL 离线全链路验证 ====');

        try {
            $this->checkCompileAndReject();      // SC-004
            $this->checkSandboxRedline();        // SC-003
            $this->checkLifecycle();             // SC-001
            $this->checkEquivalence();           // SC-002
            $this->checkRecoveryIdempotency();   // SC-005
            $this->checkPreviewConsistency();    // SC-006
            $this->checkWhitelistGate();         // SC-008
            $this->checkStepBudgetUpperBound();  // SC-009
            $this->checkPrecision();             // SC-010
        } catch (Throwable $e) {
            $this->fail++;
            $this->failures[] = '验证流程异常中断：'.$e->getMessage();
            $this->error('FATAL: '.$e->getMessage());
            $this->line($e->getTraceAsString());
        }

        $this->newLine();
        $this->info(sprintf('RESULT: %d/%d passed', $this->pass, $this->pass + $this->fail));
        if ($this->fail > 0) {
            $this->error('VERIFY FAILED');
            foreach ($this->failures as $f) {
                $this->line('  - '.$f);
            }

            return self::FAILURE;
        }

        $this->info('ALL CHECKS PASSED');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // 断言助手
    // ------------------------------------------------------------------

    private function check(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            $this->line('  PASS '.$label);
        } else {
            $this->fail++;
            $this->failures[] = $label.($detail !== '' ? "（{$detail}）" : '');
            $this->error('  FAIL '.$label.($detail !== '' ? "（{$detail}）" : ''));
        }
    }

    private function checkClose(string $actual, string $expected, string $label, int $scale = 8): void
    {
        $ok = bccomp($actual, $expected, $scale) === 0;
        $this->check($ok, $label, $ok ? '' : "actual={$actual} expected={$expected}");
    }

    // ------------------------------------------------------------------
    // SC-004: 合法编译 / 非法拒绝并定位
    // ------------------------------------------------------------------

    private function checkCompileAndReject(): void
    {
        $this->info(PHP_EOL.'---- SC-004 编译与拒绝定位 ----');

        $program = (new DslParser())->parse($this->validScript());
        $this->check($program->version === 1, '合法脚本编译成功，version=1');
        $this->check($program->meta->symbol === 'BTCUSDT', 'meta.symbol 解析正确');

        $rejected = false;
        try {
            (new DslParser())->parse($this->invalidScript());
        } catch (DslValidationException $e) {
            $rejected = true;
            $fields = array_map(static fn ($d): string => $d->field, $e->diagnostics());
            $this->check(in_array('entry.0.when.op', $fields, true), '非法脚本诊断定位到 entry.0.when.op');
        }
        $this->check($rejected, '非法脚本在装载期被拒（不进入运行态）');
    }

    // ------------------------------------------------------------------
    // SC-003: 沙箱红线（未知原语解析期拒绝）
    // ------------------------------------------------------------------

    private function checkSandboxRedline(): void
    {
        $this->info(PHP_EOL.'---- SC-003 沙箱红线 ----');

        foreach (['eval', 'exec', 'shell_exec', 'unknown_op'] as $op) {
            $script = $this->scriptWithOp($op);
            $blocked = false;
            try {
                (new DslParser())->parse($script);
            } catch (DslValidationException) {
                $blocked = true;
            }
            $this->check($blocked, "非白名单 op「{$op}」在解析期被拒");
        }

        // 运行期：未知指标由编译期拒绝，解释器只处理白名单 AST，无 eval 路径
        $this->check(
            ! str_contains((string) file_get_contents(app_path('Domain/Strategy/Custom/Dsl/WhitelistInterpreter.php')), 'eval('),
            'WhitelistInterpreter 不含 eval 调用（静态红线）',
        );
    }

    // ------------------------------------------------------------------
    // SC-001: 生命周期闭环 onBar→信号→下单→成交回报
    // ------------------------------------------------------------------

    private function checkLifecycle(): void
    {
        $this->info(PHP_EOL.'---- SC-001 生命周期闭环 ----');

        $strategy = $this->makeDsl($this->gridScript());
        $bars = $this->bars([115, 105, 115], 1_700_000_000_000);

        // bar1: close=115 >= 110 → exit 触发但无仓位 → 无信号
        $strategy->onBar($bars[0]);
        $this->check($strategy->shouldOpen($this->ctx()) === null && $strategy->shouldClose($this->ctx()) === null, 'bar1 无仓位不产出平仓信号');

        // bar2: close=105 < 110 → 开多
        $strategy->onBar($bars[1]);
        $open = $strategy->shouldOpen($this->ctx());
        $this->check($open !== null && $open['side'] === 'buy', 'bar2 产出开多信号');
        $cidOpen = 'cid-open-1';
        $strategy->attachClientOrderId($cidOpen, 'buy', (string) $open['qty']);
        $strategy->onFill($this->filledOrder($cidOpen, 'buy', (string) $open['qty'], '105'));

        // bar3: close=115 >= 110 → 平多
        $strategy->onBar($bars[2]);
        $close = $strategy->shouldClose($this->ctx());
        $this->check($close !== null && $close['side'] === 'sell', 'bar3 产出平多信号');
        $cidClose = 'cid-close-1';
        $strategy->attachClientOrderId($cidClose, 'sell', (string) $close['qty']);
        $strategy->onFill($this->filledOrder($cidClose, 'sell', (string) $close['qty'], '115'));

        // 平仓后持仓归零
        $snap = $strategy->getState()->positionSnapshot;
        $this->check(($snap['position']['qty'] ?? '0') === '0', '平仓后持仓归零');
    }

    // ------------------------------------------------------------------
    // SC-002: 语义等价（DCA + Grid）
    // ------------------------------------------------------------------

    private function checkEquivalence(): void
    {
        $this->info(PHP_EOL.'---- SC-002 语义等价 ----');

        // DCA parity
        $dcaBars = $this->bars([100, 101, 102, 103, 104], 1_700_000_000_000);
        $dca = new SpotDcaStrategy(1, ['amount_per_order' => '100', 'interval_minutes' => 1, 'max_orders' => 100], $this->ctx());
        $dslDca = $this->makeDsl($this->dcaScript(), '100');

        $dcaSeq = [];
        $dslSeq = [];
        foreach ($dcaBars as $i => $bar) {
            $dcaSig = $this->drive($dca, $bar, $i);
            $dslSig = $this->drive($dslDca, $bar, $i);
            if ($i > 0) { // bar1 DCA 锚定
                $dcaSeq[] = $dcaSig;
                $dslSeq[] = $dslSig;
            }
        }
        $this->check($dcaSeq === $dslSeq, 'DCA 与等价 DSL 决策序列（bar/side/qty）一致');

        // Grid parity
        $gridBars = $this->bars([115, 105, 115, 105, 115], 1_700_000_000_000);
        $grid = new SpotGridStrategy(2, ['lower_price' => '100', 'upper_price' => '120', 'grid_count' => 2, 'investment_amount' => '1000'], $this->ctx());
        $dslGrid = $this->makeDsl($this->gridScript(), '1000');

        $gridSeq = [];
        $dslGridSeq = [];
        foreach ($gridBars as $i => $bar) {
            $gridSeq[] = $this->drive($grid, $bar, $i);
            $dslGridSeq[] = $this->drive($dslGrid, $bar, $i);
        }
        $this->check($gridSeq === $dslGridSeq, 'SpotGrid 与等价 DSL 决策序列（bar/side/qty）一致');
    }

    // ------------------------------------------------------------------
    // SC-005: 崩溃恢复幂等（pending 不重复下单）
    // ------------------------------------------------------------------

    private function checkRecoveryIdempotency(): void
    {
        $this->info(PHP_EOL.'---- SC-005 崩溃恢复幂等 ----');

        $strategy = $this->makeDsl($this->gridScript());
        $bars = $this->bars([105], 1_700_000_000_000);
        $strategy->onBar($bars[0]);
        $open = $strategy->shouldOpen($this->ctx());
        $cid = 'cid-recover';
        $strategy->attachClientOrderId($cid, 'buy', (string) $open['qty']);

        // 模拟崩溃：保存状态后重启新实例
        $state = $strategy->getState();
        $restored = $this->makeDsl($this->gridScript());
        $restored->restoreState($state);

        // pending cid 存在 → pendingClientOrderIds 返回它
        $cids = $restored->pendingClientOrderIds();
        $this->check($cids === [$cid], '恢复后 pending cid 可被核实');

        // 在途单未决期间不产出新信号（绝不重复下单）
        $restored->onBar($bars[0]);
        $this->check(
            $restored->shouldOpen($this->ctx()) === null,
            '在途单未决期间不重复开仓（恢复幂等）',
        );

        // 核实成交后释放占位
        $restored->applyRecoveredOrder($this->filledOrder($cid, 'buy', (string) $open['qty'], '105'));
        $this->check($restored->pendingClientOrderIds() === [], '成交核实后 pending 释放');
    }

    // ------------------------------------------------------------------
    // SC-006: 预览与实跑信号一致
    // ------------------------------------------------------------------

    private function checkPreviewConsistency(): void
    {
        $this->info(PHP_EOL.'---- SC-006 预览一致性 ----');

        $closes = [115, 105, 115, 105, 115, 105];
        $bars = $this->bars($closes, 1_700_000_000_000);
        $script = $this->gridScript();

        $preview = new HistoricalPreviewService(new class($bars) implements HistoricalQuery {
            /** @param ExchangeKline[] $bars */
            public function __construct(private array $bars) {}

            public function query(HistoricalQueryRequest $request): HistoricalQueryResult
            {
                $rows = [];
                foreach ($this->bars as $i => $bar) {
                    $rows[] = [
                        'id' => (string) ($i + 1),
                        'exchange_code' => $bar->exchangeCode,
                        'symbol' => $bar->symbol,
                        'interval' => $bar->interval,
                        'open_time' => $this->ts($bar->openTime),
                        'close_time' => $this->ts($bar->closeTime),
                        'open' => $bar->open, 'high' => $bar->high, 'low' => $bar->low, 'close' => $bar->close,
                        'volume' => $bar->volume, 'quote_volume' => $bar->quoteVolume,
                        'trades_count' => (string) $bar->tradesCount,
                        'created_at' => $this->ts($bar->openTime), 'updated_at' => $this->ts($bar->openTime),
                    ];
                }

                return new HistoricalQueryResult($rows, null, HistoricalQueryResult::HOT);
            }

            private function ts(int $ms): string
            {
                return (new \DateTimeImmutable('@'.intdiv($ms, 1000), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            }
        });

        $result = $preview->preview(1, $script, 'BTCUSDT', '1m', new \DateTimeImmutable('@1700000000'), new \DateTimeImmutable('@1700001000'));

        // 实跑
        $real = $this->makeDsl($script, '10000');
        $realSignals = [];
        foreach ($bars as $i => $bar) {
            $real->onBar($bar);
            $decision = $real->shouldOpen($this->ctx()) ?? $real->shouldClose($this->ctx());
            if ($decision !== null) {
                $cid = 'real-'.$i;
                $real->attachClientOrderId($cid, (string) $decision['side'], (string) $decision['qty']);
                $real->onFill($this->filledOrder($cid, (string) $decision['side'], (string) $decision['qty'], $bar->close));
                $realSignals[] = $bar->openTime.'|'.$decision['side'].'|'.$decision['qty'];
            }
        }

        $previewSignals = array_map(
            static fn (array $s): string => $s['barTime'].'|'.($s['action'] === 'open_long' ? 'buy' : 'sell').'|'.$s['qty'],
            $result->signals,
        );

        $this->check($previewSignals === $realSignals, '预览信号序列与实跑完全一致');
        $this->check($result->assumption === '未模拟滑点/手续费', '预览结果注明未模拟滑点/手续费');
    }

    // ------------------------------------------------------------------
    // SC-008: 白名单门控结构性断言
    // ------------------------------------------------------------------

    private function checkWhitelistGate(): void
    {
        $this->info(PHP_EOL.'---- SC-008 白名单门控 ----');

        $implemented = (array) config('strategy.implemented', []);
        $this->check(in_array('custom_dsl', $implemented, true), 'custom_dsl 已在 strategy.implemented 落地');

        $plans = (array) config('plans.plans', []);
        $this->check(! in_array('custom_dsl', (array) ($plans['free']['features']['strategies'] ?? []), true), 'free 套餐不含 custom_dsl');
        $this->check(in_array('custom_dsl', (array) ($plans['pro']['features']['strategies'] ?? []), true), 'pro 套餐含 custom_dsl');
        $this->check(in_array('custom_dsl', (array) ($plans['enterprise']['features']['strategies'] ?? []), true), 'enterprise 套餐含 custom_dsl');

        // 结构性：任一套餐 strategies ⊆ implemented
        $leak = false;
        foreach ($plans as $plan) {
            foreach ((array) ($plan['features']['strategies'] ?? []) as $code) {
                if (! in_array($code, $implemented, true)) {
                    $leak = true;
                }
            }
        }
        $this->check(! $leak, '任一套餐白名单均 ⊆ strategy.implemented（防超卖）');
    }

    // ------------------------------------------------------------------
    // SC-009: 单 bar 求值步预算上界
    // ------------------------------------------------------------------

    private function checkStepBudgetUpperBound(): void
    {
        $this->info(PHP_EOL.'---- SC-009 求值步预算上界 ----');

        $program = (new DslParser())->parse($this->gridScript());
        $interpreter = new WhitelistInterpreter();

        $bars = $this->bars([105], 1_700_000_000_000);
        $ctx = new \App\Domain\Strategy\Custom\Dsl\DslEvalContext(
            tenantId: 1, instanceId: 1, trigger: 'on_bar', bar: $bars[0], ticker: null,
            recentBars: $bars, position: null, indicatorSnapshot: ['now' => [], 'prev' => null],
            allocatedCapital: '10000',
        );

        $result = $interpreter->evaluate($program, $ctx);
        $budget = (int) config('strategy.dsl.eval_step_budget', 500);
        $this->check($result->costSteps <= $budget, "单 bar 求值步数 {$result->costSteps} <= 预算 {$budget}");
    }

    // ------------------------------------------------------------------
    // SC-010: bcmath 精度边界
    // ------------------------------------------------------------------

    private function checkPrecision(): void
    {
        $this->info(PHP_EOL.'---- SC-010 bcmath 精度 ----');

        $strategy = $this->makeDsl($this->gridScript(), '1000000000');
        $strategy->onBar($this->bars([105], 1_700_000_000_000)[0]);
        $open = $strategy->shouldOpen($this->ctx());
        // qty_pct=0.5, capital=1e9, price=105 → 500000000/105 = 4761904.76190476
        $this->checkClose((string) $open['qty'], '4761904.76190476', '大额 qty_pct 换算无浮点损失');

        // 极小资金：0.000001 * 0.5 / 105 截断为 0 → 不产出信号
        $small = $this->makeDsl($this->gridScript(), '0.000001');
        $small->onBar($this->bars([105], 1_700_000_000_000)[0]);
        $this->check($small->shouldOpen($this->ctx()) === null, '极小资金 qty 截断为 0 时不产出信号');
    }

    // ------------------------------------------------------------------
    // 内部助手
    // ------------------------------------------------------------------

    private function makeDsl(string $script, string $capital = '10000'): DslStrategy
    {
        return new DslStrategy(
            0,
            ['dsl_script' => $script, 'symbol' => 'BTCUSDT', 'tenant_id' => 1, 'allocated_capital' => $capital],
            $this->ctx(),
        );
    }

    private function ctx(): StrategyContext
    {
        return new StrategyContext(0, [], null, null, [], null);
    }

    /**
     * @return array{bar:int,side:string,qty:string}|null
     */
    private function drive(object $strategy, ExchangeKline $bar, int $seq): ?array
    {
        $strategy->onBar($bar);
        $signal = $strategy->shouldOpen($this->ctx()) ?? $strategy->shouldClose($this->ctx());
        if ($signal === null) {
            return null;
        }
        $cid = 'cid-'.$seq;
        $strategy->attachClientOrderId($cid, (string) $signal['side'], (string) $signal['qty']);
        $strategy->onFill($this->filledOrder($cid, (string) $signal['side'], (string) $signal['qty'], $bar->close, intdiv($bar->closeTime, 1000)));

        return ['bar' => $bar->openTime, 'side' => (string) $signal['side'], 'qty' => (string) $signal['qty']];
    }

    private function filledOrder(string $cid, string $side, string $qty, string $price, int $filledAt = 0): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: 'ex-'.$cid, clientOrderId: $cid, symbol: 'BTCUSDT', side: $side,
            type: 'market', quantity: $qty, price: null, stopPrice: null, leverage: null,
            reduceOnly: false, status: 'filled', filledQty: $qty, avgFillPrice: $price, fee: '0',
            placedAt: $filledAt, filledAt: $filledAt,
        );
    }

    /**
     * @param list<string|int|float> $closes
     *
     * @return ExchangeKline[]
     */
    private function bars(array $closes, int $startOpenTime): array
    {
        $bars = [];
        $openTime = $startOpenTime;
        foreach ($closes as $close) {
            $close = (string) $close;
            $bars[] = new ExchangeKline('binance', 'BTCUSDT', '1m', $openTime, $openTime + 59_999, $close, $close, $close, $close, '1', '1', 1);
            $openTime += 60_000;
        }

        return $bars;
    }

    private function validScript(): string
    {
        return implode("\n", [
            'version: 1', 'name: verify', 'symbol: BTCUSDT', 'interval: 1m', 'direction: long', 'trigger: on_bar',
            'filter:', '  - action: noop', '    when:', '      op: true',
            'entry:', '  - action: open_long', '    when:', '      op: gt', '      left:', '        price: close', '      right:', '        const: "100"', '    qty_pct: "0.5"',
            'exit:', '  - action: close_long', '    when:', '      op: lt', '      left:', '        price: close', '      right:', '        const: "100"', '    qty_pct: "1.0"',
            'risk:', '  cooldown_bars: 0', '',
        ]);
    }

    private function invalidScript(): string
    {
        return implode("\n", [
            'version: 1', 'name: bad', 'symbol: BTCUSDT', 'interval: 1m', 'direction: long',
            'filter:', '  - action: noop', '    when:', '      op: true',
            'entry:', '  - action: open_long', '    when:', '      op: gtt', '      left:', '        price: close', '      right:', '        const: "1"', '    qty: "1"',
            'exit:', '  - action: close_long', '    when:', '      op: true', '    qty: "1"',
            'risk:', '  cooldown_bars: 0',
        ]);
    }

    private function scriptWithOp(string $op): string
    {
        return implode("\n", [
            'version: 1', 'name: redline', 'symbol: BTCUSDT', 'interval: 1m', 'direction: long',
            'filter:', '  - action: noop', '    when:', '      op: true',
            'entry:', '  - action: open_long', '    when:', "      op: {$op}", '      left:', '        price: close', '      right:', '        const: "1"', '    qty: "1"',
            'exit:', '  - action: close_long', '    when:', '      op: true', '    qty: "1"',
            'risk:', '  cooldown_bars: 0',
        ]);
    }

    private function dcaScript(): string
    {
        return implode("\n", [
            'version: 1', 'name: dca', 'symbol: BTCUSDT', 'interval: 1m', 'direction: long', 'trigger: on_bar',
            'filter:', '  - action: noop', '    when:', '      op: true',
            'entry:', '  - action: open_long', '    when:', '      op: true', '    qty_pct: "1"',
            'exit:', '  - action: close_long', '    when:', '      op: gt', '      left:', '        const: "0"', '      right:', '        const: "1"', '    qty_pct: "1.0"',
            'risk:', '  cooldown_bars: 0', '',
        ]);
    }

    private function gridScript(): string
    {
        return implode("\n", [
            'version: 1', 'name: grid', 'symbol: BTCUSDT', 'interval: 1m', 'direction: long', 'trigger: on_bar',
            'filter:', '  - action: noop', '    when:', '      op: true',
            'entry:', '  - action: open_long', '    when:', '      op: lt', '      left:', '        price: close', '      right:', '        const: "110"', '    qty_pct: "0.5"',
            'exit:', '  - action: close_long', '    when:', '      op: gte', '      left:', '        price: close', '      right:', '        const: "110"', '    qty_pct: "1.0"',
            'risk:', '  cooldown_bars: 0', '',
        ]);
    }
}
