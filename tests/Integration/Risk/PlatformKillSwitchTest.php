<?php

declare(strict_types=1);

namespace Tests\Integration\Risk;

use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\PlatformKillSwitch;
use App\Domain\Risk\RiskGuard;
use App\Domain\Signal\Exceptions\SignalExecutionException;
use App\Domain\Signal\SignalExecutionService;
use App\Models\Signal;
use App\Models\StrategyInstance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;

/**
 * 平台级全局急停。
 *
 * 语义红线：只停开新仓；平仓（check('close')）与信号执行前拦截必须区分。
 */
#[Group('redis')]
class PlatformKillSwitchTest extends TestCase
{
    use ChecksRedisAvailability;

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Redis::connection()->del(PlatformKillSwitch::KEY);
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                Redis::connection()->del(PlatformKillSwitch::KEY);
            } catch (\Throwable) {
                // Redis 已在用例中失联：不得阻断 parent::tearDown()（坑 #24）
            }
        }

        parent::tearDown();
    }

    public function test_switch_blocks_open_but_always_allows_close(): void
    {
        $switch = new PlatformKillSwitch();
        $guard = new RiskGuard(
            [],
            new CircuitBreaker(new BreakerStateMachine(), 990050, 990051),
            '1000',
            null,
            null,
            $switch,
        );

        $this->assertTrue($guard->check(990051, 'open')->allowed);

        $switch->activate('exchange incident', 'phpunit');

        $blocked = $guard->check(990051, 'open');
        $this->assertFalse($blocked->allowed);
        $this->assertSame('platform_kill_switch', $blocked->reason);
        $this->assertTrue($guard->check(990051, 'close')->allowed);

        $switch->deactivate();
        $this->assertTrue($guard->check(990051, 'open')->allowed);
    }

    public function test_command_toggles_and_reports_status(): void
    {
        Artisan::call('ops:kill-switch', ['action' => 'on', '--reason' => 'test', '--actor' => 'phpunit']);
        $this->assertTrue((new PlatformKillSwitch())->isActive());

        Artisan::call('ops:kill-switch', ['action' => 'status']);
        $this->assertStringContainsString('on', Artisan::output());

        Artisan::call('ops:kill-switch', ['action' => 'off']);
        $this->assertFalse((new PlatformKillSwitch())->isActive());
    }

    public function test_signal_execution_is_rejected_when_switch_is_active(): void
    {
        (new PlatformKillSwitch())->activate('test', 'phpunit');

        // 急停判断位于 execute() 第一步，容器装配的真实依赖不会被调用
        $service = app(SignalExecutionService::class);

        $this->expectException(SignalExecutionException::class);
        $this->expectExceptionMessage('全局急停');

        $service->execute(new Signal(), new StrategyInstance(), '100');
    }
}
