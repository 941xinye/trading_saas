# ADR-0003: API Key 加密 — AES-256-GCM 三段密钥层级

- **Status**: Accepted
- **Date**: 2026-10-13

## Context

交易所 API Key/Secret 需要加密存储。Laravel 默认的 `Crypt::encryptString` 使用 AES-256-CBC + HMAC-SHA256，但存在两个问题：

1. CBC 模式不是认证加密，密文可被篡改（HMAC 只验证完整性，不验证机密性）
2. 所有密钥用同一个 APP_KEY，无法做密钥轮换

## Decision

使用 AES-256-GCM（基于 libsodium `sodium_crypto_aead_aes256gcm_*`），三段密钥层级：

```
KEK（主密钥）
├── 存于 config/exchange.master_key（.env 注入，绝不入库）
├── 加密每租户的 DEK
│
DEK（数据密钥，每租户一个）
├── 加密存于 tenants.exchange_dek（用 KEK 加密）
├── 用 DEK 加密该租户的所有 API Key/Secret
│
租户 API Key
├── 用 DEK 加密存于 exchange_accounts.encrypted_api_key / encrypted_api_secret
├── DEK 轮换时标记新版本号，lazy 解密时重新加密
└── 明文仅创建时返回一次
```

输出格式：`base64(nonce(12B) + ciphertext + tag(16B))`

GCM 提供认证加密（AEAD）：密文 + nonce + tag 绑定，篡改任何一部分都会解密失败。

## Consequences

**正面**：
- 认证加密 vs CBC+HMAC：篡改密文会被检测
- 三段密钥层级：KEK 泄露不影响已加密的 DEK（DEK 需单独解密）
- DEK 可独立轮换（不影响其他租户）
- libsodium 是 PHP 核心扩展（8.0+ 默认启用）

**负面**：
- 比 CBC+HMAC 稍复杂（需要自定义 AesGcmCrypt 类）
- 解密需要同时持有 KEK 和 DEK（多一个依赖）
- 解密结果需要缓存（5 分钟，避免频繁解密）

## Hard Rules

- KEK 绝不入库，仅存于 .env / 环境变量
- API Secret 明文仅创建时返回一次，服务端不缓存
- DEK 轮换标记版本号，旧密文 lazy 解密时重新加密

## Alternatives Considered

### Laravel Crypt::encryptString（AES-256-CBC + HMAC）
- 被否决：非认证加密；所有密钥用同一个 APP_KEY，无法做租户级隔离或轮换

### RSA 非对称加密
- 被否决：性能比 GCM 差 10-100x；API Key 长度短（64 字符），GCM 更合适
