<?php

declare(strict_types=1);

namespace Capell\Core\Facades;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static DatabasePlatform for(Connection|Model|string|null $context = null)
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
