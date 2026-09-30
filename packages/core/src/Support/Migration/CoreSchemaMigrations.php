<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\Schema;

final class CoreSchemaMigrations
{
    public static function path(): string
    {
        return dirname(__DIR__, 3) . '/database/migrations';
    }

    public static function createsExistingTable(string $migration): bool
    {
        // Core create migrations only create a table; side effects belong in later migrations.
        if (preg_match('/^(?:\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_)?create_(.+)_tables?$/', $migration, $matches) !== 1) {
            return false;
        }

        return Schema::hasTable($matches[1]);
    }

    /** @param list<string> $pendingCore */
    public static function isRedundantPublishedCreate(string $migration, array $pendingCore = []): bool
    {
        $name = self::withoutTimestamp($migration);

        foreach (CapellCore::getMigrations() as $managedMigration) {
            if (self::withoutTimestamp($managedMigration) === $name) {
                if (self::createsExistingTable($migration)) {
                    return true;
                }

                return str_starts_with($name, 'create_') && in_array($name, array_map(self::withoutTimestamp(...), $pendingCore), true);
            }
        }

        return false;
    }

    private static function withoutTimestamp(string $migration): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_/', '', $migration) ?? $migration;
    }
}
