<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Observability\MetricsRegistry;
use App\Domain\Observability\MetricsStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Prometheus 文本格式指标端点（公开，供监控系统抓取）。
 *
 * 输出符合 Prometheus exposition format（text/plain; version=0.0.4），
 * 每行一条指标，支持 HELP / TYPE / 标签。
 */
final class MetricsController extends Controller
{
    public function __invoke(MetricsStore $store): Response
    {
        $lines = [];

        foreach (MetricsRegistry::all() as $name => $meta) {
            $lines[] = '# HELP '.$name.' '.$meta['help'];
            $lines[] = '# TYPE '.$name.' '.$meta['type'];

            $rows = $store->read($name, $meta['type']);
            foreach ($rows as $labelJson => $value) {
                $labels = $this->formatLabels((string) $labelJson, $meta['labels']);
                $lines[] = $name.$labels.' '.$this->formatValue($value);
            }
        }

        $body = implode("\n", $lines)."\n";

        return response($body, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * 将 Redis hash field（标签 JSON）转成 Prometheus 标签串。
     *
     * @param  array<int,string>  $labelKeys
     */
    private function formatLabels(string $labelJson, array $labelKeys): string
    {
        if ($labelJson === '') {
            return '';
        }

        $decoded = json_decode($labelJson, true);
        if (! is_array($decoded)) {
            return '';
        }

        $parts = [];
        foreach ($labelKeys as $key) {
            if (array_key_exists($key, $decoded)) {
                $parts[] = $key.'="'.$this->escapeLabel((string) $decoded[$key]).'"';
            }
        }

        return $parts === [] ? '' : '{'.implode(',', $parts).'}';
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    private function formatValue(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return 'NaN';
        }
        // 去掉无意义的小数尾零
        $formatted = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
