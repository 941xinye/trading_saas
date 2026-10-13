<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 密钥解析器：从配置/租户上下文解析 KEK/DEK。
 *
 * 实现走 DI（App\Providers\AppServiceProvider 绑定 TenantKeyResolver），
 * 避免 static 缓存（Octane 长驻下脏数据）。
 *
 * 注意：必须独立成文件（PSR-4），同文件多声明会导致非优化 autoload 下
 * "Interface not found"。
 */
interface KeyResolver
{
    /**
     * 获取主密钥 KEK（32 字节原始二进制）。
     */
    public function masterKey(): string;

    /**
     * 获取指定租户的 DEK（先解密 tenants.exchange_dek，进程内缓存）。
     */
    public function tenantDek(int $tenantId): string;
}
