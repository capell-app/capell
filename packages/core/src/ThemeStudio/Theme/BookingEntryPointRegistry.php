<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Theme;

use Capell\Core\ThemeStudio\Data\BookingEntryPointData;
use Closure;

final class BookingEntryPointRegistry
{
    /** @var array<string, Closure(): BookingEntryPointData> */
    private array $resolvers = [];

    public function register(string $key, Closure|BookingEntryPointData $entryPoint): void
    {
        $this->resolvers[$key] = $entryPoint instanceof BookingEntryPointData
            ? static fn (): BookingEntryPointData => $entryPoint
            : $entryPoint;
    }

    public function get(string $key = 'default'): ?BookingEntryPointData
    {
        $resolver = $this->resolvers[$key] ?? null;

        return $resolver instanceof Closure ? $resolver() : null;
    }

    /**
     * @return array<string, BookingEntryPointData>
     */
    public function all(): array
    {
        return collect($this->resolvers)
            ->map(fn (Closure $resolver): BookingEntryPointData => $resolver())
            ->all();
    }
}
