<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 生成 OpenAPI 规范文件：扫描 Http/Controllers/Api/V1 + Resources，输出 openapi.json/yaml。
 *
 * 沿用 CRM 项目的约定：API 契约以 OpenAPI 3.1 为单一可信源，前端 / SDK 联调
 * 优先以生成的 spec 为准（路由注解 + Resource → spec 自动产出）。
 *
 * --output 指定输出路径，缺省写到 docs/openapi.json。
 *
 * POC 阶段：handle 仅占位，实际生成器待 P4 POC（API 契约 finalize 阶段）实现。
 */
class GenerateOpenApiSpecCommand extends Command
{
    /**
     * 命令签名。
     * --output: 输出文件路径，缺省 docs/openapi.json
     */
    protected $signature = 'openapi:generate {--output=}';

    /**
     * 命令描述。
     */
    protected $description = 'Generate OpenAPI 3.1 spec from API controllers + resources (CRM convention)';

    public function handle(): int
    {
        $output = $this->option('output');
        $target = $output !== null && $output !== '' ? "output={$output}" : 'output=docs/openapi.json';
        $this->info("Skeleton: openapi:generate {$target} not implemented. Wait for P4 POC.");

        return self::SUCCESS;
    }
}
