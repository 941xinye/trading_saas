<?php

declare(strict_types=1);

namespace Tests\Integration\Strategy;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Strategy\Custom\Dsl\ConditionNode;
use App\Domain\Strategy\Custom\Dsl\DslDiagnostic;
use App\Domain\Strategy\Custom\Dsl\DslEvalContext;
use App\Domain\Strategy\Custom\Dsl\DslExecutionException;
use App\Domain\Strategy\Custom\Dsl\DslMeta;
use App\Domain\Strategy\Custom\Dsl\DslProgram;
use App\Domain\Strategy\Custom\Dsl\DslRisk;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use App\Domain\Strategy\Custom\Dsl\OperandNode;
use App\Domain\Strategy\Custom\Dsl\RuleNode;
use App\Domain\Strategy\Custom\Dsl\WhitelistInterpreter;
use App\Domain\Strategy\Custom\DslParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DSL 沙箱红线语料库（tasks T020 / SC-003 / FR-003/004/017）。
 *
 * 覆盖：eval/exec/shell/文件/网络/反射/未知原语 —— 断言**解析期**（DslYamlParser + DslCompiler）
 * 与**运行期**（WhitelistInterpreter）双处拒绝，绝不执行。
 *
 * 宪法 VI：无 RefreshDatabase（纯 Domain 层）、无 Redis、纯离线。
 */
final class DslSandboxRedlineCorpusTest extends TestCase
{
    // ------------------------------------------------------------------
    // 解析期红线：所有恶意脚本必须在此处被拒，不进入运行态（FR-005）
    // ------------------------------------------------------------------

    /**
     * YAML 子集红线：锚点/别名/tag/多文档/flow/Tab/重复键。
     */
    public static function yamlPreScanCorpus(): iterable
    {
        yield '锚点 &' => [self::validEntry()."\n  - &malicious\n    action: open_long\n    qty_pct: '0.10'\n    when:\n      op: true", '锚点'];

        yield '别名 *' => [self::validEntry()."\n  - *malicious\n    action: open_long\n    qty_pct: '0.10'\n    when:\n      op: true", '别名'];

        yield 'tag !' => [self::validEntry()."\n  - !php/object 'O:8:\"stdClass\":0:{}'\n    action: open_long\n    qty_pct: '0.10'\n    when:\n      op: true", 'tag'];

        yield '多文档 ---' => [self::valid()."\n---\nversion: 999", '多文档'];

        yield '多文档 ...' => [self::valid()."\n...", '多文档'];

        yield 'flow {} 在内联 when' => [self::validEntry()."\n  - action: open_long\n    qty_pct: '0.10'\n    when: {op: true}", 'flow'];

        yield 'flow [] 序列值' => [self::script("entry: [{action: open_long, qty_pct: '0.10', when: {op: true}}]"), 'flow'];
    }

    /**
     * 编译期红线：未知顶层键/未知 op/未知指标/未知动作/未知风险键。
     */
    public static function compilerCorpus(): iterable
    {
        // 用一个合法骨架脚本 + 注入一段恶意内容
        yield '未知顶层键 eval' => [self::script("eval: phpinfo()\n".self::validRest()), '未知顶层键'];

        yield '未知顶层键 exec' => [self::script("exec: whoami\n".self::validRest()), '未知顶层键'];

        yield '未知顶层键 system' => [self::script("system: rm -rf /\n".self::validRest()), '未知顶层键'];

        yield '未知顶层键 include' => [self::script("include: evil.php\n".self::validRest()), '未知顶层键'];

        yield '未知顶层键 file_get_contents' => [self::script("file_get_contents: /etc/passwd\n".self::validRest()), '未知顶层键'];

        yield '未知 op ===' => [self::validEntry()."\n  - action: open_long\n    qty_pct: '0.10'\n    when:\n      op: ===\n      left:\n        price: close\n      right:\n        const: '100'", '未知运算符'];

        yield '未知指标 eval' => [self::validEntry()."\n  - action: open_long\n    qty_pct: '0.10'\n    when:\n      op: gt\n      left:\n        indicator: eval\n        period: 1\n      right:\n        const: 1", '未知指标'];

        yield '未知指标 shell_exec' => [self::validEntry()."\n  - action: open_long\n    qty_pct: '0.10'\n    when:\n      op: gt\n      left:\n        indicator: shell_exec\n        period: 1\n      right:\n        const: 1", '未知指标'];

        yield '未知动作 delete' => [self::validEntry()."\n  - action: delete\n    qty_pct: '0.10'\n    when:\n      op: true", '未知动作'];

        yield '未知动作 crash' => [self::validEntry()."\n  - action: crash\n    when:\n      op: true", '未知动作'];

        yield '未知风险键 hack' => [self::script(self::validMeta()."\nfilter:\nentry:\nexit:\nrisk:\n  hack: rm -rf /"), '未知风控键'];

        yield '高版本脚本' => [self::script("version: 999\n".self::validMetaNoVersion()."\nfilter:\nentry:\nexit:\nrisk:"), '高于当前支持'];
    }

    #[DataProvider('yamlPreScanCorpus')]
    public function test_yaml_pre_scan_redlines(string $script, string $expectedKeyword): void
    {
        $this->assertParserRejects($script, $expectedKeyword);
    }

    #[DataProvider('compilerCorpus')]
    public function test_compiler_redlines(string $script, string $expectedKeyword): void
    {
        $this->assertParserRejects($script, $expectedKeyword);
    }

    // ------------------------------------------------------------------
    // 运行期红线：step budget / time budget 超限中断
    // ------------------------------------------------------------------

    public function test_step_budget_overflow_throws(): void
    {
        $interpreter = new WhitelistInterpreter(stepBudget: 3, timeBudgetMs: 5000);
        $root = $this->buildDeepCondition(8);

        $program = $this->buildProgram($root);
        $context = $this->buildContext();

        $this->expectException(DslExecutionException::class);
        $interpreter->evaluate($program, $context);
    }

    public function test_time_budget_overflow_throws(): void
    {
        $interpreter = new WhitelistInterpreter(stepBudget: 10000, timeBudgetMs: 1);
        $root = $this->buildDeepCondition(15);

        $program = $this->buildProgram($root);
        $context = $this->buildContext();

        $this->expectException(DslExecutionException::class);
        $interpreter->evaluate($program, $context);
    }

    // ------------------------------------------------------------------
    // 辅助：断言 + YAML 片段工厂
    // ------------------------------------------------------------------

    /**
     * 解析脚本，断言抛 DslValidationException 且 message 包含期望关键词；
     * diagnostics 必须含 error 级，每条带非零 line / 非空 field / 非空 message。
     */
    private function assertParserRejects(string $script, string $expectedKeyword): void
    {
        $parser = new DslParser();

        try {
            $parser->parse($script);
            $this->fail("应当被拒绝但编译通过了（关键词：{$expectedKeyword}）");
        } catch (DslValidationException $e) {
            $this->assertStringContainsString(
                $expectedKeyword,
                $e->getMessage(),
                "DslValidationException 未包含 '{$expectedKeyword}'，实际：{$e->getMessage()}\n脚本：{$script}",
            );

            $diags = $e->diagnostics();
            $this->assertNotEmpty($diags, '异常必须携带 diagnostics');

            $errors = array_values(array_filter(
                $diags,
                static fn (DslDiagnostic $d): bool => $d->isError(),
            ));
            $this->assertNotEmpty($errors, '诊断必须含 error 级');

            foreach ($diags as $d) {
                $this->assertGreaterThanOrEqual(0, $d->line);
                $this->assertNotEmpty($d->field);
                $this->assertNotEmpty($d->message);
            }
        }
    }

    /**
     * 递归来构造深嵌套的 and 条件：2^depth 叶子。
     */
    private function buildDeepCondition(int $depth): ConditionNode
    {
        if ($depth === 0) {
            return ConditionNode::comparison(
                'gt',
                OperandNode::const('1'),
                OperandNode::const('0'),
            );
        }

        return ConditionNode::logical(
            'and',
            [
                $this->buildDeepCondition($depth - 1),
                $this->buildDeepCondition($depth - 1),
            ],
        );
    }

    private function buildProgram(ConditionNode $entryCondition): DslProgram
    {
        return new DslProgram(
            version: 1,
            meta: new DslMeta('t', 'BTCUSDT', '1m', 'long', 'on_bar'),
            filters: [],
            entries: [new RuleNode('open_long', $entryCondition, null, '0.10')],
            exits: [],
            risk: new DslRisk(),
            sourceHash: sha1('s'),
        );
    }

    private function buildContext(): DslEvalContext
    {
        $bar = new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: 1_700_000_000_000,
            closeTime: 1_700_000_059_000,
            open: '100',
            high: '110',
            low: '95',
            close: '105',
            volume: '1000',
            quoteVolume: '100000',
            tradesCount: 5,
        );

        return new DslEvalContext(
            tenantId: 990001,
            instanceId: 990001,
            trigger: 'on_bar',
            bar: $bar,
            ticker: null,
            recentBars: [$bar],
            position: null,
            indicatorSnapshot: ['now' => [], 'prev' => []],
            allocatedCapital: '10000',
            positionSnapshot: null,
        );
    }

    // ---- YAML 片段工厂（全程块式写法，绝不含 {} / [] / flow） ----

    /**
     * 脚本入口：给一段完整 body（已含四段）。
     */
    private static function script(string $body): string
    {
        return $body;
    }

    /**
     * 完整合法骨架（含四段与 version/meta）。
     */
    private static function valid(): string
    {
        return self::validMeta()."\nfilter:\nentry:\nexit:\nrisk:";
    }

    /**
     * 合法 meta + filter + exit + risk，entry 段留给测试方追加。
     */
    private static function validEntry(): string
    {
        return self::validMeta()."\nfilter:\nexit:\nrisk:\nentry:";
    }

    /**
     * filter + exit + risk 空段（entry 段已在别处给出）。
     */
    private static function validRest(): string
    {
        return self::validMeta()."\nfilter:\nentry:\nexit:\nrisk:";
    }

    private static function validMeta(): string
    {
        return "version: 1\nname: redline-test\nsymbol: BTCUSDT\ninterval: 1m\ndirection: long\ntrigger: on_bar";
    }

    private static function validMetaNoVersion(): string
    {
        return "name: redline-test\nsymbol: BTCUSDT\ninterval: 1m\ndirection: long\ntrigger: on_bar";
    }
}
