<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Strategy\Custom\Dsl\DslDiagnostic;
use App\Domain\Strategy\Custom\Dsl\DslValidator;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\StrategyInstanceResource;
use App\Models\StrategyInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * DSL 编辑器校验/试装载（tasks T027 / FR-011/016 / contracts §8）。
 *
 * 四要素：
 *  1. 校验定位：非法脚本诊断带 行/字段；合法脚本返回空诊断
 *  2. 阻断：非法脚本被表单规则拦截（错误消息含「第 … 行」）；合法脚本放行
 *  3. 试装载：仅编译校验，不启动/落库策略实例
 *  4. 租户隔离：DSL 草稿随实例落库且跨租户不可见（RefreshDatabase）
 */
class DslEditorValidationTest extends TestCase
{
    private const TENANT_A = 990130;

    private const TENANT_B = 990131;

    private const INSTANCE_A = 990230;

    private const ACCOUNT_A = 990191;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scaffold();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. 校验定位
    // ------------------------------------------------------------------

    public function test_validator_locates_invalid_field_with_line(): void
    {
        $diagnostics = app(DslValidator::class)->validate($this->badScript());

        static::assertNotEmpty($diagnostics, '非法脚本必须产出诊断');

        $errors = array_values(array_filter(
            $diagnostics,
            static fn (DslDiagnostic $d): bool => $d->isError(),
        ));
        static::assertNotEmpty($errors, '非法脚本必须产出 error 级诊断');

        $fields = array_map(static fn (DslDiagnostic $d): string => $d->field, $diagnostics);
        static::assertContains('entry.0.when.op', $fields, '诊断字段应可定位到具体节点');

        foreach ($errors as $diagnostic) {
            static::assertGreaterThan(0, $diagnostic->line, '诊断应带行号');
            static::assertNotSame('', $diagnostic->field, '诊断应带字段');
        }
    }

    public function test_validator_returns_empty_for_valid_script(): void
    {
        static::assertSame([], app(DslValidator::class)->validate($this->script()), '合法脚本零诊断');
    }

    // ------------------------------------------------------------------
    // 2. 阻断保存
    // ------------------------------------------------------------------

    public function test_invalid_script_blocked_by_form_rule(): void
    {
        $validator = Validator::make(
            ['params' => ['dsl_script' => $this->badScript()]],
            ['params.dsl_script' => [StrategyInstanceResource::dslScriptRule()]],
        );

        static::assertTrue($validator->fails(), '非法脚本必须阻断保存');
        static::assertStringContainsString(
            '第',
            (string) $validator->errors()->first('params.dsl_script'),
            '阻断消息应含「第 N 行」定位',
        );
    }

    public function test_valid_script_passes_form_rule(): void
    {
        $validator = Validator::make(
            ['params' => ['dsl_script' => $this->script()]],
            ['params.dsl_script' => [StrategyInstanceResource::dslScriptRule()]],
        );

        static::assertFalse($validator->fails(), '合法脚本应放行');
    }

    // ------------------------------------------------------------------
    // 3. 试装载仅编译、不启动实例
    // ------------------------------------------------------------------

    public function test_test_load_validates_without_starting_instance(): void
    {
        $rowsBefore = (int) DB::table('strategy_instances')->count();
        static::assertSame(1, $rowsBefore, '前置：仅存在 1 条 DSL 草稿');

        static::assertNotEmpty(
            StrategyInstanceResource::testLoadScript($this->badScript()),
            '试装载非法脚本应返回诊断',
        );
        static::assertSame(
            [],
            StrategyInstanceResource::testLoadScript($this->script()),
            '试装载合法脚本应通过',
        );

        static::assertSame(
            $rowsBefore,
            (int) DB::table('strategy_instances')->count(),
            '试装载不得新建/落库实例',
        );
        static::assertSame(
            'stopped',
            (string) DB::table('strategy_instances')->where('id', self::INSTANCE_A)->value('status'),
            '试装载不得改变实例状态（未启动）',
        );
    }

    public function test_is_dsl_strategy_detects_custom_dsl(): void
    {
        $strategyId = (int) DB::table('strategies')->where('code', 'custom_dsl')->value('id');

        static::assertTrue(StrategyInstanceResource::isDslStrategy($strategyId), 'custom_dsl 应识别为 DSL');
        static::assertFalse(StrategyInstanceResource::isDslStrategy(null), 'null 不是 DSL');
        static::assertFalse(StrategyInstanceResource::isDslStrategy('not-a-number'), '非数字不是 DSL');
    }

    // ------------------------------------------------------------------
    // 4. 租户隔离
    // ------------------------------------------------------------------

    public function test_dsl_draft_is_tenant_isolated(): void
    {
        TenantContext::initialize(self::TENANT_A);
        static::assertSame(1, StrategyInstance::query()->count(), '租户 A 可见自己的 DSL 草稿');

        $draft = StrategyInstance::query()->firstOrFail();
        static::assertSame(
            $this->script(),
            $draft->params['dsl_script'] ?? null,
            'DSL 草稿随实例参数落库',
        );

        TenantContext::initialize(self::TENANT_B);
        static::assertSame(0, StrategyInstance::query()->count(), '租户 B 不可见租户 A 的 DSL 草稿');
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * 合法脚本：entry close>100 开多，exit close<100 平多。
     */
    private function script(): string
    {
        return implode("\n", [
            'version: 1',
            'name: editor',
            'symbol: BTCUSDT',
            'interval: 1m',
            'direction: long',
            'trigger: on_bar',
            'filter:',
            '  - action: noop',
            '    when:',
            '      op: true',
            'entry:',
            '  - action: open_long',
            '    when:',
            '      op: gt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }

    /**
     * 非法脚本：entry 使用未白名单运算符 `gtt`（诊断定位到 entry.0.when.op）。
     */
    private function badScript(): string
    {
        return implode("\n", [
            'version: 1',
            'name: editor-bad',
            'symbol: BTCUSDT',
            'interval: 1m',
            'direction: long',
            'filter:',
            '  - action: noop',
            '    when:',
            '      op: true',
            'entry:',
            '  - action: open_long',
            '    when:',
            '      op: gtt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "1"',
            '    qty: "1"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: true',
            '    qty: "1"',
            'risk:',
            '  cooldown_bars: 0',
        ]);
    }

    private function scaffold(): void
    {
        $now = now();

        foreach ([[self::TENANT_A, 'pro'], [self::TENANT_B, 'pro']] as [$id, $plan]) {
            DB::table('tenants')->insert([
                'id' => $id,
                'name' => "poc-dsl-editor-{$id}",
                'slug' => "poc-dsl-editor-{$id}",
                'exchange_dek' => 'dummy-dek',
                'status' => 'active',
                'plan_code' => $plan,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance-dsl-editor', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'custom_dsl', 'name' => '自定义 DSL', 'category' => 'spot',
            'risk_profile' => 'custom', 'default_params' => json_encode(['dsl_script' => null]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'id' => self::ACCOUNT_A,
            'tenant_id' => self::TENANT_A,
            'exchange_id' => $exchangeId,
            'label' => 'poc-dsl-editor',
            'market' => 'spot',
            'encrypted_api_key' => 'poc',
            'encrypted_api_secret' => 'poc',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        static::assertGreaterThan(0, $accountId);

        DB::table('strategy_instances')->insert([
            'id' => self::INSTANCE_A,
            'tenant_id' => self::TENANT_A,
            'strategy_id' => $strategyId,
            'exchange_account_id' => $accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'params' => json_encode(['dsl_script' => $this->script()]),
            'allocated_capital' => '1000',
            'status' => 'stopped',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
