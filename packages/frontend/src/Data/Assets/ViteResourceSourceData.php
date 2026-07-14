<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Frontend\Contracts\FrontendResourceSourceData;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class ViteResourceSourceData extends Data implements FrontendResourceSourceData
{
    public function __construct(
        public readonly string $entry,
        public readonly string $buildDirectory = 'build',
    ) {
        self::assertRelativePath($entry, 'Vite entry');
        self::assertRelativePath($buildDirectory, 'Vite build directory');
    }

    private static function assertRelativePath(string $path, string $label): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || filter_var($path, FILTER_VALIDATE_URL) !== false) {
            throw new InvalidArgumentException($label . ' must be a safe relative path.');
        }
    }
}
