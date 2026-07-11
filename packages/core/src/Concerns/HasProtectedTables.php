<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Closure;

trait HasProtectedTables
{
    /** @var list<string|Closure(): (string|null)> */
    private array $protectedTables = [];

    public function registerProtectedTable(string|Closure $table): void
    {
        $this->protectedTables[] = $table;
    }

    /**
     * @return list<string>
     */
    public function getProtectedTables(): array
    {
        return array_values(collect($this->protectedTables)
            ->map(fn (string|Closure $table): ?string => $table instanceof Closure ? $table() : $table)
            ->filter(fn (?string $table): bool => filled($table))
            ->unique()
            ->values()
            ->all());
    }
}
