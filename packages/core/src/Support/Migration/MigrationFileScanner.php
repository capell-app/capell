<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

use Illuminate\Support\Facades\File;

final class MigrationFileScanner
{
    /** @return list<string> */
    public static function names(string $path, bool $includeStubs = true): array
    {
        $migrationPaths = File::glob($path . '/*.php') ?: [];

        if ($includeStubs) {
            $migrationPaths = [...$migrationPaths, ...(File::glob($path . '/*.php.stub') ?: [])];
        }

        sort($migrationPaths);

        return array_values(array_unique(array_map(
            static fn (string $migrationPath): string => str($migrationPath)
                ->basename()
                ->replaceEnd('.php.stub', '')
                ->replaceEnd('.php', '')
                ->toString(),
            $migrationPaths,
        )));
    }
}
