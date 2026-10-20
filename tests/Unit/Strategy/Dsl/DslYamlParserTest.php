<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use App\Domain\Strategy\Custom\Dsl\DslYamlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 受限 YAML 子集解析器测试（tasks T004 / FR-001/002/004）。
 *
 * 覆盖：块映射/块序列/标量/注释（含中文）+ 预扫描拒绝（锚点/别名/tag/多文档/flow/Tab/重复键）定位行号。
 */
class DslYamlParserTest extends TestCase
{
    private function parser(): DslYamlParser
    {
        return new DslYamlParser();
    }

    public function testParsesBlockMappingSequenceAndComments(): void
    {
        $script = <<<'YAML'
        version: 1
        name: "MA 金叉开多" # 中文行尾注释
        # 整行中文注释
        symbol: BTCUSDT
        nested:
          flag: true
          quoted: 'single''quote'
        items:
          - plain
          - key: 2
            other: 3
        YAML;

        $raw = $this->parser()->parse($script);

        $this->assertSame('1', $raw['version']);
        $this->assertSame('MA 金叉开多', $raw['name']);
        $this->assertSame('BTCUSDT', $raw['symbol']);
        $this->assertSame(['flag' => 'true', 'quoted' => "single'quote"], $raw['nested']);
        $this->assertSame(['plain', ['key' => '2', 'other' => '3']], $raw['items']);
    }

    public function testLineMapRecordsFieldPaths(): void
    {
        $script = "version: 1\nname: x\nentry:\n  - action: open_long\n    qty_pct: \"0.3\"\n";

        $parser = $this->parser();
        $parser->parse($script);
        $map = $parser->lineMap();

        $this->assertSame(1, $map['version']);
        $this->assertSame(4, $map['entry.0']);
        $this->assertSame(5, $map['entry.0.qty_pct']);
    }

    public function testEmptyScriptReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->parser()->parse("   \n\n"));
    }

    public function testTopLevelIndentIsRejected(): void
    {
        $this->expectException(DslValidationException::class);
        $this->parser()->parse("  version: 1\n");
    }

    #[DataProvider('invalidScripts')]
    public function testPreScanRejections(string $script, int $line): void
    {
        try {
            $this->parser()->parse($script);
            $this->fail('应当抛出 DslValidationException');
        } catch (DslValidationException $e) {
            $lines = array_map(static fn ($d): int => $d->line, $e->diagnostics());
            $this->assertContains($line, $lines, $e->getMessage());
            $this->assertStringContainsString('第 '.$line.' 行', $e->getMessage());
        }
    }

    /**
     * @return array<string,array{string,int}>
     */
    public static function invalidScripts(): array
    {
        return [
            'anchor' => ["a: &x 1\nb: 2", 1],
            'alias' => ["a: 1\nb: *x", 2],
            'tag' => ["a: !custom 1", 1],
            'multidoc' => ["a: 1\n---\nb: 2", 2],
            'flow-mapping' => ["a: {b: 1}", 1],
            'flow-sequence' => ["a: [1, 2]", 1],
            'tab-indent' => ["a:\n\tb: 1", 2],
            'odd-indent' => ["a:\n   b: 1", 2],
            'duplicate-key' => ["a: 1\na: 2", 2],
            'unclosed-double-quote' => ['a: "oops', 1],
            'unclosed-single-quote' => ["a: 'oops", 1],
        ];
    }
}
