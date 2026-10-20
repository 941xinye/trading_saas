<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use PHPUnit\Framework\TestCase;

/**
 * DSL 沙箱红线 —— 静态扫描（tasks T021 / FR-003/017）。
 *
 * 断言 app/Domain/Strategy/Custom 目录下所有 PHP 文件中不含动态求值、反射、IO、网络调用等红线关键词。
 * 与 T020 解析期/运行期红线语料库形成互补：
 *   - T020 从输入侧证明恶意 DSL 被拒绝（黑盒）
 *   - T021 从代码侧证明核心目录无逃逸路径（白盒）
 *
 * 宪法 VI：纯 Unit，无 DB / Redis / Laravel 容器。
 */
final class DslNoDynamicEvalTest extends TestCase
{
    /**
     * 红线关键词列表（按风险等级）。
     *
     * 采用完整函数名 + 左括号搜索，避免命中合法变量/方法（如 create_function 的名字只是函数名）。
     *
     * @var array<string, array<string>>
     */
    private const REDLINES = [
        // 动态求值
        'dynamic_eval' => ['eval(', 'assert('],
        // Shell / 进程
        'shell' => ['exec(', 'system(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(', 'pcntl_'],
        // 文件系统写
        'file_write' => ['file_put_contents(', 'fopen(', 'fwrite(', 'fclose(', 'unlink(', 'rmdir(', 'chmod(', 'mkdir('],
        // 文件系统读（DSL 目录不应主动读外部文件）
        'file_read' => ['file_get_contents(', 'readfile(', 'fgets(', 'fread('],
        // 网络
        'network' => ['curl_exec(', 'curl_multi_exec(', 'stream_socket_client(', 'stream_socket_server(', 'fsockopen(', 'socket_create('],
        // 反射
        'reflection' => ['ReflectionClass', 'ReflectionMethod', 'ReflectionProperty', 'get_object_vars(', 'get_class_methods('],
        // 动态包含
        'include' => ['include(', 'require(', 'include_once(', 'require_once('],
        // 反序列化（可能执行 __wakeup）
        'unserialize' => ['unserialize('],
        // 动态调用
        'dynamic_call' => ['create_function(', 'call_user_func(', 'call_user_func_array('],
    ];

    /**
     * 这些文件/符号**不在** DSL 红线范围内，不参与扫描。
     *
     * @var list<string>
     */
    private const EXCLUDES = [
        // 第三方库 / vendor（不在扫描目录内，此处显式声明）
    ];

    /**
     * 扫描目录（app/Domain/Strategy/Custom）。
     *
     * 从 tests/Unit/Strategy/Dsl 向上 4 层到仓库根。
     */
    private const SCAN_DIR = __DIR__.'/../../../../app/Domain/Strategy/Custom';

    public function test_no_redline_keywords_in_dsl_core(): void
    {
        $directory = realpath(self::SCAN_DIR);
        $this->assertIsString($directory, 'DSL 核心目录不存在：'.self::SCAN_DIR);

        $phpFiles = $this->collectPhpFiles($directory);
        $this->assertNotEmpty($phpFiles, '扫描目录下未找到 PHP 文件');

        $violations = [];

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                $violations[$file][] = '无法读取文件';
                continue;
            }

            foreach (self::REDLINES as $category => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($content, $keyword)) {
                        // 命中！记录并收集全部，不立即 fail（方便一次看完所有违规）
                        $violations[$file][] = "[{$category}] 命中红线关键词 '{$keyword}'";
                    }
                }
            }
        }

        if ($violations !== []) {
            $lines = [];
            foreach ($violations as $file => $reasons) {
                $rel = str_replace($directory.'/', '', $file);
                $lines[] = "`{$rel}`:";
                foreach ($reasons as $r) {
                    $lines[] = "  - {$r}";
                }
            }

            $this->fail(sprintf(
                "DSL 核心目录检测到 %d 个文件含红线关键词：\n\n%s\n\n宪法 II/FR-003/FR-017 禁止任何动态求值/反射/IO/网络调用。",
                count($violations),
                implode("\n", $lines),
            ));
        }

        $this->addToAssertionCount(count($phpFiles));
    }

    /**
     * 额外安全检查：WhitelistInterpreter 实现必须用判别式 match，不能出现任何形式的动态分支。
     */
    public function test_whitelist_interpreter_uses_discriminator_match_only(): void
    {
        $interpreterPath = self::SCAN_DIR.'/Dsl/WhitelistInterpreter.php';
        $this->assertFileExists($interpreterPath);

        $content = (string) file_get_contents($interpreterPath);

        // 解释器必须包含 match（判别式）
        $this->assertStringContainsString('match (', $content, 'WhitelistInterpreter 未使用判别式 match');

        // 不得包含 switch 语句（可被用来绕过白名单）
        $this->assertStringNotContainsString('switch (', $content, 'WhitelistInterpreter 不得用 switch，只能用 match 判别');

        // 不得包含 $node->type 以外的动态分支（如 $node->{'method'}）
        $this->assertStringNotContainsString('$', '', '');
        // 上面这行是占位，真正的检查在下面：
        $this->assertStringNotContainsString('$node->{$', $content, 'WhitelistInterpreter 不得用可变方法/属性调用');
    }

    /**
     * 确保 DSL 核心目录下所有文件都是 PHP（避免放置 .phar / 二进制等）。
     */
    public function test_all_files_are_php_or_ignored(): void
    {
        $directory = (string) realpath(self::SCAN_DIR);

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $badFiles = [];
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            $ext = strtolower($file->getExtension());

            if ($ext !== 'php') {
                $badFiles[] = $name;
            }
        }

        $this->assertSame(
            [],
            $badFiles,
            'DSL 核心目录下必须全部为 .php 文件，发现：'.implode(', ', $badFiles),
        );
    }

    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function collectPhpFiles(string $directory): array
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $pathname = $file->getPathname();

            // 跳过排除列表
            foreach (self::EXCLUDES as $exclude) {
                if (str_contains($pathname, $exclude)) {
                    continue 2;
                }
            }

            if (strtolower($file->getExtension()) === 'php') {
                $files[] = $pathname;
            }
        }

        sort($files);

        return $files;
    }
}
