<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MainFileImportsTest extends TestCase
{
    public function test_main_file_imports_resolve_without_the_ucp_core_loaded(): void
    {
        $module = dirname(__DIR__, 2);
        $script = <<<'PHP'
define('_PS_VERSION_', '9.0.0');
require $argv[1] . '/src/autoload.php';
$tokens = token_get_all(file_get_contents($argv[1] . '/fdpsprism.php'));
foreach ($tokens as $i => $token) {
    if (is_array($token) && $token[0] === T_USE && is_array($tokens[$i + 2] ?? null)) {
        $class = ltrim($tokens[$i + 2][1], '\\');
        if (!class_exists($class) && !interface_exists($class)) {
            echo "unresolved {$class}\n";
            exit(1);
        }
    }
}
echo "ok\n";
PHP;

        $file = tempnam(sys_get_temp_dir(), 'fdpsprism');
        file_put_contents($file, "<?php\n" . $script);
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($module) . ' 2>&1', $output, $exit);
        unlink($file);

        $this->assertSame(0, $exit, implode("\n", $output));
        $this->assertSame(['ok'], $output);
    }
}
