<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use PHPUnit\Framework\TestCase;

/**
 * FR-004 守护：回测零真实交易所调用。
 * 静态扫描 Domain\Backtest 目录，禁止出现 ExchangeAdapter / placeOrder / cancelOrder 等调用。
 */
final class BacktestNoExchangeCallTest extends TestCase
{
    private const BACKTEST_DIR = 'app/Domain/Backtest';

    private const FORBIDDEN_PATTERNS = [
        '/ExchangeAdapter\b/' => 'ExchangeAdapter 类型引用',
        '/->placeOrder\s*\(/' => 'placeOrder 调用',
        '/->cancelOrder\s*\(/' => 'cancelOrder 调用',
        '/->fetchOrder\s*\(/' => 'fetchOrder 调用',
        '/->fetchBalance\s*\(/' => 'fetchBalance 调用',
        '/->fetchTicker\s*\(/' => 'fetchTicker 调用',
        '/->fetchKlines\s*\(/' => 'fetchKlines 调用',
        '/->fetchPositions\s*\(/' => 'fetchPositions 调用',
    ];

    public function test_no_exchange_adapter_usage(): void
    {
        $violations = [];
        $files = $this->phpFiles(self::BACKTEST_DIR);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // 剥离注释与字符串，避免误报
            $stripped = $this->stripCommentsAndStrings($content);

            foreach (self::FORBIDDEN_PATTERNS as $pattern => $label) {
                if (preg_match_all($pattern, $stripped, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $line = substr_count(substr($stripped, 0, $match[1]), "\n") + 1;
                        $violations[] = sprintf('%s:%d %s', $file, $line, $label);
                    }
                }
            }
        }

        static::assertSame([], $violations, 'FR-004 违规：回测目录存在交易所调用'.PHP_EOL.implode(PHP_EOL, $violations));
    }

    /**
     * 剥离 PHP 注释与字符串字面量（简化实现，仅用于守护扫描）。
     */
    private function stripCommentsAndStrings(string $code): string
    {
        // 去除块注释
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        // 去除行注释
        $code = preg_replace('#//.*$#m', '', $code);
        // 去除单行字符串
        $code = preg_replace('/\'[^\']*\'/', "''", $code);
        $code = preg_replace('/"[^"]*"/', '""', $code);

        return $code;
    }

    public function test_runner_dependencies_exclude_exchange(): void
    {
        $reflection = new \ReflectionClass(\App\Domain\Backtest\Services\BacktestRunner::class);
        $constructor = $reflection->getConstructor();

        static::assertNotNull($constructor);

        $paramNames = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );

        foreach ($paramNames as $name) {
            static::assertStringNotContainsStringIgnoringCase('exchange', $name, "Runner 依赖不应含 Exchange: {$name}");
            static::assertStringNotContainsStringIgnoringCase('adapter', $name, "Runner 依赖不应含 Adapter: {$name}");
        }
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
