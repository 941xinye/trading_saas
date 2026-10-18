<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin 面板租户告警订阅注入测试（B7，default 组：无 Redis/网络依赖）。
 *
 * 验证 panels::body.end renderHook：
 *  - 租户用户访问 /admin 时输出 window.__tenantAlerts（含本租户 ID 与 Reverb 参数）；
 *  - 平台超管（tenant_id=null）不注入，避免 platform 面板串订阅。
 */
class AdminTenantAlertsTest extends TestCase
{
    private const TENANT_ID = 990071;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-alerts-990071',
            'slug' => 'poc-alerts-990071',
            'exchange_dek' => 'dummy-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_panel_injects_tenant_alerts_config(): void
    {
        $user = User::factory()->create([
            'tenant_id' => self::TENANT_ID,
            'role' => 'tenant_admin',
        ]);

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
        $response->assertSee('window.__tenantAlerts', false);
        // Js::from 输出 JSON.parse('{\u0022tenantId\u0022:990071,...}')，按转义形态断言租户 ID
        $response->assertSee('tenantId' . '\u0022:' . self::TENANT_ID, false);
        // Vite 构建产物带内容哈希（alerts-<hash>.js），按前缀断言
        $response->assertSee('assets/alerts-', false);
    }

    public function test_platform_admin_gets_no_tenant_alerts_config(): void
    {
        $admin = User::factory()->create([
            'tenant_id' => null,
            'role' => 'platform_admin',
        ]);

        $response = $this->actingAs($admin)->get('/platform');

        $response->assertOk();
        $response->assertDontSee('window.__tenantAlerts', false);
    }
}
