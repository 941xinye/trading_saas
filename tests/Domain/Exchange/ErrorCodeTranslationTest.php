<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange;

use App\Domain\Exchange\Adapters\BinanceSpotAdapter;
use App\Domain\Exchange\Adapters\BitgetAdapter;
use App\Domain\Exchange\Adapters\BybitAdapter;
use App\Domain\Exchange\Adapters\OkxAdapter;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 跨所错误码翻译一致性断言（T039，FR-009 + R-008）。
 *
 * 认证/参数/余额/订单不存在类 → clientError 硬失败；
 * 限流/服务器类 → serverError 可重试；未知码 → 保守 serverError（三家一致）。
 */
class ErrorCodeTranslationTest extends TestCase
{
    /**
     * @return array<string,array{0:object,1:string,2:string}>
     */
    public static function clientErrorProvider(): array
    {
        return [
            'Bybit 10003 API key 无效' => [self::bybit(), '10003', 'client'],
            'Bybit 10004 签名不匹配' => [self::bybit(), '10004', 'client'],
            'Bybit 10002 时钟超窗（clarify Q2 硬失败）' => [self::bybit(), '10002', 'client'],
            'Bybit 170213 订单不存在' => [self::bybit(), '170213', 'client'],
            'Binance -2011 订单不存在' => [self::binance(), '-2011', 'client'],
            'OKX 51404 订单不存在' => [self::okx(), '51404', 'client'],
            'OKX 50111 API key 无效' => [self::okx(), '50111', 'client'],
            'Bitget 40012 API key 无效' => [self::bitget(), '40012', 'client'],
            'Bitget 40009 签名不匹配' => [self::bitget(), '40009', 'client'],
            'Bitget 40013 时钟超窗（硬失败 + 校准提示）' => [self::bitget(), '40013', 'client'],
            'Bitget 43001 订单不存在' => [self::bitget(), '43001', 'client'],
        ];
    }

    /**
     * @return array<string,array{0:object,1:string,2:string}>
     */
    public static function serverErrorProvider(): array
    {
        return [
            'Bybit 10006 触发限流' => [self::bybit(), '10006', 'server'],
            'Bybit 10016 服务器繁忙' => [self::bybit(), '10016', 'server'],
            'Binance -1003 访问频率限制' => [self::binance(), '-1003', 'client'],
            'OKX 50011 触发限流' => [self::okx(), '50011', 'server'],
            'Bitget 40725 触发限流' => [self::bitget(), '40725', 'server'],
        ];
    }

    /**
     * @return array<string,array{0:object,1:string,2:string}>
     */
    public static function unknownCodeProvider(): array
    {
        return [
            'Bybit 未知码' => [self::bybit(), '999999', 'server'],
            'Binance 未知码' => [self::binance(), '-999999', 'client'],
            'OKX 未知码' => [self::okx(), '999999', 'server'],
            'Bitget 未知码（保守 server）' => [self::bitget(), '999999', 'server'],
        ];
    }

    #[DataProvider('clientErrorProvider')]
    public function test_client_hard_failure_codes(object $adapter, string $code, string $expected): void
    {
        $this->assertTranslation($adapter, $code, $expected);
    }

    #[DataProvider('serverErrorProvider')]
    public function test_retryable_server_codes(object $adapter, string $code, string $expected): void
    {
        $this->assertTranslation($adapter, $code, $expected);
    }

    #[DataProvider('unknownCodeProvider')]
    public function test_unknown_codes_fall_back_consistently(object $adapter, string $code, string $expected): void
    {
        $this->assertTranslation($adapter, $code, $expected);
    }

    public function test_bybit_rate_limit_codes_are_retryable(): void
    {
        // Bybit 10006/10016 必须可重试，与 Binance/OKX 限流/服务器类语义一致（FR-009）
        $translate = new ReflectionMethod(self::bybit(), 'translateErrorCode');
        $translate->setAccessible(true);

        static::assertSame('server', $translate->invoke(self::bybit(), '10006', 200));
        static::assertSame('server', $translate->invoke(self::bybit(), '10016', 200));
    }

    private function assertTranslation(object $adapter, string $code, string $expected): void
    {
        $method = new ReflectionMethod($adapter, 'translateErrorCode');
        $method->setAccessible(true);

        static::assertSame($expected, $method->invoke($adapter, $code, 200));
    }

    private static function bybit(): BybitAdapter
    {
        return new BybitAdapter(new RateLimiter(), new ExchangeHealthChecker(), []);
    }

    private static function binance(): BinanceSpotAdapter
    {
        return new BinanceSpotAdapter(new RateLimiter(), new ExchangeHealthChecker(), []);
    }

    private static function okx(): OkxAdapter
    {
        return new OkxAdapter(new RateLimiter(), new ExchangeHealthChecker(), []);
    }

    private static function bitget(): BitgetAdapter
    {
        return new BitgetAdapter(new RateLimiter(), new ExchangeHealthChecker(), []);
    }
}
