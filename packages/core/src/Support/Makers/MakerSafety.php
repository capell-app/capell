<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers;

use Capell\Core\Data\Makers\MakerSafetyData;

class MakerSafety
{
    public function current(): MakerSafetyData
    {
        $environment = app()->environment();
        $phpWritesAllowed = $this->modeAllows(config('capell.diagnostics.php_writes', 'local_only'), $environment);
        $databaseWritesAllowed = $this->modeAllows(config('capell.diagnostics.database_writes', 'local_only'), $environment);
        $messages = collect();

        if (! $phpWritesAllowed) {
            $messages->push(__('PHP writes are disabled for this environment.'));
        }

        if (! $databaseWritesAllowed) {
            $messages->push(__('Database writes are disabled for this environment.'));
        }

        return new MakerSafetyData(
            phpWritesAllowed: $phpWritesAllowed,
            databaseWritesAllowed: $databaseWritesAllowed,
            allowedRoots: collect((array) config('capell.diagnostics.allowed_roots', []))
                ->map(fn (string $path): string => $this->normalizePath($path))
                ->values(),
            environment: $environment,
            messages: $messages,
        );
    }

    public function pathIsAllowed(string $path): bool
    {
        $normalizedPath = $this->normalizePath($path);

        return collect((array) config('capell.diagnostics.allowed_roots', []))
            ->map(fn (string $root): string => $this->normalizePath($root))
            ->contains(fn (string $root): bool => $normalizedPath === $root || str_starts_with($normalizedPath, $root . DIRECTORY_SEPARATOR));
    }

    private function modeAllows(string $mode, string $environment): bool
    {
        return match ($mode) {
            'enabled' => true,
            'disabled' => false,
            default => in_array($environment, ['local', 'development', 'testing'], true),
        };
    }

    private function normalizePath(string $path): string
    {
        $realPath = realpath($path);

        if (is_string($realPath)) {
            return rtrim($realPath, DIRECTORY_SEPARATOR);
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
