<?php

declare(strict_types=1);

namespace App\Domain\Observability;

/**
 * 告警规则评估器：从 MetricsStore 读指标，按 config/alerts.php 规则判定是否命中。
 *
 * 规则类型：
 *  - rate：numerator 标签值之和 / denominator 标签值之和（如失败率）
 *  - max：所有标签组合中的最大值（如队列积压）
 *  - sum：所有标签组合之和（如熔断实例数）
 */
final class AlertEvaluator
{
    public function __construct(private readonly MetricsStore $metrics)
    {
    }

    /**
     * 评估所有规则，返回命中的告警。
     *
     * @return array<int, array{rule:string,severity:string,message:string,value:float}>
     */
    public function evaluate(): array
    {
        $fired = [];
        foreach ((array) config('alerts.rules', []) as $name => $rule) {
            $result = $this->evaluateRule((string) $name, (array) $rule);
            if ($result !== null) {
                $fired[] = $result;
            }
        }

        return $fired;
    }

    /**
     * @param  array<string,mixed>  $rule
     * @return array{rule:string,severity:string,message:string,value:float}|null
     */
    private function evaluateRule(string $name, array $rule): ?array
    {
        $metric = (string) ($rule['metric'] ?? '');
        $type = (string) ($rule['type'] ?? 'max');
        $threshold = (float) ($rule['threshold'] ?? 0);

        $rows = $this->metrics->read($metric, MetricsRegistry::typeOf($metric));

        $value = match ($type) {
            'rate' => $this->rate($rows, $rule),
            'sum' => $this->sum($rows),
            default => $this->max($rows),
        };

        if ($value === null) {
            return null;
        }

        if ($value <= $threshold) {
            return null;
        }

        $message = (string) ($rule['message'] ?? $name);
        $message = str_replace(
            ['{value}', '{threshold}'],
            [$this->formatValue($value, $type), $this->formatValue($threshold, $type)],
            $message,
        );

        return [
            'rule' => $name,
            'severity' => (string) ($rule['severity'] ?? 'warning'),
            'message' => $message,
            'value' => $value,
        ];
    }

    /**
     * rate：numerator 标签匹配值之和 / denominator 标签匹配值之和。
     *
     * @param  array<string,float>  $rows
     * @param  array<string,mixed>  $rule
     */
    private function rate(array $rows, array $rule): ?float
    {
        $numeratorLabels = $this->matchLabels($rule, 'numerator');
        $denominatorLabels = $this->matchLabels($rule, 'denominator') ?: null;

        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($rows as $labelJson => $value) {
            $labels = json_decode((string) $labelJson, true);
            if (! is_array($labels)) {
                continue;
            }
            if ($this->matches($labels, $numeratorLabels)) {
                $numerator += $value;
            }
            if ($denominatorLabels === null || $this->matches($labels, $denominatorLabels)) {
                $denominator += $value;
            }
        }

        $minTotal = (float) ($rule['min_total'] ?? 0);
        if ($denominator < $minTotal) {
            return null;
        }
        if ($denominator <= 0) {
            return null;
        }

        return $numerator / $denominator;
    }

    /**
     * @param  array<string,float>  $rows
     */
    private function max(array $rows): float
    {
        return $rows === [] ? 0.0 : max($rows);
    }

    /**
     * @param  array<string,float>  $rows
     */
    private function sum(array $rows): float
    {
        return array_sum($rows);
    }

    /**
     * 取出规则中指定前缀的标签匹配条件（如 numerator_statuses）。
     *
     * @param  array<string,mixed>  $rule
     * @return array<string,array<int,string>>
     */
    private function matchLabels(array $rule, string $prefix): array
    {
        $matches = [];
        foreach ($rule as $key => $value) {
            if (! str_starts_with((string) $key, $prefix.'_')) {
                continue;
            }
            $labelKey = substr((string) $key, strlen($prefix) + 1);
            if (is_array($value)) {
                $matches[$labelKey] = array_map('strval', $value);
            }
        }

        return $matches;
    }

    /**
     * @param  array<string,string>  $labels
     * @param  array<string,array<int,string>>  $match
     */
    private function matches(array $labels, array $match): bool
    {
        foreach ($match as $key => $allowed) {
            $actual = (string) ($labels[$key] ?? '');
            if (! in_array($actual, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function formatValue(float $value, string $type): string
    {
        if ($type === 'rate') {
            return number_format($value * 100, 1);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
