<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LiteralGuardTest extends TestCase
{
    private const PATTERN = '/2026-01-23|2026-04-08|2026-08-25|ucp\.dev\/\d{4}-\d{2}-\d{2}/';

    public function test_version_dates_live_only_in_the_registry_and_wire_translators(): void
    {
        $modules = dirname(__DIR__, 3);
        $allowed = [
            realpath($modules . '/fdpsucp/src/Ucp/Wire'),
            realpath($modules . '/fdpsucp/src/Ucp/VersionRegistry.php'),
        ];
        $roots = [
            $modules . '/fdpsucp/src',
            $modules . '/fdpsucp/controllers',
            $modules . '/fdpsucp/fdpsucp.php',
            $modules . '/fdpsprism/src',
            $modules . '/fdpsprism/fdpsprism.php',
        ];

        $hits = [];
        foreach ($this->phpFiles($roots) as $path) {
            if ($this->isAllowed($path, $allowed)) {
                continue;
            }
            foreach (file($path) as $number => $line) {
                if (preg_match(self::PATTERN, $line)) {
                    $hits[] = $path . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame([], $hits);
    }

    private function phpFiles(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            if (is_file($root)) {
                $files[] = realpath($root);
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getRealPath();
                }
            }
        }

        return $files;
    }

    private function isAllowed(string $path, array $allowed): bool
    {
        foreach ($allowed as $prefix) {
            if ($path === $prefix || strpos($path, $prefix . DIRECTORY_SEPARATOR) === 0) {
                return true;
            }
        }

        return false;
    }
}
