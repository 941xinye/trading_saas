<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Risk\PlatformKillSwitch;
use App\Filament\Platform\Pages\OpsControl;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;

/**
 * Platform 面板全局急停页。
 *
 * 覆盖：状态渲染、启用 action 写 Redis、解除 action 清键、与命令共用同一标志。
 */
#[Group('redis')]
class PlatformOpsControlPageTest extends TestCase
{
    use ChecksRedisAvailability;

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Redis::connection()->del(PlatformKillSwitch::KEY);

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->create([
            'tenant_id' => null,
            'role' => 'platform_admin',
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                Redis::connection()->del(PlatformKillSwitch::KEY);
            } catch (\Throwable) {
                // Redis 失联时不阻断 parent::tearDown()（坑 #24）
            }
        }

        parent::tearDown();
    }

    public function test_page_shows_off_state_and_activates_switch(): void
    {
        Livewire::test(OpsControl::class)
            ->assertSee('全局急停')
            ->assertSee('正常 · 允许开新仓')
            ->callAction('activateKillSwitch', data: [
                'reason' => 'exchange incident',
                'actor' => 'phpunit',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, (int) Redis::connection()->exists(PlatformKillSwitch::KEY));
        $this->assertSame('exchange incident', (new PlatformKillSwitch())->status()['reason'] ?? null);
    }

    public function test_active_state_renders_details_and_can_be_deactivated(): void
    {
        (new PlatformKillSwitch())->activate('maintenance', 'ops');

        Livewire::test(OpsControl::class)
            ->assertSee('急停中 · 禁止开新仓')
            ->assertSee('maintenance')
            ->assertSee('ops')
            ->callAction('deactivateKillSwitch')
            ->assertHasNoActionErrors();

        $this->assertSame(0, (int) Redis::connection()->exists(PlatformKillSwitch::KEY));
    }
}
