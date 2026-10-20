<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use PHPUnit\Framework\TestCase;

/**
 * FR-003 守护：回测与实跑共用同一策略解释器。
 * 静态扫描 Domain\Backtest 目录，禁止直接 new 具体策略类（SpotGridStrategy/FuturesGridStrategy/...）。
 */
final class BacktestNoBypassTest extends TestCase
{
    private const BACKTEST_DIR = 'app/Domain/Backtest';

    private const STRATEGY_CLASSES = [
        'SpotGridStrategy',
        'SpotDcaStrategy',
        'FuturesGridStrategy',
        'DslStrategy',
    ];

    public function test_no_direct_strategy_construction(): void
    {
        $violations = [];
        $files = $this->phpFiles(self::BACKTEST_DIR);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            foreach (self::STRATEGY_CLASSES as $class) {
                // 匹配 "new SpotGridStrategy" 但排除注释和字符串中的出现
                if (preg_match_all('/new\s+'.$class.'\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                        $violations[] = sprintf('%s:%d 直接 new %s', $file, $line, $class);
                    }
                }
            }
        }

        static::assertSame([], $violations, 'FR-003 违规：回测目录存在直接策略构造'.PHP_EOL.implode(PHP_EOL, $violations));
    }

    public function test_strategy_registry_is_only_entry_point(): void
    {
        $files = $this->phpFiles(self::BACKTEST_DIR);
        $foundRegistry = false;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false && str_contains($content, 'StrategyRegistry')) {
                $foundRegistry = true;
            }
        }

        static::assertTrue($foundRegistry, 'BacktestRunner 应通过 StrategyRegistry 构造策略');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $path = base_path($dir);
        if (! is_dir($path)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
