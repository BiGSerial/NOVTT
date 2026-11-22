<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The engine is a standalone library: it must not reference Laravel, HTTP or database layers.
 */
final class FrameworkIndependenceTest extends TestCase
{
    private const FORBIDDEN = ['Illuminate\\', 'Laravel\\', 'Symfony\\Component\\HttpFoundation', 'PDO', 'Doctrine\\'];

    public function test_engine_source_has_no_framework_or_infrastructure_dependencies(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src'));
        $checked = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString($needle, $source, $file->getPathname() . " references {$needle}");
            }
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function test_composer_runtime_requirements_are_php_only(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['php'], array_keys($composer['require']));
    }
}
