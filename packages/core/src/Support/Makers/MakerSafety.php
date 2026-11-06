<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers;

use Capell\Core\Data\Makers\MakerSafetyData;
use Illuminate\Support\Collection;

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
            allowedRoots: $this->configuredRoots()
                ->map(fn (string $path): string => $this->normalizePath($path))
                ->values(),
            environment: $environment,
            messages: $messages,
        );
    }

    public function pathIsAllowed(string $path): bool
    {
        $normalizedPath = $this->canonicalPath($path);

        if ($normalizedPath === null) {
            return false;
        }

        return $this->configuredRoots()
            ->map(fn (string $root): ?string => $this->canonicalPath($root))
            ->contains(fn (?string $root): bool => $root !== null && ($normalizedPath === $root || str_starts_with($normalizedPath, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)));
    }

    /**
     * The configured roots plus the generated-tests root, which every maker writes to.
     * An application that published an older config would otherwise refuse every maker.
     *
     * @return Collection<int, string>
     */
    private function configuredRoots(): Collection
    {
        return collect((array) config('capell.diagnostics.allowed_roots', []))
            ->push(base_path('tests/Feature/Generated'))
            ->unique()
            ->values();
    }

    private function modeAllows(string $mode, string $environment): bool
    {
        return match ($mode) {
            'enabled' => true,
            'disabled' => false,
            default => in_array($environment, ['local', 'development', 'testing'], true),
        };
    }

    private function canonicalPath(string $path): ?string
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return null;
        }

        $segments = explode(DIRECTORY_SEPARATOR, substr($path, 1));

        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        $ancestor = $path;
        $remaining = [];

        while (! file_exists($ancestor)) {
            if (is_link($ancestor)) {
                return null;
            }

            array_unshift($remaining, basename($ancestor));
            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                return null;
            }

            $ancestor = $parent;
        }

        $resolved = realpath($ancestor);

        return $resolved === false ? null : rtrim($resolved, DIRECTORY_SEPARATOR) . ($remaining === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $remaining));
    }

    private function normalizePath(string $path): string
    {
        return $this->canonicalPath($path) ?? $path;
    }
}
