<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 受限 YAML 子集解析器（plan WS1 / contracts §2）。
 *
 * 仅支持：块映射、块序列、plain/单引号/双引号标量、整行与行尾注释、2 空格倍数缩进。
 * 预扫描拒绝：锚点(`&`)/别名(`*`)/tag(`!`)/多文档(`---`/`...`)/flow(`{}`/`[]`)/
 * Tab 缩进/重复键；拒绝时抛 `DslValidationException` 并给出源脚本行号。
 *
 * 说明：自研子集解析（不引入 symfony/yaml 等第三方），以最小化受攻击面（FR-003/FR-017）。
 */
final class DslYamlParser
{
    /** @var list<array{indent:int,text:string,no:int}> */
    private array $lines = [];

    private int $count = 0;

    private int $pos = 0;

    /** @var array<string,int> fieldPath => 行号 */
    private array $lineMap = [];

    /** @var list<DslDiagnostic> */
    private array $errors = [];

    /**
     * @return array<string,mixed> 嵌套 PHP 数组（mapping/sequence/scalar，标量统一为 string）
     *
     * @throws DslValidationException 携带 DslDiagnostic[]（含行号）
     */
    public function parse(string $script): array
    {
        $this->reset();
        $this->scan($script);

        if ($this->errors === []) {
            if ($this->count === 0) {
                return [];
            }
            if ($this->lines[0]['indent'] !== 0) {
                $this->fail($this->lines[0]['no'], '$', '顶层必须从第 0 列开始');
            } else {
                $value = $this->parseNode(0, '');
                if ($this->errors === [] && $this->pos < $this->count) {
                    $line = $this->lines[$this->pos];
                    $this->fail($line['no'], '$', '意外的缩进或多余内容');
                }
                if ($this->errors === []) {
                    $this->throwIfErrors();

                    return $value;
                }
            }
        }

        $this->throwIfErrors();

        return [];
    }

    /**
     * 字段路径 → 源脚本行号映射（供 DslCompiler 生成带行号的定位诊断）。
     *
     * @return array<string,int>
     */
    public function lineMap(): array
    {
        return $this->lineMap;
    }

    private function reset(): void
    {
        $this->lines = [];
        $this->count = 0;
        $this->pos = 0;
        $this->lineMap = [];
        $this->errors = [];
    }

    /**
     * 逐行预扫描：剥离注释、校验缩进、拒绝非法 token，构建逻辑行表。
     */
    private function scan(string $script): void
    {
        $raw = preg_split('/\r\n|\r|\n/', $script);
        if ($raw === false) {
            return;
        }

        foreach ($raw as $i => $line) {
            $no = $i + 1;

            if (str_contains($line, "\t")) {
                $this->fail($no, '$', '不允许使用 Tab 缩进（请用空格）');
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            if (str_starts_with($trimmed, '---') || str_starts_with($trimmed, '...')) {
                $this->fail($no, '$', '不支持多文档分隔符');
                continue;
            }

            $content = $this->stripInlineComment($line);
            if (trim($content) === '') {
                continue;
            }

            $indent = strlen($content) - strlen(ltrim($content, ' '));
            if ($indent % 2 !== 0) {
                $this->fail($no, '$', '缩进必须为 2 个空格的倍数');
                continue;
            }

            $text = substr($content, $indent);
            $this->checkTokens($text, $no);

            $this->lines[] = ['indent' => $indent, 'text' => $text, 'no' => $no];
        }

        $this->count = count($this->lines);
    }

    /**
     * 剥离行尾注释（`#` 前缀带空白且不在引号内）。
     */
    private function stripInlineComment(string $line): string
    {
        $quote = null;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($quote !== null) {
                if ($ch === '\\' && $quote === '"') {
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }

            if ($ch === '#' && ($i === 0 || ctype_space($line[$i - 1]))) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    /**
     * 拒绝非白名单 token：flow 指示符 / 锚点 / 别名 / tag。
     */
    private function checkTokens(string $text, int $no): void
    {
        $quote = null;
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if ($quote !== null) {
                if ($ch === '\\' && $quote === '"') {
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }

            if ($ch === '{' || $ch === '}' || $ch === '[' || $ch === ']') {
                $this->fail($no, '$', '不支持 flow 语法（`{}`/`[]`），请使用块式写法');

                return;
            }

            if ($ch === '&' || $ch === '*') {
                if ($this->isTokenStart($text, $i)) {
                    $this->fail($no, '$', '不支持锚点/别名（`&`/`*`）');

                    return;
                }
            }

            if ($ch === '!' && $this->isTokenStart($text, $i)) {
                $this->fail($no, '$', '不支持 tag（`!`）');

                return;
            }
        }
    }

    private function isTokenStart(string $text, int $i): bool
    {
        if ($i === 0) {
            return true;
        }

        return ctype_space($text[$i - 1]) || in_array($text[$i - 1], ['-', ':', ','], true);
    }

    /**
     * @return array<string,mixed>|list<mixed>|null
     */
    private function parseNode(int $indent, string $path): mixed
    {
        if ($this->pos >= $this->count) {
            return null;
        }

        $line = $this->lines[$this->pos];
        if ($line['indent'] !== $indent) {
            $this->fail($line['no'], $path === '' ? '$' : $path, '缩进错误');

            return null;
        }

        if ($this->isSequenceItem($line['text'])) {
            return $this->parseSequence($indent, $path);
        }

        return $this->parseMapping($indent, null, 0, $path);
    }

    /**
     * @return list<mixed>
     */
    private function parseSequence(int $indent, string $path): array
    {
        $items = [];

        while ($this->pos < $this->count) {
            $line = $this->lines[$this->pos];

            if ($line['indent'] !== $indent) {
                if ($line['indent'] > $indent) {
                    $this->fail($line['no'], $path, '缩进错误');
                }
                break;
            }

            if (! $this->isSequenceItem($line['text'])) {
                break;
            }

            $rest = ltrim(substr($line['text'], 1));
            $lead = strlen($line['text']) - 1 - strlen($rest);
            $no = $line['no'];
            $index = count($items);
            $itemPath = $path === '' ? (string) $index : $path.'.'.$index;
            $this->lineMap[$itemPath] = $no;
            $this->pos++;

            if ($rest === '') {
                if ($this->pos < $this->count && $this->lines[$this->pos]['indent'] > $indent) {
                    $items[] = $this->parseNode($this->lines[$this->pos]['indent'], $itemPath);
                } else {
                    $items[] = null;
                }
                continue;
            }

            if ($this->isMappingPair($rest)) {
                $virtualIndent = $indent + 1 + $lead;
                $items[] = $this->parseMapping($virtualIndent, $rest, $no, $itemPath);
            } else {
                $items[] = $this->parseScalar($rest, $no, $itemPath);
            }
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseMapping(int $indent, ?string $inline, int $inlineNo, string $path): array
    {
        $map = [];

        if ($inline !== null) {
            $this->assignPair($map, $inline, $inlineNo, $path, $indent);
        }

        while ($this->pos < $this->count) {
            $line = $this->lines[$this->pos];

            if ($line['indent'] !== $indent) {
                if ($line['indent'] > $indent) {
                    $this->fail($line['no'], $path === '' ? '$' : $path, '缩进错误');
                }
                break;
            }

            if ($this->isSequenceItem($line['text'])) {
                $this->fail($line['no'], $path === '' ? '$' : $path, '此处不应为序列项');

                return $map;
            }

            $text = $line['text'];
            $no = $line['no'];
            $this->pos++;
            $this->assignPair($map, $text, $no, $path, $indent);
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $map
     */
    private function assignPair(array &$map, string $text, int $no, string $path, int $indent): void
    {
        $colon = strpos($text, ':');
        if ($colon === false || $colon === 0) {
            $this->fail($no, $path === '' ? '$' : $path, '不是合法的 `key: value` 结构');

            return;
        }

        $key = substr($text, 0, $colon);
        if (str_contains($key, ' ')) {
            $this->fail($no, $path === '' ? '$' : $path, '键名不允许包含空格');

            return;
        }

        $childPath = $path === '' ? $key : $path.'.'.$key;
        $this->lineMap[$childPath] = $no;

        if (array_key_exists($key, $map)) {
            $this->fail($no, $childPath, '重复键');

            return;
        }

        $value = trim(substr($text, $colon + 1));

        if ($value === '') {
            if ($this->pos < $this->count && $this->lines[$this->pos]['indent'] > $indent) {
                $map[$key] = $this->parseNode($this->lines[$this->pos]['indent'], $childPath);
            } else {
                $map[$key] = null;
            }

            return;
        }

        $map[$key] = $this->parseScalar($value, $no, $childPath);
    }

    private function parseScalar(string $value, int $no, string $path): string
    {
        $this->lineMap[$path] = $no;

        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            $this->fail($no, $path, '不支持 flow 语法（`{}`/`[]`），请使用块式写法');

            return '';
        }

        if ($value[0] === '"') {
            if (strlen($value) < 2 || ! str_ends_with($value, '"')) {
                $this->fail($no, $path, '双引号字符串未闭合');

                return '';
            }

            return str_replace(['\\"', '\\\\', '\\n'], ['"', '\\', "\n"], substr($value, 1, -1));
        }

        if ($value[0] === "'") {
            if (strlen($value) < 2 || ! str_ends_with($value, "'")) {
                $this->fail($no, $path, '单引号字符串未闭合');

                return '';
            }

            return str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
    }

    private function isSequenceItem(string $text): bool
    {
        return $text === '-' || str_starts_with($text, '- ');
    }

    private function isMappingPair(string $text): bool
    {
        $colon = strpos($text, ':');
        if ($colon === false || $colon === 0) {
            return false;
        }

        return ! str_contains(substr($text, 0, $colon), ' ');
    }

    private function fail(int $line, string $field, string $message): void
    {
        $this->errors[] = new DslDiagnostic(DslDiagnostic::LEVEL_ERROR, $line, $field, $message);
    }

    private function throwIfErrors(): void
    {
        $errors = array_values(array_filter(
            $this->errors,
            static fn (DslDiagnostic $d): bool => $d->isError(),
        ));

        if ($errors !== []) {
            throw new DslValidationException($errors);
        }
    }
}
