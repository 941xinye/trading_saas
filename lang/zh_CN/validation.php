<?php

declare(strict_types=1);

/**
 * 中文（简体）validation 翻译骨架。
 *
 * 沿用 CRM 项目教训：phpunit.xml 强制 env 对 Laravel i18n 不生效，
 * 必须在 TestCase::setUp 中调用 $this->app->setLocale('zh_CN')。
 *
 * POC 阶段建议：composer require caouecs/laravel-lang 替换为社区完整翻译。
 */
return [

    'required' => ':attribute 不能为空。',
    'email' => ':attribute 必须是有效的邮箱。',
    'unique' => ':attribute 已被占用。',
    'min' => [
        'numeric' => ':attribute 必须不小于 :min。',
        'string' => ':attribute 至少 :min 个字符。',
        'array' => ':attribute 至少 :min 项。',
    ],
    'max' => [
        'numeric' => ':attribute 不能大于 :max。',
        'string' => ':attribute 不能超过 :max 个字符。',
        'array' => ':attribute 最多 :max 项。',
    ],
    'numeric' => ':attribute 必须是数字。',
    'alpha_dash' => ':attribute 只能包含字母、数字、连字符与下划线。',
    'in' => '所选 :attribute 无效。',

    'custom' => [
        'tenant_id' => [
            'required' => '租户 ID 不能为空。',
        ],
        'role' => [
            'in' => '角色无效。',
        ],
    ],

    'attributes' => [
        'name' => '名称',
        'email' => '邮箱',
        'password' => '密码',
        'symbol' => '交易对',
        'interval' => '周期',
        'status' => '状态',
        'plan_code' => '套餐',
        'tenant_id' => '租户',
        'role' => '角色',
    ],

];
