<?php

declare(strict_types=1);

namespace Capell\Core\Facades;

use Capell\Core\Support\CapellCoreManager;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin CapellCoreManager
 */
class CapellCore extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CapellCoreManager::class;
    }
}
