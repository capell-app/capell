<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use Illuminate\Support\Facades\File;

final class ResponsiveVisibilityGuard
{
    public static function hasConflict(string $classes): bool
    {
        $boundary = '[\s\x22\x27]';
        $display = 'block|inline|inline-block|flex|inline-flex|grid|inline-grid|flow-root|contents|list-item|inline-table|table(?:-cell|-row|-caption|-column|-column-group|-footer-group|-header-group|-row-group)?';

        return preg_match('/(?:^|' . $boundary . ')hidden(?=$|' . $boundary . ')/', $classes) === 1
            && preg_match('/(?:^|' . $boundary . ')(?:sm|md|lg|xl|2xl):(?:' . $display . ')(?=$|' . $boundary . ')/', $classes) === 1;
    }

    /**
     * @return list<string>
     */
    public static function violations(string $source): array
    {
        // Include whole class arrays so separate conditional entries cannot hide the conflict.
        preg_match_all('~@class\s*\(\s*\[.*?\]\s*\)|["\x27]class["\x27]\s*=>\s*\[.*?\]|(["\x27])(?:\\\\.|(?!\1).)*?\1~s', $source, $matches);

        return array_values(array_filter($matches[0], self::hasConflict(...)));
    }

    /**
     * @return list<string>
     */
    public static function scan(string $root): array
    {
        $violations = [];

        foreach (['resources', 'src'] as $directory) {
            foreach (glob($root . '/packages/*/' . $directory, GLOB_ONLYDIR) ?: [] as $path) {
                foreach (File::allFiles($path) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $relativePath = substr($file->getPathname(), strlen($root) + 1);

                    if (str_starts_with($relativePath, 'packages/frontend/resources/views/')
                        && ! str_contains($relativePath, '/filament/')) {
                        continue;
                    }

                    foreach (self::violations(File::get($file->getPathname())) as $classes) {
                        $violations[] = $relativePath . ': ' . trim($classes);
                    }
                }
            }
        }

        sort($violations);

        return $violations;
    }
}
