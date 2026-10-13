<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * 基础测试用例骨架。
 *
 * 沿用 CRM 教训：
 *  - phpunit.xml env force 对 Laravel i18n 与运行时服务不生效，
 *    必须在 setUp() 中显式调用 $this->app->setLocale('zh_CN')。
 *  - RefreshDatabase trait 保证每次 test 隔离（SQLite::memory:）。
 *  - Octane 长驻 worker 状态不污染测试：测试在独立 CLI 进程跑。
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * setUp：基础初始化。
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 沿用 CRM 教训：phpunit.xml env force 不生效，必须在 setUp 显式设置 locale。
        $this->app->setLocale('zh_CN');
    }
}
