<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use PHPUnit\Framework\TestCase;

/**
 * config/strategy.php `dsl` 硬闸门与 `strategies.custom_dsl.dsl_schema` 镜像一致性断言
 * （tasks T001 / data-model §6.1：两者取值 MUST 一致，且 MUST NOT 静默丢弃 dsl_schema）。
 *
 * 纯单元测试：直接 require 配置文件（不启动 Laravel，config 文件不含 env()）。
 */
class DslConfigConsistencyTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function strategyConfig(): array
    {
        return require dirname(__DIR__, 4).'/config/strategy.php';
    }

    public function testDslLimitsBlockIsPresent(): void
    {
        $dsl = $this->strategyConfig()['dsl'];

        foreach ([
            'max_script_bytes', 'max_lines', 'max_depth', 'max_rules_per_section',
            'max_indicators', 'eval_step_budget', 'indicator_window_max',
            'allowed_triggers', 'allowed_actions', 'allowed_indicators',
        ] as $key) {
            $this->assertArrayHasKey($key, $dsl, "config('strategy.dsl.{$key}') 缺失");
        }

        $this->assertSame(500, $dsl['eval_step_budget']);
        $this->assertSame(150, $dsl['indicator_window_max']);
    }

    public function testDslAllowedListsMirrorDslSchema(): void
    {
        $config = $this->strategyConfig();
        $dsl = $config['dsl'];
        $schema = $config['strategies']['custom_dsl']['dsl_schema'];

        $this->assertSame($schema['triggers'], $dsl['allowed_triggers']);
        $this->assertSame($schema['actions'], $dsl['allowed_actions']);
        $this->assertSame($schema['indicators'], $dsl['allowed_indicators']);
    }

    public function testDslSchemaIsPreserved(): void
    {
        $schema = $this->strategyConfig()['strategies']['custom_dsl']['dsl_schema'];

        $this->assertNotEmpty($schema['triggers']);
        $this->assertNotEmpty($schema['actions']);
        $this->assertNotEmpty($schema['indicators']);
    }
}
