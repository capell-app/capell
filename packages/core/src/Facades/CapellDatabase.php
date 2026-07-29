<?php

declare(strict_types=1);

namespace Capell\Core\Facades;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Data\Database\DatabaseFullTextSearch;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\DatabaseSearchExpression;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static DatabasePlatform for(Connection|Model|string|null $context = null)
 * @method static DatabasePlatform forDriver(string $driver)
 * @method static DatabasePlatform forConnection(?string $connectionName = null)
 * @method static DatabaseFullTextSearch fullTextSearch(Connection|Model $context, DatabaseIndexDefinition $index, non-empty-list<DatabaseSearchExpression> $expressions, string $query)
 * @method static bool createFullTextIndex(Connection $connection, DatabaseIndexDefinition $index)
 * @method static void dropFullTextIndex(Connection $connection, DatabaseIndexDefinition $index)
 * @method static void forgetFullTextIndexCompatibility(Connection $connection, ?DatabaseIndexDefinition $index = null)
 * @method static void flushFullTextIndexCompatibility()
 *
 * @see DatabasePlatformRegistry
 */
final class CapellDatabase extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DatabasePlatformRegistry::class;
    }
}
