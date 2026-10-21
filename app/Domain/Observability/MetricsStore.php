<?php

declare(strict_types=1);

namespace App\Domain\Observability;

use Illuminate\Support\Facades\Redis;

/**
 * Redis 指标存储（计数器 / 仪表盘）。
 *
 * 存储结构：
 *  - 计数器：hash `metrics:c:{name}`，field = 标签 JSON，value = 累计值
 *  - 仪表盘：hash `metrics:g:{name}`，field = 标签 JSON，value = 当前值
 *
 * 标签按 key 排序后 JSON 编码，保证同组标签落在同一 field。
 *
 * 可用性：Redis 不可用时所有操作静默失败（fail-open），指标降级不影响业务。
 */
final class MetricsStore
{
    /**
     * 计数器递增。
     *
     * @param  array<string,string|int>  $labels
     */
    public function increment(string $name, array $labels = [], int $amount = 1): void
    {
        try {
            Redis::connection()->hincrby($this->counterKey($name), $this->labelField($labels), $amount);
        } catch (\Throwable) {
            // 指标降级不影响业务
        }
    }

    /**
     * 仪表盘设置当前值。
     *
     * @param  array<string,string|int>  $labels
     */
    public function gauge(string $name, array $labels = [], float|int $value = 1): void
    {
        try {
            Redis::connection()->hset($this->gaugeKey($name), $this->labelField($labels), (string) $value);
        } catch (\Throwable) {
            // 指标降级不影响业务
        }
    }

    /**
     * 仪表盘增量（可正可负）。
     *
     * @param  array<string,string|int>  $labels
     */
    public function gaugeAdd(string $name, array $labels = [], float|int $delta = 1): void
    {
        try {
            Redis::connection()->hincrbyfloat($this->gaugeKey($name), $this->labelField($labels), (string) $delta);
        } catch (\Throwable) {
            // 指标降级不影响业务
        }
    }

    /**
     * 读取指定指标的所有标签组合及值。
     *
     * @return array<string,float>  field(标签JSON) => value
     */
    public function read(string $name, string $type): array
    {
        $key = $type === 'gauge' ? $this->gaugeKey($name) : $this->counterKey($name);
        try {
            $raw = Redis::connection()->hgetall($key);
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($raw as $field => $value) {
            $result[$field] = (float) $value;
        }

        return $result;
    }

    private function counterKey(string $name): string
    {
        return 'metrics:c:'.$name;
    }

    private function gaugeKey(string $name): string
    {
        return 'metrics:g:'.$name;
    }

    /**
     * @param  array<string,string|int>  $labels
     */
    private function labelField(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        ksort($labels);

        return json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
