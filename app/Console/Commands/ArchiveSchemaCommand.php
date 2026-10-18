<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Exceptions\ArchiveStorageUnavailableException;
use Illuminate\Console\Command;
use Throwable;

/**
 * P8 归档 Schema 建立（spec 004 / R-011：CH 无 migration 机制，命令幂等 DDL）。
 *
 *   php artisan archive:schema
 *
 * 幂等：全部 CREATE TABLE/DATABASE IF NOT EXISTS，重复执行不报错。
 * CH 不可达 → 非零退出并报原因（FR-008 旁路语义：不影响热链路）。
 */
class ArchiveSchemaCommand extends Command
{
    protected $signature = 'archive:schema';

    protected $description = '幂等建立 ClickHouse 历史归档库与四张冷表（P8）';

    public function handle(ArchiveStore $store): int
    {
        try {
            $statements = $store->createSchema();
        } catch (ArchiveStorageUnavailableException $e) {
            $this->error('ClickHouse 不可达：' . $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Schema 建立异常：' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($statements as $statement) {
            $this->info($this->describe($statement));
        }
        $this->newLine();
        $this->info('归档 Schema 就绪（4 张冷表 + 库，幂等可重跑）。');

        return self::SUCCESS;
    }

    private function describe(string $statement): string
    {
        if (preg_match('/CREATE (?:DATABASE|TABLE) IF NOT EXISTS [`"]?(\w+)/', $statement, $m) === 1) {
            return sprintf('✓ %s 就绪', $m[1]);
        }

        return '✓ ' . substr($statement, 0, 60);
    }
}
