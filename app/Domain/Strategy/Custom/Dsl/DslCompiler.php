<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 结构校验 + AST 构建（plan WS1 / contracts §2）。
 *
 * 顶层键白名单 / 四段（filter/entry/exit/risk）/ 枚举 / 类型 / 范围校验；
 * error 级诊断 ⇒ 抛 `DslValidationException`（FR-005），MUST NOT 静默降级或忽略未知字段（FR-002）。
 *
 * 限额取自 `config('strategy.dsl')`（data-model §6.1）；未在 Laravel 上下文调用时回退内置默认。
 */
final class DslCompiler
{
    private const SECTION_KEYS = ['filter', 'entry', 'exit', 'risk'];

    private const TOP_KEYS = [
        'version', 'name', 'symbol', 'interval', 'direction', 'trigger',
        'filter', 'entry', 'exit', 'risk',
    ];

    private const RULE_KEYS = ['action', 'when', 'qty', 'qty_pct', 'note'];

    private const CONDITION_KEYS = ['op', 'left', 'right', 'operands', 'operand'];

    private const OPERAND_KEYS = ['indicator', 'period', 'source', 'band', 'std_dev', 'price', 'const'];

    private const RISK_KEYS = ['max_position_pct', 'cooldown_bars', 'stop_loss_pct', 'take_profit_pct', 'max_entries_per_bar'];

    private const COMPARISON_OPS = ['gt', 'gte', 'lt', 'lte', 'eq', 'ne'];

    private const CROSS_OPS = ['cross_above', 'cross_below'];

    private const TRADING_ACTIONS = ['open_long', 'open_short', 'close_long', 'close_short'];

    /** @var array<string,mixed> */
    private array $config;

    /** @var array<string,int> */
    private array $lineMap = [];

    /** @var list<DslDiagnostic> */
    private array $errors = [];

    private int $indicatorCount = 0;

    private int $observedDepth = 0;

    /**
     * @param array<string,mixed>|null $config config('strategy.dsl') 覆盖；null 用内置默认
     */
    public function __construct(?array $config = null)
    {
        $this->config = ($config === null || $config === []) ? self::defaultLimits() : ($config + self::defaultLimits());
    }

    /**
     * @param array<string,mixed> $raw        DslYamlParser 输出
     * @param string              $sourceHash sha1(原始脚本字节)
     * @param array<string,int>   $lineMap    字段路径 → 行号（DslYamlParser::lineMap()）
     *
     * @throws DslValidationException
     */
    public function compile(array $raw, string $sourceHash, array $lineMap = []): DslProgram
    {
        $this->lineMap = $lineMap;
        $this->errors = [];
        $this->indicatorCount = 0;
        $this->observedDepth = 0;

        foreach ($raw as $key => $_) {
            if (! in_array((string) $key, self::TOP_KEYS, true)) {
                $this->err((string) $key, "未知顶层键 '{$key}'");
            }
        }

        foreach (self::SECTION_KEYS as $section) {
            if (! array_key_exists($section, $raw)) {
                $this->err($section, "缺少 `{$section}` 段（四段为必需）");
            }
        }

        if (! array_key_exists('version', $raw)) {
            $this->err('version', '缺少 `version`（当前支持 1）');
        }

        $version = $this->intValue($raw['version'] ?? null, 'version');
        if ($version !== null && $version !== 1) {
            $this->err('version', $version > 1
                ? "脚本版本 {$version} 高于当前支持版本 1"
                : '不支持的脚本版本');
        }

        $meta = $this->compileMeta($raw);
        $filters = $this->compileSection('filter', $raw['filter'] ?? null, true);
        $entries = $this->compileSection('entry', $raw['entry'] ?? null, false);
        $exits = $this->compileSection('exit', $raw['exit'] ?? null, false);
        $risk = $this->compileRisk($raw['risk'] ?? null);

        if ($this->indicatorCount > $this->limit('max_indicators')) {
            $this->err('$', sprintf('指标数量 %d 超过上限 %d', $this->indicatorCount, $this->limit('max_indicators')));
        }

        if ($this->observedDepth > $this->limit('max_depth')) {
            $this->err('$', sprintf('条件嵌套深度 %d 超过上限 %d', $this->observedDepth, $this->limit('max_depth')));
        }

        $this->throwIfErrors();

        return new DslProgram(
            version: 1,
            meta: $meta,
            filters: $filters,
            entries: $entries,
            exits: $exits,
            risk: $risk,
            sourceHash: $sourceHash,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaultLimits(): array
    {
        return [
            'max_script_bytes' => 8192,
            'max_lines' => 200,
            'max_depth' => 8,
            'max_rules_per_section' => 20,
            'max_indicators' => 8,
            'eval_step_budget' => 500,
            'indicator_window_max' => 150,
            'allowed_triggers' => ['on_bar', 'on_tick', 'on_fill'],
            'allowed_actions' => ['open_long', 'open_short', 'close_long', 'close_short', 'noop'],
            'allowed_indicators' => ['ma', 'rsi', 'macd', 'bollinger', 'atr'],
        ];
    }

    private function compileMeta(array $raw): DslMeta
    {
        $name = $this->stringValue($raw['name'] ?? null, 'name');
        if ($name === null || $name === '') {
            $this->err('name', '`name` 必须为非空字符串');
            $name = '';
        } elseif (strlen($name) > 64) {
            $this->err('name', '`name` 长度不得超过 64');
        }

        $symbol = $this->stringValue($raw['symbol'] ?? null, 'symbol');
        if ($symbol === null || $symbol === '') {
            $this->err('symbol', '`symbol` 必须为非空字符串');
            $symbol = '';
        }

        $interval = $this->stringValue($raw['interval'] ?? null, 'interval');
        if ($interval === null || $interval === '') {
            $this->err('interval', '`interval` 必须为非空字符串');
            $interval = '';
        }

        $direction = $this->stringValue($raw['direction'] ?? null, 'direction');
        if ($direction === null || ! in_array($direction, ['long', 'short', 'both'], true)) {
            $this->err('direction', '`direction` 必须为 long|short|both');
            $direction = 'long';
        }

        $trigger = 'on_bar';
        if (array_key_exists('trigger', $raw)) {
            $trigger = (string) $this->stringValue($raw['trigger'], 'trigger');
            $allowed = (array) $this->config['allowed_triggers'];
            if (! in_array($trigger, $allowed, true)) {
                $this->err('trigger', sprintf("未知触发器 '%s'（允许：%s）", $trigger, implode('|', $allowed)));
            }
        }

        return new DslMeta(
            name: $name,
            symbol: $symbol,
            interval: $interval,
            direction: $direction,
            trigger: $trigger,
        );
    }

    /**
     * @return list<RuleNode>
     */
    private function compileSection(string $section, mixed $value, bool $allowEmpty): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $this->err($section, "`{$section}` 必须为块序列");

            return [];
        }

        $rules = [];
        $count = count($value);

        if ($count === 0 && ! $allowEmpty) {
            $this->err($section, "`{$section}` 段不得为空");
        }

        if ($count > $this->limit('max_rules_per_section')) {
            $this->err($section, sprintf('`%s` 规则数 %d 超过上限 %d', $section, $count, $this->limit('max_rules_per_section')));
        }

        foreach ($value as $index => $item) {
            $rule = $this->compileRule($section, (int) $index, $item);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function compileRule(string $section, int $index, mixed $item): ?RuleNode
    {
        $field = "{$section}.{$index}";

        if (! is_array($item) || array_is_list($item)) {
            $this->err($field, '规则必须为映射');

            return null;
        }

        foreach (array_keys($item) as $key) {
            if (! in_array((string) $key, self::RULE_KEYS, true)) {
                $this->err("{$field}.{$key}", "未知规则键 '{$key}'");
            }
        }

        $action = (string) $this->stringValue($item['action'] ?? null, "{$field}.action");
        $allowedActions = (array) $this->config['allowed_actions'];
        if (! in_array($action, $allowedActions, true)) {
            $this->err("{$field}.action", sprintf("未知动作 '%s'（允许：%s）", $action, implode('|', $allowedActions)));
        }

        $hasQty = array_key_exists('qty', $item);
        $hasPct = array_key_exists('qty_pct', $item);
        $isTrading = in_array($action, self::TRADING_ACTIONS, true);

        if ($hasQty && $hasPct) {
            $this->err($field, '`qty` 与 `qty_pct` 只能二选一');
        }

        if ($isTrading && ! $hasQty && ! $hasPct) {
            $this->err($field, '开/平仓动作必须提供 `qty` 或 `qty_pct`');
        }

        if (! $isTrading && ($hasQty || $hasPct)) {
            $this->err($field, "动作 '{$action}' 不接受数量字段");
        }

        $qty = null;
        $qtyPct = null;

        if ($hasQty) {
            $qty = (string) $this->stringValue($item['qty'], "{$field}.qty");
            if (! $this->isDecimal($qty) || bccomp($qty, '0', 10) <= 0) {
                $this->err("{$field}.qty", '`qty` 必须为正数');
            }
        }

        if ($hasPct) {
            $qtyPct = (string) $this->stringValue($item['qty_pct'], "{$field}.qty_pct");
            if (! $this->isDecimal($qtyPct) || bccomp($qtyPct, '0', 10) <= 0 || bccomp($qtyPct, '1', 10) > 0) {
                $this->err("{$field}.qty_pct", '`qty_pct` 必须落在 (0, 1]');
            }
        }

        $note = array_key_exists('note', $item)
            ? $this->stringValue($item['note'], "{$field}.note")
            : null;

        $condition = null;
        if (array_key_exists('when', $item)) {
            $condition = $this->compileCondition($item['when'], "{$field}.when", 1);
        } elseif ($action === 'noop') {
            $condition = ConditionNode::always();
        } else {
            $this->err($field, '缺少 `when` 条件');
        }

        if ($condition === null) {
            return null;
        }

        return new RuleNode(
            action: $action,
            condition: $condition,
            qty: $qty,
            qtyPct: $qtyPct,
            note: $note,
        );
    }

    private function compileCondition(mixed $value, string $field, int $depth): ?ConditionNode
    {
        $this->observedDepth = max($this->observedDepth, $depth);

        if (! is_array($value) || array_is_list($value)) {
            $this->err($field, '条件必须为映射');

            return null;
        }

        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, self::CONDITION_KEYS, true)) {
                $this->err("{$field}.{$key}", "未知条件键 '{$key}'");
            }
        }

        $op = array_key_exists('op', $value) ? $this->stringValue($value['op'], "{$field}.op") : null;
        if ($op === null) {
            $this->err($field, '缺少 `op`');

            return null;
        }

        if ($op === 'true') {
            return ConditionNode::always();
        }

        if (in_array($op, self::COMPARISON_OPS, true) || in_array($op, self::CROSS_OPS, true)) {
            $left = array_key_exists('left', $value) ? $this->compileOperand($value['left'], "{$field}.left") : null;
            $right = array_key_exists('right', $value) ? $this->compileOperand($value['right'], "{$field}.right") : null;

            if ($left === null) {
                $this->err("{$field}.left", '缺少 `left` 操作数');
            }
            if ($right === null) {
                $this->err("{$field}.right", '缺少 `right` 操作数');
            }
            if ($left === null || $right === null) {
                return null;
            }

            return in_array($op, self::CROSS_OPS, true)
                ? ConditionNode::cross($op, $left, $right)
                : ConditionNode::comparison($op, $left, $right);
        }

        if ($op === 'and' || $op === 'or') {
            if (! array_key_exists('operands', $value) || ! is_array($value['operands']) || ! array_is_list($value['operands'])) {
                $this->err("{$field}.operands", '逻辑条件必须提供非空 `operands` 序列');

                return null;
            }
            if ($value['operands'] === []) {
                $this->err("{$field}.operands", '`operands` 不得为空');

                return null;
            }

            $operands = [];
            foreach ($value['operands'] as $i => $child) {
                $compiled = $this->compileCondition($child, "{$field}.operands.{$i}", $depth + 1);
                if ($compiled !== null) {
                    $operands[] = $compiled;
                }
            }

            return ConditionNode::logical($op, $operands);
        }

        if ($op === 'not') {
            if (! array_key_exists('operand', $value)) {
                $this->err("{$field}.operand", '`not` 必须提供 `operand`');

                return null;
            }

            $operand = $this->compileCondition($value['operand'], "{$field}.operand", $depth + 1);

            return $operand === null ? null : ConditionNode::logical('not', [], $operand);
        }

        $this->err("{$field}.op", sprintf("未知运算符 '%s'", $op));

        return null;
    }

    private function compileOperand(mixed $value, string $field): ?OperandNode
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->err($field, '操作数必须为映射');

            return null;
        }

        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, self::OPERAND_KEYS, true)) {
                $this->err("{$field}.{$key}", "未知操作数键 '{$key}'");
            }
        }

        if (array_key_exists('price', $value)) {
            $this->errIfUnexpectedKeys($value, ['price'], $field, 'price');
            $price = (string) $this->stringValue($value['price'], "{$field}.price");
            if (! in_array($price, ['open', 'high', 'low', 'close', 'last'], true)) {
                $this->err("{$field}.price", sprintf("未知价格字段 '%s'", $price));

                return null;
            }

            return OperandNode::price($price);
        }

        if (array_key_exists('const', $value)) {
            $this->errIfUnexpectedKeys($value, ['const'], $field, 'const');
            $const = (string) $this->stringValue($value['const'], "{$field}.const");
            if (! $this->isDecimal($const)) {
                $this->err("{$field}.const", '`const` 必须为数字字符串');

                return null;
            }

            return OperandNode::const($const);
        }

        if (! array_key_exists('indicator', $value)) {
            $this->err($field, '操作数必须包含 `indicator`/`price`/`const` 之一');

            return null;
        }

        $this->indicatorCount++;

        $indicator = (string) $this->stringValue($value['indicator'], "{$field}.indicator");
        $allowed = (array) $this->config['allowed_indicators'];
        if (! in_array($indicator, $allowed, true)) {
            $this->err("{$field}.indicator", sprintf("未知指标 '%s'（允许：%s）", $indicator, implode('|', $allowed)));

            return null;
        }

        $indicatorKeys = [
            'ma' => ['indicator', 'period'],
            'rsi' => ['indicator', 'period'],
            'atr' => ['indicator', 'period'],
            'macd' => ['indicator', 'source'],
            'bollinger' => ['indicator', 'period', 'band', 'std_dev'],
        ];
        $this->errIfUnexpectedKeys($value, $indicatorKeys[$indicator], $field, $indicator);

        $period = null;
        if ($indicator !== 'macd') {
            $period = $this->intValue($value['period'] ?? null, "{$field}.period");
            if ($period === null || $period < 1 || $period > $this->limit('indicator_window_max')) {
                $this->err("{$field}.period", sprintf('`period` 必须落在 [1, %d]', $this->limit('indicator_window_max')));
                $period = null;
            }
        }

        if ($indicator === 'macd') {
            $source = array_key_exists('source', $value)
                ? (string) $this->stringValue($value['source'], "{$field}.source")
                : 'dif';
            if (! in_array($source, ['dif', 'dea', 'hist'], true)) {
                $this->err("{$field}.source", sprintf("未知 macd source '%s'", $source));

                return null;
            }

            return OperandNode::indicator('macd', null, $source);
        }

        if ($indicator === 'bollinger') {
            $band = array_key_exists('band', $value)
                ? (string) $this->stringValue($value['band'], "{$field}.band")
                : null;
            if ($band === null || ! in_array($band, ['upper', 'middle', 'lower'], true)) {
                $this->err("{$field}.band", '`bollinger` 必须提供 band ∈ upper|middle|lower');
            }

            $stdDev = array_key_exists('std_dev', $value)
                ? (string) $this->stringValue($value['std_dev'], "{$field}.std_dev")
                : '2';
            if (! $this->isDecimal($stdDev) || bccomp($stdDev, '0', 10) <= 0) {
                $this->err("{$field}.std_dev", '`std_dev` 必须为正数');
            }

            if ($band === null || ! in_array($band, ['upper', 'middle', 'lower'], true)) {
                return null;
            }

            return OperandNode::indicator('bollinger', $period, null, $band, $stdDev);
        }

        if ($period === null) {
            return null;
        }

        return OperandNode::indicator($indicator, $period);
    }

    private function compileRisk(mixed $value): DslRisk
    {
        if ($value === null) {
            return new DslRisk();
        }

        if (! is_array($value) || array_is_list($value)) {
            $this->err('risk', '`risk` 段必须为映射');

            return new DslRisk();
        }

        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, self::RISK_KEYS, true)) {
                $this->err("risk.{$key}", "未知风控键 '{$key}'");
            }
        }

        $maxPositionPct = null;
        if (array_key_exists('max_position_pct', $value)) {
            $maxPositionPct = (string) $this->stringValue($value['max_position_pct'], 'risk.max_position_pct');
            if (! $this->isDecimal($maxPositionPct) || bccomp($maxPositionPct, '0', 10) <= 0 || bccomp($maxPositionPct, '1', 10) > 0) {
                $this->err('risk.max_position_pct', '`max_position_pct` 必须落在 (0, 1]');
            }
        }

        $cooldownBars = 0;
        if (array_key_exists('cooldown_bars', $value)) {
            $cooldownBars = (int) $this->intValue($value['cooldown_bars'], 'risk.cooldown_bars');
            if ($cooldownBars < 0) {
                $this->err('risk.cooldown_bars', '`cooldown_bars` 不得为负');
                $cooldownBars = 0;
            }
        }

        $stopLossPct = $this->optionalPct($value, 'stop_loss_pct', 'risk.stop_loss_pct');
        $takeProfitPct = $this->optionalPct($value, 'take_profit_pct', 'risk.take_profit_pct');

        $maxEntriesPerBar = 1;
        if (array_key_exists('max_entries_per_bar', $value)) {
            $maxEntriesPerBar = (int) $this->intValue($value['max_entries_per_bar'], 'risk.max_entries_per_bar');
            if ($maxEntriesPerBar < 1) {
                $this->err('risk.max_entries_per_bar', '`max_entries_per_bar` 必须 ≥ 1');
                $maxEntriesPerBar = 1;
            }
        }

        return new DslRisk(
            maxPositionPct: $maxPositionPct,
            cooldownBars: $cooldownBars,
            stopLossPct: $stopLossPct,
            takeProfitPct: $takeProfitPct,
            maxEntriesPerBar: $maxEntriesPerBar,
        );
    }

    private function optionalPct(array $value, string $key, string $field): ?string
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }

        $pct = (string) $this->stringValue($value[$key], $field);
        if (! $this->isDecimal($pct) || bccomp($pct, '0', 10) <= 0 || bccomp($pct, '1', 10) > 0) {
            $this->err($field, "`{$key}` 必须落在 (0, 1]");

            return null;
        }

        return $pct;
    }

    private function limit(string $key): int
    {
        return (int) ($this->config[$key] ?? 0);
    }

    /**
     * @param array<string,mixed> $value
     * @param string[]            $allowedKeys
     */
    private function errIfUnexpectedKeys(array $value, array $allowedKeys, string $field, string $kind): void
    {
        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, $allowedKeys, true)) {
                $this->err("{$field}.{$key}", sprintf("'%s' 不接受键 '%s'", $kind, $key));
            }
        }
    }

    private function stringValue(mixed $value, string $field): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return null;
        }

        $this->err($field, '必须为标量字符串');

        return null;
    }

    private function intValue(mixed $value, string $field): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_string($value)) {
            $this->err($field, '必须为非负整数');

            return null;
        }

        return null;
    }

    private function isDecimal(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1;
    }

    private function err(string $field, string $message): void
    {
        $this->errors[] = new DslDiagnostic(
            DslDiagnostic::LEVEL_ERROR,
            $this->resolveLine($field),
            $field,
            $message,
        );
    }

    private function resolveLine(string $field): int
    {
        $path = $field;

        while ($path !== '') {
            if (isset($this->lineMap[$path])) {
                return $this->lineMap[$path];
            }

            $pos = strrpos($path, '.');
            if ($pos === false) {
                break;
            }
            $path = substr($path, 0, $pos);
        }

        return 0;
    }

    private function throwIfErrors(): void
    {
        if ($this->errors !== []) {
            throw new DslValidationException($this->errors);
        }
    }
}
