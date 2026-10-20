<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Domain\Payment\Dto\PaymentStatus;
use App\Domain\Payment\Dto\PaymentTransition;
use App\Domain\Payment\PaymentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 支付单状态机迁移表逐行断言（data-model.md §5 / webhook-contract.md §3）：
 * 终态拒绝更新（只进不退）、乱序 processing 丢弃、过期后足额到账 → manual 不自动激活、
 * 部分支付边界（0 < paid < amount）、超额 → confirmed 接线（FR-003 / FR-012 / FR-013）。
 *
 * 纯逻辑用例：不启动 Laravel（事件分类数组直接注入，无 config 依赖）。
 */
class PaymentStatusMachineTest extends TestCase
{
    private const AMOUNT = '49.00000000';

    /**
     * @return array<string, array{0: PaymentStatus, 1: string, 2: ?string, 3: ?PaymentStatus, 4: bool, 5: string[]}>
     */
    public static function transitionProvider(): array
    {
        $P = PaymentStatus::Pending;
        $G = PaymentStatus::Processing;
        $Pa = PaymentStatus::Partial;
        $C = PaymentStatus::Confirmed;
        $E = PaymentStatus::Expired;
        $M = PaymentStatus::Manual;

        return [
            // 支付中（processing 事件）
            'pending + processing(无金额) → processing' => [$P, 'fake.processing', null, $G, false, ['pending']],
            'pending + processing(不足额) → partial' => [$P, 'fake.processing', '10.00000000', $Pa, false, ['pending', 'processing']],
            'pending + processing(零额) → processing' => [$P, 'fake.processing', '0.00000000', $G, false, ['pending']],
            'processing + processing(不足额) → partial' => [$G, 'fake.processing', '10.00000000', $Pa, false, ['pending', 'processing']],
            'processing + processing(无金额) → 丢弃（重复）' => [$G, 'fake.processing', null, null, false, []],
            'partial + processing(仍不足) → 丢弃' => [$Pa, 'fake.processing', '10.00000000', null, false, []],

            // 足额确认（settled 事件）
            'pending + settled(足额) → confirmed+接线' => [$P, 'fake.settled', '49.00000000', $C, true, ['pending', 'processing', 'partial']],
            'processing + settled(足额) → confirmed+接线' => [$G, 'fake.settled', '49.00000000', $C, true, ['pending', 'processing', 'partial']],
            'partial + settled(补齐足额) → confirmed+接线' => [$Pa, 'fake.settled', '49.00000000', $C, true, ['pending', 'processing', 'partial']],
            'pending + settled(超额) → confirmed+接线' => [$P, 'fake.settled', '100.00000000', $C, true, ['pending', 'processing', 'partial']],
            'pending + settled(无金额) → confirmed+接线' => [$P, 'fake.settled', null, $C, true, ['pending', 'processing', 'partial']],
            'pending + settled(不足额,防御) → partial 不接线' => [$P, 'fake.settled', '10.00000000', $Pa, false, ['pending', 'processing']],
            'partial + settled(仍不足) → 丢弃' => [$Pa, 'fake.settled', '10.00000000', null, false, []],

            // 过期（expired 事件）
            'pending + expired → expired' => [$P, 'fake.expired', null, $E, false, ['pending', 'processing', 'partial']],
            'processing + expired → expired' => [$G, 'fake.expired', null, $E, false, ['pending', 'processing', 'partial']],
            'partial + expired(未补齐) → expired' => [$Pa, 'fake.expired', '10.00000000', $E, false, ['pending', 'processing', 'partial']],

            // 过期后足额到账 → manual（不自动激活）
            'expired + settled(足额) → manual 不接线' => [$E, 'fake.settled', '49.00000000', $M, false, ['expired']],
            'expired + settled(超额) → manual 不接线' => [$E, 'fake.settled', '100.00000000', $M, false, ['expired']],
            'expired + settled(不足额) → 丢弃（保持 expired 等人工）' => [$E, 'fake.settled', '10.00000000', null, false, []],
            'expired + expired(重复) → 丢弃' => [$E, 'fake.expired', null, null, false, []],
            'expired + processing(乱序) → 丢弃' => [$E, 'fake.processing', null, null, false, []],

            // 终态拒绝（只进不退）
            'confirmed + settled(重放) → 丢弃' => [$C, 'fake.settled', '49.00000000', null, false, []],
            'confirmed + processing(乱序) → 丢弃' => [$C, 'fake.processing', null, null, false, []],
            'confirmed + expired(乱序) → 丢弃' => [$C, 'fake.expired', null, null, false, []],
            'manual + settled → 丢弃' => [$M, 'fake.settled', '49.00000000', null, false, []],

            // 未知事件类型 → 丢弃
            'pending + 未知事件 → 丢弃' => [$P, 'InvoiceCreated', null, null, false, []],
        ];
    }

    /**
     * @param  string[]  $expectedFromStates
     */
    #[DataProvider('transitionProvider')]
    public function test_transition_table(
        PaymentStatus $current,
        string $eventType,
        ?string $paidAmount,
        ?PaymentStatus $expectedTarget,
        bool $expectedActivate,
        array $expectedFromStates,
    ): void {
        $machine = new PaymentStateMachine(['fake.settled'], ['fake.processing'], ['fake.expired']);

        $transition = $machine->decide($current, $eventType, $paidAmount, self::AMOUNT);

        if ($expectedTarget === null) {
            static::assertNull($transition);

            return;
        }

        static::assertInstanceOf(PaymentTransition::class, $transition);
        static::assertSame($expectedTarget, $transition->target);
        static::assertSame($expectedActivate, $transition->activate);
        static::assertSame(
            $expectedFromStates,
            array_map(fn (PaymentStatus $s): string => $s->value, $transition->fromStates),
        );
    }

    public function test_terminal_states_reject_everything_except_expired_topup(): void
    {
        $machine = new PaymentStateMachine(['fake.settled'], ['fake.processing'], ['fake.expired']);

        foreach ([PaymentStatus::Confirmed, PaymentStatus::Manual] as $terminal) {
            foreach (['fake.settled', 'fake.processing', 'fake.expired'] as $event) {
                static::assertNull(
                    $machine->decide($terminal, $event, '49.00000000', self::AMOUNT),
                    "终态 {$terminal->value} 收到 {$event} 必须拒绝",
                );
            }
        }
    }
}
